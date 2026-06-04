<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Filters (all optional) ────────────────────────────────────────────────────
$cat_filter     = trim($_GET['cat']           ?? '');
$search_filter  = trim($_GET['search']        ?? '');
$include_draft  = ($_GET['include_draft']     ?? '1') !== '0';

// ── Active products query ─────────────────────────────────────────────────────
$where_parts = ["p.status = '" . PRODUCT_ACTIVE . "'"];
$params = [];
$types  = '';

if ($cat_filter !== '') {
    $where_parts[] = "TRIM(p.category) = ?";
    $params[] = $cat_filter;
    $types   .= 's';
}
if ($search_filter !== '') {
    $where_parts[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
    $like = '%' . $search_filter . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$where_sql = "WHERE " . implode(" AND ", $where_parts);

$sql = "SELECT
    LOWER(TRIM(p.name))                                                    AS name_key,
    p.name,
    MIN(NULLIF(p.barcode,''))                                              AS barcode,
    MAX(p.box_barcode)                                                     AS box_barcode,
    MAX(p.category)                                                        AS category,
    MAX(p.vat_exempt)                                                      AS vat_exempt,
    SUM(p.quantity)                                                        AS active_qty,
    SUM(p.max_quantity)                                                    AS max_qty,
    MAX(p.price)                                                           AS selling_price,
    MAX(CASE WHEN p.cost_price > 0 THEN p.cost_price ELSE p.last_buy_cost END) AS cost_price,
    GROUP_CONCAT(DISTINCT NULLIF(p.expiry_date,'')
                 ORDER BY p.expiry_date ASC SEPARATOR ' / ')               AS expiry_dates,
    GROUP_CONCAT(DISTINCT COALESCE(s.name, rb.supplier_name)
                 SEPARATOR ', ')                                           AS suppliers
FROM products p
LEFT JOIN suppliers s ON p.supplier_id = s.id
LEFT JOIN receiving_batches rb ON rb.id = p.receiving_batch_id
$where_sql
GROUP BY LOWER(TRIM(p.name))
ORDER BY p.name ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$active_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Draft/held quantities per product name ────────────────────────────────────
$draft_qtys = [];
if ($include_draft) {
    $dq = $conn->query(
        "SELECT LOWER(TRIM(name)) AS name_key, SUM(quantity) AS draft_qty
         FROM products WHERE status = '" . PRODUCT_DRAFT . "'
         GROUP BY LOWER(TRIM(name))"
    );
    if ($dq) while ($d = $dq->fetch_assoc()) $draft_qtys[$d['name_key']] = intval($d['draft_qty']);
}

// ── Low-stock threshold ───────────────────────────────────────────────────────
$thr_q     = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='low_stock_threshold'");
$threshold = intval($thr_q ? ($thr_q->fetch_assoc()['setting_value'] ?? DEFAULT_LOW_STOCK_THRESHOLD) : DEFAULT_LOW_STOCK_THRESHOLD);

// ── Stream CSV ────────────────────────────────────────────────────────────────
$filename = 'stock_level_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

$out = fopen('php://output', 'w');

fputcsv($out, [
    'Product Name', 'Barcode', 'Box Barcode', 'Category', 'VAT Status', 'Supplier(s)',
    'Active Qty', 'Draft/Held Qty', 'Total Qty', 'Low-Stock Threshold', 'Stock Status',
    'Expiry Dates', 'Cost Price (PHP)', 'Selling Price (PHP)',
    'Stock Value at Cost (PHP)', 'Stock Value at Retail (PHP)', 'Markup %',
]);

foreach ($active_rows as $r) {
    $name_key   = $r['name_key'];
    $active_qty = intval($r['active_qty']);
    $draft_qty  = $include_draft ? ($draft_qtys[$name_key] ?? 0) : 0;
    $total_qty  = $active_qty + $draft_qty;
    $cost       = floatval($r['cost_price']);
    $price      = floatval($r['selling_price']);
    $max_qty    = intval($r['max_qty']);
    $thr        = $max_qty > 0 ? (int)floor($max_qty * DEFAULT_LOW_STOCK_PCT) : $threshold;

    if ($active_qty <= 0)        $stock_status = 'Out of Stock';
    elseif ($active_qty <= $thr) $stock_status = 'Low Stock';
    else                         $stock_status = 'In Stock';
    if ($draft_qty > 0)          $stock_status .= ' + Held';

    $markup    = ($cost > 0 && $price > 0) ? round(($price - $cost) / $cost * 100, 1) . '%' : '';
    $val_cost  = ($cost > 0)  ? number_format($active_qty * $cost,  2, '.', '') : '';
    $val_retail = ($price > 0) ? number_format($active_qty * $price, 2, '.', '') : '';

    fputcsv($out, [
        $r['name'],
        $r['barcode']      ?? '',
        $r['box_barcode']  ?? '',
        $r['category']     ?? '',
        intval($r['vat_exempt']) ? 'Non-VAT' : 'VAT 12%',
        $r['suppliers']    ?? '',
        $active_qty,
        $draft_qty,
        $total_qty,
        $thr,
        $stock_status,
        $r['expiry_dates'] ?? '',
        $cost  > 0 ? number_format($cost,  2, '.', '') : '',
        $price > 0 ? number_format($price, 2, '.', '') : '',
        $val_cost,
        $val_retail,
        $markup,
    ]);
}

fclose($out);
exit();
