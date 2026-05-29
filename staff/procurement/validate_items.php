<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_VALIDATOR, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$batch_id = intval($_GET['batch_id'] ?? 0);
if (!$batch_id) {
    header("Location: validate_batch.php?error=" . urlencode("No batch selected."));
    exit();
}

// SECURITY: control_subtotal is NOT selected here — only on validate_process.php
$bq = $conn->prepare(
    "SELECT id, supplier_name, supplier_contact, status, computed_subtotal, tally_result
     FROM receiving_batches WHERE id = ? AND status = 'pending_validation' LIMIT 1"
);
$bq->bind_param("i", $batch_id);
$bq->execute();
$batch = $bq->get_result()->fetch_assoc();

if (!$batch) {
    header("Location: validate_batch.php?error=" . urlencode("Batch not found or not in pending_validation status."));
    exit();
}

// Load items: description, barcode, qty (read-only), expiry, base_price (if already set)
// Amount column is intentionally NOT fetched here
$iq = $conn->prepare(
    "SELECT id, barcode, description, quantity, expiry_date, base_price
     FROM receiving_items WHERE batch_id = ? ORDER BY id ASC"
);
$iq->bind_param("i", $batch_id);
$iq->execute();
$items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

$error = trim($_GET['error'] ?? '');

include '../layout_top.php';
?>

<div class="max-w-5xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex flex-wrap items-start gap-4">
        <a href="validate_batch.php" class="text-sm text-slate-500 font-bold hover:underline mt-1">&larr; Back</a>
        <div class="flex-1">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Batch #<?= $batch_id ?></p>
            <h3 class="serif-title text-xl font-black text-slate-800"><?= htmlspecialchars($batch['supplier_name'] ?? 'Unknown Supplier') ?></h3>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-sky-50 border border-sky-200 text-sky-800 rounded-2xl px-5 py-4 text-sm font-bold">
        Enter the base price for each item. Your computed subtotal will update live.
        The per-item amount is not displayed — only the running total is shown.
    </div>

    <form method="POST" action="validate_process.php" id="validateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="batch_id" value="<?= $batch_id ?>">

        <div class="card-modern p-6">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left">
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4">#</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4">Barcode</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4">Description</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4 w-20">Qty</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4">Expiry</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest w-36">Base Price <span class="text-rose-500">*</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $i => $item): ?>
                        <tr class="border-t border-slate-50">
                            <td class="py-3 pr-4 text-slate-400 text-xs"><?= $i + 1 ?></td>
                            <td class="py-3 pr-4 font-mono text-xs text-slate-500"><?= htmlspecialchars($item['barcode'] ?? '—') ?></td>
                            <td class="py-3 pr-4 font-bold"><?= htmlspecialchars($item['description']) ?></td>
                            <td class="py-3 pr-4 font-black text-center"><?= intval($item['quantity']) ?></td>
                            <td class="py-3 pr-4 text-slate-400 text-xs"><?= $item['expiry_date'] ? date('M j, Y', strtotime($item['expiry_date'])) : '—' ?></td>
                            <td class="py-3">
                                <input type="hidden" name="items[<?= $i ?>][item_id]" value="<?= $item['id'] ?>">
                                <input type="hidden" name="items[<?= $i ?>][qty]" value="<?= intval($item['quantity']) ?>">
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">₱</span>
                                    <input type="number" name="items[<?= $i ?>][base_price]"
                                           required min="0.01" step="0.01"
                                           value="<?= $item['base_price'] !== null ? htmlspecialchars($item['base_price']) : '' ?>"
                                           class="input-modern pl-7 text-sm w-full price-input"
                                           data-qty="<?= intval($item['quantity']) ?>"
                                           placeholder="0.00"
                                           oninput="recalcSubtotal()">
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Running subtotal — amount per item is NOT shown -->
        <div class="card-modern p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Your Computed Subtotal</p>
                    <p class="text-3xl font-black text-slate-800 mt-1" id="computed-subtotal">₱0.00</p>
                    <p class="text-[10px] text-slate-400 mt-1">Sum of (base price × qty) for all items</p>
                </div>
                <button type="submit" class="btn-pos-primary px-10 py-4 text-sm font-black uppercase tracking-widest">
                    Submit Validation
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function recalcSubtotal() {
    let total = 0;
    document.querySelectorAll('.price-input').forEach(input => {
        const price = parseFloat(input.value) || 0;
        const qty   = parseInt(input.dataset.qty) || 0;
        total += price * qty;
    });
    document.getElementById('computed-subtotal').textContent = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
// Initialise if prices already filled
recalcSubtotal();
</script>

<?php include '../layout_bottom.php'; ?>
