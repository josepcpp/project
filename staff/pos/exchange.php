<?php
/**
 * exchange.php — Item exchange workflow
 * Supports: even exchange (same value) and delta exchange (collect/refund difference).
 * This is separate from the refund system — stock is swapped, not just returned.
 */
include '../../config/db.php';
include '../../config/settings.php';
include '../layout_top.php';

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
    $sale = $sq->get_result()->fetch_assoc();

    if ($sale) {
        $iq = $conn->prepare("
            SELECT si.*, p.name, p.price AS current_price, p.barcode, p.quantity AS stock
            FROM sales_items si
            JOIN products p ON p.id = si.product_id
            WHERE si.sale_id = ?
        ");
        $iq->bind_param("i", $sale['id']);
        $iq->execute();
        while ($row = $iq->get_result()->fetch_assoc()) $sale_items[] = $row;
    }
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
        <form method="GET" action="" class="flex gap-4">
            <input type="hidden" name="step" value="1">
            <input type="text" name="receipt" value="<?= htmlspecialchars($receipt_no) ?>"
                   placeholder="RCPT-20260526-XXXX" required
                   class="input-modern flex-1 uppercase font-black tracking-widest">
            <button type="submit" class="btn-pos-primary px-8">Search</button>
        </form>

        <?php if ($receipt_no && !$sale): ?>
            <p class="text-rose-500 font-bold text-sm mt-4">No sale found for receipt <strong><?= htmlspecialchars($receipt_no) ?></strong>.</p>
        <?php endif; ?>
    </div>

    <?php if ($sale && !empty($sale_items)): ?>

    <!-- ── STEP 2: SELECT ITEMS TO RETURN + REPLACEMENTS ─────────────────── -->
    <div class="card-modern shadow-xl">
        <h4 class="font-black text-slate-700 text-sm uppercase tracking-widest mb-2">Step 2 — Select Items to Exchange</h4>
        <p class="text-slate-400 text-xs font-bold mb-6">Receipt <strong class="text-slate-600"><?= htmlspecialchars($sale['receipt_no']) ?></strong> · <?= date('M j, Y g:i A', strtotime($sale['created_at'])) ?> · <strong class="text-emerald-600">₱<?= number_format($sale['total'], 2) ?></strong></p>

        <form method="POST" action="exchange_process.php" id="exchangeForm">
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
                        <p class="font-bold text-slate-700"><?= htmlspecialchars($item['name']) ?></p>
                        <p class="text-[10px] text-slate-400 font-bold">
                            Purchased: <?= $item['qty'] ?> × ₱<?= number_format($item['price'], 2) ?> = ₱<?= number_format($item['qty'] * $item['price'], 2) ?>
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

    <!-- Product search panel for replacements -->
    <div id="product-search-panel" class="hidden card-modern shadow-xl border-2 border-violet-200">
        <h5 class="font-black text-slate-700 text-xs uppercase tracking-widest mb-4">Search Replacement Product</h5>
        <div class="flex gap-3 mb-4">
            <input type="text" id="product-search-input" placeholder="Name or barcode…" oninput="searchProducts()"
                   class="input-modern flex-1">
        </div>
        <div id="product-search-results" class="space-y-2 max-h-72 overflow-y-auto"></div>
    </div>

    <?php endif; ?>
</div>

<script>
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
    const returnTotal = getReturnTotal();
    const newTotal    = getNewTotal();
    const delta       = newTotal - returnTotal;

    document.getElementById('return-total-display').textContent = '₱' + returnTotal.toFixed(2);
    document.getElementById('new-total-display').textContent    = '₱' + newTotal.toFixed(2);

    const display = document.getElementById('delta-display');
    const label   = document.getElementById('delta-label');
    const paySection = document.getElementById('delta-payment-section');

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
        label.textContent   = 'Customer pays the difference';
        paySection.classList.remove('hidden');
        document.getElementById('delta-type-hidden').value   = 'collect';
        document.getElementById('delta-amount-hidden').value = delta.toFixed(2);
    } else {
        display.textContent = '− ₱' + Math.abs(delta).toFixed(2);
        display.className   = 'text-2xl font-black text-blue-400';
        label.textContent   = 'Refund the difference to customer';
        paySection.classList.add('hidden');
        document.getElementById('delta-type-hidden').value   = 'refund';
        document.getElementById('delta-amount-hidden').value = Math.abs(delta).toFixed(2);
    }
}

// Add a replacement row
function addReplacement() {
    pendingSlotIndex = replacements.length;
    document.getElementById('product-search-panel').classList.remove('hidden');
    document.getElementById('product-search-input').value = '';
    document.getElementById('product-search-results').innerHTML = '';
    document.getElementById('product-search-input').focus();
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
    document.getElementById('product-search-panel').classList.add('hidden');
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
                <div onclick="pickReplacement(${p.id}, ${JSON.stringify(p.name)}, ${p.price})"
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
