<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$batch_id = intval($_GET['batch_id'] ?? 0);
if (!$batch_id) {
    header("Location: batches_pending.php?error=" . urlencode("No batch selected."));
    exit();
}

$bq = $conn->prepare("SELECT * FROM receiving_batches WHERE id = ? AND status = 'pending_request' LIMIT 1");
$bq->bind_param("i", $batch_id);
$bq->execute();
$batch = $bq->get_result()->fetch_assoc();
if (!$batch) {
    header("Location: batches_pending.php?error=" . urlencode("Batch not found or already processed."));
    exit();
}

// Load items — NO prices, NO amounts (admin sees qty/desc/barcode/expiry only here)
$iq = $conn->prepare("SELECT id, barcode, description, quantity, expiry_date FROM receiving_items WHERE batch_id = ? ORDER BY id ASC");
$iq->bind_param("i", $batch_id);
$iq->execute();
$items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle POST
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('validator_request.php?batch_id=' . $batch_id);

    $supplier_name    = trim($_POST['supplier_name']    ?? '');
    $supplier_contact = trim($_POST['supplier_contact'] ?? '');
    $control_subtotal = trim($_POST['control_subtotal'] ?? '');

    if ($supplier_name === '') $error = "Supplier name is required.";
    elseif (!is_numeric($control_subtotal) || floatval($control_subtotal) <= 0) $error = "Receipt subtotal must be a positive amount.";

    if (!$error) {
        $control_subtotal = round(floatval($control_subtotal), 2);

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare(
                "UPDATE receiving_batches SET
                    supplier_name = ?, supplier_contact = ?,
                    control_subtotal = ?,
                    status = 'pending_validation',
                    request_created_by = ?, request_created_at = NOW()
                 WHERE id = ?"
            );
            $upd->bind_param("ssdii", $supplier_name, $supplier_contact, $control_subtotal, $user_id, $batch_id);
            $upd->execute();

            $al = $conn->prepare("INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action) VALUES (?,?,?,?,'validator_request_created')");
            $al->bind_param("iiss", $batch_id, $user_id, $username, $role);
            $al->execute();

            $conn->commit();
            header("Location: batches_pending.php?success=" . urlencode("Validator request created for Batch #$batch_id."));
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

include '../layout_top.php';
?>

<div class="max-w-4xl mx-auto space-y-6">

    <div class="flex items-center gap-4">
        <a href="batches_pending.php" class="text-sm text-slate-500 font-bold hover:underline">&larr; Back</a>
        <h3 class="serif-title text-xl font-black text-slate-800">Create Validator Request — Batch #<?= $batch_id ?></h3>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Items preview (no prices, no amounts) -->
    <div class="card-modern p-6">
        <h4 class="text-sm font-black text-slate-500 uppercase tracking-widest mb-4">Receiver-Encoded Items (<?= count($items) ?>)</h4>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Barcode</th>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Expiry</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $i => $item): ?>
                    <tr>
                        <td class="text-slate-400"><?= $i + 1 ?></td>
                        <td class="font-mono text-xs"><?= htmlspecialchars($item['barcode'] ?? '—') ?></td>
                        <td class="font-bold"><?= htmlspecialchars($item['description']) ?></td>
                        <td class="text-center font-black"><?= intval($item['quantity']) ?></td>
                        <td class="text-slate-400 text-xs"><?= $item['expiry_date'] ? date('M j, Y', strtotime($item['expiry_date'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Validator Request Form -->
    <div class="card-modern p-8">
        <h4 class="serif-title text-lg font-black text-slate-800 mb-6">Invoice Details</h4>
        <form method="POST" class="space-y-5">
            <?= csrf_field() ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="label-modern">Supplier Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="supplier_name" required maxlength="255"
                           value="<?= htmlspecialchars($batch['supplier_name'] ?? '') ?>"
                           class="input-modern">
                </div>
                <div>
                    <label class="label-modern">Supplier Contact</label>
                    <input type="text" name="supplier_contact" maxlength="255"
                           value="<?= htmlspecialchars($batch['supplier_contact'] ?? '') ?>"
                           class="input-modern">
                </div>
            </div>
            <div class="max-w-xs">
                <label class="label-modern">Receipt / Invoice Subtotal <span class="text-rose-500">*</span></label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">₱</span>
                    <input type="number" name="control_subtotal" required min="0.01" step="0.01"
                           class="input-modern pl-8" placeholder="0.00">
                </div>
                <p class="text-[10px] text-rose-500 font-bold mt-1 uppercase tracking-wider">
                    This amount is stored securely and will NOT be shown to the validator.
                </p>
            </div>
            <button type="submit" class="btn-pos-primary px-10 py-3 text-sm font-black uppercase tracking-widest">
                Send to Validator
            </button>
        </form>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
