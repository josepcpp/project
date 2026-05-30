<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_VALIDATOR, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';

$batch_id = intval($_GET['batch_id'] ?? 0);
if (!$batch_id) {
    header("Location: validate_batch.php?error=" . urlencode("No batch specified."));
    exit();
}

// Load batch (must be on_hold)
$bq = $conn->prepare("SELECT id, supplier_name, computed_subtotal FROM receiving_batches WHERE id = ? AND status = 'on_hold' LIMIT 1");
$bq->bind_param("i", $batch_id);
$bq->execute();
$batch = $bq->get_result()->fetch_assoc();
if (!$batch) {
    header("Location: validate_batch.php?error=" . urlencode("Batch not found or not on hold."));
    exit();
}

// Check if ticket already exists
$tq = $conn->prepare("SELECT id FROM delivery_damage_tickets WHERE batch_id = ? LIMIT 1");
$tq->bind_param("i", $batch_id);
$tq->execute();
if ($tq->get_result()->num_rows > 0) {
    header("Location: validate_batch.php?success=" . urlencode("Damage ticket for Batch #$batch_id already submitted."));
    exit();
}

// Load damaged items with base_price from validation
$iq = $conn->prepare(
    "SELECT id, barcode, description, quantity, damaged_qty, damage_notes, base_price
     FROM receiving_items WHERE batch_id = ? AND damaged_qty > 0 ORDER BY id ASC"
);
$iq->bind_param("i", $batch_id);
$iq->execute();
$damaged_items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($damaged_items)) {
    header("Location: validate_batch.php?error=" . urlencode("No damaged items recorded for this batch."));
    exit();
}

$total_deduction = 0.0;
foreach ($damaged_items as $di) {
    $total_deduction += floatval($di['base_price'] ?? 0) * intval($di['damaged_qty']);
}
$total_deduction = round($total_deduction, 2);

$error = trim($_GET['error'] ?? '');
include '../layout_top.php';
?>

<div class="max-w-3xl mx-auto space-y-6">

    <div class="flex items-start gap-4">
        <a href="validate_batch.php" class="text-sm text-slate-500 font-bold hover:underline mt-1">&larr; Back</a>
        <div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Batch #<?= $batch_id ?> — <?= htmlspecialchars($batch['supplier_name']) ?></p>
            <h3 class="serif-title text-xl font-black text-slate-800">Create Damage Return Ticket</h3>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl px-5 py-4 text-sm font-bold">
        A subtotal discrepancy was detected and damaged items were reported by the Receiver.
        Review the breakdown below and submit a Damage Return Ticket to Admin for deduction approval.
    </div>

    <!-- Damaged Items Breakdown -->
    <div class="card-modern p-6">
        <h4 class="text-sm font-black text-slate-500 uppercase tracking-widest mb-4">Damaged Items</h4>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Barcode</th>
                        <th class="text-center">Good Qty</th>
                        <th class="text-center">Damaged Qty</th>
                        <th>Damage Notes</th>
                        <th class="text-right">Unit Price</th>
                        <th class="text-right">Deduction</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($damaged_items as $di):
                    $deduction = round(floatval($di['base_price'] ?? 0) * intval($di['damaged_qty']), 2);
                ?>
                <tr>
                    <td class="font-bold"><?= htmlspecialchars($di['description']) ?></td>
                    <td class="font-mono text-xs text-slate-400"><?= htmlspecialchars($di['barcode'] ?? '—') ?></td>
                    <td class="text-center"><?= intval($di['quantity']) ?></td>
                    <td class="text-center font-black text-rose-500"><?= intval($di['damaged_qty']) ?></td>
                    <td class="text-slate-500 text-xs italic"><?= htmlspecialchars($di['damage_notes'] ?? '—') ?></td>
                    <td class="text-right text-slate-600">₱<?= number_format(floatval($di['base_price'] ?? 0), 2) ?></td>
                    <td class="text-right font-black text-rose-600">₱<?= number_format($deduction, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-200">
                        <td colspan="6" class="pt-3 text-right font-black text-slate-700 uppercase tracking-widest text-xs">Total Deduction</td>
                        <td class="pt-3 text-right font-black text-rose-600 text-lg">₱<?= number_format($total_deduction, 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Submit Form -->
    <div class="card-modern p-8">
        <h4 class="serif-title text-lg font-black text-slate-800 mb-5">Submit to Admin</h4>
        <form method="POST" action="damage_ticket_process.php" class="space-y-5">
            <?= csrf_field() ?>
            <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
            <input type="hidden" name="total_deduction" value="<?= $total_deduction ?>">
            <div>
                <label class="label-modern">Damage Summary <span class="text-rose-500">*</span></label>
                <textarea name="damage_summary" required rows="3"
                          class="input-modern w-full resize-none"
                          placeholder="Briefly describe the damage found upon delivery..."></textarea>
            </div>
            <button type="submit" class="btn-pos-primary px-10 py-3 text-sm font-black uppercase tracking-widest">
                Submit Damage Ticket
            </button>
        </form>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
