<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$success = trim($_GET['success'] ?? '');
$error   = trim($_GET['error']   ?? '');

// Handle resolution POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('discrepancy_resolve.php');

    $batch_id   = intval($_POST['batch_id'] ?? 0);
    $resolution = trim($_POST['resolution'] ?? '');
    $reason     = trim($_POST['reason']     ?? '');

    if (!$batch_id || !in_array($resolution, ['reopen_receiver', 'reopen_validator', 'override', 'reject'])) {
        header("Location: discrepancy_resolve.php?error=" . urlencode("Invalid resolution action."));
        exit();
    }
    if ($reason === '') {
        header("Location: discrepancy_resolve.php?error=" . urlencode("Resolution reason is required."));
        exit();
    }

    $conn->begin_transaction();
    try {
        $bq = $conn->prepare("SELECT * FROM receiving_batches WHERE id = ? AND status = 'on_hold' LIMIT 1 FOR UPDATE");
        $bq->bind_param("i", $batch_id);
        $bq->execute();
        $batch = $bq->get_result()->fetch_assoc();
        if (!$batch) throw new Exception("Batch not found or not on hold.");

        $new_status    = '';
        $audit_action  = '';
        $notify_role   = null;
        $notify_type   = null;
        $notify_msg    = '';

        switch ($resolution) {
            case 'reopen_receiver':
                $new_status   = 'pending_request';
                $audit_action = 'reopen_receiver';
                $notify_role  = ROLE_RECEIVER;
                $notify_type  = 'override';
                $notify_msg   = "Batch #$batch_id has been re-opened for re-encoding. Reason: $reason";
                break;
            case 'reopen_validator':
                $new_status   = 'pending_validation';
                $audit_action = 'reopen_validator';
                $notify_role  = ROLE_VALIDATOR;
                $notify_type  = 'override';
                $notify_msg   = "Batch #$batch_id has been re-opened for re-validation. Reason: $reason";
                break;
            case 'override':
                $new_status   = 'completed'; // will be set by push_inventory
                $audit_action = 'override_accepted';
                $notify_role  = ROLE_RECEIVER;
                $notify_type  = 'override';
                $notify_msg   = "Batch #$batch_id discrepancy was overridden and accepted. Inventory updated.";
                break;
            case 'reject':
                $new_status   = 'rejected';
                $audit_action = 'rejected';
                $notify_role  = ROLE_RECEIVER;
                $notify_type  = 'batch_rejected';
                $notify_msg   = "Batch #$batch_id has been rejected. Reason: $reason";
                break;
        }

        if ($resolution !== 'override') {
            $upd = $conn->prepare(
                "UPDATE receiving_batches SET status = ?, resolution_action = ?, resolution_by = ?, resolution_reason = ?, resolution_at = NOW() WHERE id = ?"
            );
            $upd->bind_param("ssisi", $new_status, $resolution, $user_id, $reason, $batch_id);
            $upd->execute();
        }

        // Audit log
        $al = $conn->prepare(
            "INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action, reason) VALUES (?,?,?,?,?,?)"
        );
        $al->bind_param("iissss", $batch_id, $user_id, $username, $role, $audit_action, $reason);
        $al->execute();

        // Notification to affected party
        if ($notify_role && $notify_type) {
            $notif = $conn->prepare("INSERT INTO notifications (recipient_id, recipient_role, type, batch_id, message) VALUES (?, ?, ?, ?, ?)");
            $recv_id = intval($batch['receiver_id']);
            $notif->bind_param("issis", $recv_id, $notify_role, $notify_type, $batch_id, $notify_msg);
            $notif->execute();
        }

        if ($resolution === 'override') {
            // Run inventory push inside the same transaction scope
            include 'push_inventory.php';
            push_inventory($batch_id, $user_id, $username, $role, $conn);

            // Also update resolution columns after push_inventory sets status=completed
            $upd2 = $conn->prepare(
                "UPDATE receiving_batches SET resolution_action = 'override', resolution_by = ?, resolution_reason = ?, resolution_at = NOW() WHERE id = ?"
            );
            $upd2->bind_param("isi", $user_id, $reason, $batch_id);
            $upd2->execute();
        }

        $conn->commit();

        $msg_map = [
            'reopen_receiver'  => "Batch #$batch_id re-opened for Receiver.",
            'reopen_validator' => "Batch #$batch_id re-opened for Validator.",
            'override'         => "Batch #$batch_id override accepted — inventory updated.",
            'reject'           => "Batch #$batch_id rejected.",
        ];
        header("Location: discrepancy_resolve.php?success=" . urlencode($msg_map[$resolution]));
        exit();

    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: discrepancy_resolve.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// Load on_hold batches
$pq = $conn->query(
    "SELECT rb.*, COUNT(ri.id) AS item_count,
            u.username AS receiver_name
     FROM receiving_batches rb
     LEFT JOIN receiving_items ri ON ri.batch_id = rb.id
     LEFT JOIN users u ON u.id = rb.receiver_id
     WHERE rb.status = 'on_hold'
       AND NOT EXISTS (
           SELECT 1 FROM delivery_damage_tickets d
           WHERE d.batch_id = rb.id AND d.status = 'pending'
       )
     GROUP BY rb.id
     ORDER BY rb.validated_at ASC"
);

include '../layout_top.php';
?>

<div class="max-w-6xl mx-auto space-y-6">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">Discrepancy Resolution</h3>

        <?php if (!$pq || $pq->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-10">No batches on hold.</p>
        <?php else: ?>
        <div class="space-y-8">
        <?php while ($b = $pq->fetch_assoc()):
            // Load items for this batch — Amount IS visible to admin here
            $iq = $conn->prepare("SELECT * FROM receiving_items WHERE batch_id = ? ORDER BY id ASC");
            $iq->bind_param("i", $b['id']);
            $iq->execute();
            $items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);
        ?>
            <div class="border border-orange-200 rounded-2xl overflow-hidden">
                <!-- Batch summary header -->
                <div class="bg-orange-50 px-6 py-4 flex flex-wrap gap-4 items-start">
                    <div class="flex-1 min-w-0">
                        <p class="text-[10px] font-black text-orange-400 uppercase tracking-widest">Batch #<?= $b['id'] ?> · On Hold</p>
                        <h4 class="text-lg font-black text-slate-800"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></h4>
                        <p class="text-xs text-slate-500 font-bold mt-1">Receiver: <?= htmlspecialchars($b['receiver_name'] ?? $b['receiver_username'] ?? '—') ?></p>
                    </div>
                    <div class="text-right text-sm space-y-1">
                        <p class="text-[10px] text-slate-400 font-black uppercase">Computed Subtotal</p>
                        <p class="text-xl font-black text-slate-800">₱<?= number_format(floatval($b['computed_subtotal']), 2) ?></p>
                    </div>
                </div>

                <!-- Item breakdown — Amount visible to admin -->
                <div class="p-6 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left">
                                <th class="pb-2 text-[10px] font-black text-slate-400 uppercase pr-3">Description</th>
                                <th class="pb-2 text-[10px] font-black text-slate-400 uppercase pr-3">Barcode</th>
                                <th class="pb-2 text-[10px] font-black text-slate-400 uppercase pr-3 text-right">Qty</th>
                                <th class="pb-2 text-[10px] font-black text-slate-400 uppercase pr-3 text-right">Base Price</th>
                                <th class="pb-2 text-[10px] font-black text-slate-400 uppercase text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr class="border-t border-slate-50">
                                <td class="py-2 pr-3 font-bold"><?= htmlspecialchars($item['description']) ?></td>
                                <td class="py-2 pr-3 font-mono text-xs text-slate-500"><?= htmlspecialchars($item['barcode'] ?? '—') ?></td>
                                <td class="py-2 pr-3 text-right font-black"><?= intval($item['quantity']) ?></td>
                                <td class="py-2 pr-3 text-right">
                                    <?= $item['base_price'] !== null ? '₱' . number_format(floatval($item['base_price']), 2) : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <td class="py-2 text-right font-black text-slate-700">
                                    <?= $item['amount'] !== null ? '₱' . number_format(floatval($item['amount']), 2) : '<span class="text-slate-300">—</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Resolution form -->
                <div class="bg-slate-50 px-6 py-5 border-t border-slate-100">
                    <p class="text-xs font-black text-slate-500 uppercase tracking-widest mb-4">Resolution Action</p>
                    <form method="POST" onsubmit="return confirmResolution(this)">
                        <?= csrf_field() ?>
                        <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                        <div class="space-y-4">
                            <div>
                                <label class="label-modern">Reason / Notes <span class="text-rose-500">*</span></label>
                                <textarea name="reason" required rows="2"
                                          class="input-modern text-sm w-full" placeholder="Explain the resolution..."></textarea>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <button type="submit" name="resolution" value="reopen_receiver"
                                        class="bg-amber-100 hover:bg-amber-200 text-amber-800 text-xs font-black px-5 py-2.5 rounded-xl uppercase tracking-widest transition-all">
                                    Re-open to Receiver
                                </button>
                                <button type="submit" name="resolution" value="reopen_validator"
                                        class="bg-blue-100 hover:bg-blue-200 text-blue-800 text-xs font-black px-5 py-2.5 rounded-xl uppercase tracking-widest transition-all">
                                    Re-open to Validator
                                </button>
                                <button type="submit" name="resolution" value="override"
                                        class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-black px-5 py-2.5 rounded-xl uppercase tracking-widest transition-all">
                                    Override &amp; Accept
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmResolution(form) {
    const action = document.activeElement?.value ?? '';
    const reason = form.querySelector('[name="reason"]').value.trim();
    if (!reason) { showFlash('Please enter a resolution reason.', 'error'); return false; }

    const labels = {
        override: 'Override & accept this batch (inventory will be updated)',
        reopen_receiver: 'Re-open to Receiver for re-encoding',
        reopen_validator: 'Re-open to Validator for re-pricing',
    };
    const label = labels[action] || action;
    return confirm(label + '?\n\nReason: ' + reason);
}
</script>

<?php include '../layout_bottom.php'; ?>
