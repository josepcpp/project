<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── One row per product name: current prices + tiers + supplier + cost ────────
$sql = "SELECT
    MIN(p.id)                                                              AS id,
    p.name                                                                 AS product_name,
    MIN(NULLIF(p.barcode,''))                                              AS barcode,
    MAX(p.box_barcode)                                                     AS box_barcode,
    MAX(p.box_units)                                                       AS box_units,
    MAX(p.category)                                                        AS category,
    MAX(p.vat_exempt)                                                      AS vat_exempt,
    MAX(p.price)                                                           AS price,
    MAX(CASE WHEN p.cost_price > 0 THEN p.cost_price ELSE p.last_buy_cost END) AS cost_price,
    MAX(p.bulk_qty_half)                                                   AS bulk_qty_half,
    MAX(p.price_half_box)                                                  AS price_half_box,
    MAX(p.bulk_qty_full)                                                   AS bulk_qty_full,
    MAX(p.price_full_box)                                                  AS price_full_box,
    GROUP_CONCAT(DISTINCT COALESCE(s.name, rb.supplier_name)
                 SEPARATOR ', ')                                           AS supplier_names
FROM products p
LEFT JOIN suppliers s ON p.supplier_id = s.id
LEFT JOIN receiving_batches rb ON rb.id = p.receiving_batch_id
WHERE p.status = '" . PRODUCT_ACTIVE . "'
GROUP BY LOWER(TRIM(p.name))
ORDER BY p.name ASC";

$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// ── Most recent price_history entry per product (for Previous Price + Date) ───
$price_history = [];
if (!empty($rows)) {
    $product_ids = array_map('intval', array_column($rows, 'id'));
    $in = implode(',', $product_ids);
    $ph_q = $conn->query(
        "SELECT ph.product_id, ph.old_price, ph.new_price, ph.change_date
         FROM price_history ph
         INNER JOIN (
             SELECT product_id, MAX(id) AS max_id
             FROM price_history WHERE product_id IN ($in)
             GROUP BY product_id
         ) latest ON ph.id = latest.max_id"
    );
    if ($ph_q) while ($ph = $ph_q->fetch_assoc()) {
        $price_history[intval($ph['product_id'])] = $ph;
    }
}

// ── Stream CSV ────────────────────────────────────────────────────────────────
$filename = 'master_prices_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

fputcsv($out, [
    'Product Name', 'Category', 'VAT Status', 'Barcode', 'Box Barcode', 'Box Units',
    'Supplier(s)', 'Cost Price (PHP)', 'Selling Price (PHP)', 'Markup %',
    'T1 Half-Box Min Qty', 'T1 Half-Box Price (PHP)',
    'T2 Full-Box Min Qty', 'T2 Full-Box Price (PHP)',
    'Previous Price (PHP)', 'Date of Last Price Change',
]);

foreach ($rows as $r) {
    $price  = floatval($r['price']);
    $cost   = floatval($r['cost_price']);
    $markup = ($cost > 0 && $price > 0) ? round(($price - $cost) / $cost * 100, 1) . '%' : '';
    $ph     = $price_history[intval($r['id'])] ?? null;

    fputcsv($out, [
        $r['product_name'],
        $r['category']   ?? '',
        intval($r['vat_exempt']) ? 'Non-VAT' : 'VAT 12%',
        $r['barcode']    ?? '',
        $r['box_barcode'] ?? '',
        intval($r['box_units']) > 1 ? intval($r['box_units']) : '',
        $r['supplier_names'] ?? '',
        $cost  > 0 ? number_format($cost,  2, '.', '') : '',
        $price > 0 ? number_format($price, 2, '.', '') : '',
        $markup,
        intval($r['bulk_qty_half'])  > 0 ? intval($r['bulk_qty_half'])  : '',
        floatval($r['price_half_box']) > 0 ? number_format(floatval($r['price_half_box']), 2, '.', '') : '',
        intval($r['bulk_qty_full'])  > 0 ? intval($r['bulk_qty_full'])  : '',
        floatval($r['price_full_box']) > 0 ? number_format(floatval($r['price_full_box']), 2, '.', '') : '',
        $ph ? number_format(floatval($ph['old_price']), 2, '.', '') : '',
        $ph && !empty($ph['change_date']) ? date('M j, Y', strtotime($ph['change_date'])) : '',
    ]);
}

fclose($out);
exit();
