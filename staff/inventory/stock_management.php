<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$role    = strtolower($_SESSION['role'] ?? ROLE_STAFF);
$user_id = $_SESSION['user_id'] ?? null;

// --- ARCHIVAL / UNARCHIVE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($role !== ROLE_STAFF) {
        if ($action === 'confirm_archive') {
            $p_name = $_POST['product_name'];
            $st = $conn->prepare("UPDATE products SET status='" . PRODUCT_ARCHIVED . "', archived_at = IF(archived_at IS NULL, NOW(), archived_at) WHERE name=?");
            $st->bind_param("s", $p_name);
            $st->execute();
        } elseif ($action === 'unarchive') {
            $p_id = intval($_POST['product_id']);
            $st = $conn->prepare("UPDATE products SET status='" . PRODUCT_ACTIVE . "' WHERE id=?");
            $st->bind_param("i", $p_id);
            $st->execute();
        }
    }

}

include '../layout_top.php';

// ── AUTO-QUEUE EXPIRED ITEMS ──────────────────────────────────────────────────
// Find active products past their expiry date with no open disposal entry,
// and insert a pending disposal request automatically.
$expired_q = $conn->query(
    "SELECT id, name, barcode, quantity, expiry_date
     FROM products
     WHERE status = '" . PRODUCT_ACTIVE . "'
       AND expiry_date IS NOT NULL
       AND expiry_date < CURDATE()
       AND quantity > 0
       AND id NOT IN (
           SELECT product_id FROM product_disposals
           WHERE status IN ('" . DISPOSAL_PENDING . "','" . DISPOSAL_APPROVED . "')
       )"
);
if ($expired_q && $expired_q->num_rows > 0) {
    $auto_ins = $conn->prepare(
        "INSERT INTO product_disposals
             (product_id, product_name, barcode, qty, reason, expiry_date, notes,
              requested_by, requested_username, status)
         VALUES (?, ?, ?, ?, '" . DISPOSE_EXPIRED . "', ?, 'Auto-queued: past expiry date.', ?, 'system', '" . DISPOSAL_PENDING . "')"
    );
    while ($ep = $expired_q->fetch_assoc()) {
        $auto_ins->bind_param("issisi",
            $ep['id'], $ep['name'], $ep['barcode'],
            $ep['quantity'], $ep['expiry_date'],
            $user_id
        );
        $auto_ins->execute();
    }
}

// ── FILTER PARAMS ────────────────────────────────────────────────────────────
$search       = trim($_GET['search']   ?? '');
$batch_filter = $_GET['batch_id']      ?? '';
$cat_filter   = trim($_GET['cat']      ?? '');
$stock_filter = $_GET['stock']         ?? '';   // low | zero | in | archived | ''

// Low-stock threshold
$set_q     = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='low_stock_threshold'");
$threshold = intval($set_q ? ($set_q->fetch_assoc()['setting_value'] ?? 10) : 10);

// Distinct categories for filter dropdown
$cats_q = $conn->query("SELECT DISTINCT TRIM(category) as category FROM products WHERE category != '' AND category IS NOT NULL ORDER BY category ASC");
$categories = [];
if ($cats_q) { while ($c = $cats_q->fetch_assoc()) $categories[] = $c['category']; }

// Active batch details (for header label)
$active_batch_name = '';
$active_batch_inv  = '';
if (!empty($batch_filter)) {
    $bst = $conn->prepare("SELECT name, invoice_number FROM suppliers WHERE id=?");
    $batch_id_int = intval($batch_filter);
    $bst->bind_param("i", $batch_id_int);
    $bst->execute();
    $brow = $bst->get_result()->fetch_assoc();
    $active_batch_name = $brow['name']           ?? 'Unknown';
    $active_batch_inv  = $brow['invoice_number'] ?? 'N/A';
}

// ── DYNAMIC QUERY BUILDER ────────────────────────────────────────────────────
$like   = "%{$search}%";
$wheres = [];
$params = [];
$types  = '';

// Status
$wheres[] = ($stock_filter === 'archived') ? "p.status = '" . PRODUCT_ARCHIVED . "'" : "p.status = '" . PRODUCT_ACTIVE . "'";

// Batch (supplier) filter
if (!empty($batch_filter)) {
    $wheres[] = "p.supplier_id = ?";
    $params[] = intval($batch_filter);
    $types   .= 'i';
}

// Keyword search
if ($search !== '') {
    $wheres[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

// Category filter
if ($cat_filter !== '') {
    $wheres[] = "TRIM(p.category) = ?";
    $params[] = $cat_filter;
    $types   .= 's';
}


$where_sql = "WHERE " . implode(" AND ", $wheres);

// HAVING for stock level (grouped / no-batch mode only)
$having_sql = '';
if (empty($batch_filter) && $stock_filter !== 'archived') {
    if ($stock_filter === 'low')
        $having_sql = "HAVING total_stock > 0 AND ((total_max > 0 AND total_stock <= FLOOR(total_max * " . DEFAULT_LOW_STOCK_PCT . ")) OR (total_max = 0 AND total_stock <= {$threshold}))";
    elseif ($stock_filter === 'zero')
        $having_sql = "HAVING total_stock <= 0";
    elseif ($stock_filter === 'in')
        $having_sql = "HAVING total_stock > 0 AND ((total_max > 0 AND total_stock > FLOOR(total_max * " . DEFAULT_LOW_STOCK_PCT . ")) OR (total_max = 0 AND total_stock > {$threshold}))";
}

// Final SQL
if (!empty($batch_filter)) {
    $sql = "SELECT p.*,
                   COALESCE(s.name, rb.supplier_name) AS supplier_display,
                   COALESCE(s.invoice_number, IF(rb.id IS NOT NULL, CONCAT('Batch #', rb.id), NULL)) AS invoice_number,
                   COALESCE(s.created_at, rb.inventory_pushed_at) AS supplier_date,
                   (SELECT pur.proposed_price
                    FROM price_update_requests pur
                    WHERE pur.product_id = p.id
                      AND pur.status NOT IN ('applied','rejected')
                    LIMIT 1) AS pending_price
            FROM products p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            LEFT JOIN receiving_batches rb ON rb.id = p.receiving_batch_id
            {$where_sql}
            ORDER BY p.name ASC";
} else {
    // Stock-level HAVING filters applied in PHP after grouping — no HAVING clause here
    $sql = "SELECT MIN(p.id) AS id, p.name,
                   MIN(p.barcode) AS barcode,
                   MAX(p.box_barcode) AS box_barcode,
                   MAX(p.box_units)   AS box_units,
                   SUM(p.quantity) AS total_stock,
                   SUM(p.max_quantity) AS total_max,
                   MAX(p.category) AS category,
                   MAX(p.price) AS price,
                   MAX(p.cost_price) AS cost_price,
                   p.supplier_id,
                   COALESCE(s.name,
                       (SELECT supplier_name FROM receiving_batches WHERE id = MIN(p.receiving_batch_id) LIMIT 1)
                   ) AS supplier_display,
                   COALESCE(s.invoice_number,
                       IF(MIN(p.receiving_batch_id) IS NOT NULL, CONCAT('Batch #', MIN(p.receiving_batch_id)), NULL)
                   ) AS invoice_number,
                   COALESCE(s.created_at,
                       (SELECT inventory_pushed_at FROM receiving_batches WHERE id = MIN(p.receiving_batch_id) LIMIT 1)
                   ) AS supplier_date,
                   p.expiry_date AS earliest_expiry,
                   (SELECT pur.proposed_price
                    FROM price_update_requests pur
                    WHERE pur.product_id = MIN(p.id)
                      AND pur.status NOT IN ('applied','rejected')
                    LIMIT 1) AS pending_price
            FROM products p
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            {$where_sql}
            GROUP BY p.supplier_id, LOWER(TRIM(p.name)), p.expiry_date
            ORDER BY p.name ASC, p.expiry_date ASC, total_stock ASC";
}

if ($types === '') {
    $res = $conn->query($sql);
} else {
    $q = $conn->prepare($sql);
    $q->bind_param($types, ...$params);
    $q->execute();
    $res = $q->get_result();
}

$inv_tab = match($stock_filter) {
    'archived' => 'archived',
    'disposed' => 'disposed',
    'critical' => 'critical',
    'fast'     => 'fast',
    'slow'     => 'slow',
    default    => 'live',
};

// ── Master view: group per-supplier rows by product name in PHP ───────────────
$product_groups = [];
$product_order  = [];
if (empty($batch_filter) && !in_array($inv_tab, ['disposed', 'fast', 'slow']) && $res) {
    while ($row = $res->fetch_assoc()) {
        $key = mb_strtolower(trim($row['name']));
        if (!isset($product_groups[$key])) {
            $product_order[]         = $key;
            $product_groups[$key]    = [
                'id'             => $row['id'],
                'name'           => $row['name'],
                'barcode'        => $row['barcode'],
                'category'       => $row['category'],
                'price'          => floatval($row['price']),
                'pending_price'  => $row['pending_price'],
                'total_stock'    => 0,
                'total_max'      => 0,
                'earliest_expiry'=> null,
                'suppliers'      => [],
            ];
        }
        $sup_qty = intval($row['total_stock'] ?? 0);
        $product_groups[$key]['total_stock'] += $sup_qty;
        $product_groups[$key]['total_max']   += intval($row['total_max'] ?? 0);
        $product_groups[$key]['suppliers'][]  = [
            'id'           => intval($row['id']),
            'supplier_name'=> $row['supplier_display'] ?? '—',
            'invoice'      => $row['invoice_number']   ?? '—',
            'date'         => $row['supplier_date']    ?? null,
            'qty'          => $sup_qty,
            'barcode'      => $row['barcode'],
            'expiry_date'  => $row['earliest_expiry']  ?? null,
        ];
        $sup_expiry = $row['earliest_expiry'] ?? null;
        if ($sup_expiry) {
            $cur = $product_groups[$key]['earliest_expiry'];
            if (!$cur || $sup_expiry < $cur) $product_groups[$key]['earliest_expiry'] = $sup_expiry;
        }
    }

    // Apply stock-level filter in PHP (replaces SQL HAVING clause)
    if (!in_array($stock_filter, ['', 'archived', 'fast', 'slow'])) {
        foreach ($product_order as $i => $key) {
            if (!isset($product_groups[$key])) continue;
            $ts = $product_groups[$key]['total_stock'];
            $tm = $product_groups[$key]['total_max'];
            $rt = $tm > 0 ? (int)floor($tm * DEFAULT_LOW_STOCK_PCT) : $threshold;
            $keep = match($stock_filter) {
                'low'      => $ts > 0 && $ts <= $rt,
                'zero'     => $ts <= 0,
                'in'       => $ts > 0 && $ts > $rt,
                'critical' => $ts <= $rt,   // zero + low combined; most urgent first
                default    => true,
            };
            if (!$keep) { unset($product_groups[$key]); unset($product_order[$i]); }
        }
        $product_order = array_values($product_order);
        // Critical: sort by stock level ascending so most urgent rows appear first.
        if ($stock_filter === 'critical') {
            usort($product_order, function ($a, $b) use ($product_groups) {
                return ($product_groups[$a]['total_stock'] ?? 0) <=> ($product_groups[$b]['total_stock'] ?? 0);
            });
        }
    }
}

// ── FAST / SLOW MOVING DATA (last 30 days) ───────────────────────────────────
$fast_rows = [];
$slow_rows = [];
$_sales_sub = "(SELECT si.product_id, SUM(si.qty) AS sold_qty
                FROM sales_items si
                JOIN sales s ON s.id = si.sale_id
                WHERE s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY si.product_id) AS _sa";
if ($inv_tab === 'fast') {
    $fq = $conn->query(
        "SELECT MIN(p.id) AS id, p.name,
                MIN(NULLIF(p.barcode,'')) AS barcode, MAX(p.price) AS price,
                SUM(p.quantity) AS total_stock, COALESCE(SUM(_sa.sold_qty), 0) AS sold_30d
         FROM products p
         LEFT JOIN $_sales_sub ON _sa.product_id = p.id
         WHERE p.status = '" . PRODUCT_ACTIVE . "'
         GROUP BY LOWER(TRIM(p.name))
         HAVING sold_30d > 0
         ORDER BY sold_30d DESC LIMIT 30"
    );
    if ($fq) $fast_rows = $fq->fetch_all(MYSQLI_ASSOC);
}
if ($inv_tab === 'slow') {
    $sq = $conn->query(
        "SELECT MIN(p.id) AS id, p.name,
                MIN(NULLIF(p.barcode,'')) AS barcode, MAX(p.price) AS price,
                SUM(p.quantity) AS total_stock, COALESCE(SUM(_sa.sold_qty), 0) AS sold_30d
         FROM products p
         LEFT JOIN $_sales_sub ON _sa.product_id = p.id
         WHERE p.status = '" . PRODUCT_ACTIVE . "'
         GROUP BY LOWER(TRIM(p.name))
         HAVING total_stock > 0
         ORDER BY sold_30d ASC, total_stock DESC LIMIT 30"
    );
    if ($sq) $slow_rows = $sq->fetch_all(MYSQLI_ASSOC);
}

$result_count = match($inv_tab) {
    'fast'     => count($fast_rows),
    'slow'     => count($slow_rows),
    'disposed' => ($res ? $res->num_rows : 0),
    default    => (empty($batch_filter) ? count($product_groups) : ($res ? $res->num_rows : 0)),
};
$all_batches  = $conn->query("SELECT id, name, invoice_number, created_at FROM suppliers ORDER BY id DESC LIMIT 100");

// Disposal history data
$disposed_rows = [];
if ($inv_tab === 'disposed') {
    $dpr = $conn->query("SELECT d.*, u.full_name AS approver_fullname
        FROM product_disposals d
        LEFT JOIN users u ON d.approved_by = u.id
        WHERE d.status = '" . DISPOSAL_APPROVED . "'
        ORDER BY d.approved_at DESC
        LIMIT 100");
    if ($dpr) $disposed_rows = $dpr->fetch_all(MYSQLI_ASSOC);
}

// Show Reset button only when a keyword search is active.
$has_filter = $search !== '';
?>

<div class="max-w-7xl mx-auto space-y-6 animate-in pb-20">

    <!-- ── SEARCH BAR ────────────────────────────────────────────────────── -->
    <div class="bg-white px-8 py-6 rounded-[3rem] border border-slate-100 shadow-xl">
        <div class="flex gap-3 items-center">
            <form method="GET" action="stock_management.php" class="flex-1" id="searchInventoryForm">
                <div class="flex gap-2 p-1.5 bg-slate-50 rounded-[2rem] border border-slate-100 focus-within:ring-4 focus-within:ring-emerald-500/5 transition-all">
                    <div class="flex-1 relative">
                        <svg class="h-5 w-5 absolute left-4 top-1/2 -translate-y-1/2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input type="text" name="search" id="kw-input" value="<?= htmlspecialchars($search) ?>"
                               data-live="#stockRows"
                               placeholder="Search by product name…"
                               class="w-full bg-transparent border-none py-3.5 pl-11 pr-4 outline-none font-bold text-slate-600 text-sm">
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-6 rounded-[1.5rem] font-black uppercase text-[10px] tracking-widest hover:bg-emerald-600 transition-all">Search</button>
                </div>
                <!-- Preserve current tab when submitting a keyword search -->
                <input type="hidden" name="stock" id="kw-stock" value="<?= htmlspecialchars($stock_filter) ?>">
            </form>

            <a href="export_inventory_csv.php" target="_blank"
               class="flex-shrink-0 h-[52px] px-5 bg-emerald-50 border border-emerald-100 text-emerald-700 rounded-[1.5rem] font-black text-[10px] uppercase tracking-widest hover:bg-emerald-600 hover:text-white transition-all flex items-center gap-2 whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Export CSV
            </a>

            <?php if ($has_filter): ?>
            <button onclick="navigate('stock_management.php')"
                    class="flex-shrink-0 h-[52px] px-5 bg-rose-50 text-rose-500 rounded-[1.5rem] hover:bg-rose-500 hover:text-white transition-all font-black uppercase text-[10px] tracking-widest">
                Reset
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── AWAITING PRICING (draft stock held from POS) ──────────────────── -->
    <?php
    $awaiting_rows = [];
    if ($inv_tab === 'live') {
        $awaiting_q = $conn->query(
            "SELECT name, barcode, draft_reason, SUM(quantity) AS qty, MAX(cost_price) AS cost
             FROM products WHERE status = '" . PRODUCT_DRAFT . "'
             GROUP BY barcode, name, draft_reason
             ORDER BY FIELD(draft_reason,'new','cost_change'), name ASC"
        );
        $awaiting_rows = $awaiting_q ? $awaiting_q->fetch_all(MYSQLI_ASSOC) : [];
    }
    if (!empty($awaiting_rows)):
    ?>
    <div class="bg-white rounded-[3rem] border-2 border-sky-200 shadow-xl overflow-hidden mb-8">
        <div class="px-8 py-6 border-b border-slate-50 bg-sky-50/50 flex items-center gap-3 flex-wrap">
            <span class="w-2.5 h-2.5 bg-sky-500 rounded-full shadow-sm"></span>
            <h4 class="font-black text-slate-800 text-sm uppercase tracking-[0.15em] flex-1">Awaiting Pricing — In Stock, Held from POS</h4>
            <span class="text-[10px] font-black text-sky-700 bg-sky-100 px-3 py-1 rounded-full uppercase tracking-widest"><?= count($awaiting_rows) ?> item<?= count($awaiting_rows) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="divide-y divide-slate-50">
        <?php foreach ($awaiting_rows as $aw):
            $aw_new = ($aw['draft_reason'] ?? 'new') === 'new';
        ?>
        <div class="px-8 py-4 flex items-center gap-4 flex-wrap">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($aw['name']) ?></p>
                    <?php if ($aw_new): ?>
                        <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 uppercase">New Item</span>
                    <?php else: ?>
                        <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 uppercase">Cost Change</span>
                    <?php endif; ?>
                    <code class="text-[10px] font-bold text-slate-400 bg-slate-50 px-2 py-0.5 rounded border">#<?= htmlspecialchars($aw['barcode'] ?? '—') ?></code>
                </div>
            </div>
            <div class="text-center shrink-0">
                <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest">In Stock</p>
                <p class="font-black text-slate-700"><?= intval($aw['qty']) ?></p>
            </div>
            <div class="text-center shrink-0">
                <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest">Supplier Cost</p>
                <p class="font-black text-slate-500">₱<?= number_format(floatval($aw['cost']), 2) ?></p>
            </div>
            <?php if ($role !== ROLE_STAFF): ?>
            <a href="price_maintenance.php" class="bg-sky-600 hover:bg-sky-500 text-white px-5 py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest transition-all shadow-md whitespace-nowrap">
                Set Selling Price →
            </a>
            <?php else: ?>
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Awaiting Admin pricing</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── RESULTS TABLE ─────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden mb-20">
        <div class="p-8 border-b border-slate-100 bg-slate-50/20 flex justify-between items-center flex-wrap gap-3">
            <h4 class="font-black text-slate-800 text-sm uppercase tracking-[0.15em] flex items-center gap-2">
                <?php
                $dot_cls = match($inv_tab) {
                    'archived' => 'bg-slate-400',
                    'disposed' => 'bg-orange-500',
                    'critical' => 'bg-rose-500',
                    'fast'     => 'bg-sky-500',
                    'slow'     => 'bg-amber-500',
                    default    => !empty($batch_filter) ? 'bg-blue-500' : 'bg-emerald-500',
                };
                ?>
                <span class="w-2.5 h-2.5 <?= $dot_cls ?> rounded-full shadow-sm"></span>
                <?php
                if ($inv_tab === 'disposed')       echo "Disposed Items";
                elseif ($inv_tab === 'archived')   echo "Archived Products";
                elseif ($inv_tab === 'critical')   echo "Critical Stock";
                elseif ($inv_tab === 'fast')       echo "Fast Moving — Top 30 (Last 30 Days)";
                elseif ($inv_tab === 'slow')       echo "Slow Moving — Last 30 Days";
                elseif (!empty($batch_filter))     echo "Voucher: " . htmlspecialchars($active_batch_name);
                else                               echo "Overall Store Inventory";
                ?>
            </h4>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex gap-1 bg-slate-100 p-1 rounded-xl flex-wrap">
                    <a href="stock_management.php"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all
                              <?= $inv_tab === 'live' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Live Stock
                    </a>
                    <a href="stock_management.php?stock=critical"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all
                              <?= $inv_tab === 'critical' ? 'bg-rose-500 text-white shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Critical
                    </a>
                    <a href="stock_management.php?stock=fast"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all
                              <?= $inv_tab === 'fast' ? 'bg-sky-500 text-white shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Fast Moving
                    </a>
                    <a href="stock_management.php?stock=slow"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all
                              <?= $inv_tab === 'slow' ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Slow Moving
                    </a>
                    <?php if ($role !== ROLE_STAFF): ?>
                    <a href="stock_management.php?stock=archived"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all
                              <?= $inv_tab === 'archived' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Archived
                    </a>
                    <?php endif; ?>
                    <a href="stock_management.php?stock=disposed"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all
                              <?= $inv_tab === 'disposed' ? 'bg-orange-500 text-white shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Disposed
                    </a>
                </div>
                <span class="text-[10px] font-black text-slate-400 bg-slate-100 px-3 py-1 rounded-full uppercase tracking-widest">
                    <?= $result_count ?> record<?= $result_count !== 1 ? 's' : '' ?>
                </span>
            </div>
        </div>

        <?php if (in_array($inv_tab, ['fast', 'slow'])): ?>
        <!-- ── FAST / SLOW MOVING TABLE ────────────────────────────────────── -->
        <?php
        $mv_rows   = $inv_tab === 'fast' ? $fast_rows : $slow_rows;
        $max_sold  = !empty($mv_rows) ? max(array_column($mv_rows, 'sold_30d')) : 1;
        $max_sold  = max(1, $max_sold);
        $mv_color  = $inv_tab === 'fast' ? ['ring' => 'bg-sky-500', 'bg' => 'bg-sky-50', 'text' => 'text-sky-700']
                                         : ['ring' => 'bg-amber-400', 'bg' => 'bg-amber-50', 'text' => 'text-amber-700'];
        ?>
        <table class="table-modern text-left w-full">
            <thead>
                <tr class="<?= $inv_tab === 'fast' ? 'bg-sky-50/40' : 'bg-amber-50/30' ?>">
                    <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">#</th>
                    <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Product</th>
                    <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Price</th>
                    <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">In Stock</th>
                    <th class="px-6 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Sold (30 days)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
            <?php if (!empty($mv_rows)): foreach ($mv_rows as $rank => $mv):
                $sold    = intval($mv['sold_30d']);
                $stock   = intval($mv['total_stock']);
                $bar_pct = round($sold / $max_sold * 100);
                $stock_cls = $stock <= 0 ? 'text-rose-500' : ($stock <= $threshold ? 'text-amber-500' : 'text-emerald-600');
            ?>
            <tr class="hover:bg-slate-50/40 transition-all">
                <td class="px-10 py-5 text-[10px] font-black text-slate-300"><?= $rank + 1 ?></td>
                <td class="px-6 py-5">
                    <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($mv['name']) ?></p>
                    <?php if (!empty($mv['barcode'])): ?>
                    <code class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded mt-0.5 inline-block">#<?= htmlspecialchars($mv['barcode']) ?></code>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-5 text-center font-black text-slate-700">
                    <?= floatval($mv['price']) > 0 ? '₱' . number_format(floatval($mv['price']), 2) : '<span class="text-slate-200">—</span>' ?>
                </td>
                <td class="px-6 py-5 text-center">
                    <span class="text-2xl font-black <?= $stock_cls ?>"><?= number_format($stock) ?></span>
                    <?php if ($stock <= 0): ?><p class="text-[9px] font-black text-rose-400 uppercase tracking-widest mt-0.5">Out</p>
                    <?php elseif ($stock <= $threshold): ?><p class="text-[9px] font-black text-amber-400 uppercase tracking-widest mt-0.5">Low</p>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-5">
                    <div class="flex items-center gap-3">
                        <span class="font-black text-lg <?= $mv_color['text'] ?> w-12 text-right flex-shrink-0"><?= number_format($sold) ?></span>
                        <div class="flex-1 bg-slate-100 rounded-full h-2 overflow-hidden">
                            <div class="<?= $mv_color['ring'] ?> h-2 rounded-full transition-all" style="width:<?= $bar_pct ?>%"></div>
                        </div>
                        <span class="text-[10px] text-slate-400 font-bold w-8 flex-shrink-0"><?= $bar_pct ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" class="p-20 text-center">
                <p class="font-black text-slate-300 uppercase tracking-widest text-sm">
                    <?= $inv_tab === 'fast' ? 'No sales recorded in the last 30 days.' : 'No products in stock.' ?>
                </p>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php elseif ($inv_tab === 'disposed'): ?>
        <!-- ── DISPOSED ITEMS TABLE ─────────────────────────────────────────── -->
        <table class="table-modern text-left w-full">
            <thead>
                <tr class="bg-orange-50/60">
                    <th class="px-10 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Product</th>
                    <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Qty</th>
                    <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Reason</th>
                    <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Expiry Date</th>
                    <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Requested By</th>
                    <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Approved By</th>
                    <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
            <?php if (!empty($disposed_rows)): foreach ($disposed_rows as $dr):
                $dr_reason_cls = match($dr['reason']) {
                    'Expired'      => 'bg-orange-50 text-orange-600 border-orange-100',
                    'Contaminated' => 'bg-rose-50 text-rose-600 border-rose-100',
                    'Damaged'      => 'bg-amber-50 text-amber-600 border-amber-100',
                    'Spoiled'      => 'bg-red-50 text-red-600 border-red-100',
                    default        => 'bg-slate-100 text-slate-500 border-slate-200',
                };
            ?>
            <tr class="hover:bg-orange-50/10 transition-all">
                <td class="px-10 py-5">
                    <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($dr['product_name']) ?></p>
                    <code class="text-xs text-slate-400 font-mono">#<?= htmlspecialchars($dr['barcode']) ?></code>
                    <?php if ($dr['notes']): ?>
                    <p class="text-xs text-slate-500 mt-0.5 italic"><?= htmlspecialchars($dr['notes']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-5 text-center font-black text-slate-700 text-xl"><?= intval($dr['qty']) ?></td>
                <td class="px-6 py-5 text-center">
                    <span class="text-xs font-black px-4 py-1.5 rounded-full border <?= $dr_reason_cls ?> uppercase tracking-wide whitespace-nowrap"><?= htmlspecialchars($dr['reason']) ?></span>
                </td>
                <td class="px-6 py-5 text-center">
                    <?php if ($dr['expiry_date']): ?>
                        <span class="font-bold text-rose-500 text-sm"><?= date('M j, Y', strtotime($dr['expiry_date'])) ?></span>
                    <?php else: ?>
                        <span class="text-slate-200 font-bold">—</span>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-5 text-center">
                    <p class="font-bold text-slate-700 text-sm"><?= htmlspecialchars($dr['requested_username'] ?? '—') ?></p>
                </td>
                <td class="px-6 py-5 text-center">
                    <p class="font-bold text-slate-700 text-sm"><?= htmlspecialchars($dr['approver_fullname'] ?: ($dr['approved_username'] ?? '—')) ?></p>
                </td>
                <td class="px-6 py-5 text-center">
                    <p class="text-slate-400 text-xs font-bold"><?= $dr['approved_at'] ? date('M j, Y', strtotime($dr['approved_at'])) : date('M j, Y', strtotime($dr['created_at'])) ?></p>
                    <p class="text-slate-300 text-[10px]"><?= $dr['approved_at'] ? date('g:i A', strtotime($dr['approved_at'])) : '' ?></p>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="7" class="p-20 text-center">
                <p class="font-black text-slate-300 uppercase tracking-widest text-sm">No disposed items on record.</p>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php else: ?>
        <!-- ── NORMAL INVENTORY TABLE ───────────────────────────────────────── -->
        <table class="table-modern text-left w-full">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="px-10 py-7 text-[10px] font-black text-slate-400 uppercase tracking-widest">Product</th>
                    <th class="px-6 py-7 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Category</th>
                    <th class="px-6 py-7 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">
                        <?= !empty($batch_filter) ? 'Qty in Batch' : 'Total Stock' ?>
                    </th>
                    <th class="px-6 py-7 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Price</th>
                    <th class="px-6 py-7 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Margin</th>
                    <th class="px-6 py-7 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Expiry</th>
                </tr>
            </thead>
            <tbody id="stockRows">

            <?php if (!empty($batch_filter)): ?>
            <?php /* ── BATCH MODE: one row per product, supplier shown inline ─── */ ?>
            <?php if ($res && $res->num_rows > 0): ?>
                <?php while ($p = $res->fetch_assoc()):
                    $qty         = max(0, intval($p['quantity']));
                    $price       = floatval($p['price'] ?? 0);
                    $cat         = htmlspecialchars($p['category'] ?? '—');
                    $is_archived = $stock_filter === 'archived';
                    $row_max     = intval($p['max_quantity'] ?? 0);
                    $row_threshold = $row_max > 0 ? (int)floor($row_max * DEFAULT_LOW_STOCK_PCT) : $threshold;
                    $qty_color = 'text-slate-700';
                    if (!$is_archived) {
                        if     ($qty <= 0)              $qty_color = 'text-rose-600';
                        elseif ($qty <= $row_threshold) $qty_color = 'text-amber-600';
                        else                            $qty_color = 'text-emerald-600';
                    }
                    $expiry_raw = $p['expiry_date'] ?? null;
                    $expiry_cls = 'text-slate-200'; $expiry_badge = '';
                    if ($expiry_raw) {
                        $days_left = (int)ceil((strtotime($expiry_raw) - strtotime('today')) / 86400);
                        if ($days_left < 0)      { $expiry_cls = 'text-rose-600';  $expiry_badge = 'Expired'; }
                        elseif ($days_left === 0) { $expiry_cls = 'text-rose-600';  $expiry_badge = 'Today'; }
                        elseif ($days_left <= DEFAULT_EXPIRY_WARNING_DAYS)  { $expiry_cls = 'text-amber-600'; $expiry_badge = "In {$days_left}d"; }
                        else                      { $expiry_cls = 'text-slate-500'; }
                    }
                ?>
                <tr class="hover:bg-slate-50/50 transition-all border-b border-slate-50">
                    <td class="px-10 py-7">
                        <p class="live-name font-bold text-slate-800 text-lg tracking-tight leading-tight"><?= htmlspecialchars($p['name']) ?></p>
                        <code class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded shadow-sm mt-1 inline-block">#<?= htmlspecialchars($p['barcode']) ?></code>
                        <?php if (!empty($p['supplier_display'])): ?>
                        <p class="text-[10px] text-slate-400 font-bold mt-0.5"><?= htmlspecialchars($p['supplier_display']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-7 text-center">
                        <?php if ($p['category'] && $p['category'] !== '—'): ?>
                        <span class="text-[10px] font-black text-slate-500 bg-slate-100 px-3 py-1 rounded-full uppercase tracking-widest"><?= $cat ?></span>
                        <?php else: ?><span class="text-slate-200 font-black text-sm">—</span><?php endif; ?>
                    </td>
                    <td class="px-6 py-7 text-center">
                        <span class="text-4xl font-black <?= $qty_color ?> tracking-tighter"><?= number_format($qty) ?></span>
                        <?php if (!$is_archived && $qty > 0 && $qty <= $row_threshold): ?><p class="text-[9px] font-black text-amber-500 uppercase tracking-widest mt-1">Low</p>
                        <?php elseif (!$is_archived && $qty <= 0): ?><p class="text-[9px] font-black text-rose-500 uppercase tracking-widest mt-1">Out</p><?php endif; ?>
                    </td>
                    <td class="px-6 py-7 text-center font-black text-slate-700 text-base">
                        <?php if ($price > 0): ?>₱<?= number_format($price, 2) ?>
                            <?php if (!empty($p['pending_price'])): ?><p class="text-[9px] font-black text-amber-500 uppercase tracking-widest mt-1">→ ₱<?= number_format(floatval($p['pending_price']), 2) ?></p><?php endif; ?>
                        <?php else: ?><span class="text-slate-200 font-black">—</span><?php endif; ?>
                    </td>
                    <td class="px-6 py-7 text-center">
                        <?php
                        $cost  = floatval($p['cost_price'] ?? 0);
                        $margin_pct = ($cost > 0 && $price > 0) ? (($price - $cost) / $cost * 100) : null;
                        if ($margin_pct !== null):
                            $m_cls = $margin_pct >= 20 ? 'text-emerald-600 bg-emerald-50' : ($margin_pct >= 5 ? 'text-amber-600 bg-amber-50' : 'text-rose-500 bg-rose-50');
                        ?>
                            <span class="text-xs font-black px-2 py-1 rounded-full <?= $m_cls ?>"><?= number_format($margin_pct, 1) ?>%</span>
                            <p class="text-[9px] text-slate-400 font-bold mt-1">Cost ₱<?= number_format($cost, 2) ?></p>
                        <?php else: ?>
                            <span class="text-slate-200 font-black text-sm">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-7 text-center">
                        <?php if ($expiry_raw): ?>
                            <span class="font-bold <?= $expiry_cls ?> text-sm whitespace-nowrap"><?= date('M j, Y', strtotime($expiry_raw)) ?></span>
                            <?php if ($expiry_badge): ?><p class="text-[9px] font-black <?= $expiry_cls ?> uppercase tracking-widest mt-1"><?= $expiry_badge ?></p><?php endif; ?>
                        <?php else: ?><span class="text-slate-200 font-black text-sm">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5" class="p-32 text-center">
                    <h3 class="text-xl font-black text-slate-300 uppercase tracking-widest">No results found.</h3>
                    <button onclick="navigate('stock_management.php')" class="mt-6 text-xs font-black text-blue-500 uppercase hover:underline">Clear all filters</button>
                </td></tr>
            <?php endif; ?>

            <?php else: ?>
            <?php /* ── MASTER MODE: one collapsed row per product, expandable supplier detail ── */ ?>
            <?php if (!empty($product_groups)): ?>
                <?php foreach ($product_order as $gkey):
                    if (!isset($product_groups[$gkey])) continue;
                    $g           = $product_groups[$gkey];
                    $is_archived = $stock_filter === 'archived';
                    $total_qty   = $g['total_stock'];
                    $total_max   = $g['total_max'];
                    $price       = $g['price'];
                    $row_threshold = $total_max > 0 ? (int)floor($total_max * DEFAULT_LOW_STOCK_PCT) : $threshold;
                    $qty_color = 'text-slate-700';
                    if (!$is_archived) {
                        if     ($total_qty <= 0)              $qty_color = 'text-rose-600';
                        elseif ($total_qty <= $row_threshold) $qty_color = 'text-amber-600';
                        else                                  $qty_color = 'text-emerald-600';
                    }
                    $sup_count   = count($g['suppliers']);
                    $row_id      = 'sup-' . $g['id'];
                    $expiry_raw  = $g['earliest_expiry'] ?? null;
                    $expiry_cls  = 'text-slate-200'; $expiry_badge = '';
                    if ($expiry_raw) {
                        $days_left = (int)ceil((strtotime($expiry_raw) - strtotime('today')) / 86400);
                        if ($days_left < 0)      { $expiry_cls = 'text-rose-600';  $expiry_badge = 'Expired'; }
                        elseif ($days_left === 0) { $expiry_cls = 'text-rose-600';  $expiry_badge = 'Today'; }
                        elseif ($days_left <= DEFAULT_EXPIRY_WARNING_DAYS)  { $expiry_cls = 'text-amber-600'; $expiry_badge = "In {$days_left}d"; }
                        else                      { $expiry_cls = 'text-slate-500'; }
                    }
                ?>
                <!-- Master product row -->
                <tr class="hover:bg-slate-50/40 transition-all border-b border-slate-50 cursor-pointer" onclick="toggleSuppliers('<?= $g['id'] ?>')">
                    <td class="px-10 py-6">
                        <p class="live-name font-bold text-slate-800 text-lg tracking-tight leading-tight"><?= htmlspecialchars($g['name']) ?></p>
                        <?php if (!empty($g['barcode'])): ?>
                        <code class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded shadow-sm mt-1 inline-block" title="Per-item barcode">#<?= htmlspecialchars($g['barcode']) ?></code>
                        <?php endif; ?>
                        <?php if (!empty($g['box_barcode'])): ?>
                        <code class="text-[10px] font-bold text-amber-600 bg-amber-50 px-2 py-0.5 rounded shadow-sm mt-1 inline-block" title="Box barcode (<?= intval($g['box_units']) ?> per box)">📦 <?= htmlspecialchars($g['box_barcode']) ?></code>
                        <?php endif; ?>
                        <button type="button" id="sup-btn-<?= $g['id'] ?>"
                                onclick="event.stopPropagation(); toggleSuppliers('<?= $g['id'] ?>')"
                                class="ml-2 inline-flex items-center gap-1 text-[9px] font-black text-slate-500 bg-slate-100 hover:bg-slate-200 border border-slate-200 px-2.5 py-1 rounded-full uppercase tracking-widest transition-all">
                            <span><?= $sup_count ?> supplier<?= $sup_count !== 1 ? 's' : '' ?></span>
                            <svg id="sup-chevron-<?= $g['id'] ?>" class="w-2.5 h-2.5 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </td>
                    <td class="px-6 py-6 text-center">
                        <?php if ($g['category'] && $g['category'] !== '—'): ?>
                        <span class="text-[10px] font-black text-slate-500 bg-slate-100 px-3 py-1 rounded-full uppercase tracking-widest"><?= htmlspecialchars($g['category']) ?></span>
                        <?php else: ?><span class="text-slate-200 font-black text-sm">—</span><?php endif; ?>
                    </td>
                    <td class="px-6 py-6 text-center">
                        <span class="text-4xl font-black <?= $qty_color ?> tracking-tighter"><?= number_format($total_qty) ?></span>
                        <?php if (!$is_archived && $total_qty > 0 && $total_qty <= $row_threshold): ?><p class="text-[9px] font-black text-amber-500 uppercase tracking-widest mt-1">Low</p>
                        <?php elseif (!$is_archived && $total_qty <= 0): ?><p class="text-[9px] font-black text-rose-500 uppercase tracking-widest mt-1">Out</p><?php endif; ?>
                    </td>
                    <td class="px-6 py-6 text-center font-black text-slate-700 text-base">
                        <?php if ($price > 0): ?>₱<?= number_format($price, 2) ?>
                            <?php if (!empty($g['pending_price'])): ?><p class="text-[9px] font-black text-amber-500 uppercase tracking-widest mt-1">→ ₱<?= number_format(floatval($g['pending_price']), 2) ?></p><?php endif; ?>
                        <?php else: ?><span class="text-slate-200 font-black">—</span><?php endif; ?>
                    </td>
                    <td class="px-6 py-6 text-center">
                        <?php
                        $cost = floatval($g['cost_price'] ?? 0);
                        $margin_pct = ($cost > 0 && $price > 0) ? (($price - $cost) / $cost * 100) : null;
                        if ($margin_pct !== null):
                            $m_cls = $margin_pct >= 20 ? 'text-emerald-600 bg-emerald-50' : ($margin_pct >= 5 ? 'text-amber-600 bg-amber-50' : 'text-rose-500 bg-rose-50');
                        ?>
                            <span class="text-xs font-black px-2 py-1 rounded-full <?= $m_cls ?>"><?= number_format($margin_pct, 1) ?>%</span>
                            <p class="text-[9px] text-slate-400 font-bold mt-1">Cost ₱<?= number_format($cost, 2) ?></p>
                        <?php else: ?>
                            <span class="text-slate-200 font-black text-sm">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-6 text-center">
                        <?php if ($expiry_raw): ?>
                            <span class="font-bold <?= $expiry_cls ?> text-sm whitespace-nowrap"><?= date('M j, Y', strtotime($expiry_raw)) ?></span>
                            <?php if ($expiry_badge): ?><p class="text-[9px] font-black <?= $expiry_cls ?> uppercase tracking-widest mt-1"><?= $expiry_badge ?></p><?php endif; ?>
                        <?php else: ?><span class="text-slate-200 font-black text-sm">—</span><?php endif; ?>
                    </td>
                </tr>

                <!-- Collapsible supplier detail row -->
                <tr id="<?= $row_id ?>" data-live-detail class="hidden border-b border-slate-100">
                    <td colspan="5" class="px-10 pb-5 pt-0">
                        <div class="bg-slate-50 border border-slate-100 rounded-2xl overflow-hidden">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="bg-slate-100/60 border-b border-slate-200/50">
                                        <th class="px-5 py-2.5 font-black text-slate-400 uppercase tracking-widest">Supplier</th>
                                        <th class="px-5 py-2.5 font-black text-slate-400 uppercase tracking-widest">Invoice</th>
                                        <th class="px-5 py-2.5 font-black text-slate-400 uppercase tracking-widest text-center">Date Delivered</th>
                                        <th class="px-5 py-2.5 font-black text-slate-400 uppercase tracking-widest text-center">Remaining</th>
                                        <th class="px-5 py-2.5 font-black text-slate-400 uppercase tracking-widest text-center">Expiry</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                <?php foreach ($g['suppliers'] as $sup):
                                    $sup_net = max(0, $sup['qty']);
                                    $sup_color = $sup_net <= 0 ? 'text-rose-500' : ($sup_net <= $row_threshold ? 'text-amber-600' : 'text-slate-700');
                                    $s_exp = $sup['expiry_date'] ?? null;
                                    $s_exp_cls = 'text-slate-300'; $s_exp_badge = '';
                                    if ($s_exp) {
                                        $s_days = (int)ceil((strtotime($s_exp) - strtotime('today')) / 86400);
                                        if ($s_days < 0)      { $s_exp_cls = 'text-rose-600';  $s_exp_badge = 'Expired'; }
                                        elseif ($s_days === 0){ $s_exp_cls = 'text-rose-600';  $s_exp_badge = 'Today'; }
                                        elseif ($s_days <= 7) { $s_exp_cls = 'text-amber-600'; $s_exp_badge = "In {$s_days}d"; }
                                        else                  { $s_exp_cls = 'text-slate-500'; }
                                    }
                                ?>
                                <tr class="hover:bg-white/70 transition-all">
                                    <td class="px-5 py-3 font-bold text-slate-700"><?= htmlspecialchars($sup['supplier_name']) ?></td>
                                    <td class="px-5 py-3 font-mono text-slate-400 text-[10px] uppercase"><?= htmlspecialchars($sup['invoice']) ?></td>
                                    <td class="px-5 py-3 text-center text-slate-500 font-bold">
                                        <?= $sup['date'] ? date('M d, Y', strtotime($sup['date'])) : '—' ?>
                                    </td>
                                    <td class="px-5 py-3 text-center font-black text-lg <?= $sup_color ?>"><?= number_format($sup_net) ?></td>
                                    <td class="px-5 py-3 text-center">
                                        <?php if ($s_exp): ?>
                                            <span class="font-bold <?= $s_exp_cls ?> whitespace-nowrap"><?= date('M j, Y', strtotime($s_exp)) ?></span>
                                            <?php if ($s_exp_badge): ?><span class="ml-1 text-[8px] font-black <?= $s_exp_cls ?> uppercase tracking-widest"><?= $s_exp_badge ?></span><?php endif; ?>
                                        <?php else: ?><span class="text-[9px] font-black text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full uppercase tracking-widest">No Expiry Set</span><?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" class="p-32 text-center">
                    <h3 class="text-xl font-black text-slate-300 uppercase tracking-widest">No results found.</h3>
                    <button onclick="navigate('stock_management.php')" class="mt-6 text-xs font-black text-blue-500 uppercase hover:underline">Clear all filters</button>
                </td></tr>
            <?php endif; ?>
            <?php endif; /* end batch/master split */ ?>

            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
// ── SEARCH FORM ──────────────────────────────────────────────────────────────
document.getElementById('searchInventoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var kw    = document.getElementById('kw-input').value;
    var stock = document.getElementById('kw-stock').value;
    var url   = 'stock_management.php?search=' + encodeURIComponent(kw)
              + (stock ? '&stock=' + encodeURIComponent(stock) : '');
    navigate(url);
});

// ── SUPPLIER DETAIL TOGGLE ───────────────────────────────────────────────────
function toggleSuppliers(id) {
    var row     = document.getElementById('sup-' + id);
    var chevron = document.getElementById('sup-chevron-' + id);
    if (!row) return;
    var hidden = row.classList.toggle('hidden');
    if (chevron) chevron.style.transform = hidden ? '' : 'rotate(180deg)';
}

// ── ARCHIVE / UNARCHIVE HANDLERS ─────────────────────────────────────────────
function handleArchiveSubmit(e, form) {
    e.preventDefault();
    customConfirm('This product will be archived and hidden from POS.', 'Archive Product?').then(function(ok) {
        if (ok) navigate('stock_management.php', new FormData(form));
    });
}

function handleUnarchive(e, form) {
    e.preventDefault();
    customConfirm('Restore this product to active inventory?', 'Restore Product?').then(function(ok) {
        if (ok) navigate('stock_management.php', new FormData(form));
    });
}
</script>

<?php
// ── DISPOSAL QUEUE (admin/superadmin only) ────────────────────────────────────
if (in_array($role, ROLES_ADMIN_AND_UP)):
    $disposal_q = $conn->query(
        "SELECT pd.*, u.full_name AS requester_fullname
         FROM product_disposals pd
         LEFT JOIN users u ON u.id = pd.requested_by
         WHERE pd.status = '" . DISPOSAL_PENDING . "'
         ORDER BY pd.created_at ASC"
    );
    $disposal_pending = $disposal_q ? $disposal_q->fetch_all(MYSQLI_ASSOC) : [];
    if (!empty($disposal_pending)):
?>
<div class="max-w-7xl mx-auto mt-10 space-y-4 animate-in">
    <div class="flex items-center justify-between">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Disposal Queue</p>
        <span class="bg-orange-500 text-white text-[9px] font-black px-3 py-1 rounded-full"><?= count($disposal_pending) ?> pending</span>
    </div>
    <div class="bg-white rounded-[2.5rem] border border-orange-100 shadow-xl overflow-hidden">
        <div class="divide-y divide-slate-50">
        <?php foreach ($disposal_pending as $dp):
            $reason_cfg = match($dp['reason']) {
                'Expired'      => 'bg-orange-50 text-orange-600 border-orange-100',
                'Contaminated' => 'bg-rose-50 text-rose-600 border-rose-100',
                'Damaged'      => 'bg-amber-50 text-amber-600 border-amber-100',
                'Spoiled'      => 'bg-red-50 text-red-600 border-red-100',
                default        => 'bg-slate-100 text-slate-500 border-slate-200',
            };
        ?>
        <div class="px-8 py-5 flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 flex-wrap">
                    <p class="font-black text-slate-800"><?= htmlspecialchars($dp['product_name']) ?></p>
                    <span class="text-[9px] font-black px-2.5 py-1 rounded-full border <?= $reason_cfg ?> uppercase tracking-wider"><?= htmlspecialchars($dp['reason']) ?></span>
                </div>
                <div class="flex items-center gap-4 mt-1 text-[10px] text-slate-400 font-bold flex-wrap">
                    <span>Qty: <span class="text-slate-700 font-black"><?= intval($dp['qty']) ?></span></span>
                    <?php if ($dp['expiry_date']): ?><span>Expiry: <?= date('M j, Y', strtotime($dp['expiry_date'])) ?></span><?php endif; ?>
                    <span>Requested by <?= htmlspecialchars($dp['requester_fullname'] ?: $dp['requested_username']) ?></span>
                    <span><?= date('M j, Y g:i A', strtotime($dp['created_at'])) ?></span>
                </div>
                <?php if ($dp['notes']): ?>
                <p class="text-[10px] text-slate-400 italic mt-0.5">"<?= htmlspecialchars($dp['notes']) ?>"</p>
                <?php endif; ?>
            </div>
            <div class="flex gap-2 flex-shrink-0">
                <form method="POST" action="disposal_approve.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="disposal_id" value="<?= $dp['id'] ?>">
                    <button type="submit"
                            onclick="return confirm('Approve disposal of <?= intval($dp['qty']) ?> pcs of \'<?= htmlspecialchars(addslashes($dp['product_name'])) ?>\'?')"
                            class="bg-orange-500 hover:bg-orange-600 text-white font-black text-xs px-5 py-2.5 rounded-xl uppercase tracking-widest transition-all">
                        Approve
                    </button>
                </form>
                <form method="POST" action="disposal_approve.php" class="flex gap-2 items-center">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="disposal_id" value="<?= $dp['id'] ?>">
                    <input type="text" name="reject_reason" placeholder="Reason..." required
                           class="input-modern text-xs py-2 w-36">
                    <button type="submit"
                            class="bg-slate-200 hover:bg-rose-500 hover:text-white text-slate-600 font-black text-xs px-5 py-2.5 rounded-xl uppercase tracking-widest transition-all">
                        Reject
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; endif; ?>

<?php include '../layout_bottom.php'; ?>
