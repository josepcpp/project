<?php
include '../config/db.php';
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

    // Staff-initiated recount request
    if ($action === 'request_recount' && $role === ROLE_STAFF) {
        $barcode      = trim($_POST['barcode']       ?? '');
        $product_name = trim($_POST['product_name']  ?? '');
        $expected_qty = intval($_POST['expected_qty'] ?? 0);
        $product_id   = intval($_POST['product_id']  ?? 0);
        $uid          = intval($_SESSION['user_id']  ?? 0);

        $ex = $conn->prepare("SELECT id FROM quantity_alerts WHERE barcode = ? AND status IN ('pending','recounting','submitted') LIMIT 1");
        $ex->bind_param("s", $barcode); $ex->execute();
        if ($ex->get_result()->num_rows === 0) {
            $ins = $conn->prepare("INSERT INTO quantity_alerts (product_name, barcode, expected_qty, requested_by, product_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $ins->bind_param("ssiis", $product_name, $barcode, $expected_qty, $uid, $product_id);
            $ins->execute();
            $alert_id = $conn->insert_id;
            $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_INVENTORY . "', ?, ?)");
            $lmsg = "RECOUNT REQUESTED: '{$product_name}' (#{$barcode}) — expected qty: {$expected_qty}";
            $lg->bind_param("iis", $uid, $alert_id, $lmsg); $lg->execute();
        }
        header("Location: stock_management.php?recount_sent=1"); exit();
    }
}

include 'layout_top.php';

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
    $sql = "SELECT p.*, s.name AS supplier_display, s.invoice_number,
                   (SELECT pur.proposed_price
                    FROM price_update_requests pur
                    WHERE pur.product_id = p.id
                      AND pur.status NOT IN ('applied','rejected')
                    LIMIT 1) AS pending_price,
                   COALESCE((
                    SELECT SUM(qa.received_qty)
                    FROM quantity_alerts qa
                    WHERE qa.product_id = p.id
                      AND qa.received_qty IS NOT NULL
                      AND qa.status IN ('pending','recounting','submitted')
                   ), 0) AS recount_pending_qty
            FROM products p
            JOIN suppliers s ON p.supplier_id = s.id
            {$where_sql}
            ORDER BY p.name ASC";
} else {
    // Stock-level HAVING filters applied in PHP after grouping — no HAVING clause here
    $sql = "SELECT MIN(p.id) AS id, p.name,
                   MIN(p.barcode) AS barcode,
                   SUM(p.quantity) AS total_stock,
                   SUM(p.max_quantity) AS total_max,
                   MAX(p.category) AS category,
                   MAX(p.price) AS price,
                   p.supplier_id,
                   s.name AS supplier_display,
                   s.invoice_number,
                   s.created_at AS supplier_date,
                   p.expiry_date AS earliest_expiry,
                   (SELECT pur.proposed_price
                    FROM price_update_requests pur
                    WHERE pur.product_id = MIN(p.id)
                      AND pur.status NOT IN ('applied','rejected')
                    LIMIT 1) AS pending_price,
                   COALESCE((
                    SELECT SUM(qa.received_qty)
                    FROM quantity_alerts qa
                    WHERE qa.barcode = MIN(p.barcode)
                      AND qa.received_qty IS NOT NULL
                      AND qa.status IN ('pending','recounting','submitted')
                   ), 0) AS recount_pending_qty
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

$inv_tab = ($stock_filter === 'archived') ? 'archived' : ($stock_filter === 'recount' ? 'recount' : ($stock_filter === 'disposed' ? 'disposed' : 'live'));

// ── Master view: group per-supplier rows by product name in PHP ───────────────
$product_groups = [];
$product_order  = [];
if (empty($batch_filter) && $inv_tab !== 'recount' && $inv_tab !== 'disposed' && $res) {
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
            'id'                  => intval($row['id']),
            'supplier_name'       => $row['supplier_display'] ?? '—',
            'invoice'             => $row['invoice_number']   ?? '—',
            'date'                => $row['supplier_date']    ?? null,
            'qty'                 => $sup_qty,
            'barcode'             => $row['barcode'],
            'recount_pending_qty' => intval($row['recount_pending_qty'] ?? 0),
            'expiry_date'         => $row['earliest_expiry']  ?? null,
        ];
        $sup_expiry = $row['earliest_expiry'] ?? null;
        if ($sup_expiry) {
            $cur = $product_groups[$key]['earliest_expiry'];
            if (!$cur || $sup_expiry < $cur) $product_groups[$key]['earliest_expiry'] = $sup_expiry;
        }
    }

    // Apply stock-level filter in PHP (replaces SQL HAVING clause)
    if (!in_array($stock_filter, ['', 'archived'])) {
        foreach ($product_order as $i => $key) {
            if (!isset($product_groups[$key])) continue;
            $ts = $product_groups[$key]['total_stock'];
            $tm = $product_groups[$key]['total_max'];
            $rt = $tm > 0 ? (int)floor($tm * DEFAULT_LOW_STOCK_PCT) : $threshold;
            $keep = match($stock_filter) {
                'low'  => $ts > 0 && $ts <= $rt,
                'zero' => $ts <= 0,
                'in'   => $ts > 0 && $ts > $rt,
                default => true,
            };
            if (!$keep) { unset($product_groups[$key]); unset($product_order[$i]); }
        }
        $product_order = array_values($product_order);
    }
}

$result_count = empty($batch_filter) && $inv_tab !== 'recount' && $inv_tab !== 'disposed'
    ? count($product_groups)
    : ($res ? $res->num_rows : 0);
$all_batches  = $conn->query("SELECT id, name, invoice_number, created_at FROM suppliers ORDER BY id DESC LIMIT 100");

// Recount monitoring data
$recount_rows = [];
$recount_count_badge = 0;
$rq_count = $conn->query("SELECT COUNT(*) AS c FROM quantity_alerts WHERE status IN ('pending','recounting','submitted')");
if ($rq_count) $recount_count_badge = intval($rq_count->fetch_assoc()['c'] ?? 0);
if ($inv_tab === 'recount') {
    $rq = $conn->query("SELECT qa.*, u.username AS requester_name
        FROM quantity_alerts qa
        LEFT JOIN users u ON qa.requested_by = u.id
        WHERE qa.status IN ('pending','recounting','submitted')
        ORDER BY FIELD(qa.status,'submitted','recounting','pending'), qa.created_at DESC");
    if ($rq) $recount_rows = $rq->fetch_all(MYSQLI_ASSOC);
}

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

// Has any filter active?
$has_filter = $search !== '' || !empty($batch_filter) || $cat_filter !== '' || $stock_filter !== '';
?>

<div class="max-w-7xl mx-auto space-y-6 animate-in pb-20">

    <?php if (!empty($_GET['recount_sent'])): ?>
    <div class="bg-amber-500 text-white px-8 py-4 rounded-2xl font-black text-sm text-center shadow-lg animate-in">
        Recount request submitted. An admin will review and approve it shortly.
    </div>
    <?php endif; ?>

    <!-- ── SEARCH & FILTER BAR ───────────────────────────────────────────── -->
    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-xl space-y-5">

        <!-- Row 1: Keyword + batch + reset -->
        <div class="flex flex-col lg:flex-row gap-4 items-end">

            <!-- Keyword search -->
            <form method="GET" action="stock_management.php" class="flex-1 w-full" id="searchInventoryForm">
                <label class="label-modern ml-4">Search Inventory</label>
                <div class="flex gap-2 p-1.5 bg-slate-50 rounded-[2rem] border border-slate-100 focus-within:ring-4 focus-within:ring-emerald-500/5 transition-all">
                    <div class="flex-1 relative">
                        <svg class="h-6 w-6 absolute left-4 top-1/2 -translate-y-1/2 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" name="search" id="kw-input" value="<?= htmlspecialchars($search) ?>"
                               placeholder="Name or barcode..."
                               class="w-full bg-transparent border-none py-4 pl-12 pr-4 outline-none font-bold text-slate-600">
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-8 rounded-[1.5rem] font-black uppercase text-xs tracking-widest hover:bg-emerald-600 transition-all">Search</button>
                </div>
                <!-- Hidden holders so form submit carries all current filters -->
                <input type="hidden" name="batch_id" id="kw-batch" value="<?= htmlspecialchars($batch_filter) ?>">
                <input type="hidden" name="cat"      id="kw-cat"   value="<?= htmlspecialchars($cat_filter) ?>">
                <input type="hidden" name="stock"    id="kw-stock" value="<?= htmlspecialchars($stock_filter) ?>">
            </form>

            <!-- Batch / Transaction context -->
            <div class="w-full lg:w-[360px]">
                <label class="label-modern ml-4">Transaction Context</label>
                <select id="filter-batch" class="input-modern w-full h-[64px] bg-white cursor-pointer font-bold text-slate-600 shadow-sm" onchange="submitFilters()">
                    <option value="">-- All Deliveries (Master) --</option>
                    <?php if ($all_batches): while ($b = $all_batches->fetch_assoc()): ?>
                    <option value="<?= $b['id'] ?>" <?= ($batch_filter == $b['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['invoice_number']) ?> — <?= htmlspecialchars($b['name']) ?> (<?= date('M d, Y', strtotime($b['created_at'])) ?>)
                    </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>

            <?php if ($has_filter): ?>
            <button onclick="navigate('stock_management.php')"
                    class="h-[64px] px-8 bg-rose-50 text-rose-500 rounded-[1.5rem] hover:bg-rose-500 hover:text-white transition-all font-black uppercase text-[10px] tracking-widest flex-shrink-0">
                Reset
            </button>
            <?php endif; ?>
        </div>

        <!-- Row 2: Category + Stock Level + Import XML -->
        <div class="flex flex-col sm:flex-row gap-4 items-end">

            <!-- Category filter -->
            <div class="flex-1">
                <label class="label-modern ml-4">Category</label>
                <select id="filter-cat" class="input-modern w-full h-[56px] bg-white cursor-pointer font-bold text-slate-600" onchange="submitFilters()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= ($cat_filter === $cat) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Stock level filter -->
            <div class="flex-1">
                <label class="label-modern ml-4">Stock Level</label>
                <select id="filter-stock" class="input-modern w-full h-[56px] bg-white cursor-pointer font-bold text-slate-600" onchange="submitFilters()">
                    <option value=""         <?= $stock_filter === ''         ? 'selected' : '' ?>>All Stock</option>
                    <option value="in"       <?= $stock_filter === 'in'       ? 'selected' : '' ?>>In Stock</option>
                    <option value="low"      <?= $stock_filter === 'low'      ? 'selected' : '' ?>>Low Stock (≤ 10% of intake)</option>
                    <option value="zero"     <?= $stock_filter === 'zero'     ? 'selected' : '' ?>>Out of Stock</option>
                    <option value="archived" <?= $stock_filter === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>

            <!-- Import XML (admin/superadmin only) -->
            <?php if ($role !== ROLE_STAFF): ?>
            <div class="flex-shrink-0">
                <label class="label-modern ml-4 opacity-0 select-none">Action</label>
                <button onclick="toggleXmlPanel()"
                        class="h-[56px] px-6 bg-blue-50 border border-blue-100 text-blue-600 rounded-[1.5rem] font-black text-[10px] uppercase tracking-widest hover:bg-blue-600 hover:text-white transition-all flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    Import XML
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── XML IMPORT PANEL ──────────────────────────────────────────────── -->
    <?php if ($role !== ROLE_STAFF): ?>
    <div id="xml-panel" class="hidden bg-white rounded-[3rem] border border-blue-100 shadow-2xl overflow-hidden">
        <div class="p-8 border-b border-blue-50 bg-blue-50/30 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-blue-600 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">XML Inventory Import</h4>
                    <p class="text-slate-400 text-[10px] font-bold mt-0.5">Existing barcodes → quantity is <em>added</em>, price updated. New barcodes → inserted as active.</p>
                </div>
            </div>
            <button onclick="toggleXmlPanel()" class="text-slate-300 hover:text-slate-600 transition-colors p-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-0 divide-y lg:divide-y-0 lg:divide-x divide-slate-100">

            <!-- Format spec -->
            <div class="p-8 space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Required XML Format</p>
                    <button onclick="downloadSampleXml()"
                            class="text-[9px] font-black text-blue-500 border border-blue-100 px-3 py-1.5 rounded-xl hover:bg-blue-50 transition-all uppercase tracking-widest">
                        ↓ Download Sample
                    </button>
                </div>
                <pre class="bg-slate-900 text-emerald-400 rounded-2xl p-5 text-[11px] font-mono leading-relaxed overflow-x-auto"><?= htmlspecialchars(
'<?xml version="1.0" encoding="UTF-8"?>
<inventory>
  <product>
    <name>Product Name</name>       <!-- required -->
    <barcode>1234567890</barcode>   <!-- required -->
    <price>29.99</price>            <!-- required -->
    <quantity>100</quantity>        <!-- required -->
    <category>Beverages</category>  <!-- optional -->
  </product>
  <product>
    <name>Another Product</name>
    <barcode>0987654321</barcode>
    <price>15.50</price>
    <quantity>50</quantity>
    <category>Snacks</category>
  </product>
</inventory>'
                ) ?></pre>
                <ul class="space-y-1">
                    <?php foreach ([
                        ['Root element', '<inventory>'],
                        ['Each item',    '<product> or <item>'],
                        ['Required fields', 'name, barcode, price, quantity'],
                        ['Optional',     'category (defaults to "General")'],
                        ['Max file size','2 MB'],
                    ] as [$lbl, $val]): ?>
                    <li class="flex items-center gap-3 text-[11px]">
                        <span class="text-slate-400 font-bold w-28 flex-shrink-0"><?= $lbl ?></span>
                        <code class="text-slate-700 font-black bg-slate-50 px-2 py-0.5 rounded"><?= $val ?></code>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Upload form -->
            <div class="p-8 flex flex-col justify-center space-y-6">
                <form id="xmlImportForm" method="POST" action="inventory_xml_import.php" enctype="multipart/form-data">
                    <div id="xml-drop-zone"
                         class="border-2 border-dashed border-slate-200 rounded-3xl p-10 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50/30 transition-all"
                         ondragover="event.preventDefault(); this.classList.add('border-blue-400','bg-blue-50/30');"
                         ondragleave="this.classList.remove('border-blue-400','bg-blue-50/30');"
                         ondrop="handleXmlDrop(event)"
                         onclick="document.getElementById('xml-file-input').click()">
                        <svg class="w-10 h-10 text-slate-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <p id="xml-drop-label" class="font-black text-slate-400 text-sm">Drop .xml file here or click to browse</p>
                        <p class="text-[10px] text-slate-300 font-bold mt-1">Maximum 2 MB</p>
                        <input type="file" id="xml-file-input" name="xml_file" accept=".xml" class="hidden" onchange="onXmlFileSelected(this)">
                    </div>

                    <button type="submit" id="xml-submit-btn" disabled
                            onclick="return confirmXmlImport(event)"
                            class="mt-5 w-full py-4 rounded-2xl font-black text-sm uppercase tracking-widest transition-all
                                   disabled:bg-slate-100 disabled:text-slate-300 disabled:cursor-not-allowed
                                   bg-blue-600 text-white hover:bg-blue-700 hidden">
                        Import Inventory
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── RESULTS TABLE ─────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden mb-20">
        <div class="p-8 border-b border-slate-100 bg-slate-50/20 flex justify-between items-center flex-wrap gap-3">
            <h4 class="font-black text-slate-800 text-sm uppercase tracking-[0.15em] flex items-center gap-2">
                <span class="w-2.5 h-2.5 <?= $inv_tab === 'archived' ? 'bg-slate-400' : ($inv_tab === 'recount' ? 'bg-amber-500' : ($inv_tab === 'disposed' ? 'bg-orange-500' : (!empty($batch_filter) ? 'bg-blue-500' : 'bg-emerald-500'))) ?> rounded-full shadow-sm"></span>
                <?php
                if ($inv_tab === 'recount')        echo "Pending Recount Items";
                elseif ($inv_tab === 'disposed')   echo "Disposed Items";
                elseif ($inv_tab === 'archived')   echo "Archived Products";
                elseif (!empty($batch_filter))     echo "Voucher: " . htmlspecialchars($active_batch_name);
                elseif ($cat_filter !== '')        echo "Category: " . htmlspecialchars($cat_filter);
                elseif ($stock_filter === 'low')   echo "Low Stock Items";
                elseif ($stock_filter === 'zero')  echo "Out-of-Stock Items";
                elseif ($stock_filter === 'in')    echo "In Stock Items";
                else                               echo "Overall Store Inventory";
                ?>
            </h4>
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex gap-1 bg-slate-100 p-1 rounded-xl">
                    <a href="stock_management.php"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all
                              <?= $inv_tab === 'live' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Live Stock
                    </a>
                    <?php if ($role !== ROLE_STAFF): ?>
                    <a href="stock_management.php?stock=archived"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all
                              <?= $inv_tab === 'archived' ? 'bg-slate-900 text-white shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Archived
                    </a>
                    <?php endif; ?>
                    <a href="stock_management.php?stock=recount"
                       class="px-4 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all flex items-center gap-1.5
                              <?= $inv_tab === 'recount' ? 'bg-amber-500 text-white shadow-sm' : 'text-slate-400 hover:text-slate-600' ?>">
                        Pending Recount
                        <?php if ($recount_count_badge > 0): ?>
                        <span class="<?= $inv_tab === 'recount' ? 'bg-white/30 text-white' : 'bg-amber-500 text-white' ?> text-[8px] font-black px-1.5 py-0.5 rounded-full leading-none"><?= $recount_count_badge ?></span>
                        <?php endif; ?>
                    </a>
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

        <?php if ($inv_tab === 'recount'): ?>
        <!-- ── RECOUNT MONITORING TABLE ────────────────────────────────────── -->
        <table class="table-modern text-left w-full">
            <thead>
                <tr class="bg-amber-50/60">
                    <th class="px-10 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest" width="30%">Product</th>
                    <th class="px-6 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                    <th class="px-6 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Actual Count</th>
                    <th class="px-6 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Variance</th>
                    <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Requested</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
            <?php if (!empty($recount_rows)): foreach ($recount_rows as $rr):
                $status = $rr['status'];
                $status_cfg = [
                    'pending'    => ['label' => 'Awaiting Approval', 'cls' => 'bg-slate-100 text-slate-500'],
                    'recounting' => ['label' => 'Counting in Progress', 'cls' => 'bg-amber-100 text-amber-700'],
                    'submitted'  => ['label' => 'Count Submitted', 'cls' => 'bg-blue-100 text-blue-700'],
                ][$status] ?? ['label' => $status, 'cls' => 'bg-slate-100 text-slate-400'];
                $variance = $rr['actual_qty'] !== null ? intval($rr['variance'] ?? 0) : null;
                $var_color = $variance === null ? 'text-slate-300' : ($variance === 0 ? 'text-emerald-600' : ($variance > 0 ? 'text-rose-600' : 'text-amber-600'));
                $var_label = $variance === null ? '—' : ($variance === 0 ? '0 (exact)' : ($variance > 0 ? "+{$variance} short" : abs($variance) . " over"));
            ?>
            <tr class="hover:bg-amber-50/20 transition-all">
                <td class="px-10 py-6">
                    <p class="font-bold text-slate-800 leading-tight"><?= htmlspecialchars($rr['product_name']) ?></p>
                    <code class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded mt-1 inline-block">#<?= htmlspecialchars($rr['barcode']) ?></code>
                    <?php if ($rr['invoice']): ?>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">INV: <?= htmlspecialchars($rr['invoice']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-6 text-center">
                    <span class="text-[9px] font-black px-3 py-1 rounded-full uppercase tracking-widest <?= $status_cfg['cls'] ?>"><?= $status_cfg['label'] ?></span>
                </td>
                <td class="px-6 py-6 text-center">
                    <span class="text-xl font-black <?= $rr['actual_qty'] !== null ? 'text-blue-600' : 'text-slate-200' ?>">
                        <?= $rr['actual_qty'] !== null ? intval($rr['actual_qty']) : '—' ?>
                    </span>
                    <?php if ($rr['submitted_at']): ?>
                    <p class="text-[9px] text-slate-300 mt-0.5"><?= date('M d, g:i A', strtotime($rr['submitted_at'])) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-6 text-center">
                    <span class="text-base font-black <?= $var_color ?>"><?= $var_label ?></span>
                </td>
                <td class="px-8 py-6">
                    <p class="text-xs font-bold text-slate-600"><?= htmlspecialchars($rr['requester_name'] ?? '—') ?></p>
                    <p class="text-[10px] text-slate-300 mt-0.5"><?= date('M d, Y', strtotime($rr['created_at'])) ?></p>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="5" class="p-20 text-center">
                <p class="font-black text-slate-300 uppercase tracking-widest text-sm">No pending recounts.</p>
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
                    <th class="px-6 py-7 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Expiry</th>
                </tr>
            </thead>
            <tbody>

            <?php if (!empty($batch_filter)): ?>
            <?php /* ── BATCH MODE: one row per product, supplier shown inline ─── */ ?>
            <?php if ($res && $res->num_rows > 0): ?>
                <?php while ($p = $res->fetch_assoc()):
                    $qty         = max(0, intval($p['quantity']) - intval($p['recount_pending_qty'] ?? 0));
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
                        <p class="font-bold text-slate-800 text-lg tracking-tight leading-tight"><?= htmlspecialchars($p['name']) ?></p>
                        <code class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded shadow-sm mt-1 inline-block">#<?= htmlspecialchars($p['barcode']) ?></code>
                        <?php if (!empty($p['supplier_display'])): ?>
                        <p class="text-[10px] text-slate-400 font-bold mt-0.5"><?= htmlspecialchars($p['supplier_display']) ?></p>
                        <?php endif; ?>
                        <?php if ($role === ROLE_STAFF && !$is_archived): ?>
                        <form method="POST" class="mt-2 inline-block">
                            <input type="hidden" name="action"       value="request_recount">
                            <input type="hidden" name="barcode"      value="<?= htmlspecialchars($p['barcode']) ?>">
                            <input type="hidden" name="product_name" value="<?= htmlspecialchars($p['name']) ?>">
                            <input type="hidden" name="expected_qty" value="<?= $qty ?>">
                            <input type="hidden" name="product_id"   value="<?= intval($p['id']) ?>">
                            <button type="submit" class="text-[9px] font-black text-amber-600 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full uppercase tracking-widest hover:bg-amber-500 hover:text-white transition-all">Request Recount</button>
                        </form>
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
                        <p class="font-bold text-slate-800 text-lg tracking-tight leading-tight"><?= htmlspecialchars($g['name']) ?></p>
                        <code class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded shadow-sm mt-1 inline-block">#<?= htmlspecialchars($g['barcode']) ?></code>
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
                        <?php if ($expiry_raw): ?>
                            <span class="font-bold <?= $expiry_cls ?> text-sm whitespace-nowrap"><?= date('M j, Y', strtotime($expiry_raw)) ?></span>
                            <?php if ($expiry_badge): ?><p class="text-[9px] font-black <?= $expiry_cls ?> uppercase tracking-widest mt-1"><?= $expiry_badge ?></p><?php endif; ?>
                        <?php else: ?><span class="text-slate-200 font-black text-sm">—</span><?php endif; ?>
                    </td>
                </tr>

                <!-- Collapsible supplier detail row -->
                <tr id="<?= $row_id ?>" class="hidden border-b border-slate-100">
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
                                        <?php if ($role === ROLE_STAFF && !$is_archived): ?><th class="px-5 py-2.5"></th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                <?php foreach ($g['suppliers'] as $sup):
                                    $sup_net = max(0, $sup['qty'] - intval($sup['recount_pending_qty'] ?? 0));
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
                                    <?php if ($role === ROLE_STAFF && !$is_archived): ?>
                                    <td class="px-5 py-3">
                                        <form method="POST" onclick="event.stopPropagation()">
                                            <input type="hidden" name="action"       value="request_recount">
                                            <input type="hidden" name="barcode"      value="<?= htmlspecialchars($sup['barcode']) ?>">
                                            <input type="hidden" name="product_name" value="<?= htmlspecialchars($g['name']) ?>">
                                            <input type="hidden" name="expected_qty" value="<?= $sup_net ?>">
                                            <input type="hidden" name="product_id"   value="<?= $sup['id'] ?>">
                                            <button type="submit" class="text-[9px] font-black text-amber-600 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full uppercase tracking-widest hover:bg-amber-500 hover:text-white transition-all whitespace-nowrap">Request Recount</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
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
// ── FILTER FORM HELPERS ──────────────────────────────────────────────────────
document.getElementById('searchInventoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    submitFilters(this.search.value);
});

function submitFilters(keyword) {
    var kw    = (keyword !== undefined) ? keyword : document.getElementById('kw-input').value;
    var batch = document.getElementById('filter-batch').value;
    var cat   = document.getElementById('filter-cat').value;
    var stock = document.getElementById('filter-stock').value;
    var url   = 'stock_management.php?search=' + encodeURIComponent(kw)
              + '&batch_id=' + encodeURIComponent(batch)
              + '&cat='      + encodeURIComponent(cat)
              + '&stock='    + encodeURIComponent(stock);
    navigate(url);
}

// ── XML IMPORT PANEL ─────────────────────────────────────────────────────────
function toggleXmlPanel() {
    var panel = document.getElementById('xml-panel');
    if (!panel) return;
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden')) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function onXmlFileSelected(input) {
    var btn   = document.getElementById('xml-submit-btn');
    var label = document.getElementById('xml-drop-label');
    if (input.files && input.files[0]) {
        label.textContent = input.files[0].name;
        btn.disabled = false;
        btn.classList.remove('hidden');
    } else {
        label.textContent = 'Drop .xml file here or click to browse';
        btn.disabled = true;
        btn.classList.add('hidden');
    }
}

function handleXmlDrop(e) {
    e.preventDefault();
    var zone = document.getElementById('xml-drop-zone');
    zone.classList.remove('border-blue-400', 'bg-blue-50/30');
    var dt   = e.dataTransfer;
    if (!dt || !dt.files || !dt.files[0]) return;
    var file = dt.files[0];
    if (!file.name.endsWith('.xml')) {
        showFlash('Only .xml files are accepted.', 'error');
        return;
    }
    var input  = document.getElementById('xml-file-input');
    var dT = new DataTransfer();
    dT.items.add(file);
    input.files = dT.files;
    onXmlFileSelected(input);
}

function confirmXmlImport(e) {
    e.preventDefault();
    customConfirm(
        'This will add quantities to existing products (matched by barcode) and insert new ones. Proceed?',
        'Import XML Inventory?'
    ).then(function(ok) {
        if (ok) {
            var fd = new FormData(document.getElementById('xmlImportForm'));
            navigate('inventory_xml_import.php', fd, false);
        }
    });
    return false;
}

function downloadSampleXml() {
    var sample = '<' + '?xml version="1.0" encoding="UTF-8"?>\n'
               + '<inventory>\n'
               + '  <product>\n'
               + '    <name>Product Name</name>\n'
               + '    <barcode>1234567890</barcode>\n'
               + '    <price>29.99</price>\n'
               + '    <quantity>100</quantity>\n'
               + '    <category>Beverages</category>\n'
               + '  </product>\n'
               + '  <product>\n'
               + '    <name>Another Product</name>\n'
               + '    <barcode>0987654321</barcode>\n'
               + '    <price>15.50</price>\n'
               + '    <quantity>50</quantity>\n'
               + '    <category>Snacks</category>\n'
               + '  </product>\n'
               + '</inventory>';
    var blob = new Blob([sample], { type: 'application/xml' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = 'inventory_import_sample.xml';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

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

<?php include 'layout_bottom.php'; ?>
