<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Get current user for the Audit Trail
$user_id = $_SESSION['user_id'] ?? null;

// SAFETY CHECK
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /project/staff/sales/payments.php");
    exit();
}

/**
 * 🛠️ Phase 4 Handler
 */

// --- ACTION: MARK AS PAID ---
if (isset($_POST['action']) && $_POST['action'] === 'paid') {
    $payment_id = intval($_POST['payment_id']);

    $stmt = $conn->prepare("UPDATE payments SET status = '" . SUP_PAY_PAID . "' WHERE id = ?");
    if (!$stmt) { header("Location: /project/staff/sales/payments.php?error=" . urlencode("DB error. Please try again.")); exit(); }
    $stmt->bind_param("i", $payment_id);

    if ($stmt->execute()) {
        $log_msg = "Payment record ID #$payment_id marked as PAID.";
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PAYMENTS . "', ?, ?)");
        if ($log) { $log->bind_param("iis", $user_id, $payment_id, $log_msg); $log->execute(); }
    }
}

// --- ACTION: MARK AS UNPAID (REVERSAL) ---
elseif (isset($_POST['action']) && $_POST['action'] === 'unpaid') {
    $payment_id = intval($_POST['payment_id']);

    $stmt = $conn->prepare("UPDATE payments SET status = '" . SUP_PAY_UNPAID . "' WHERE id = ?");
    if (!$stmt) { header("Location: /project/staff/sales/payments.php?error=" . urlencode("DB error. Please try again.")); exit(); }
    $stmt->bind_param("i", $payment_id);

    if ($stmt->execute()) {
        $log_msg = "REVERSAL: Payment record ID #$payment_id reverted to UNPAID.";
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PAYMENTS . "', ?, ?)");
        if ($log) { $log->bind_param("iis", $user_id, $payment_id, $log_msg); $log->execute(); }
    }
}

// --- ACTION: ADD NEW PAYMENT RECORD ---
else {
    $supplier_id = intval($_POST['supplier_id']);
    $amount      = floatval($_POST['amount']);

    $invoice_no = PREFIX_PAYMENT . date('Ymd') . "-" . strtoupper(substr(uniqid(), -4));

    $stmt = $conn->prepare("INSERT INTO payments (supplier_id, invoice_no, amount, status) VALUES (?, ?, ?, '" . SUP_PAY_UNPAID . "')");
    if (!$stmt) { header("Location: /project/staff/sales/payments.php?error=" . urlencode("DB error. Please try again.")); exit(); }
    $stmt->bind_param("isd", $supplier_id, $invoice_no, $amount);

    if ($stmt->execute()) {
        $new_payment_id = $conn->insert_id;
        $log_msg = "Generated new outward payment invoice: $invoice_no for Amount: ₱" . number_format($amount, 2);
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PAYMENTS . "', ?, ?)");
        if ($log) { $log->bind_param("iis", $user_id, $new_payment_id, $log_msg); $log->execute(); }
    }
}

// REDIRECT BACK TO PAYMENTS PAGE
header("Location: /project/staff/sales/payments.php");
exit();