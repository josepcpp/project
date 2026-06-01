<?php
include '../../config/db.php';
include '../../config/settings.php';
include '../../includes/csrf.php';
include '../layout_top.php';

$role     = strtolower($_SESSION['role'] ?? '');
$user_id  = $_SESSION['user_id'] ?? null;
$is_admin = in_array($role, ROLES_ADMIN_AND_UP);

// All staff-level roles can use this page — kept in sync with exchange_process.php's gate.
if (!in_array($role, [ROLE_STAFF, ROLE_ADMIN, ROLE_OWNER, ROLE_SUPERADMIN, ROLE_RECEIVER, ROLE_VALIDATOR, ROLE_PRICE_CHECKER])) {
    header("Location: ../dashboard.php");
    exit();
}

$receipt_no = trim($_GET['receipt'] ?? '');
$error      = htmlspecialchars(trim($_GET['error']   ?? ''));
$success    = htmlspecialchars(trim($_GET['success'] ?? ''));
$sale       = null;
$sale_items = [];

// ── Load sale if receipt provided ────────────────────────────────────────────
if ($receipt_no !== '') {
    $sq = $conn->prepare("SELECT * FROM sales WHERE receipt_no = ? LIMIT 1");
    $sq->bind_param("s", $receipt_no);
    $sq->execute();
    $sale = $sq->get_result()->fetch_assoc();

    if ($sale) {
        $iq = $conn->prepare("
            SELECT si.*, p.name, p.category, p.price AS current_price,
                   p.barcode, p.quantity AS stock,
                   COALESCE((SELECT SUM(r.qty) FROM refunds r
                             WHERE r.sale_id = si.sale_id AND r.product_id = si.product_id
                               AND r.status != '" . REFUND_REJECTED . "'), 0) AS already_refunded
            FROM sales_items si
            JOIN products p ON p.id = si.product_id
            WHERE si.sale_id = ?
        ");
        $iq->bind_param("i", $sale['id']);
        $iq->execute();
        $sale_items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// ── Tax display mode ──────────────────────────────────────────────────────────
$tax_display_mode = 'exclusive';
$tdm = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='tax_display_mode' LIMIT 1");
if ($tdm && $tdm_row = $tdm->fetch_assoc()) $tax_display_mode = $tdm_row['setting_value'] ?? 'exclusive';

// ── Recent sales for quick-pick ───────────────────────────────────────────────
$recent_sales = [];
$rq = $conn->query("
    SELECT s.receipt_no, s.total, s.created_at, s.payment_mode, COUNT(si.id) AS item_count
    FROM sales s LEFT JOIN sales_items si ON si.sale_id = s.id
    GROUP BY s.id ORDER BY s.created_at DESC LIMIT 30
");
if ($rq) while ($r = $rq->fetch_assoc()) $recent_sales[] = $r;

// ── Admin: pending refund queue ───────────────────────────────────────────────
$pending_refunds = [];
if ($is_admin) {
    $prq = $conn->query("
        SELECT r.*, s.receipt_no, p.name AS product_name, u.username AS requested_by_name
        FROM refunds r
        JOIN sales s    ON r.sale_id    = s.id
        JOIN products p ON r.product_id = p.id
        LEFT JOIN users u ON r.requested_by = u.id
        WHERE r.status = '" . REFUND_PENDING . "'
        ORDER BY r.created_at ASC
    ");
    if ($prq) $pending_refunds = $prq->fetch_all(MYSQLI_ASSOC);
}
?>

<!-- POS Hub Tab Strip -->
<div class="flex gap-2 mb-5 bg-white rounded-2xl border border-slate-100 shadow-sm p-1.5">
    <a href="/project/staff/pos/pos.php"
       class="flex-1 py-2.5 rounded-xl text-center font-black text-xs uppercase tracking-widest text-slate-400 hover:bg-slate-50 transition-all">
        Point of Sale
    </a>
    <span class="flex-1 py-2.5 rounded-xl text-center font-black text-xs uppercase tracking-widest bg-slate-900 text-white shadow">
        Returns &amp; Exchange
    </span>
</div>

<div class="max-w-4xl mx-auto space-y-5 pb-20 animate-in">

    <?php if ($error): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-700 font-bold rounded-2xl px-5 py-4 text-sm"><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 font-bold rounded-2xl px-5 py-4 text-sm"><?= $success ?></div>
    <?php endif; ?>

    <!-- ── RECEIPT SEARCH ─────────────────────────────────────────────────── -->
    <div class="card-modern p-6">
        <div class="flex gap-3 mb-3">
            <div class="relative flex-1">
                <input type="text" id="receipt-input"
                       value="<?= htmlspecialchars($receipt_no) ?>"
                       placeholder="Enter receipt no. (e.g. RCPT-20260530-XXXX)"
                       oninput="filterReceipts(this.value)"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();goReceipt();}"
                       autocomplete="off"
                       class="input-modern w-full uppercase font-black tracking-widest pr-24">
                <button onclick="goReceipt()"
                        class="btn-pos-primary absolute right-2 top-1/2 -translate-y-1/2 px-4 py-2 text-xs">
                    Find
                </button>
            </div>
        </div>
        <?php if ($receipt_no && !$sale): ?>
        <p class="text-rose-500 font-bold text-sm">No sale found for <strong><?= htmlspecialchars($receipt_no) ?></strong>.</p>
        <?php endif; ?>
        <?php if (!empty($recent_sales)): ?>
        <div id="receipts-panel" class="mt-2">
            <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest mb-2">Recent — click to load</p>
            <div id="receipts-list" class="space-y-1 max-h-52 overflow-y-auto">
                <?php foreach ($recent_sales as $rs): ?>
                <div class="receipt-row flex items-center justify-between px-4 py-2.5 rounded-xl hover:bg-slate-50 cursor-pointer border border-transparent hover:border-slate-200 transition-all"
                     data-receipt="<?= htmlspecialchars($rs['receipt_no']) ?>"
                     onclick="loadReceipt('<?= htmlspecialchars($rs['receipt_no']) ?>')">
                    <div>
                        <p class="font-black text-slate-700 text-sm tracking-widest"><?= htmlspecialchars($rs['receipt_no']) ?></p>
                        <p class="text-[10px] text-slate-400 font-bold">
                            <?= date('M j, Y g:i A', strtotime($rs['created_at'])) ?>
                            · <?= $rs['item_count'] ?> item<?= $rs['item_count'] != 1 ? 's' : '' ?>
                        </p>
                    </div>
                    <span class="font-black text-emerald-600 text-sm">₱<?= number_format($rs['total'], 2) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($sale && !empty($sale_items)): ?>

    <!-- ── RECEIPT INFO + MODE TOGGLE ────────────────────────────────────── -->
    <div class="bg-slate-900 text-white rounded-2xl px-6 py-4 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="font-black tracking-widest text-sm"><?= htmlspecialchars($sale['receipt_no']) ?></p>
            <p class="text-slate-400 text-xs font-bold"><?= date('M j, Y g:i A', strtotime($sale['created_at'])) ?> · <?= htmlspecialchars($sale['payment_mode']) ?></p>
        </div>
        <p class="font-black text-emerald-400 text-xl">₱<?= number_format($sale['total'], 2) ?></p>
        <!-- Mode toggle -->
        <div class="flex gap-1 bg-slate-800 rounded-xl p-1 w-full sm:w-auto">
            <button id="btn-mode-refund" onclick="setMode('refund')"
                    class="flex-1 sm:flex-none px-5 py-2 rounded-lg font-black text-xs uppercase tracking-widest transition-all bg-white text-slate-900 shadow">
                ↩ Refund
            </button>
            <button id="btn-mode-exchange" onclick="setMode('exchange')"
                    class="flex-1 sm:flex-none px-5 py-2 rounded-lg font-black text-xs uppercase tracking-widest transition-all text-slate-400 hover:text-white">
                ⇄ Exchange
            </button>
        </div>
    </div>

    <!-- ── REFUND SECTION ────────────────────────────────────────────────── -->
    <div id="refund-section" class="card-modern p-6 space-y-3">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Select an item to refund — one at a time</p>
        <?php foreach ($sale_items as $item):
            $remaining = intval($item['qty']) - intval($item['already_refunded']);
        ?>
        <div class="bg-slate-50 rounded-2xl p-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-slate-800 text-sm leading-tight"><?= htmlspecialchars($item['name']) ?></p>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">
                        Purchased: <?= intval($item['qty']) ?> × ₱<?= number_format($item['price'], 2) ?>
                        <?php if ($item['already_refunded'] > 0): ?>
                        · <span class="text-amber-500"><?= intval($item['already_refunded']) ?> already refunded</span>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($remaining > 0): ?>
                <form method="POST" action="refund_process.php" class="flex items-center gap-2 flex-shrink-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="sale_id"    value="<?= $sale['id'] ?>">
                    <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                    <input type="hidden" name="receipt_no" value="<?= htmlspecialchars($sale['receipt_no']) ?>">
                    <select name="disposition" class="input-modern text-xs py-2 pr-8 w-28">
                        <option value="restock">Restock</option>
                        <option value="dispose">Dispose</option>
                    </select>
                    <input type="number" name="qty" min="1" max="<?= $remaining ?>" value="1"
                           class="input-modern text-xs py-2 w-16 text-center font-black">
                    <button type="submit"
                            onclick="return confirm('Submit refund for <?= htmlspecialchars(addslashes($item['name'])) ?>?')"
                            class="bg-amber-500 hover:bg-amber-600 text-white font-black text-xs px-4 py-2 rounded-xl uppercase tracking-widest transition-all whitespace-nowrap">
                        Refund
                    </button>
                </form>
                <?php else: ?>
                <span class="text-[10px] font-black text-slate-300 bg-slate-100 px-3 py-1.5 rounded-xl uppercase">Fully Refunded</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── EXCHANGE SECTION ───────────────────────────────────────────────── -->
    <div id="exchange-section" class="card-modern p-6 space-y-5 hidden">
        <form method="POST" action="../pos/exchange_process.php" id="exchangeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="sale_id"    value="<?= $sale['id'] ?>">
            <input type="hidden" name="receipt_no" value="<?= htmlspecialchars($sale['receipt_no']) ?>">

            <!-- Items to return -->
            <div class="space-y-2">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Items to return:</p>
                <?php foreach ($sale_items as $i => $item): ?>
                <div class="flex items-center gap-3 bg-slate-50 rounded-xl px-4 py-3">
                    <input type="checkbox" name="return_items[<?= $i ?>][selected]" value="1"
                           id="xitem_<?= $i ?>" onchange="toggleReturn(<?= $i ?>)"
                           class="w-4 h-4 accent-violet-500 flex-shrink-0">
                    <div class="flex-1 min-w-0">
                        <p class="font-bold text-slate-700 text-sm truncate"><?= htmlspecialchars($item['name']) ?></p>
                        <p class="text-[10px] text-slate-400 font-bold"><?= intval($item['qty']) ?> × ₱<?= number_format($item['price'], 2) ?></p>
                    </div>
                    <div id="xqty-wrap-<?= $i ?>" class="hidden flex items-center gap-2">
                        <span class="text-[10px] font-black text-slate-400 uppercase">Qty:</span>
                        <input type="number" name="return_items[<?= $i ?>][qty]" min="1" max="<?= intval($item['qty']) ?>" value="1"
                               onchange="recalcDelta()"
                               class="w-16 border-2 border-violet-200 rounded-xl px-2 py-1.5 text-sm font-black text-center focus:outline-none focus:border-violet-500">
                    </div>
                    <input type="hidden" name="return_items[<?= $i ?>][product_id]" value="<?= $item['product_id'] ?>">
                    <input type="hidden" name="return_items[<?= $i ?>][unit_price]"  value="<?= $item['price'] ?>">
                    <input type="hidden" name="return_items[<?= $i ?>][max_qty]"     value="<?= intval($item['qty']) ?>">
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Replacements -->
            <div class="border-t border-slate-100 pt-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Replacement items:</p>
                    <button type="button" onclick="addReplacement()"
                            class="text-[10px] font-black text-violet-600 border border-violet-200 rounded-xl px-3 py-1.5 hover:bg-violet-50 transition-colors uppercase tracking-widest">
                        + Add Item
                    </button>
                </div>
                <div id="replacements-list" class="space-y-2"></div>
                <p id="no-replacements-hint" class="text-slate-300 text-xs font-bold italic">Click "Add Item" to pick replacement products.</p>
                <div id="product-search-panel" class="hidden mt-3 bg-violet-50 border border-violet-200 rounded-2xl p-4">
                    <div class="flex gap-2 mb-2">
                        <input type="text" id="product-search-input" placeholder="Search product…"
                               oninput="searchProducts()" autocomplete="off"
                               class="input-modern flex-1 bg-white text-sm">
                        <button type="button" onclick="closeSearch()" class="text-slate-300 hover:text-rose-400 font-black px-3">✕</button>
                    </div>
                    <div id="product-search-results" class="max-h-52 overflow-y-auto space-y-1"></div>
                </div>
            </div>

            <!-- Delta summary -->
            <div class="bg-slate-900 rounded-2xl p-5 text-white">
                <div class="flex justify-between text-xs font-bold text-slate-400 mb-1">
                    <span>Return Value</span><span id="return-total-display">₱0.00</span>
                </div>
                <div class="flex justify-between text-xs font-bold text-slate-400 mb-3">
                    <span>Replacement Value</span><span id="new-total-display">₱0.00</span>
                </div>
                <div class="flex justify-between items-center border-t border-slate-700 pt-3">
                    <span class="font-black text-slate-300 uppercase text-xs tracking-widest">Balance</span>
                    <span id="delta-display" class="text-xl font-black text-emerald-400">₱0.00</span>
                </div>
                <p id="delta-label" class="text-center text-[10px] font-black uppercase tracking-widest text-slate-400 mt-1">Even exchange</p>
            </div>

            <!-- Additional payment if collecting difference -->
            <div id="delta-payment-section" class="hidden bg-amber-50 border border-amber-200 rounded-2xl p-5 space-y-3">
                <p class="text-amber-700 font-black text-xs uppercase tracking-widest">Additional Payment Required</p>
                <div class="grid grid-cols-3 gap-2">
                    <?php foreach ([PAY_METHOD_CASH, PAY_METHOD_GCASH, PAY_METHOD_MAYA] as $m): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_mode" value="<?= $m ?>" <?= $m===PAY_METHOD_CASH?'checked':'' ?> class="hidden peer"
                               onchange="toggleRefField('<?= $m !== PAY_METHOD_CASH ? 'yes':'no' ?>')">
                        <div class="py-2.5 border-2 border-amber-200 rounded-xl text-center font-black text-amber-500 text-[10px] uppercase peer-checked:border-amber-500 peer-checked:bg-amber-100 peer-checked:text-amber-700 transition-all"><?= $m ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="ref-field-wrap" class="hidden">
                    <label class="label-modern text-amber-600">Reference No. <span class="text-rose-400">*</span></label>
                    <input type="text" name="reference_no" id="ref-field" placeholder="TRANS-ID-XXXX"
                           class="input-modern bg-white font-mono uppercase text-sm">
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="label-modern">Notes <span class="text-slate-300 font-normal normal-case text-xs">(optional)</span></label>
                <textarea name="notes" rows="2" placeholder="e.g. Customer prefers different flavor"
                          class="input-modern resize-none text-sm"></textarea>
            </div>

            <input type="hidden" name="delta_type"   id="delta-type-hidden"   value="none">
            <input type="hidden" name="delta_amount" id="delta-amount-hidden" value="0">

            <button type="submit" id="xSubmitBtn"
                    class="btn-pos-primary w-full py-4 text-sm font-black uppercase tracking-widest shadow-lg shadow-violet-200 mt-2">
                Process Exchange
            </button>
        </form>
    </div>

    <?php endif; ?>

    <!-- ── ADMIN REFUND QUEUE ────────────────────────────────────────────── -->
    <?php if ($is_admin && !empty($pending_refunds)): ?>
    <div class="card-modern p-6">
        <div class="flex items-center justify-between mb-4">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Pending Refund Approvals</p>
            <span class="bg-amber-500 text-white text-[9px] font-black px-3 py-1 rounded-full"><?= count($pending_refunds) ?></span>
        </div>
        <div class="space-y-3">
        <?php foreach ($pending_refunds as $pr): ?>
        <div class="flex flex-wrap items-center justify-between gap-3 bg-amber-50 border border-amber-100 rounded-2xl px-5 py-3">
            <div class="flex-1 min-w-0">
                <p class="font-black text-slate-800 text-sm"><?= htmlspecialchars($pr['product_name']) ?></p>
                <p class="text-[10px] text-slate-400 font-bold mt-0.5">
                    Qty: <?= intval($pr['qty']) ?> ·
                    <span class="<?= $pr['disposition'] === 'restock' ? 'text-emerald-600' : 'text-rose-500' ?>"><?= ucfirst($pr['disposition']) ?></span>
                    · Receipt: <?= htmlspecialchars($pr['receipt_no']) ?>
                    · by @<?= htmlspecialchars($pr['requested_by_name'] ?? '?') ?>
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <span class="font-black text-amber-600">₱<?= number_format($pr['amount_refunded'], 2) ?></span>
                <form method="POST" action="refund_approve.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"    value="approve">
                    <input type="hidden" name="refund_id" value="<?= $pr['id'] ?>">
                    <button type="submit"
                            onclick="return confirm('Approve this refund of ₱<?= number_format($pr['amount_refunded'], 2) ?>?')"
                            class="bg-emerald-500 hover:bg-emerald-600 text-white font-black text-xs px-4 py-2 rounded-xl uppercase transition-all">
                        Approve
                    </button>
                </form>
                <form method="POST" action="refund_approve.php" class="flex gap-2 items-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action"    value="reject">
                    <input type="hidden" name="refund_id" value="<?= $pr['id'] ?>">
                    <input type="text" name="note" placeholder="Reason…" required class="input-modern text-xs py-2 w-28">
                    <button type="submit"
                            class="bg-slate-200 hover:bg-rose-500 hover:text-white text-slate-600 font-black text-xs px-4 py-2 rounded-xl uppercase transition-all">
                        Reject
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- end max-w-4xl -->

<script>
// var (not const/let) so this script re-initialises cleanly when the SPA
// re-renders the page (e.g. returning here after a rejected exchange) instead
// of throwing a redeclaration error and leaving stale replacement state behind.
var TAX_MODE = <?= json_encode($tax_display_mode) ?>;

// ── Mode toggle ───────────────────────────────────────────────────────────────
function setMode(mode) {
    const isRefund = mode === 'refund';
    document.getElementById('refund-section')?.classList.toggle('hidden', !isRefund);
    document.getElementById('exchange-section')?.classList.toggle('hidden', isRefund);
    document.getElementById('btn-mode-refund').className   = 'flex-1 sm:flex-none px-5 py-2 rounded-lg font-black text-xs uppercase tracking-widest transition-all ' + (isRefund ? 'bg-white text-slate-900 shadow' : 'text-slate-400 hover:text-white');
    document.getElementById('btn-mode-exchange').className = 'flex-1 sm:flex-none px-5 py-2 rounded-lg font-black text-xs uppercase tracking-widest transition-all ' + (!isRefund ? 'bg-white text-slate-900 shadow' : 'text-slate-400 hover:text-white');
}

// ── Receipt helpers ───────────────────────────────────────────────────────────
function filterReceipts(q) {
    const term = q.trim().toUpperCase();
    document.querySelectorAll('.receipt-row').forEach(row => {
        row.style.display = (!term || row.dataset.receipt.toUpperCase().includes(term) || row.textContent.toUpperCase().includes(term)) ? '' : 'none';
    });
}
function goReceipt() {
    const v = document.getElementById('receipt-input').value.trim();
    if (v) loadReceipt(v);
}
function loadReceipt(r) {
    window.location.href = 'returns_exchange.php?receipt=' + encodeURIComponent(r);
}
document.getElementById('receipt-input')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); goReceipt(); } });
(function(){ const i = document.getElementById('receipt-input'); if (i?.value.trim()) filterReceipts(i.value); })();

// ── Exchange helpers ──────────────────────────────────────────────────────────
var replacements = [], pendingSlotIndex = null;

function toggleReturn(i) {
    document.getElementById('xqty-wrap-' + i)?.classList.toggle('hidden', !document.getElementById('xitem_' + i).checked);
    recalcDelta();
}
function getReturnTotal() {
    let t = 0;
    document.querySelectorAll('[name^="return_items"][name$="[selected]"]').forEach((cb, i) => {
        if (!cb.checked) return;
        const q = parseInt(document.querySelector(`[name="return_items[${i}][qty]"]`)?.value || 1);
        const p = parseFloat(document.querySelector(`[name="return_items[${i}][unit_price]"]`)?.value || 0);
        t += p * q;
    });
    return t;
}
// product_ids of the currently-checked return items (replacements must differ from these)
function getReturnedIds() {
    const ids = new Set();
    document.querySelectorAll('[name^="return_items"][name$="[selected]"]').forEach(cb => {
        if (!cb.checked) return;
        const m = cb.name.match(/return_items\[(\d+)\]/); if (!m) return;
        const pid = document.querySelector(`[name="return_items[${m[1]}][product_id]"]`)?.value;
        if (pid) ids.add(String(pid));
    });
    return ids;
}
// Per-replacement line total using the same Full/Half-box bulk rule as the POS
// and the exchange server (exchange_process.php).
function replLineTotal(r) {
    const qty    = parseInt(r.qty) || 0;
    const retail = parseFloat(r.price) || 0;
    const bqf = parseInt(r.bulk_qty_full)  || 0, pfb = parseFloat(r.price_full_box) || 0;
    const bqh = parseInt(r.bulk_qty_half)  || 0, phb = parseFloat(r.price_half_box) || 0;
    if (bqf > 0 && qty >= bqf) return pfb + ((qty - bqf) * retail);
    if (bqh > 0 && qty >= bqh) return phb + ((qty - bqh) * retail);
    return qty * retail;
}
function getNewTotal() { return replacements.reduce((s, r) => s + replLineTotal(r), 0); }
function recalcDelta() {
    const rd = document.getElementById('return-total-display'); if (!rd) return;
    const rt = getReturnTotal(), nt = getNewTotal(), delta = nt - rt;
    rd.textContent = '₱' + rt.toFixed(2);
    document.getElementById('new-total-display').textContent = '₱' + nt.toFixed(2);
    const dd = document.getElementById('delta-display');
    const lbl = document.getElementById('delta-label');
    const pay = document.getElementById('delta-payment-section');
    const taxNote = TAX_MODE === 'inclusive' ? ' (incl. VAT)' : ' (excl. VAT)';
    if (Math.abs(delta) < 0.01) {
        dd.textContent = '₱0.00'; dd.className = 'text-xl font-black text-emerald-400';
        lbl.textContent = 'Even exchange — no payment needed';
        pay.classList.add('hidden');
        document.getElementById('delta-type-hidden').value = 'none';
        document.getElementById('delta-amount-hidden').value = '0';
    } else if (delta > 0) {
        dd.textContent = '+ ₱' + delta.toFixed(2); dd.className = 'text-xl font-black text-amber-400';
        lbl.textContent = 'Customer pays the difference' + taxNote;
        pay.classList.remove('hidden');
        document.getElementById('delta-type-hidden').value = 'collect';
        document.getElementById('delta-amount-hidden').value = delta.toFixed(2);
    } else {
        dd.textContent = '− ₱' + Math.abs(delta).toFixed(2); dd.className = 'text-xl font-black text-blue-400';
        lbl.textContent = 'Refund the difference to customer' + taxNote;
        pay.classList.add('hidden');
        document.getElementById('delta-type-hidden').value = 'refund';
        document.getElementById('delta-amount-hidden').value = Math.abs(delta).toFixed(2);
    }
}
function addReplacement() {
    pendingSlotIndex = replacements.length;
    const p = document.getElementById('product-search-panel');
    const i = document.getElementById('product-search-input');
    p?.classList.remove('hidden'); if (i) { i.value = ''; i.focus(); }
    document.getElementById('product-search-results').innerHTML = '';
    p?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function closeSearch() { document.getElementById('product-search-panel')?.classList.add('hidden'); pendingSlotIndex = null; }
function pickReplacement(pid, name, price, stock, tiers) {
    if (pendingSlotIndex === null) return;
    stock = parseInt(stock) || 0;
    if (stock < 1) { showFlash('"' + name + '" is out of stock.', 'error'); return; }
    // Replacement must be a different item than what's being returned.
    if (getReturnedIds().has(String(pid))) {
        showFlash('Replacement must be a different item than what you\'re returning. For more/less of the same item, use Refund or a new sale.', 'error');
        return;
    }
    tiers = tiers || {};
    const base = {
        product_id: pid, name, price, stock,
        bulk_qty_half:  parseInt(tiers.bulk_qty_half)  || 0,
        price_half_box: parseFloat(tiers.price_half_box) || 0,
        bulk_qty_full:  parseInt(tiers.bulk_qty_full)  || 0,
        price_full_box: parseFloat(tiers.price_full_box) || 0,
    };
    const idx = pendingSlotIndex;
    if (idx < replacements.length) {
        base.qty = Math.min(replacements[idx].qty || 1, stock);
        replacements[idx] = base;
    } else {
        base.qty = 1;
        replacements.push(base);
    }
    pendingSlotIndex = null; closeSearch(); renderReplacements(); recalcDelta();
}
// Live clamp (fires on every keystroke): the moment the typed qty exceeds stock,
// force it down to the available amount and warn. Empty/low values are allowed
// mid-typing and normalised on blur (fixReplQty) so editing stays smooth.
function setReplQty(idx, input) {
    const max = replacements[idx].stock || 1;
    let v = parseInt(input.value);
    if (isNaN(v)) { replacements[idx].qty = 0; recalcDelta(); return; }   // still typing
    if (v > max) {
        v = max;
        input.value = max;                                                // force to available stock
        showFlash('Only ' + max + ' in stock for "' + replacements[idx].name + '".', 'error');
    }
    replacements[idx].qty = Math.max(v, 0);
    recalcDelta();
}
// Normalise on blur — never leave the box empty or below 1.
function fixReplQty(idx, input) {
    const max = replacements[idx].stock || 1;
    let v = parseInt(input.value) || 1;
    if (v < 1)   v = 1;
    if (v > max) v = max;
    input.value = v;
    replacements[idx].qty = v;
    recalcDelta();
}
function removeReplacement(idx) { replacements.splice(idx, 1); renderReplacements(); recalcDelta(); }
function renderReplacements() {
    const list = document.getElementById('replacements-list');
    const hint = document.getElementById('no-replacements-hint');
    hint?.classList.toggle('hidden', replacements.length > 0);
    list.innerHTML = '';
    replacements.forEach((r, i) => {
        const d = document.createElement('div');
        d.className = 'flex items-center gap-3 bg-violet-50 border border-violet-100 rounded-xl px-4 py-3';
        d.innerHTML = `
            <input type="hidden" name="new_items[${i}][product_id]" value="${r.product_id}">
            <input type="hidden" name="new_items[${i}][unit_price]" value="${r.price}">
            <div class="flex-1 min-w-0">
                <p class="font-bold text-slate-700 text-sm truncate">${r.name}</p>
                <p class="text-[10px] text-violet-600 font-bold">₱${r.price.toFixed(2)} · ${r.stock} in stock</p>
            </div>
            <input type="number" name="new_items[${i}][qty]" min="1" max="${r.stock}" value="${r.qty}"
                   oninput="setReplQty(${i}, this)" onblur="fixReplQty(${i}, this)"
                   class="w-16 border-2 border-violet-200 rounded-xl px-2 py-1.5 text-sm font-black text-center focus:outline-none focus:border-violet-500">
            <button type="button" onclick="removeReplacement(${i})" class="text-slate-300 hover:text-rose-400 transition-colors font-black">✕</button>`;
        list.appendChild(d);
    });
}
function searchProducts() {
    const q = document.getElementById('product-search-input').value.trim();
    const res = document.getElementById('product-search-results');
    if (q.length < 2) { res.innerHTML = ''; return; }
    fetch(`/project/staff/api/product_search.php?q=${encodeURIComponent(q)}&mode=exchange`)
        .then(r => r.json())
        .then(data => {
            if (!data.length) { res.innerHTML = '<p class="text-slate-300 text-xs font-bold p-3">No products found.</p>'; return; }
            res.innerHTML = data.map(p => `
                <div onclick='pickReplacement(${p.id},${JSON.stringify(p.name)},${parseFloat(p.price)},${parseInt(p.total_qty)||0},{"bulk_qty_half":${parseInt(p.bulk_qty_half)||0},"price_half_box":${parseFloat(p.price_half_box)||0},"bulk_qty_full":${parseInt(p.bulk_qty_full)||0},"price_full_box":${parseFloat(p.price_full_box)||0}})'
                     class="flex items-center justify-between px-4 py-3 hover:bg-violet-50 rounded-xl cursor-pointer transition-colors">
                    <div><p class="font-bold text-slate-700 text-sm">${p.name}</p><p class="text-[10px] text-slate-400">${p.barcode||''} · ${parseInt(p.total_qty)||0} in stock</p></div>
                    <span class="font-black text-emerald-600 text-sm">₱${parseFloat(p.price).toFixed(2)}</span>
                </div>`).join('');
        }).catch(() => { res.innerHTML = '<p class="text-rose-400 text-xs font-bold p-3">Search failed.</p>'; });
}
function toggleRefField(mode) { document.getElementById('ref-field-wrap')?.classList.toggle('hidden', mode !== 'yes'); }
// Enter inside a field must NOT submit the whole exchange — it only commits/clamps
// the value (via blur → onchange). Submitting is done explicitly with the button.
document.getElementById('exchangeForm')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'submit') {
        e.preventDefault();
        e.target.blur();
    }
});
document.getElementById('exchangeForm')?.addEventListener('submit', function(e) {
    const anyReturn = [...document.querySelectorAll('[name$="[selected]"]')].some(c => c.checked);
    if (!anyReturn) { e.preventDefault(); showFlash('Select at least one item to return.', 'error'); return; }
    if (!replacements.length) { e.preventDefault(); showFlash('Add at least one replacement item.', 'error'); return; }

    // Replacements must be DIFFERENT products from the returned items.
    // (Catches the case where a return item is checked AFTER a matching replacement was added.)
    const returnedIds = getReturnedIds();
    const clash = replacements.find(r => returnedIds.has(String(r.product_id)));
    if (clash) {
        e.preventDefault();
        showFlash('"' + clash.name + '" is being returned — replacements must be different items. Use a refund or a new sale for the same item.', 'error');
        return;
    }
    if (document.getElementById('delta-type-hidden').value === 'collect') {
        const m = document.querySelector('[name="payment_mode"]:checked')?.value;
        if (m !== '<?= PAY_METHOD_CASH ?>' && !document.getElementById('ref-field')?.value.trim()) {
            e.preventDefault(); showFlash('Reference number required for digital payment.', 'error'); return;
        }
    }
});
recalcDelta();
</script>

<?php include '../layout_bottom.php'; ?>
