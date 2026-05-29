<?php
/**
 * exchange.php — Item exchange workflow
 * Supports: even exchange (same value) and delta exchange (collect/refund difference).
 * This is separate from the refund system — stock is swapped, not just returned.
 */
include '../../config/db.php';
include '../../config/settings.php';
include '../../includes/csrf.php';
include '../layout_top.php';

// Only cashiers (staff) and above can process exchanges — members cannot
$_exchange_role = strtolower($_SESSION['role'] ?? '');
if (!in_array($_exchange_role, [ROLE_STAFF, ROLE_ADMIN, ROLE_OWNER, ROLE_SUPERADMIN])) {
    header("Location: ../dashboard.php?error=" . urlencode("You do not have permission to process item exchanges."));
    exit();
}

$step         = intval($_GET['step'] ?? 1);
$receipt_no   = trim($_GET['receipt'] ?? '');
$sale         = null;
$sale_items   = [];
$error        = htmlspecialchars(trim($_GET['error'] ?? ''));

// ── STEP 1: Find receipt ──────────────────────────────────────────────────────
if ($step >= 1 && $receipt_no !== '') {
    $sq = $conn->prepare("SELECT * FROM sales WHERE receipt_no = ? LIMIT 1");
    $sq->bind_param("s", $receipt_no);
    $sq->execute();
    $sq_res = $sq->get_result();   // store — must be freed before next statement
    $sale   = $sq_res->fetch_assoc();
    $sq_res->free();
    $sq->close();

    if ($sale) {
        $iq = $conn->prepare("
            SELECT si.*, p.name, p.category, p.price AS current_price, p.barcode, p.quantity AS stock
            FROM sales_items si
            JOIN products p ON p.id = si.product_id
            WHERE si.sale_id = ?
        ");
        $iq->bind_param("i", $sale['id']);
        $iq->execute();
        $iq_res = $iq->get_result();
        while ($row = $iq_res->fetch_assoc()) $sale_items[] = $row;
        $iq_res->free();
        $iq->close();
    }
}

// ── Tax display mode (GAP-2) ──────────────────────────────────────────────────
$tax_display_mode = 'exclusive';
$_tdm = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='tax_display_mode' LIMIT 1");
if ($_tdm && $_tdm_row = $_tdm->fetch_assoc()) $tax_display_mode = $_tdm_row['setting_value'] ?? 'exclusive';

// ── Fetch recent sales for the quick-pick list ────────────────────────────────
$recent_sales = [];
$rq = $conn->query("
    SELECT s.receipt_no, s.total, s.created_at, s.payment_mode,
           COUNT(si.id) AS item_count
    FROM sales s
    LEFT JOIN sales_items si ON si.sale_id = s.id
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT 30
");
if ($rq) {
    while ($r = $rq->fetch_assoc()) $recent_sales[] = $r;
}
?>

<div class="max-w-4xl mx-auto pb-20 animate-in space-y-8">

    <!-- Header -->
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 bg-violet-100 rounded-2xl flex items-center justify-center text-violet-600">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
        </div>
        <div>
            <h2 class="serif-title text-2xl font-bold text-slate-800">Item Exchange</h2>
            <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">Swap items — even or with price difference</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-700 font-bold rounded-3xl p-5 text-sm"><?= $error ?></div>
    <?php endif; ?>

    <!-- ── STEP 1: RECEIPT LOOKUP ──────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <h4 class="font-black text-slate-700 text-sm uppercase tracking-widest mb-5">Step 1 — Find Original Receipt</h4>

        <!-- Live search input -->
        <div class="relative mb-2">
            <input type="text" id="receipt-search-input"
                   value="<?= htmlspecialchars($receipt_no) ?>"
                   placeholder="Type receipt no. or date to filter…"
                   oninput="filterReceipts(this.value)"
                   autocomplete="off"
                   class="input-modern w-full uppercase font-black tracking-widest pr-24">
            <button onclick="goReceipt()"
                    class="btn-pos-primary absolute right-2 top-1/2 -translate-y-1/2 px-5 py-2 text-xs">Search</button>
        </div>

        <?php if ($receipt_no && !$sale): ?>
            <p class="text-rose-500 font-bold text-sm mb-4">No sale found for receipt <strong><?= htmlspecialchars($receipt_no) ?></strong>.</p>
        <?php endif; ?>

        <!-- Recent receipts quick-pick -->
        <?php if (!empty($recent_sales)): ?>
        <div id="receipts-panel" class="mt-3">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Recent Receipts — click to load</p>
            <div id="receipts-list" class="space-y-1 max-h-72 overflow-y-auto">
                <?php foreach ($recent_sales as $rs): ?>
                <div class="receipt-row flex items-center justify-between px-4 py-3 rounded-2xl hover:bg-violet-50 cursor-pointer border border-transparent hover:border-violet-100 transition-all group"
                     data-receipt="<?= htmlspecialchars($rs['receipt_no']) ?>"
                     onclick="loadReceipt('<?= htmlspecialchars($rs['receipt_no']) ?>')">
                    <div>
                        <p class="font-black text-slate-700 text-sm group-hover:text-violet-700 tracking-widest"><?= htmlspecialchars($rs['receipt_no']) ?></p>
                        <p class="text-[10px] text-slate-400 font-bold">
                            <?= date('M j, Y g:i A', strtotime($rs['created_at'])) ?>
                            · <?= $rs['item_count'] ?> item<?= $rs['item_count'] != 1 ? 's' : '' ?>
                            · <?= htmlspecialchars($rs['payment_mode']) ?>
                        </p>
                    </div>
                    <span class="font-black text-emerald-600 text-sm">₱<?= number_format($rs['total'], 2) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <p class="text-slate-300 text-xs font-bold italic mt-3">No sales records found yet.</p>
        <?php endif; ?>
    </div>

    <?php if ($sale && !empty($sale_items)): ?>

    <!-- ── STEP 2: SELECT ITEMS TO RETURN + REPLACEMENTS ─────────────────── -->
    <div class="card-modern shadow-xl">
        <h4 class="font-black text-slate-700 text-sm uppercase tracking-widest mb-2">Step 2 — Select Items to Exchange</h4>
        <p class="text-slate-400 text-xs font-bold mb-6">Receipt <strong class="text-slate-600"><?= htmlspecialchars($sale['receipt_no']) ?></strong> · <?= date('M j, Y g:i A', strtotime($sale['created_at'])) ?> · <strong class="text-emerald-600">₱<?= number_format($sale['total'], 2) ?></strong></p>

        <form method="POST" action="exchange_process.php" id="exchangeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="sale_id" value="<?= $sale['id'] ?>">
            <input type="hidden" name="receipt_no" value="<?= htmlspecialchars($sale['receipt_no']) ?>">

            <!-- Original items grid -->
            <div class="space-y-3 mb-8">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Items on this receipt:</p>
                <?php foreach ($sale_items as $i => $item): ?>
                <div class="flex items-center gap-4 bg-slate-50 rounded-2xl p-4">
                    <input type="checkbox" name="return_items[<?= $i ?>][selected]" value="1"
                           id="item_<?= $i ?>" onchange="toggleReturn(<?= $i ?>)"
                           class="w-5 h-5 accent-violet-500 flex-shrink-0">
                    <div class="flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-bold text-slate-700"><?= htmlspecialchars($item['name']) ?></p>
                            <span class="text-[8px] font-black text-slate-400 bg-slate-100 px-2 py-0.5 rounded uppercase tracking-wide"><?= htmlspecialchars($item['category'] ?? '') ?></span>
                        </div>
                        <p class="text-[10px] text-slate-400 font-bold mt-0.5">
                            Purchased: <?= $item['qty'] ?> × ₱<?= number_format($item['price'], 2) ?> = ₱<?= number_format($item['qty'] * $item['price'], 2) ?>
                            <?php if (abs($item['current_price'] - $item['price']) > 0.001): ?>
                                <span class="ml-2 text-amber-500">· Current price: ₱<?= number_format($item['current_price'], 2) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div id="return-qty-wrap-<?= $i ?>" class="hidden flex items-center gap-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase">Return qty:</label>
                        <input type="number" name="return_items[<?= $i ?>][qty]" min="1" max="<?= $item['qty'] ?>" value="1"
                               onchange="recalcDelta()"
                               class="w-20 border-2 border-violet-200 rounded-xl px-3 py-2 text-sm font-black text-slate-700 focus:outline-none focus:border-violet-500">
                    </div>
                    <input type="hidden" name="return_items[<?= $i ?>][product_id]" value="<?= $item['product_id'] ?>">
                    <input type="hidden" name="return_items[<?= $i ?>][unit_price]" value="<?= $item['price'] ?>">
                    <input type="hidden" name="return_items[<?= $i ?>][max_qty]" value="<?= $item['qty'] ?>">
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Replacement items section -->
            <div class="border-t border-slate-100 pt-6">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Replacement Items:</p>
                    <button type="button" onclick="addReplacement()" class="text-[10px] font-black text-violet-600 hover:text-violet-700 uppercase tracking-widest border border-violet-200 rounded-xl px-3 py-1.5 hover:bg-violet-50 transition-colors">
                        + Add Item
                    </button>
                </div>
                <div id="replacements-list" class="space-y-3"></div>
                <p id="no-replacements-hint" class="text-slate-300 text-xs font-bold italic">Click "Add Item" to pick replacement products.</p>

                <!-- Product search panel — inline inside replacement section so it's always in view -->
                <div id="product-search-panel" class="hidden mt-4 bg-violet-50 border border-violet-200 rounded-2xl p-4">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Search Replacement Product</p>
                    <div class="flex gap-3 mb-3">
                        <input type="text" id="product-search-input" placeholder="Type name or barcode…"
                               oninput="searchProducts()"
                               autocomplete="off"
                               class="input-modern flex-1 bg-white">
                        <button type="button" onclick="closeSearch()"
                                class="text-slate-300 hover:text-rose-400 font-black px-3 transition-colors">✕</button>
                    </div>
                    <div id="product-search-results" class="space-y-1 max-h-60 overflow-y-auto"></div>
                </div>
            </div>

            <!-- Delta summary -->
            <div class="mt-8 bg-slate-900 rounded-3xl p-6 text-white space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="font-bold text-slate-400">Return Value</span>
                    <span id="return-total-display" class="font-black">₱0.00</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="font-bold text-slate-400">Replacement Value</span>
                    <span id="new-total-display" class="font-black">₱0.00</span>
                </div>
                <div class="border-t border-slate-700 pt-3 flex justify-between items-center">
                    <span class="font-black text-slate-300 uppercase text-sm">Delta</span>
                    <span id="delta-display" class="text-2xl font-black text-emerald-400">₱0.00</span>
                </div>
                <p id="delta-label" class="text-center text-[10px] font-black uppercase tracking-widest text-slate-400">Even exchange</p>
            </div>

            <!-- Delta payment for "collect" -->
            <div id="delta-payment-section" class="hidden mt-6 space-y-4 bg-amber-50 border border-amber-200 rounded-3xl p-6">
                <p class="text-amber-700 font-black text-sm uppercase tracking-widest">Additional Payment Required</p>
                <div class="grid grid-cols-3 gap-3">
                    <?php foreach ([PAY_METHOD_CASH, PAY_METHOD_GCASH, PAY_METHOD_MAYA] as $m): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_mode" value="<?= $m ?>" <?= $m===PAY_METHOD_CASH?'checked':'' ?> class="hidden peer"
                               onchange="toggleRefField('<?= $m !== PAY_METHOD_CASH ? 'yes':'no' ?>')">
                        <div class="py-3 border-2 border-amber-200 rounded-2xl text-center font-black text-amber-500 text-[10px] uppercase peer-checked:border-amber-500 peer-checked:bg-amber-100 peer-checked:text-amber-700 transition-all"><?= $m ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="ref-field-wrap" class="hidden">
                    <label class="label-modern text-amber-600">Reference No. <span class="text-rose-400">*</span></label>
                    <input type="text" name="reference_no" id="ref-field" placeholder="TRANS-ID-XXXX"
                           class="input-modern bg-white font-mono uppercase">
                </div>
            </div>

            <!-- Cashier notes -->
            <div class="mt-4">
                <label class="label-modern ml-2">Notes <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                <textarea name="notes" rows="2" placeholder="e.g. Customer prefers different flavor" class="input-modern resize-none"></textarea>
            </div>

            <!-- Hidden state -->
            <input type="hidden" name="delta_type"   id="delta-type-hidden"   value="none">
            <input type="hidden" name="delta_amount" id="delta-amount-hidden" value="0">

            <button type="submit" id="submitBtn"
                    class="btn-pos-primary w-full py-6 text-xl shadow-xl shadow-violet-200 uppercase tracking-widest mt-6">
                Process Exchange
            </button>
        </form>
    </div>

    <?php endif; ?>
</div>

<script>
// ── Receipt search helpers ────────────────────────────────────────────────────
function filterReceipts(q) {
    const rows = document.querySelectorAll('.receipt-row');
    const term = q.trim().toUpperCase();
    let visible = 0;
    rows.forEach(row => {
        const match = !term || row.dataset.receipt.toUpperCase().includes(term) ||
                      row.textContent.toUpperCase().includes(term);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
}

function goReceipt() {
    const val = document.getElementById('receipt-search-input').value.trim();
    if (!val) return;
    loadReceipt(val);
}

function loadReceipt(receiptNo) {
    window.location.href = 'exchange.php?step=1&receipt=' + encodeURIComponent(receiptNo);
}

// Allow Enter key to trigger search
document.getElementById('receipt-search-input')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); goReceipt(); }
});

// Pre-filter list if a receipt value is already in the box (e.g. "not found" state)
(function() {
    const inp = document.getElementById('receipt-search-input');
    if (inp && inp.value.trim()) filterReceipts(inp.value);
})();

// ── Replacement / exchange helpers ───────────────────────────────────────────
const TAX_MODE = <?= json_encode($tax_display_mode) ?>; // GAP-2

let replacements     = [];  // [{ product_id, name, price, qty }]
let pendingSlotIndex = null;

// Toggle return qty input when checkbox checked
function toggleReturn(i) {
    const cb   = document.getElementById('item_' + i);
    const wrap = document.getElementById('return-qty-wrap-' + i);
    wrap.classList.toggle('hidden', !cb.checked);
    recalcDelta();
}

// Collect total return value from checked items
function getReturnTotal() {
    let total = 0;
    document.querySelectorAll('[name^="return_items"][name$="[selected]"]').forEach((cb, i) => {
        if (!cb.checked) return;
        const qtyInput = document.querySelector(`[name="return_items[${i}][qty]"]`);
        const price    = parseFloat(document.querySelector(`[name="return_items[${i}][unit_price]"]`).value) || 0;
        const qty      = parseInt(qtyInput?.value || 1);
        total += price * qty;
    });
    return total;
}

// Collect total replacement value
function getNewTotal() {
    return replacements.reduce((s, r) => s + (r.price * r.qty), 0);
}

function recalcDelta() {
    // Guard: these elements only exist in step 2 (when a sale is loaded)
    if (!document.getElementById('return-total-display')) return;

    const returnTotal = getReturnTotal();
    const newTotal    = getNewTotal();
    const delta       = newTotal - returnTotal;

    document.getElementById('return-total-display').textContent = '₱' + returnTotal.toFixed(2);
    document.getElementById('new-total-display').textContent    = '₱' + newTotal.toFixed(2);

    const display = document.getElementById('delta-display');
    const label   = document.getElementById('delta-label');
    const paySection = document.getElementById('delta-payment-section');

    // GAP-2: note whether displayed prices include or exclude VAT
    const taxNote = TAX_MODE === 'inclusive' ? ' (incl. VAT)' : ' (excl. VAT)';

    if (Math.abs(delta) < 0.01) {
        display.textContent = '₱0.00';
        display.className   = 'text-2xl font-black text-emerald-400';
        label.textContent   = 'Even exchange — no payment needed';
        paySection.classList.add('hidden');
        document.getElementById('delta-type-hidden').value   = 'none';
        document.getElementById('delta-amount-hidden').value = '0';
    } else if (delta > 0) {
        display.textContent = '+ ₱' + delta.toFixed(2);
        display.className   = 'text-2xl font-black text-amber-400';
        label.textContent   = 'Customer pays the difference' + taxNote;
        paySection.classList.remove('hidden');
        document.getElementById('delta-type-hidden').value   = 'collect';
        document.getElementById('delta-amount-hidden').value = delta.toFixed(2);
    } else {
        display.textContent = '− ₱' + Math.abs(delta).toFixed(2);
        display.className   = 'text-2xl font-black text-blue-400';
        label.textContent   = 'Refund the difference to customer' + taxNote;
        paySection.classList.add('hidden');
        document.getElementById('delta-type-hidden').value   = 'refund';
        document.getElementById('delta-amount-hidden').value = Math.abs(delta).toFixed(2);
    }
}

// Add a replacement row — opens inline search panel
function addReplacement() {
    pendingSlotIndex = replacements.length;
    var panel = document.getElementById('product-search-panel');
    var input = document.getElementById('product-search-input');
    var results = document.getElementById('product-search-results');
    if (!panel) return;
    panel.classList.remove('hidden');
    if (input)   { input.value = ''; input.focus(); }
    if (results) results.innerHTML = '';
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function closeSearch() {
    var panel = document.getElementById('product-search-panel');
    if (panel) panel.classList.add('hidden');
    pendingSlotIndex = null;
}

// Pick a product from search results into a replacement slot
function pickReplacement(pid, name, price) {
    if (pendingSlotIndex === null) return;
    const idx = pendingSlotIndex;

    // If slot exists, update; else push new
    if (idx < replacements.length) {
        replacements[idx] = { product_id: pid, name, price, qty: replacements[idx].qty };
    } else {
        replacements.push({ product_id: pid, name, price, qty: 1 });
    }
    pendingSlotIndex = null;
    closeSearch();
    renderReplacements();
    recalcDelta();
}

function removeReplacement(idx) {
    replacements.splice(idx, 1);
    renderReplacements();
    recalcDelta();
}

function renderReplacements() {
    const list = document.getElementById('replacements-list');
    const hint = document.getElementById('no-replacements-hint');
    hint.classList.toggle('hidden', replacements.length > 0);

    list.innerHTML = '';
    replacements.forEach((r, i) => {
        const div = document.createElement('div');
        div.className = 'flex items-center gap-4 bg-violet-50 border border-violet-100 rounded-2xl p-4';
        div.innerHTML = `
            <input type="hidden" name="new_items[${i}][product_id]" value="${r.product_id}">
            <input type="hidden" name="new_items[${i}][unit_price]" value="${r.price}">
            <div class="flex-1">
                <p class="font-bold text-slate-700">${r.name}</p>
                <p class="text-[10px] text-violet-600 font-bold">₱${r.price.toFixed(2)} each</p>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-[10px] font-black text-slate-400 uppercase">Qty:</label>
                <input type="number" name="new_items[${i}][qty]" min="1" value="${r.qty}"
                       onchange="replacements[${i}].qty=parseInt(this.value)||1; recalcDelta()"
                       class="w-20 border-2 border-violet-200 rounded-xl px-3 py-2 text-sm font-black focus:outline-none focus:border-violet-500">
            </div>
            <button type="button" onclick="removeReplacement(${i})"
                    class="text-slate-300 hover:text-rose-400 transition-colors font-black text-sm">✕</button>
        `;
        list.appendChild(div);
    });
}

// Live product search
function searchProducts() {
    const q = document.getElementById('product-search-input').value.trim();
    const results = document.getElementById('product-search-results');
    if (q.length < 2) { results.innerHTML = ''; return; }

    fetch(`/project/staff/api/product_search.php?q=${encodeURIComponent(q)}&mode=exchange`)
        .then(r => r.json())
        .then(data => {
            if (!data.length) { results.innerHTML = '<p class="text-slate-300 text-xs font-bold p-4">No products found.</p>'; return; }
            results.innerHTML = data.map(p => `
                <div onclick='pickReplacement(${p.id}, ${JSON.stringify(p.name)}, ${parseFloat(p.price)})'
                     class="flex items-center justify-between p-4 hover:bg-violet-50 rounded-2xl cursor-pointer transition-colors border border-transparent hover:border-violet-100">
                    <div>
                        <p class="font-bold text-slate-700 text-sm">${p.name}</p>
                        <p class="text-[10px] text-slate-400 font-bold">${p.barcode || ''}</p>
                    </div>
                    <span class="font-black text-emerald-600">₱${parseFloat(p.price).toFixed(2)}</span>
                </div>
            `).join('');
        })
        .catch(() => { results.innerHTML = '<p class="text-rose-400 text-xs font-bold p-4">Search failed.</p>'; });
}

function toggleRefField(mode) {
    document.getElementById('ref-field-wrap').classList.toggle('hidden', mode !== 'yes');
}

// Validate before submit
document.getElementById('exchangeForm')?.addEventListener('submit', function(e) {
    const anyReturn = [...document.querySelectorAll('[name$="[selected]"]')].some(c => c.checked);
    if (!anyReturn) { e.preventDefault(); showFlash('Select at least one item to return.', 'error'); return; }
    if (replacements.length === 0) { e.preventDefault(); showFlash('Add at least one replacement item.', 'error'); return; }
    const deltaType = document.getElementById('delta-type-hidden').value;
    if (deltaType === 'collect') {
        const method = document.querySelector('[name="payment_mode"]:checked')?.value;
        if (method !== '<?= PAY_METHOD_CASH ?>' && !document.getElementById('ref-field').value.trim()) {
            e.preventDefault(); showFlash('Reference number is required for digital payment.', 'error'); return;
        }
    }
});

recalcDelta();
</script>

<?php include '../layout_bottom.php'; ?>
