<?php
/**
 * exchange_process.php — Processes an item exchange transaction.
 * - Returns selected items (restores stock)
 * - Dispenses replacement items (deducts stock)
 * - Handles even exchanges and delta (collect/refund)
 * - Logs to activity_logs and exchanges table
 */
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../config/settings.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: exchange.php");
    exit();
}

$sale_id      = intval($_POST['sale_id'] ?? 0);
$receipt_no   = trim($_POST['receipt_no'] ?? '');
$delta_type   = in_array($_POST['delta_type'] ?? '', ['none','collect','refund']) ? $_POST['delta_type'] : 'none';
$delta_amount = round(floatval($_POST['delta_amount'] ?? 0), 2);
$payment_mode = trim($_POST['payment_mode'] ?? PAY_METHOD_CASH);
$reference_no = !empty($_POST['reference_no']) ? trim($_POST['reference_no']) : null;
$notes        = trim($_POST['notes'] ?? '');

$return_items  = $_POST['return_items']  ?? [];
$new_items     = $_POST['new_items']     ?? [];

// Filter only selected return items
$selected_returns = array_filter($return_items, fn($r) => !empty($r['selected']));

if (!$sale_id || empty($selected_returns) || empty($new_items)) {
    header("Location: exchange.php?error=" . urlencode("Invalid exchange submission."));
    exit();
}

$conn->begin_transaction();
try {

    // 1. Verify the original sale exists
    $sq = $conn->prepare("SELECT id, receipt_no FROM sales WHERE id = ? LIMIT 1");
    $sq->bind_param("i", $sale_id); $sq->execute();
    $sale = $sq->get_result()->fetch_assoc();
    if (!$sale) throw new Exception("Original sale not found.");

    // 2. Generate exchange number
    $exchange_no = "EXC-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -4));

    // 3. Insert exchange header
    $ex_stmt = $conn->prepare("INSERT INTO exchanges
        (exchange_no, original_sale_id, original_receipt_no, delta_type, delta_amount, payment_mode, reference_no, processed_by, processed_username, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $ex_stmt->bind_param("ssssdssiss", $exchange_no, $sale_id, $receipt_no, $delta_type, $delta_amount, $payment_mode, $reference_no, $user_id, $username, $notes);
    $ex_stmt->execute();
    $exchange_id = $conn->insert_id;

    // 4. Process RETURNED items — restore stock, insert exchange_items (direction=return)
    foreach ($selected_returns as $r) {
        $product_id = intval($r['product_id']);
        $qty        = max(1, intval($r['qty']));
        $unit_price = floatval($r['unit_price']);
        $line_total = $qty * $unit_price;

        // Validate: cannot return more than was bought on this sale
        $si_q = $conn->prepare("SELECT qty FROM sales_items WHERE sale_id = ? AND product_id = ? LIMIT 1");
        $si_q->bind_param("ii", $sale_id, $product_id); $si_q->execute();
        $si = $si_q->get_result()->fetch_assoc();
        if (!$si || $qty > intval($si['qty']))
            throw new Exception("Return quantity exceeds purchased quantity for product ID $product_id.");

        // Restore stock; reactivate if was archived-at-zero
        $rs = $conn->prepare("UPDATE products SET quantity = quantity + ?, status = '" . PRODUCT_ACTIVE . "', archived_at = NULL WHERE id = ?");
        $rs->bind_param("ii", $qty, $product_id); $rs->execute();

        // Log exchange item
        $ei = $conn->prepare("INSERT INTO exchange_items (exchange_id, direction, product_id, product_name, qty, unit_price, line_total)
            SELECT ?, 'return', p.id, p.name, ?, ?, ? FROM products p WHERE p.id = ?");
        $ei->bind_param("iiddi", $exchange_id, $qty, $unit_price, $line_total, $product_id); $ei->execute();
    }

    // 5. Process OUTGOING (replacement) items — deduct stock, insert exchange_items (direction=outgoing)
    foreach ($new_items as $n) {
        $product_id = intval($n['product_id']);
        $qty        = max(1, intval($n['qty']));
        $unit_price = floatval($n['unit_price']);
        $line_total = $qty * $unit_price;

        // Check stock availability
        $pq = $conn->prepare("SELECT name, quantity, status FROM products WHERE id = ? AND status = '" . PRODUCT_ACTIVE . "' LIMIT 1");
        $pq->bind_param("i", $product_id); $pq->execute();
        $prod = $pq->get_result()->fetch_assoc();
        if (!$prod) throw new Exception("Replacement product ID $product_id is not available.");
        if ($prod['quantity'] < $qty) throw new Exception("Insufficient stock for \"" . $prod['name'] . "\" (need $qty, have {$prod['quantity']}).");

        // Deduct stock; archive if zero
        $new_qty = $prod['quantity'] - $qty;
        $new_status = ($new_qty <= 0) ? PRODUCT_ARCHIVED : PRODUCT_ACTIVE;
        $ds = $conn->prepare("UPDATE products SET quantity = ?, status = ?, archived_at = IF(? = '" . PRODUCT_ARCHIVED . "', NOW(), archived_at) WHERE id = ?");
        $ds->bind_param("isis", $new_qty, $new_status, $new_status, $product_id); $ds->execute();

        // Log exchange item
        $ei = $conn->prepare("INSERT INTO exchange_items (exchange_id, direction, product_id, product_name, qty, unit_price, line_total)
            SELECT ?, 'outgoing', p.id, p.name, ?, ?, ? FROM products p WHERE p.id = ?");
        $ei->bind_param("iiddi", $exchange_id, $qty, $unit_price, $line_total, $product_id); $ei->execute();
    }

    // 6. Activity log
    $log_msg = "Exchange #{$exchange_no} for receipt {$receipt_no}. Delta: {$delta_type} ₱" . number_format($delta_amount, 2);
    $log_type = LOG_EXCHANGE;
    $al = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?,?,?,?)");
    $al->bind_param("isis", $user_id, $log_type, $exchange_id, $log_msg); $al->execute();

    $conn->commit();

    // Store exchange summary in session for confirmation display
    $_SESSION['exchange_done'] = [
        'exchange_no'  => $exchange_no,
        'receipt_no'   => $receipt_no,
        'delta_type'   => $delta_type,
        'delta_amount' => $delta_amount,
    ];
    header("Location: exchange_receipt.php");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: exchange.php?step=1&receipt=" . urlencode($receipt_no) . "&error=" . urlencode($e->getMessage()));
    exit();
}
