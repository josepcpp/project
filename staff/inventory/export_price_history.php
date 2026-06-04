<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── All price history events ordered by product name then date ────────────────
$rows = $conn->query(
    "SELECT p.name AS product_name,
            MIN(NULLIF(p.barcode,'')) AS barcode,
            ph.old_price, ph.new_price, ph.change_date
     FROM price_history ph
     JOIN products p ON p.id = ph.product_id
     GROUP BY ph.id, p.name, ph.old_price, ph.new_price, ph.change_date
     ORDER BY p.name ASC, ph.change_date ASC"
);

// ── Stream CSV ────────────────────────────────────────────────────────────────
$filename = 'price_history_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

fputcsv($out, [
    'Product Name', 'Barcode',
    'Old Price (PHP)', 'New Price (PHP)', 'Change Amount (PHP)', 'Change %',
    'Date Changed',
]);

if ($rows) {
    while ($r = $rows->fetch_assoc()) {
        $old = floatval($r['old_price']);
        $new = floatval($r['new_price']);
        $diff = $new - $old;
        $pct  = $old > 0 ? round($diff / $old * 100, 1) . '%' : '';

        fputcsv($out, [
            $r['product_name'],
            $r['barcode'] ?? '',
            number_format($old, 2, '.', ''),
            number_format($new, 2, '.', ''),
            number_format($diff, 2, '.', ''),
            $pct,
            !empty($r['change_date']) ? date('M j, Y g:i A', strtotime($r['change_date'])) : '',
        ]);
    }
}

fclose($out);
exit();
