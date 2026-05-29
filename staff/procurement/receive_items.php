<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$batch_id = intval($_GET['batch_id'] ?? 0);
$readonly = !empty($_GET['readonly']);

if (!$batch_id) {
    header("Location: receive_batch.php?error=" . urlencode("No batch selected."));
    exit();
}

// Load batch — receiver can only see their own batches; admin/superadmin see all
if ($role === ROLE_RECEIVER) {
    $bq = $conn->prepare("SELECT * FROM receiving_batches WHERE id = ? AND receiver_id = ? LIMIT 1");
    $bq->bind_param("ii", $batch_id, $user_id);
} else {
    $bq = $conn->prepare("SELECT * FROM receiving_batches WHERE id = ? LIMIT 1");
    $bq->bind_param("i", $batch_id);
}
$bq->execute();
$batch = $bq->get_result()->fetch_assoc();

if (!$batch) {
    header("Location: receive_batch.php?error=" . urlencode("Batch not found."));
    exit();
}

if (!$readonly && $batch['status'] !== 'pending_request') {
    $readonly = true;
}

// Load existing items
$iq = $conn->prepare("SELECT * FROM receiving_items WHERE batch_id = ? ORDER BY id ASC");
$iq->bind_param("i", $batch_id);
$iq->execute();
$items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

$error   = trim($_GET['error']   ?? '');
$success = trim($_GET['success'] ?? '');

include '../layout_top.php';
?>

<div class="max-w-5xl mx-auto space-y-6">

    <!-- Batch Info Header -->
    <div class="card-modern p-6 flex flex-wrap gap-6 items-start">
        <div class="flex-1 min-w-0">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Batch #<?= $batch['id'] ?></p>
            <h3 class="serif-title text-xl font-black text-slate-800"><?= htmlspecialchars($batch['supplier_name'] ?? 'Unknown Supplier') ?></h3>
            <?php if ($batch['supplier_contact']): ?>
            <p class="text-sm text-slate-400 font-bold mt-1"><?= htmlspecialchars($batch['supplier_contact']) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <a href="receive_batch.php" class="text-sm text-slate-500 font-bold hover:underline">&larr; Back to Batches</a>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($readonly): ?>
    <div class="bg-sky-50 border border-sky-200 text-sky-700 rounded-2xl px-5 py-4 text-sm font-bold">
        This batch is in <strong><?= htmlspecialchars($batch['status']) ?></strong> status — items are read-only.
    </div>
    <?php endif; ?>

    <!-- Item Encoding Form -->
    <?php if (!$readonly): ?>
    <div class="card-modern p-8">
        <div class="flex items-center justify-between mb-6">
            <h3 class="serif-title text-lg font-black text-slate-800">Encode Received Items</h3>
            <button type="button" onclick="addRow()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-black px-4 py-2 rounded-xl uppercase tracking-widest transition-all">
                + Add Row
            </button>
        </div>

        <form method="POST" action="receive_process.php" id="itemsForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_items">
            <input type="hidden" name="batch_id" value="<?= $batch_id ?>">

            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="items-table">
                    <thead>
                        <tr class="text-left">
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3">Barcode</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3">Description <span class="text-rose-500">*</span></th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3 w-24">Qty <span class="text-rose-500">*</span></th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3 w-36">Expiry Date</th>
                            <th class="pb-3 w-8"></th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                    <?php if (empty($items)): ?>
                        <!-- default first row -->
                        <tr class="item-row">
                            <td class="pr-3 pb-2"><input type="text" name="items[0][barcode]" class="input-modern text-sm w-full" placeholder="628..."></td>
                            <td class="pr-3 pb-2"><input type="text" name="items[0][description]" required class="input-modern text-sm w-full" placeholder="Product name"></td>
                            <td class="pr-3 pb-2"><input type="number" name="items[0][qty]" required min="1" class="input-modern text-sm w-full" value="1"></td>
                            <td class="pr-3 pb-2"><input type="date" name="items[0][expiry_date]" class="input-modern text-sm w-full"></td>
                            <td class="pb-2"></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $i => $item): ?>
                        <tr class="item-row">
                            <td class="pr-3 pb-2"><input type="text" name="items[<?= $i ?>][barcode]" class="input-modern text-sm w-full" value="<?= htmlspecialchars($item['barcode'] ?? '') ?>"></td>
                            <td class="pr-3 pb-2"><input type="text" name="items[<?= $i ?>][description]" required class="input-modern text-sm w-full" value="<?= htmlspecialchars($item['description']) ?>"></td>
                            <td class="pr-3 pb-2"><input type="number" name="items[<?= $i ?>][qty]" required min="1" class="input-modern text-sm w-full" value="<?= intval($item['quantity']) ?>"></td>
                            <td class="pr-3 pb-2"><input type="date" name="items[<?= $i ?>][expiry_date]" class="input-modern text-sm w-full" value="<?= htmlspecialchars($item['expiry_date'] ?? '') ?>"></td>
                            <td class="pb-2"><button type="button" onclick="removeRow(this)" class="text-rose-400 hover:text-rose-600 font-black text-lg leading-none">&times;</button></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-3 mt-6">
                <button type="submit" name="submit_action" value="save" class="btn-pos-primary px-8 py-3 text-sm font-black uppercase tracking-widest">
                    Save Items
                </button>
                <button type="submit" name="submit_action" value="submit"
                        onclick="return confirm('Submit this batch for Admin review? You will not be able to edit items after this.')"
                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-8 py-3 rounded-2xl text-sm font-black uppercase tracking-widest transition-all shadow-lg shadow-emerald-100">
                    Submit Batch
                </button>
            </div>
        </form>
    </div>
    <?php else: ?>
    <!-- Read-only item list -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-lg font-black text-slate-800 mb-6">Encoded Items (<?= count($items) ?>)</h3>
        <?php if (empty($items)): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-6">No items encoded.</p>
        <?php else: ?>
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
                        <td class="text-slate-400"><?= $item['expiry_date'] ? date('M j, Y', strtotime($item['expiry_date'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
let _rowIdx = <?= max(count($items), 1) ?>;

function addRow() {
    const i = _rowIdx++;
    const tbody = document.getElementById('items-body');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="pr-3 pb-2"><input type="text" name="items[${i}][barcode]" class="input-modern text-sm w-full" placeholder="628..."></td>
        <td class="pr-3 pb-2"><input type="text" name="items[${i}][description]" required class="input-modern text-sm w-full" placeholder="Product name"></td>
        <td class="pr-3 pb-2"><input type="number" name="items[${i}][qty]" required min="1" class="input-modern text-sm w-full" value="1"></td>
        <td class="pr-3 pb-2"><input type="date" name="items[${i}][expiry_date]" class="input-modern text-sm w-full"></td>
        <td class="pb-2"><button type="button" onclick="removeRow(this)" class="text-rose-400 hover:text-rose-600 font-black text-lg leading-none">&times;</button></td>`;
    tbody.appendChild(tr);
    tr.querySelector('input[name*="[description]"]').focus();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.item-row');
    if (rows.length <= 1) { showFlash('At least one item row is required.', 'error'); return; }
    btn.closest('tr').remove();
}
</script>

<?php include '../layout_bottom.php'; ?>
