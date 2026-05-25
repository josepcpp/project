<?php
include '../../config/db.php';
include '../../config/settings.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../sales/refund_management.php?tab=delivery");
    exit();
}

$supplier_id = intval($_POST['supplier_id']);
$product_id  = intval($_POST['product_id']);
$return_qty  = intval($_POST['qty']);
$reason      = trim($_POST['reason'] ?? 'Damaged');
$deduct_pay  = isset($_POST['deduct_pay']) ? 1 : 0;
$invoice_no  = trim($_POST['invoice_no'] ?? '');
$user_id     = $_SESSION['user_id'] ?? null;

$back_url = "../sales/refund_management.php?tab=delivery&invoice_no=" . urlencode($invoice_no);

if ($return_qty <= 0) {
    header("Location: {$back_url}&error=" . urlencode("Return quantity must be greater than zero."));
    exit();
}

$conn->begin_transaction();

try {
    // 1. Validate product belongs to this supplier and get current stock
    $p_stmt = $conn->prepare("SELECT name, quantity, price FROM products WHERE id = ? AND supplier_id = ?");
    $p_stmt->bind_param("ii", $product_id, $supplier_id);
    $p_stmt->execute();
    $product = $p_stmt->get_result()->fetch_assoc();

    if (!$product) {
        throw new Exception("Product not found in this shipment.");
    }
    if ($return_qty > $product['quantity']) {
        throw new Exception("Return qty ({$return_qty}) exceeds available stock ({$product['quantity']}).");
    }

    // 2. Reduce product stock; archive if it hits zero
    $new_qty    = $product['quantity'] - $return_qty;
    $new_status = ($new_qty <= 0) ? PRODUCT_ARCHIVED : PRODUCT_ACTIVE;

    $up_prod = $conn->prepare("UPDATE products SET quantity = ?, status = ?, archived_at = IF(? = '" . PRODUCT_ARCHIVED . "', NOW(), archived_at) WHERE id = ?");
    $up_prod->bind_param("isis", $new_qty, $new_status, $new_status, $product_id);
    $up_prod->execute();

    // 3. Record the return
    $ins = $conn->prepare("
        INSERT INTO delivery_returns (supplier_id, product_id, qty, reason, deduct_pay)
        VALUES (?, ?, ?, ?, ?)
    ");
    $ins->bind_param("iiisi", $supplier_id, $product_id, $return_qty, $reason, $deduct_pay);
    $ins->execute();

    // 4. Optionally reduce the outstanding supplier payment
    $deduct_amount = 0;
    if ($deduct_pay) {
        $deduct_amount = $return_qty * floatval($product['price']);
        $pay_upd = $conn->prepare("
            UPDATE supplier_payments
            SET amount = GREATEST(0, amount - ?)
            WHERE supplier_id = ? AND status = '" . SUP_PAY_UNPAID . "'
            ORDER BY id DESC
            LIMIT 1
        ");
        $pay_upd->bind_param("di", $deduct_amount, $supplier_id);
        $pay_upd->execute();
    }

    // 5. Activity log
    $deduct_note = $deduct_pay
        ? " | ₱" . number_format($deduct_amount, 2) . " deducted from unpaid balance."
        : "";
    $log_msg = "DELIVERY RETURN: {$return_qty} pcs of \"{$product['name']}\" returned to supplier."
             . " Reason: {$reason}.{$deduct_note}";

    $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
    if ($log) {
        $log->bind_param("iis", $user_id, $product_id, $log_msg);
        $log->execute();
    }

    $conn->commit();

    $success_msg = "{$return_qty} pcs of \"{$product['name']}\" returned successfully.";
    if ($deduct_pay && $deduct_amount > 0) {
        $success_msg .= " ₱" . number_format($deduct_amount, 2) . " deducted from unpaid balance.";
    }

    header("Location: {$back_url}&success=" . urlencode($success_msg));

} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: {$back_url}&error=" . urlencode($e->getMessage()));
}
exit();
