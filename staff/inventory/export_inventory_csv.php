<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_ADMIN, ROLE_OWNER, ROLE_SUPERADMIN, ROLE_STAFF, ROLE_RECEIVER, ROLE_VALIDATOR, ROLE_PRICE_CHECKER]);

// 30-day sales aggregate per product name
$sales_sub = "(SELECT LOWER(TRIM(p2.name)) AS pname, SUM(si.qty) AS sold_30d
               FROM sales_items si
               JOIN sales s  ON s.id  = si.sale_id
               JOIN products p2 ON p2.id = si.product_id
               WHERE s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               GROUP BY LOWER(TRIM(p2.name))) AS _mv";

$low_threshold_q = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='low_stock_threshold'");
$threshold = intval($low_threshold_q ? ($low_threshold_q->fetch_assoc()['setting_value'] ?? 10) : 10);

$rows = $conn->query(
    "SELECT MIN(p.id) AS id,
            p.name,
            MIN(NULLIF(p.barcode,''))     AS barcode,
            MAX(p.box_barcode)            AS box_barcode,
            MAX(p.category)               AS category,
            MAX(p.price)                  AS price,
            MAX(p.cost_price)             AS cost_price,
            SUM(p.quantity)               AS total_stock,
            MAX(p.status)                 AS status,
            MIN(p.expiry_date)            AS earliest_expiry,
            COALESCE(_mv.sold_30d, 0)     AS sold_30d
     FROM products p
     LEFT JOIN $sales_sub ON _mv.pname = LOWER(TRIM(p.name))
     WHERE p.status IN ('" . PRODUCT_ACTIVE . "','" . PRODUCT_ARCHIVED . "')
     GROUP BY LOWER(TRIM(p.name)), _mv.sold_30d
     ORDER BY p.status ASC, p.name ASC"
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="inventory_export_' . date('Ymd_His') . '.csv"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel opens it correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Name', 'Barcode', 'Box Barcode', 'Category', 'Selling Price', 'Cost Price',
               'Total Stock', 'Status', 'Earliest Expiry', 'Sold (Last 30 Days)', 'Movement Tag']);

if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $stock   = intval($r['total_stock']);
        $sold    = intval($r['sold_30d']);
        $status  = ucfirst($r['status'] ?? '');

        // Movement classification
        if ($r['status'] === PRODUCT_ARCHIVED) {
            $tag = 'Archived';
        } elseif ($stock <= 0) {
            $tag = 'Out of Stock';
        } elseif ($stock <= $threshold) {
            $tag = 'Critical';
        } elseif ($sold >= 20) {
            $tag = 'Fast Moving';
        } elseif ($sold <= 2) {
            $tag = 'Slow Moving';
        } else {
            $tag = 'Normal';
        }

        fputcsv($out, [
            $r['name'],
            $r['barcode']       ?? '',
            $r['box_barcode']   ?? '',
            $r['category']      ?? '',
            number_format(floatval($r['price']),      2, '.', ''),
            number_format(floatval($r['cost_price']), 2, '.', ''),
            $stock,
            $status,
            $r['earliest_expiry'] ?? '',
            $sold,
            $tag,
        ]);
    }
}

fclose($out);
exit();
