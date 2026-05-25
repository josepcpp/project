<?php
/**
 * export.php — CSV data export endpoint
 * Supports: sales, inventory, refunds
 * Access: admin and above only
 */
include '../../config/db.php';
include '../../config/settings.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$type       = $_GET['type']       ?? '';
$date_from  = $_GET['date_from']  ?? date('Y-m-01');
$date_to    = $_GET['date_to']    ?? date('Y-m-d');
$allowed    = ['sales', 'inventory', 'refunds'];

if (!in_array($type, $allowed)) {
    http_response_code(400);
    exit("Invalid export type.");
}

// ── HELPERS ────────────────────────────────────────────────────────────────────
function csv_row(array $row): string {
    return implode(',', array_map(function($v) {
        $v = str_replace('"', '""', (string)$v);
        return '"' . $v . '"';
    }, $row)) . "\r\n";
}

ob_start();

switch ($type) {

    // ── SALES EXPORT ─────────────────────────────────────────────────────────
    case 'sales':
        $filename = "sales_{$date_from}_to_{$date_to}.csv";
        $q = $conn->prepare("
            SELECT s.receipt_no, s.created_at, s.payment_mode, s.discount_name,
                   s.total, s.cash, s.change_amt, s.reference_no,
                   u.username AS cashier,
                   GROUP_CONCAT(CONCAT(si.qty,'x ',p.name) ORDER BY p.name SEPARATOR ' | ') AS items
            FROM sales s
            LEFT JOIN activity_logs al ON al.item_id = s.id AND al.log_type = '" . LOG_SALES . "'
            LEFT JOIN users u ON u.id = al.user_id
            LEFT JOIN sales_items si ON si.sale_id = s.id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE DATE(s.created_at) BETWEEN ? AND ?
            GROUP BY s.id
            ORDER BY s.created_at DESC
        ");
        $q->bind_param("ss", $date_from, $date_to);
        $q->execute();
        $rows = $q->get_result();

        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        ob_end_clean();
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        echo csv_row(['Receipt No', 'Date & Time', 'Cashier', 'Payment Mode', 'Reference No', 'Discount', 'Total (₱)', 'Cash (₱)', 'Change (₱)', 'Items']);
        while ($r = $rows->fetch_assoc()) {
            echo csv_row([
                $r['receipt_no'],
                $r['created_at'],
                $r['cashier'] ?? '—',
                $r['payment_mode'],
                $r['reference_no'] ?? '',
                $r['discount_name'],
                number_format($r['total'], 2),
                number_format($r['cash'],  2),
                number_format($r['change_amt'], 2),
                $r['items'] ?? '',
            ]);
        }
        exit;

    // ── INVENTORY EXPORT ──────────────────────────────────────────────────────
    case 'inventory':
        $filename = "inventory_" . date('Y-m-d') . ".csv";
        $q = $conn->query("
            SELECT p.barcode, p.name, p.category, p.status,
                   p.quantity, p.price, p.cost_price,
                   ROUND(IF(p.cost_price > 0, (p.price - p.cost_price) / p.cost_price * 100, NULL), 1) AS margin_pct,
                   p.bulk_qty_half, p.price_half_box,
                   p.bulk_qty_full, p.price_full_box,
                   p.expiry_date, p.last_buy_cost,
                   sup.name AS supplier
            FROM products p
            LEFT JOIN suppliers sup ON sup.id = p.supplier_id
            ORDER BY p.name ASC
        ");

        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        ob_end_clean();
        echo "\xEF\xBB\xBF";
        echo csv_row(['Barcode', 'Product Name', 'Category', 'Status', 'Qty', 'Sell Price (₱)', 'Cost Price (₱)', 'Margin %', 'Half Box Qty', 'Half Box Price (₱)', 'Full Box Qty', 'Full Box Price (₱)', 'Expiry Date', 'Last Buy Cost (₱)', 'Supplier']);
        while ($r = $q->fetch_assoc()) {
            echo csv_row([
                $r['barcode'],
                $r['name'],
                $r['category'],
                $r['status'],
                $r['quantity'],
                number_format($r['price'], 2),
                number_format($r['cost_price'], 2),
                $r['margin_pct'] !== null ? $r['margin_pct'] . '%' : 'N/A',
                $r['bulk_qty_half'],
                number_format($r['price_half_box'], 2),
                $r['bulk_qty_full'],
                number_format($r['price_full_box'], 2),
                $r['expiry_date'] ?? '',
                number_format($r['last_buy_cost'], 2),
                $r['supplier'] ?? '',
            ]);
        }
        exit;

    // ── REFUNDS EXPORT ────────────────────────────────────────────────────────
    case 'refunds':
        $filename = "refunds_{$date_from}_to_{$date_to}.csv";
        $q = $conn->prepare("
            SELECT r.id, s.receipt_no, p.name AS product, r.qty, r.disposition,
                   r.amount_refunded, r.status, r.reason,
                   req.username AS requested_by,
                   rev.username AS reviewed_by,
                   r.reject_note, r.override_note,
                   r.created_at, r.reviewed_at
            FROM refunds r
            LEFT JOIN sales s ON s.id = r.sale_id
            LEFT JOIN products p ON p.id = r.product_id
            LEFT JOIN users req ON req.id = r.requested_by
            LEFT JOIN users rev ON rev.id = r.reviewed_by
            WHERE DATE(r.created_at) BETWEEN ? AND ?
            ORDER BY r.created_at DESC
        ");
        $q->bind_param("ss", $date_from, $date_to);
        $q->execute();
        $rows = $q->get_result();

        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        ob_end_clean();
        echo "\xEF\xBB\xBF";
        echo csv_row(['ID', 'Receipt No', 'Product', 'Qty', 'Disposition', 'Amount (₱)', 'Status', 'Reason', 'Requested By', 'Reviewed By', 'Note', 'Requested At', 'Reviewed At']);
        while ($r = $rows->fetch_assoc()) {
            echo csv_row([
                $r['id'],
                $r['receipt_no'],
                $r['product'],
                $r['qty'],
                $r['disposition'],
                number_format($r['amount_refunded'], 2),
                $r['status'],
                $r['reason'],
                $r['requested_by'] ?? '—',
                $r['reviewed_by']  ?? '—',
                $r['reject_note'] ?? $r['override_note'] ?? '',
                $r['created_at'],
                $r['reviewed_at'] ?? '',
            ]);
        }
        exit;
}
