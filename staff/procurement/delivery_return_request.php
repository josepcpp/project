<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../sales/refund_management.php?tab=delivery");
    exit();
}

$supplier_id   = intval($_POST['supplier_id']   ?? 0);
$supplier_name = trim($_POST['supplier_name']   ?? '');
$invoice_no    = trim($_POST['invoice_no']      ?? '');
$purpose       = trim($_POST['purpose']         ?? '');
$deduct_pay    = isset($_POST['deduct_pay']) ? 1 : 0;
$include       = $_POST['include'] ?? [];
$qty_map       = $_POST['qty']       ?? [];
$reason_map    = $_POST['reason']    ?? [];
$notes_map     = $_POST['notes']     ?? [];
$item_name_map = $_POST['item_name'] ?? [];
$item_price_map= $_POST['item_price'] ?? [];

$user_id = $_SESSION['user_id']  ?? null;
$uname   = $_SESSION['username'] ?? 'Unknown';

$back_url = "../sales/refund_management.php?tab=delivery&invoice_no=" . urlencode($invoice_no);

if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    header("Location: {$back_url}&error=" . urlencode("Security token mismatch. Please try again."));
    exit();
}

if (empty($include)) {
    header("Location: {$back_url}&error=" . urlencode("Please select at least one item to return."));
    exit();
}
if (empty($purpose)) {
    header("Location: {$back_url}&error=" . urlencode("Please provide a purpose for the return."));
    exit();
}
if (!$supplier_id || !$invoice_no) {
    header("Location: {$back_url}&error=" . urlencode("Invalid shipment data."));
    exit();
}

$conn->begin_transaction();
try {
    // Create the request header
    $ins = $conn->prepare("INSERT INTO delivery_return_requests
        (invoice_no, supplier_id, supplier_name, purpose, deduct_pay, requested_by, requested_username)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param("sissiis", $invoice_no, $supplier_id, $supplier_name, $purpose, $deduct_pay, $user_id, $uname);
    $ins->execute();
    $request_id = $conn->insert_id;

    // Insert each selected item
    foreach ($include as $product_id) {
        $product_id = intval($product_id);
        $qty        = max(1, intval($qty_map[$product_id] ?? 1));
        $reason     = trim($reason_map[$product_id] ?? 'Damaged');
        $notes      = trim($notes_map[$product_id]  ?? '');
        $pname      = trim($item_name_map[$product_id] ?? '');
        $uprice     = floatval($item_price_map[$product_id] ?? 0);

        // Validate product belongs to supplier
        $pv = $conn->prepare("SELECT id FROM products WHERE id = ? AND supplier_id = ? LIMIT 1");
        $pv->bind_param("ii", $product_id, $supplier_id); $pv->execute();
        if ($pv->get_result()->num_rows === 0) continue;

        $ii = $conn->prepare("INSERT INTO delivery_return_request_items
            (request_id, product_id, product_name, qty, reason, unit_price, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ii->bind_param("iisisds", $request_id, $product_id, $pname, $qty, $reason, $uprice, $notes);
        $ii->execute();
    }

    // Activity log
    $item_count = count($include);
    $log_msg = "DELIVERY RETURN REQUEST #{$request_id}: {$uname} submitted a return request for {$item_count} item(s) from invoice {$invoice_no} ({$supplier_name}). Purpose: {$purpose}";
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
    if ($log) {
        $log->bind_param("iis", $user_id, $request_id, $log_msg);
        $log->execute();
    }

    $conn->commit();
    header("Location: {$back_url}&success=" . urlencode("Return request submitted. An admin will review and generate the return ticket."));
} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: {$back_url}&error=" . urlencode($e->getMessage()));
}
exit();
