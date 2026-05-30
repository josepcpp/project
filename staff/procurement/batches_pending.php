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
$error   = '';

// ── CREATE VOUCHER ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('batches_pending.php');

    $supplier_name    = trim($_POST['supplier_name']    ?? '');
    $supplier_contact = trim($_POST['supplier_contact'] ?? '');
    $control_subtotal = trim($_POST['control_subtotal'] ?? '');

    if ($supplier_name === '') {
        $error = "Supplier name is required.";
    } elseif (!is_numeric($control_subtotal) || floatval($control_subtotal) <= 0) {
        $error = "Receipt subtotal must be a valid positive amount.";
    }

    if (!$error) {
        $control_subtotal = round(floatval($control_subtotal), 2);

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare(
                "INSERT INTO receiving_batches
                 (supplier_name, supplier_contact, control_subtotal, status, request_created_by, request_created_at, created_at)
                 VALUES (?, ?, ?, 'pending_request', ?, NOW(), NOW())"
            );
            $ins->bind_param("ssdi", $supplier_name, $supplier_contact, $control_subtotal, $user_id);
            $ins->execute();
            $batch_id = $conn->insert_id;

            $al = $conn->prepare("INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action) VALUES (?,?,?,?,'voucher_created')");
            $al->bind_param("iiss", $batch_id, $user_id, $username, $role);
            $al->execute();

            $conn->commit();
            header("Location: batches_pending.php?success=" . urlencode("Voucher #$batch_id created. Receiver can now select and encode items."));
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
// Vouchers awaiting receiver (no receiver assigned yet)
$open_q = $conn->query(
    "SELECT rb.id, rb.supplier_name, rb.supplier_contact, rb.created_at,
            COUNT(ri.id) AS item_count
     FROM receiving_batches rb
     LEFT JOIN receiving_items ri ON ri.batch_id = rb.id
     WHERE rb.status = 'pending_request' AND rb.receiver_id IS NULL
     GROUP BY rb.id
     ORDER BY rb.created_at ASC"
);

// Vouchers being encoded by a receiver
$inprog_q = $conn->query(
    "SELECT rb.id, rb.supplier_name, rb.receiver_username, rb.created_at,
            COUNT(ri.id) AS item_count
     FROM receiving_batches rb
     LEFT JOIN receiving_items ri ON ri.batch_id = rb.id
     WHERE rb.status = 'pending_request' AND rb.receiver_id IS NOT NULL
     GROUP BY rb.id
     ORDER BY rb.created_at ASC"
);

include '../layout_top.php';
?>

<div class="max-w-4xl mx-auto space-y-8">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Create Voucher Form -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-1">Create Receiving Voucher</h3>
        <p class="text-slate-400 text-sm font-bold mb-6">Enter the supplier details and the invoice subtotal. The Receiver will encode the items against this voucher.</p>
        <form method="POST" class="space-y-5">
            <?= csrf_field() ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="label-modern">Supplier Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="supplier_name" required maxlength="255"
                           class="input-modern" placeholder="e.g. ABC Trading Co.">
                </div>
                <div>
                    <label class="label-modern">Supplier Contact</label>
                    <input type="text" name="supplier_contact" maxlength="255"
                           class="input-modern" placeholder="Phone / email (optional)">
                </div>
            </div>
            <div class="max-w-xs">
                <label class="label-modern">Receipt / Invoice Subtotal <span class="text-rose-500">*</span></label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-black">₱</span>
                    <input type="number" name="control_subtotal" required min="0.01" step="0.01"
                           class="input-modern pl-8" placeholder="0.00">
                </div>
                <p class="text-[10px] text-rose-500 font-bold mt-1 uppercase tracking-wider">
                    Stored securely — never shown to the Receiver.
                </p>
            </div>
            <button type="submit" class="btn-pos-primary px-10 py-3 text-sm font-black uppercase tracking-widest">
                Create Voucher
            </button>
        </form>
    </div>

    <!-- Open Vouchers (awaiting Receiver) -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">
            Open Vouchers
            <span class="text-slate-400 font-bold text-sm ml-2">— awaiting Receiver</span>
        </h3>
        <?php if (!$open_q || $open_q->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-6">No open vouchers. Create one above.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Voucher #</th>
                        <th>Supplier</th>
                        <th>Contact</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($b = $open_q->fetch_assoc()): ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $b['id'] ?></td>
                        <td class="font-bold"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
                        <td class="text-slate-400"><?= htmlspecialchars($b['supplier_contact'] ?? '—') ?></td>
                        <td class="text-slate-400 text-xs"><?= date('M j, Y g:i A', strtotime($b['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- In-Progress Vouchers (Receiver encoding) -->
    <?php if ($inprog_q && $inprog_q->num_rows > 0): ?>
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">
            Being Encoded
            <span class="text-slate-400 font-bold text-sm ml-2">— Receiver working on these</span>
        </h3>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Voucher #</th>
                        <th>Supplier</th>
                        <th>Receiver</th>
                        <th>Items</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($b = $inprog_q->fetch_assoc()): ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $b['id'] ?></td>
                        <td class="font-bold"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
                        <td class="text-slate-500">@<?= htmlspecialchars($b['receiver_username'] ?? '—') ?></td>
                        <td class="text-center font-black text-slate-600"><?= intval($b['item_count']) ?></td>
                        <td class="text-slate-400 text-xs"><?= date('M j, Y g:i A', strtotime($b['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include '../layout_bottom.php'; ?>
