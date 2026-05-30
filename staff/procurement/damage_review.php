<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);

$success = trim($_GET['success'] ?? '');
$error   = trim($_GET['error']   ?? '');

// ── AUTO-EXPIRE stale pending tickets ─────────────────────────────────────────
$exp_q    = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='damage_ticket_expiry_days' LIMIT 1");
$exp_days = intval($exp_q ? ($exp_q->fetch_assoc()['setting_value'] ?? 3) : 3);
if ($exp_days > 0) {
    $stale_q = $conn->query(
        "SELECT ddt.id, ddt.batch_id FROM delivery_damage_tickets ddt
         WHERE ddt.status = 'pending'
           AND ddt.created_at <= NOW() - INTERVAL $exp_days DAY"
    );
    if ($stale_q && $stale_q->num_rows > 0) {
        while ($st = $stale_q->fetch_assoc()) {
            $upd_exp = $conn->prepare("UPDATE delivery_damage_tickets SET status = 'expired' WHERE id = ?");
            $upd_exp->bind_param("i", $st['id']);
            $upd_exp->execute();

            $exp_msg = "Damage Return Ticket for Batch #{$st['batch_id']} expired after {$exp_days} day(s) without review — now flagged as counting discrepancy.";
            $notif = $conn->prepare(
                "INSERT INTO notifications (recipient_role, type, batch_id, message) VALUES ('admin', 'discrepancy', ?, ?)"
            );
            $notif->bind_param("is", $st['batch_id'], $exp_msg);
            $notif->execute();
        }
    }
}

// ── Load all tickets ──────────────────────────────────────────────────────────
$tq = $conn->query(
    "SELECT ddt.*, rb.supplier_name, rb.computed_subtotal, rb.control_subtotal
     FROM delivery_damage_tickets ddt
     JOIN receiving_batches rb ON rb.id = ddt.batch_id
     ORDER BY FIELD(ddt.status,'pending','approved','rejected','expired'), ddt.created_at DESC
     LIMIT 50"
);
$tickets = $tq ? $tq->fetch_all(MYSQLI_ASSOC) : [];

include '../layout_top.php';
?>

<div class="max-w-5xl mx-auto space-y-6">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">Damage Return Tickets</h3>

        <?php if (empty($tickets)): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-10">No damage tickets on record.</p>
        <?php else: ?>
        <div class="space-y-4">
        <?php foreach ($tickets as $t):
            $status_cfg = match($t['status']) {
                'approved' => ['bg-emerald-100 text-emerald-700', 'Approved'],
                'rejected' => ['bg-rose-100 text-rose-700',       'Rejected'],
                'expired'  => ['bg-slate-100 text-slate-500',     'Expired — Counting Discrepancy'],
                default    => ['bg-amber-100 text-amber-700',     'Pending Review'],
            };
            $discrepancy = round(abs(floatval($t['control_subtotal']) - floatval($t['computed_subtotal'])), 2);
            $delta       = round(abs($discrepancy - floatval($t['total_deduction'])), 2);
            $auto_match  = $delta <= 0.01;
        ?>
        <div class="bg-white border <?= $t['status'] === 'pending' ? 'border-amber-200' : 'border-slate-100' ?> rounded-2xl overflow-hidden shadow-sm">
            <div class="p-5 flex flex-wrap items-start gap-4 justify-between">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 flex-wrap mb-1">
                        <p class="font-black text-slate-800">Batch #<?= $t['batch_id'] ?> — <?= htmlspecialchars($t['supplier_name']) ?></p>
                        <span class="text-[9px] font-black px-2 py-0.5 rounded-full <?= $status_cfg[0] ?> uppercase"><?= $status_cfg[1] ?></span>
                        <?php if ($auto_match && $t['status'] === 'pending'): ?>
                        <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 uppercase">Auto-Match on Approve</span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-500 font-bold">Raised by @<?= htmlspecialchars($t['raised_by_username']) ?> · <?= date('M j, Y g:i A', strtotime($t['created_at'])) ?></p>
                    <p class="text-sm text-slate-600 mt-2 italic">"<?= htmlspecialchars($t['damage_summary']) ?>"</p>
                </div>
                <div class="text-right flex-shrink-0">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Deduction</p>
                    <p class="text-2xl font-black text-rose-600">₱<?= number_format($t['total_deduction'], 2) ?></p>
                    <p class="text-[10px] text-slate-400 font-bold mt-1">Discrepancy: ₱<?= number_format($discrepancy, 2) ?></p>
                    <?php if ($auto_match): ?>
                    <p class="text-[10px] text-emerald-600 font-black mt-0.5">✓ Damage explains gap</p>
                    <?php else: ?>
                    <p class="text-[10px] text-amber-600 font-black mt-0.5">⚠ ₱<?= number_format($delta, 2) ?> unexplained</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($t['status'] === 'pending'): ?>
            <!-- Damaged items detail -->
            <?php
            $diq = $conn->prepare(
                "SELECT description, barcode, quantity, damaged_qty, damage_notes, base_price
                 FROM receiving_items WHERE batch_id = ? AND damaged_qty > 0 ORDER BY id ASC"
            );
            $diq->bind_param("i", $t['batch_id']);
            $diq->execute();
            $d_items = $diq->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>
            <div class="border-t border-slate-100 px-5 py-4">
                <table class="w-full text-xs mb-4">
                    <thead>
                        <tr class="text-left text-slate-400 font-black uppercase tracking-widest">
                            <th class="pb-2 pr-4">Item</th>
                            <th class="pb-2 pr-4 text-center">Damaged Qty</th>
                            <th class="pb-2 pr-4">Notes</th>
                            <th class="pb-2 text-right">Deduction</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                    <?php foreach ($d_items as $di): ?>
                        <tr>
                            <td class="py-1.5 pr-4 font-bold text-slate-700"><?= htmlspecialchars($di['description']) ?></td>
                            <td class="py-1.5 pr-4 text-center font-black text-rose-500"><?= intval($di['damaged_qty']) ?></td>
                            <td class="py-1.5 pr-4 italic text-slate-400"><?= htmlspecialchars($di['damage_notes'] ?? '—') ?></td>
                            <td class="py-1.5 text-right font-black text-rose-600">₱<?= number_format(floatval($di['base_price'] ?? 0) * intval($di['damaged_qty']), 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="POST" action="damage_review_process.php" class="flex flex-col sm:flex-row gap-3 items-end">
                    <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                    <div class="flex-1">
                        <label class="label-modern text-xs">Admin Notes (optional)</label>
                        <input type="text" name="admin_notes" class="input-modern text-sm w-full" placeholder="Remarks...">
                    </div>
                    <button type="submit" name="decision" value="approve"
                            onclick="return confirm('Approve this damage ticket? <?= $auto_match ? 'Tally will be marked as matched and inventory pushed automatically.' : 'Batch will remain on hold for further review.' ?>')"
                            class="bg-emerald-500 hover:bg-emerald-600 text-white font-black text-xs px-6 py-3 rounded-xl uppercase tracking-widest transition-all whitespace-nowrap">
                        Approve <?= $auto_match ? '& Push Inventory' : 'Deduction' ?>
                    </button>
                    <button type="submit" name="decision" value="reject"
                            onclick="return confirm('Reject this damage ticket?')"
                            class="bg-rose-500 hover:bg-rose-600 text-white font-black text-xs px-6 py-3 rounded-xl uppercase tracking-widest transition-all whitespace-nowrap">
                        Reject
                    </button>
                </form>
            </div>
            <?php elseif ($t['admin_notes']): ?>
            <div class="border-t border-slate-100 px-5 py-3">
                <p class="text-xs text-slate-500 font-bold">Admin notes: <span class="italic"><?= htmlspecialchars($t['admin_notes']) ?></span></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
