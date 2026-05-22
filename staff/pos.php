<?php
include '../config/db.php';
include 'layout_top.php';

$_SESSION['cart'] = $_SESSION['cart'] ?? [];

/**
 * 1. LOGIC: FETCH DATA
 */
$categories = $conn->query("SELECT DISTINCT category FROM products WHERE status = '" . PRODUCT_ACTIVE . "'");
$cur_cat = $_GET['category'] ?? 'All';

$cat_filter = $cur_cat !== 'All' ? " AND p.category = '" . $conn->real_escape_string($cur_cat) . "'" : "";

// Inner query groups products first, then joins aggregated price_update_requests by barcode.
// This avoids JOIN multiplication when multiple pending requests exist for the same product.
$sql = "SELECT p_agg.id, p_agg.name, p_agg.barcode,
        p_agg.quantity, p_agg.price, p_agg.category,
        p_agg.bulk_qty_half, p_agg.bulk_qty_full, p_agg.tiers_locked,
        COALESCE(pur_agg.total_locked, 0) AS locked_qty,
        IF(pur_agg.cnt > 0, 1, 0) AS has_pending_price
        FROM (
            SELECT MIN(p.id) AS id, p.name, MIN(p.barcode) AS barcode,
                   SUM(p.quantity) AS quantity, MAX(p.price) AS price,
                   MAX(p.category) AS category,
                   MAX(p.bulk_qty_half) AS bulk_qty_half, MAX(p.bulk_qty_full) AS bulk_qty_full,
                   MAX(p.tiers_locked) AS tiers_locked
            FROM products p
            WHERE p.status = '" . PRODUCT_ACTIVE . "' AND p.quantity > 0
              AND (p.expiry_date IS NULL OR p.expiry_date > CURDATE())" . $cat_filter . "
            GROUP BY LOWER(TRIM(p.name))
        ) AS p_agg
        LEFT JOIN (
            SELECT barcode, SUM(locked_qty) AS total_locked, COUNT(*) AS cnt
            FROM price_update_requests
            WHERE status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')
            GROUP BY barcode
        ) AS pur_agg ON pur_agg.barcode = p_agg.barcode
        ORDER BY p_agg.name ASC";
$products = $conn->query($sql);

// Calculate Subtotal (No VAT Tax as requested)
$subtotal = 0;
if(!empty($_SESSION['cart'])) {
    foreach($_SESSION['cart'] as $item) {
        $subtotal += $item['line_total'] ?? ($item['price'] * $item['qty']);
    }
}
?>

<div class="flex flex-col lg:flex-row gap-6 h-[calc(100vh-120px)] w-full animate-in">

    <!-- 🟢 LEFT: Product Selection Section -->
    <div class="flex-1 flex flex-col gap-6 overflow-hidden">
        
        <!-- Search & Filter Bar -->
        <div class="bg-white p-4 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col gap-4">
            <div class="relative w-full">
                <span class="absolute left-5 top-1/2 -translate-y-1/2 text-slate-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="pos-search" onkeyup="searchProducts()" placeholder="Find item..." 
                    class="input-modern w-full pl-14 shadow-inner">
            </div>
            
            <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
                <a href="pos.php?category=All" class="px-6 py-2 rounded-full font-black text-[10px] uppercase tracking-widest transition-all <?= ($cur_cat == 'All') ? 'bg-emerald-500 text-white shadow-md' : 'bg-slate-50 text-slate-400 hover:bg-slate-100' ?>">All</a>
                <?php while($cat = $categories->fetch_assoc()): ?>
                    <a href="pos.php?category=<?= urlencode($cat['category']) ?>" 
                       class="px-6 py-2 rounded-full font-black text-[10px] uppercase tracking-widest whitespace-nowrap transition-all <?= ($cur_cat == $cat['category']) ? 'bg-emerald-500 text-white shadow-md' : 'bg-slate-50 text-slate-400 hover:bg-slate-100' ?>">
                       <?= htmlspecialchars($cat['category']) ?>
                    </a>
                <?php endwhile; ?>
            </div>
        </div>

        <!-- Product Grid -->
        <div class="flex-1 overflow-y-auto pr-2 no-scrollbar">
            <div class="grid grid-cols-2 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4" id="product-grid">
                <?php while($p = $products->fetch_assoc()):
                    $effective_qty = max(0, intval($p['quantity']) - intval($p['locked_qty']));
                    if ($effective_qty <= 0) continue;
                    $has_pending = intval($p['has_pending_price']) === 1;
                    $tiers_warn  = intval($p['tiers_locked'])      === 1;
                    $card_border = $tiers_warn  ? 'border-rose-200 bg-rose-50/30' :
                                  ($has_pending ? 'border-amber-200 bg-amber-50/20' : 'border-slate-100');
                ?>
                    <div class="item-box bg-white p-5 rounded-[2rem] border shadow-sm hover:border-emerald-400 transition-all flex flex-col h-full group <?= $card_border ?>">
                        <div class="flex justify-between mb-2">
                            <span class="text-[8px] font-black text-slate-300 uppercase"><?= htmlspecialchars($p['category']) ?></span>
                            <span class="text-[9px] font-black text-emerald-500 bg-emerald-50 px-2 py-0.5 rounded-lg"><?= $effective_qty ?> LEFT</span>
                        </div>

                        <h4 class="product-name font-bold text-slate-800 text-sm leading-tight mb-1 flex-grow"><?= htmlspecialchars($p['name']) ?></h4>
                        <span class="product-barcode hidden"><?= $p['barcode'] ?></span>

                        <?php if ($tiers_warn): ?>
                            <span class="text-[8px] font-black text-rose-500 bg-rose-50 border border-rose-200 px-2 py-0.5 rounded-full mb-2 self-start uppercase tracking-wider">Tiers Under Review</span>
                        <?php elseif ($has_pending): ?>
                            <span class="text-[8px] font-black text-amber-600 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full mb-2 self-start uppercase tracking-wider">Pending Price Update</span>
                        <?php else: ?>
                            <div class="mb-2"></div>
                        <?php endif; ?>

                        <div class="flex items-center justify-between mb-4 mt-auto">
                            <span class="text-xl font-black text-slate-900 tracking-tighter">₱<?= number_format($p['price'], 2) ?></span>
                            <button onclick="quickAdd('<?= $p['barcode'] ?>')" class="w-10 h-10 bg-slate-900 text-white rounded-xl flex items-center justify-center hover:bg-emerald-500 active:scale-95 transition-all shadow-lg shadow-slate-200">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path d="M12 4v16m8-8H4"/></svg>
                            </button>
                        </div>

                        <!-- Bulk Badges -->
                        <?php if($p['bulk_qty_half'] > 0 || $p['bulk_qty_full'] > 0): ?>
                            <div class="grid grid-cols-2 gap-2 border-t border-slate-50 pt-3 mt-auto">
                                <?php if($p['bulk_qty_half'] > 0): ?>
                                    <?php if($tiers_warn): ?>
                                        <button disabled title="Tiers locked — update in Master Price Table" class="bg-slate-100 text-slate-300 rounded-lg py-1.5 font-black text-[8px] uppercase border border-slate-100 cursor-not-allowed">½ BOX</button>
                                    <?php else: ?>
                                        <button onclick="setBulk('<?= $p['barcode'] ?>', <?= $p['bulk_qty_half'] ?>)" class="bg-amber-50 text-amber-600 rounded-lg py-1.5 font-black text-[8px] uppercase border border-amber-100 hover:bg-amber-500 hover:text-white transition-all">½ BOX</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if($p['bulk_qty_full'] > 0): ?>
                                    <?php if($tiers_warn): ?>
                                        <button disabled title="Tiers locked — update in Master Price Table" class="bg-slate-100 text-slate-300 rounded-lg py-1.5 font-black text-[8px] uppercase border border-slate-100 cursor-not-allowed">FULL</button>
                                    <?php else: ?>
                                        <button onclick="setBulk('<?= $p['barcode'] ?>', <?= $p['bulk_qty_full'] ?>)" class="bg-blue-50 text-blue-600 rounded-lg py-1.5 font-black text-[8px] uppercase border border-blue-100 hover:bg-blue-500 hover:text-white transition-all">FULL</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>

    <!-- 🔵 RIGHT: Cart Sidebar Section -->
    <div class="w-full lg:w-[380px] bg-white rounded-[3.5rem] shadow-xl border border-slate-50 flex flex-col overflow-hidden relative" onmouseenter="focusScanField()">
        <div class="p-8 border-b border-slate-50 bg-slate-50/20 space-y-4">
            <div class="flex justify-between items-center">
                <h3 class="serif-title text-2xl font-black text-slate-800">Current Cart</h3>
                <span class="bg-emerald-500 text-white px-3 py-1.5 rounded-full text-[8px] font-black uppercase">Active</span>
            </div>
            <div class="relative">
                <svg class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="1" y="4" width="2" height="16" rx="0.5"/><rect x="5" y="4" width="1" height="16" rx="0.5"/><rect x="8" y="4" width="3" height="16" rx="0.5"/><rect x="13" y="4" width="1" height="16" rx="0.5"/><rect x="16" y="4" width="2" height="16" rx="0.5"/><rect x="20" y="4" width="3" height="16" rx="0.5"/>
                </svg>
                <input type="text" id="barcode-scan-input"
                       autocomplete="off" autocorrect="off" spellcheck="false"
                       placeholder="Scan barcode here..."
                       class="w-full pl-10 pr-4 py-2.5 bg-white border-2 border-slate-200 rounded-2xl text-sm font-mono text-slate-700 outline-none transition-colors duration-300 focus:border-emerald-400"
                       onkeydown="onScanKeydown(event)">
            </div>
        </div>

        <!-- Scrollable Cart Area -->
        <div id="cart-items" class="flex-1 overflow-y-auto p-6 space-y-4 no-scrollbar">
            <?php if(!empty($_SESSION['cart'])): foreach($_SESSION['cart'] as $id => $item): ?>
                <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 shadow-sm group">
                    <div class="flex justify-between items-start mb-3">
                        <h5 class="font-bold text-slate-700 text-sm truncate w-48"><?= htmlspecialchars($item['name']) ?></h5>
                        <button onclick="quickRemove(<?= $id ?>)" class="text-slate-300 hover:text-rose-500 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    
                    <div class="flex justify-between items-center">
                        <!-- 🛠️ PLUS/MINUS QTY CONTROLS -->
                        <div class="flex items-center bg-white rounded-xl border border-slate-200 px-1 shadow-sm">
                            <button onclick="adjustQty(<?= $id ?>, 'minus')" class="w-8 h-8 text-slate-400 font-black hover:text-rose-600 transition-all text-lg">−</button>
                            <input type="number" min="1" value="<?= $item['qty'] ?>"
                                onchange="setQty(<?= $id ?>, this.value)"
                                class="w-12 text-center text-xs font-black text-slate-800 bg-transparent outline-none border-none focus:bg-slate-100 rounded-lg transition-all">
                            <button onclick="adjustQty(<?= $id ?>, 'plus')" class="w-8 h-8 text-slate-400 font-black hover:text-emerald-500 transition-all text-lg">+</button>
                        </div>
                        <span class="font-black text-slate-900 text-lg">₱<?= number_format($item['line_total'], 2) ?></span>
                    </div>
                </div>
            <?php endforeach; else: ?>
                <div class="h-64 flex flex-col items-center justify-center opacity-30 italic">
                     <p class="font-black text-[10px] uppercase tracking-widest text-slate-400">Cart is empty</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- 💰 FOOTER: PROPORTIONATE (Reduced size) -->
        <div class="p-6 bg-slate-900 text-white rounded-t-[3.5rem] shadow-2xl">
            <div class="flex justify-between items-center mb-6 px-2">
                <span class="text-slate-500 text-[10px] font-black uppercase tracking-[0.2em]">Total Payable</span>
                <h2 id="cart-total" class="text-3xl font-black text-emerald-400 tracking-tighter">₱<?= number_format($subtotal, 2) ?></h2>
            </div>
            <a href="checkout.php" class="block w-full bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-black py-4 rounded-2xl text-center shadow-lg transition-all uppercase tracking-widest text-xs">
                PROCEED TO CHECKOUT
            </a>
        </div>
    </div>
</div>

<script>
// ── SEARCH (client-side, no server round-trip) ────────────────────────────────
function searchProducts() {
    var term  = document.getElementById('pos-search').value.toLowerCase();
    document.querySelectorAll('.item-box').forEach(function(item) {
        var name    = item.querySelector('.product-name').innerText.toLowerCase();
        var barcode = item.querySelector('.product-barcode').innerText.toLowerCase();
        item.style.display = (name.includes(term) || barcode.includes(term)) ? 'flex' : 'none';
    });
}

// ── CART FETCH (no navigate, no page reload) ──────────────────────────────────
function cartAction(fd) {
    fetch('pos_process.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(renderCart)
        .catch(function(e) { console.error('Cart error:', e); });
}

function renderCart(data) {
    var cartEl   = document.getElementById('cart-items');
    var totalEl  = document.getElementById('cart-total');

    if (!data.cart || data.cart.length === 0) {
        cartEl.innerHTML =
            '<div class="h-64 flex flex-col items-center justify-center opacity-30 italic">' +
                '<p class="font-black text-[10px] uppercase tracking-widest text-slate-400">Cart is empty</p>' +
            '</div>';
    } else {
        cartEl.innerHTML = data.cart.map(function(item) {
            return (
                '<div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 shadow-sm group">' +
                    '<div class="flex justify-between items-start mb-3">' +
                        '<h5 class="font-bold text-slate-700 text-sm truncate w-48">' + esc(item.name) + '</h5>' +
                        '<button onclick="quickRemove(' + item.id + ')" class="text-slate-300 hover:text-rose-500 transition-colors">' +
                            '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>' +
                        '</button>' +
                    '</div>' +
                    '<div class="flex justify-between items-center">' +
                        '<div class="flex items-center bg-white rounded-xl border border-slate-200 px-1 shadow-sm">' +
                            '<button onclick="adjustQty(' + item.id + ',\'minus\')" class="w-8 h-8 text-slate-400 font-black hover:text-rose-600 transition-all text-lg">−</button>' +
                            '<input type="number" min="1" value="' + item.qty + '" onchange="setQty(' + item.id + ',this.value)" class="w-12 text-center text-xs font-black text-slate-800 bg-transparent outline-none border-none focus:bg-slate-100 rounded-lg transition-all">' +
                            '<button onclick="adjustQty(' + item.id + ',\'plus\')" class="w-8 h-8 text-slate-400 font-black hover:text-emerald-500 transition-all text-lg">+</button>' +
                        '</div>' +
                        '<span class="font-black text-slate-900 text-lg">₱' + item.line_total + '</span>' +
                    '</div>' +
                '</div>'
            );
        }).join('');
    }

    if (totalEl) totalEl.textContent = '₱' + data.subtotal;
    var wasScan = _scanPending;
    _scanPending = false;
    focusScanField();
    if (wasScan) flashScanField(data.found);
}

function esc(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ── CART ACTIONS ──────────────────────────────────────────────────────────────
function quickAdd(barcode) {
    var fd = new FormData();
    fd.append('barcode', barcode);
    fd.append('action', 'add');
    cartAction(fd);
}

function adjustQty(id, action) {
    var fd = new FormData();
    fd.append('id', id);
    fd.append('action', action);
    cartAction(fd);
}

function quickRemove(id) {
    customConfirm('This item will be removed from the cart.', 'Remove Item?').then(function(ok) {
        if (!ok) return;
        var fd = new FormData();
        fd.append('id', id);
        fd.append('action', 'remove');
        cartAction(fd);
    });
}

function setBulk(barcode, qty) {
    var fd = new FormData();
    fd.append('barcode', barcode);
    fd.append('qty_override', qty);
    fd.append('action', 'bulk_add');
    cartAction(fd);
}

function setQty(id, val) {
    var fd = new FormData();
    fd.append('id', id);
    fd.append('qty', val);
    fd.append('action', 'set_qty');
    cartAction(fd);
}

// ── BARCODE SCANNER ───────────────────────────────────────────────────────────
var _scanPending = false;

function focusScanField() {
    var f = document.getElementById('barcode-scan-input');
    if (f && document.activeElement !== f) f.focus();
}

function flashScanField(found) {
    var f = document.getElementById('barcode-scan-input');
    if (!f || found) return;
    f.style.borderColor = '#f43f5e';
    f.placeholder = 'Not found — try again';
    setTimeout(function() {
        f.style.borderColor = '';
        f.placeholder = 'Scan barcode here...';
    }, 1000);
}

function onScanKeydown(e) {
    if (e.key !== 'Enter') return;
    var barcode = e.target.value.trim();
    e.target.value = '';
    if (!barcode) return;
    _scanPending = true;
    quickAdd(barcode);
}

focusScanField();
</script>

<?php include 'layout_bottom.php'; ?>