<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);
if (session_status() === PHP_SESSION_NONE) session_start();

// ── Date filter (optional) ────────────────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');

$where_date = '';
$params = [];
$types  = '';
if ($date_from !== '') {
    $where_date .= " AND sp.verified_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types   .= 's';
}
if ($date_to !== '') {
    $where_date .= " AND sp.verified_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types   .= 's';
}

// ── Load all paid batches ─────────────────────────────────────────────────────
$sql = "SELECT
    rb.id                   AS batch_id,
    rb.supplier_name,
    rb.supplier_contact,
    rb.control_subtotal,
    rb.computed_subtotal,
    rb.validated_at,
    u.username              AS receiver_name,
    vu.username             AS validator_name,
    sp.net_amount,
    sp.receipt_subtotal,
    sp.damage_deduction,
    COALESCE(sp.supplier_discount, 0) AS supplier_discount,
    sp.payment_reference,
    sp.payment_method,
    sp.notes                AS payment_notes,
    sp.verified_by_username,
    sp.verified_at
FROM procurement_payments sp
JOIN receiving_batches rb ON rb.id = sp.batch_id
LEFT JOIN users u  ON u.id  = rb.receiver_id
LEFT JOIN users vu ON vu.id = rb.validator_id
WHERE sp.status = 'paid' $where_date
ORDER BY rb.supplier_name ASC, sp.verified_at ASC";

$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Stream CSV even if empty ──────────────────────────────────────────────────
$filename = 'supplier_payments_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

if (empty($payments)) {
    fputcsv($out, ['No paid supplier payments found for the selected period.']);
    fclose($out);
    exit();
}

// ── Load items for all batches ────────────────────────────────────────────────
$batch_ids = array_map('intval', array_column($payments, 'batch_id'));
$in = implode(',', $batch_ids);
$items_by_batch = [];
$ir = $conn->query(
    "SELECT batch_id, barcode, description, quantity, damaged_qty, damage_notes,
            expiry_date, base_price, amount
     FROM receiving_items WHERE batch_id IN ($in) ORDER BY batch_id, id ASC"
);
if ($ir) while ($row = $ir->fetch_assoc()) $items_by_batch[$row['batch_id']][] = $row;

// ── Build supplier summary ────────────────────────────────────────────────────
$supplier_summary = [];
foreach ($payments as $p) {
    $sup = $p['supplier_name'];
    if (!isset($supplier_summary[$sup])) {
        $supplier_summary[$sup] = [
            'name'           => $sup,
            'contact'        => $p['supplier_contact'] ?? '',
            'total_paid'     => 0.0,
            'payment_count'  => 0,
            'last_paid_at'   => '',
            'paid_by'        => [],
        ];
    }
    $supplier_summary[$sup]['total_paid']    += floatval($p['net_amount']);
    $supplier_summary[$sup]['payment_count']++;
    if (($p['verified_at'] ?? '') > $supplier_summary[$sup]['last_paid_at']) {
        $supplier_summary[$sup]['last_paid_at'] = $p['verified_at'];
    }
    $payer = $p['verified_by_username'] ?? '';
    if ($payer !== '' && !in_array($payer, $supplier_summary[$sup]['paid_by'])) {
        $supplier_summary[$sup]['paid_by'][] = $payer;
    }
}

// ── Date range label for report header ───────────────────────────────────────
$range_label = ($date_from !== '' || $date_to !== '')
    ? 'Period: ' . ($date_from !== '' ? $date_from : 'All') . ' to ' . ($date_to !== '' ? $date_to : 'All')
    : 'Period: All Time';

fputcsv($out, ['Supplier Payments Export — ' . date('M j, Y g:i A')]);
fputcsv($out, [$range_label]);
fputcsv($out, []);

// ── BLOCK 1: Supplier Summary ─────────────────────────────────────────────────
fputcsv($out, ['=== SUPPLIER SUMMARY ===']);
fputcsv($out, ['Supplier Name', 'Contact', 'Total Paid (PHP)', 'No. of Payments', 'Last Payment Date', 'Paid By']);
foreach ($supplier_summary as $s) {
    fputcsv($out, [
        $s['name'],
        $s['contact'],
        number_format($s['total_paid'], 2, '.', ''),
        $s['payment_count'],
        $s['last_paid_at'] !== '' ? date('M j, Y', strtotime($s['last_paid_at'])) : '',
        implode(', ', $s['paid_by']),
    ]);
}
fputcsv($out, []);

// ── BLOCK 2: Payment Records ──────────────────────────────────────────────────
fputcsv($out, ['=== PAYMENT RECORDS ===']);
fputcsv($out, [
    'Supplier Name', 'Supplier Contact', 'Payment Date', 'Voucher #',
    'Payment Method', 'Payment Reference',
    'Receipt Amount (PHP)', 'Damage Deduction (PHP)', 'Supplier Discount (PHP)', 'Net Paid (PHP)',
    'Receiver', 'Validator', 'Validated Date',
    'Paid By', 'Notes',
]);
foreach ($payments as $p) {
    fputcsv($out, [
        $p['supplier_name'],
        $p['supplier_contact'] ?? '',
        $p['verified_at'] !== null ? date('M j, Y', strtotime($p['verified_at'])) : '',
        '#' . $p['batch_id'],
        $p['payment_method']       ?? '',
        $p['payment_reference']    ?? '',
        number_format(floatval($p['receipt_subtotal']), 2, '.', ''),
        number_format(floatval($p['damage_deduction']), 2, '.', ''),
        number_format(floatval($p['supplier_discount']), 2, '.', ''),
        number_format(floatval($p['net_amount']), 2, '.', ''),
        $p['receiver_name']        ?? '',
        $p['validator_name']       ?? '',
        $p['validated_at'] !== null ? date('M j, Y', strtotime($p['validated_at'])) : '',
        $p['verified_by_username'] ?? '',
        $p['payment_notes']        ?? '',
    ]);
}
fputcsv($out, []);

// ── BLOCK 3: Item Details ─────────────────────────────────────────────────────
fputcsv($out, ['=== ITEM DETAILS ===']);
fputcsv($out, [
    'Supplier Name', 'Payment Date', 'Voucher #',
    'Product Description', 'Barcode',
    'Good Qty', 'Damaged Qty', 'Damage Notes',
    'Expiry Date', 'Unit Cost (PHP)', 'Line Total (PHP)',
]);
foreach ($payments as $p) {
    $items = $items_by_batch[$p['batch_id']] ?? [];
    if (empty($items)) {
        fputcsv($out, [
            $p['supplier_name'],
            $p['verified_at'] !== null ? date('M j, Y', strtotime($p['verified_at'])) : '',
            '#' . $p['batch_id'],
            '(no items recorded)', '', '', '', '', '', '', '',
        ]);
        continue;
    }
    foreach ($items as $it) {
        fputcsv($out, [
            $p['supplier_name'],
            $p['verified_at'] !== null ? date('M j, Y', strtotime($p['verified_at'])) : '',
            '#' . $p['batch_id'],
            $it['description'],
            $it['barcode']      ?? '',
            intval($it['quantity']),
            intval($it['damaged_qty'] ?? 0),
            $it['damage_notes'] ?? '',
            !empty($it['expiry_date']) ? date('M j, Y', strtotime($it['expiry_date'])) : '',
            $it['base_price'] !== null ? number_format(floatval($it['base_price']), 2, '.', '') : '',
            $it['amount']     !== null ? number_format(floatval($it['amount']),     2, '.', '') : '',
        ]);
    }
}

fclose($out);
exit();
