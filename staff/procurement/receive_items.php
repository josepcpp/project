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

// Load batch — receiver can see their own batches OR unclaimed admin-created vouchers
if ($role === ROLE_RECEIVER) {
    $bq = $conn->prepare("SELECT * FROM receiving_batches WHERE id = ? AND (receiver_id = ? OR receiver_id IS NULL) LIMIT 1");
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

<div class="max-w-6xl mx-auto space-y-6">

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
    <style>
        @keyframes rowFlash {
            0%, 100% { background-color: transparent; }
            20%      { background-color: #fde68a; }
            60%      { background-color: #fef3c7; }
        }
        .row-flash {
            animation: rowFlash 0.9s ease-in-out 3;
            outline: 3px solid #f59e0b;
            outline-offset: -2px;
            border-radius: 6px;
        }
        @keyframes scanPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } 50% { box-shadow: 0 0 0 4px rgba(16,185,129,.25); } }
        #scan-box.scan-active { animation: scanPulse 1.4s ease-in-out infinite; }
    </style>
    <div class="card-modern p-8">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
            <h3 class="serif-title text-lg font-black text-slate-800">Encode Received Items</h3>
            <button type="button" onclick="addRow()" class="text-slate-500 hover:text-slate-700 text-xs font-black px-3 py-2 rounded-xl uppercase tracking-widest transition-all hover:bg-slate-100">
                + Blank row (no barcode)
            </button>
        </div>

        <!-- ── Scan Station ─────────────────────────────────────────────── -->
        <div id="scan-box" class="bg-slate-900 rounded-xl px-4 py-2.5 flex items-center gap-3 mb-6 scan-active">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-emerald-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 5v14M7 5v14M11 5v14M15 5v14M19 5v14"/>
            </svg>
            <input type="text" id="scan-input" autocomplete="off" inputmode="numeric"
                   placeholder="Scan to add — barcode then Enter…"
                   class="flex-1 min-w-0 bg-transparent text-white text-sm font-bold placeholder-slate-500 focus:outline-none"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();handleScan();}">
            <span id="scan-hint" class="text-[11px] font-black text-slate-400 whitespace-nowrap">Ready</span>
        </div>

        <form method="POST" action="receive_process.php" id="itemsForm" onsubmit="return beforeSubmit()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_items">
            <input type="hidden" name="batch_id" value="<?= $batch_id ?>">

            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="items-table">
                    <thead>
                        <tr class="text-left">
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3 w-36">Barcode</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3">Description <span class="text-rose-500">*</span></th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3 w-20 text-center">Qty/Box</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3 w-20 text-center">Boxes</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3 w-24 text-center">Total Qty</th>
                            <th class="pb-3 text-[10px] font-black text-rose-400 uppercase tracking-widest pr-3 w-20 text-center">Damaged</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3 w-24 text-center">Good Qty</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3 w-36">Expiry Date</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-3">Damage Notes</th>
                            <th class="pb-3 w-8"></th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                        <?php foreach ($items as $i => $item):
                            $total_raw = intval($item['quantity']) + intval($item['damaged_qty'] ?? 0);
                        ?>
                        <tr class="item-row">
                            <td class="pr-3 pb-2"><input type="text" name="items[<?= $i ?>][barcode]" class="input-modern text-sm w-full barcode-input" value="<?= htmlspecialchars($item['barcode'] ?? '') ?>" onblur="lookupBarcode(this)"></td>
                            <td class="pr-3 pb-2"><input type="text" name="items[<?= $i ?>][description]" required class="input-modern text-sm w-full" value="<?= htmlspecialchars($item['description']) ?>"></td>
                            <td class="pr-3 pb-2"><input type="number" name="items[<?= $i ?>][qty_per_box]" min="1" value="1" class="input-modern text-sm w-full text-center qty-per-box" oninput="updateTotal(this)"></td>
                            <td class="pr-3 pb-2"><input type="number" name="items[<?= $i ?>][box_qty]" min="1" value="<?= $total_raw ?>" class="input-modern text-sm w-full text-center box-qty" oninput="updateTotal(this)"></td>
                            <td class="pr-3 pb-2 text-center">
                                <span class="total-display font-black text-slate-800 text-base"><?= $total_raw ?></span>
                            </td>
                            <td class="pr-3 pb-2"><input type="number" name="items[<?= $i ?>][damaged_qty]" min="0" value="<?= intval($item['damaged_qty'] ?? 0) ?>" class="input-modern text-sm w-full text-center damaged-qty" oninput="updateTotal(this)"></td>
                            <td class="pr-3 pb-2 text-center">
                                <span class="good-display font-black text-emerald-600 text-base"><?= intval($item['quantity']) ?></span>
                                <input type="hidden" name="items[<?= $i ?>][qty]" class="qty-hidden" value="<?= intval($item['quantity']) ?>">
                            </td>
                            <td class="pr-3 pb-2"><input type="date" name="items[<?= $i ?>][expiry_date]" class="input-modern text-sm w-full" value="<?= htmlspecialchars($item['expiry_date'] ?? '') ?>"></td>
                            <td class="pr-3 pb-2"><input type="text" name="items[<?= $i ?>][damage_notes]" class="input-modern text-sm w-full" value="<?= htmlspecialchars($item['damage_notes'] ?? '') ?>" placeholder="e.g. crushed packaging"></td>
                            <td class="pb-2"><button type="button" onclick="removeRow(this)" class="text-rose-400 hover:text-rose-600 font-black text-lg leading-none">&times;</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-3 mt-6">
                <button type="submit" name="submit_action" value="save" class="btn-pos-primary px-8 py-3 text-sm font-black uppercase tracking-widest">
                    Save Items
                </button>
                <button type="submit" name="submit_action" value="submit"
                        onclick="return confirm('Submit this batch? You will not be able to edit items after this.')"
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
                        <th>Total Qty</th>
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
let _rowIdx = <?= count($items) ?>;

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function updateTotal(input) {
    const row     = input.closest('tr');
    const perBox  = parseInt(row.querySelector('.qty-per-box').value)  || 1;
    const boxes   = parseInt(row.querySelector('.box-qty').value)      || 1;
    const damaged = parseInt(row.querySelector('.damaged-qty').value)  || 0;
    const total   = perBox * boxes;
    const good    = Math.max(0, total - damaged);
    row.querySelector('.total-display').textContent = total;
    row.querySelector('.good-display').textContent  = good;
    row.querySelector('.qty-hidden').value          = good;
}

function syncQtys() {
    document.querySelectorAll('.item-row').forEach(row => {
        const perBox  = parseInt(row.querySelector('.qty-per-box').value)  || 1;
        const boxes   = parseInt(row.querySelector('.box-qty').value)      || 1;
        const damaged = parseInt(row.querySelector('.damaged-qty').value)  || 0;
        row.querySelector('.qty-hidden').value = Math.max(0, perBox * boxes - damaged);
    });
}

function beforeSubmit() {
    syncQtys();
    if (document.querySelectorAll('.item-row').length === 0) {
        showFlash('Scan or add at least one item before saving.', 'error');
        document.getElementById('scan-input').focus();
        return false;
    }
    return true;
}

async function lookupBarcodeData(barcode) {
    try {
        const res = await fetch(`../api/product_lookup.php?barcode=${encodeURIComponent(barcode)}`);
        return await res.json();
    } catch (_) { return null; }
}

async function lookupBarcode(input) {
    const barcode = input.value.trim();
    if (!barcode) return;
    const row  = input.closest('tr');
    const desc = row.querySelector('input[name*="[description]"]');
    const exp  = row.querySelector('input[name*="[expiry_date]"]');
    const data = await lookupBarcodeData(barcode);
    if (data && data.found) {
        if (!desc.value.trim()) desc.value = data.name;
        if (!exp.value && data.expiry_date) exp.value = data.expiry_date;
    }
}

// ── Scan station ───────────────────────────────────────────────────────────
function setHint(text, tone) {
    const hint = document.getElementById('scan-hint');
    hint.textContent = text;
    const colors = { idle: 'text-slate-400', ok: 'text-emerald-400', warn: 'text-amber-400', busy: 'text-sky-400' };
    hint.className = 'text-xs font-black whitespace-nowrap ' + (colors[tone] || colors.idle);
}

function findRowByBarcode(barcode) {
    const norm = barcode.trim().toLowerCase();
    if (!norm) return null;
    let match = null;
    document.querySelectorAll('.item-row .barcode-input').forEach(bc => {
        if (bc.value.trim().toLowerCase() === norm) match = bc.closest('tr');
    });
    return match;
}

function flashRow(row) {
    row.classList.remove('row-flash');
    void row.offsetWidth;                       // restart the animation
    row.classList.add('row-flash');
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => row.classList.remove('row-flash'), 2800);
}

async function handleScan() {
    const input   = document.getElementById('scan-input');
    const barcode = input.value.trim();
    if (!barcode) return;
    input.value = '';

    // Already on the list → DO NOT change counts. Make it obvious so the
    // clerk re-checks the physical count and avoids a discrepancy.
    const existing = findRowByBarcode(barcode);
    if (existing) {
        flashRow(existing);
        const name = existing.querySelector('input[name*="[description]"]')?.value.trim() || 'this item';
        showFlash('⚠ "' + name + '" is already on the list — re-count it and update the boxes manually.', 'error');
        setHint('Already on list ↑ verify', 'warn');
        input.focus();
        return;
    }

    // New barcode → add a row and look it up
    const row = addRow(barcode);
    setHint('Looking up…', 'busy');
    input.focus();

    const data = await lookupBarcodeData(barcode);
    const desc = row.querySelector('input[name*="[description]"]');
    const exp  = row.querySelector('input[name*="[expiry_date]"]');
    if (data && data.found) {
        if (!desc.value.trim()) desc.value = data.name;
        if (!exp.value && data.expiry_date) exp.value = data.expiry_date;
        setHint('✓ Added — keep scanning', 'ok');
        input.focus();
    } else {
        setHint('New product — type its name', 'warn');
        flashRow(row);
        desc.focus();                            // make them name the unknown item
    }
}

function addRow(barcode = '') {
    const i = _rowIdx++;
    const tbody = document.getElementById('items-body');
    const tr = document.createElement('tr');
    tr.className = 'item-row';
    tr.innerHTML = `
        <td class="pr-3 pb-2"><input type="text" name="items[${i}][barcode]" class="input-modern text-sm w-full barcode-input" value="${esc(barcode)}" placeholder="628..." onblur="lookupBarcode(this)"></td>
        <td class="pr-3 pb-2"><input type="text" name="items[${i}][description]" required class="input-modern text-sm w-full" placeholder="Product name"></td>
        <td class="pr-3 pb-2"><input type="number" name="items[${i}][qty_per_box]" min="1" value="1" class="input-modern text-sm w-full text-center qty-per-box" oninput="updateTotal(this)"></td>
        <td class="pr-3 pb-2"><input type="number" name="items[${i}][box_qty]" min="1" value="1" class="input-modern text-sm w-full text-center box-qty" oninput="updateTotal(this)"></td>
        <td class="pr-3 pb-2 text-center"><span class="total-display font-black text-slate-800 text-base">1</span></td>
        <td class="pr-3 pb-2"><input type="number" name="items[${i}][damaged_qty]" min="0" value="0" class="input-modern text-sm w-full text-center damaged-qty" oninput="updateTotal(this)"></td>
        <td class="pr-3 pb-2 text-center">
            <span class="good-display font-black text-emerald-600 text-base">1</span>
            <input type="hidden" name="items[${i}][qty]" class="qty-hidden" value="1">
        </td>
        <td class="pr-3 pb-2"><input type="date" name="items[${i}][expiry_date]" class="input-modern text-sm w-full"></td>
        <td class="pr-3 pb-2"><input type="text" name="items[${i}][damage_notes]" class="input-modern text-sm w-full" placeholder="e.g. crushed packaging"></td>
        <td class="pb-2"><button type="button" onclick="removeRow(this)" class="text-rose-400 hover:text-rose-600 font-black text-lg leading-none">&times;</button></td>`;
    tbody.appendChild(tr);
    if (!barcode) tr.querySelector('.barcode-input').focus();
    return tr;
}

function removeRow(btn) {
    btn.closest('tr').remove();
    document.getElementById('scan-input').focus();
}

// Pressing Enter inside any grid field jumps back to the scan box (and never
// submits the batch by accident).
document.getElementById('items-body').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
        e.preventDefault();
        document.getElementById('scan-input').focus();
    }
});

// Keep the scan box focused as the default landing spot.
window.addEventListener('load', () => document.getElementById('scan-input')?.focus());
</script>

<?php include '../layout_bottom.php'; ?>
