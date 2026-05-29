<?php
/**
 * exchange_process.php — Processes an item exchange transaction.
 * - Returns selected items (restores stock)
 * - Dispenses replacement items (deducts stock)
 * - Handles even exchanges and delta (collect/refund)
 * - Logs to activity_logs and exchanges table
 *
 * EXC-1: delta_type and delta_amount are RECALCULATED server-side from actual item
 *        prices. POST values for these fields are ignored entirely.
 * EXC-2: All qty inputs are validated (must be >= 1) before any DB write occurs.
 */
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../config/settings.php';
include '../../includes/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';

$_ex_role = strtolower($_SESSION['role'] ?? '');
if (!in_array($_ex_role, [ROLE_STAFF, ROLE_ADMIN, ROLE_OWNER, ROLE_SUPERADMIN])) {
    header("Location: exchange.php?error=" . urlencode("Insufficient permissions."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: exchange.php");
    exit();
}

// SEC-1: Reject forged cross-origin POST requests
csrf_verify('exchange.php');

$sale_id      = intval($_POST['sale_id'] ?? 0);
$receipt_no   = trim($_POST['receipt_no'] ?? '');
$payment_mode = trim($_POST['payment_mode'] ?? PAY_METHOD_CASH);
$reference_no = !empty($_POST['reference_no']) ? trim($_POST['reference_no']) : null;
$notes        = trim($_POST['notes'] ?? '');

$return_items = $_POST['return_items'] ?? [];
$new_items    = $_POST['new_items']    ?? [];

// Filter only checked return items
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
    $sale_row = $sq->get_result()->fetch_assoc();
    if (!$sale_row) throw new Exception("Original sale not found.");

    // 2. Validate & price RETURN items server-side
    //    Unit price comes from sales_items (the original purchase price), not from POST.
    $total_return_value = 0.0;
    $validated_returns  = [];

    foreach ($selected_returns as $r) {
        $product_id = intval($r['product_id'] ?? 0);
        $qty        = intval($r['qty']        ?? 0);  // EXC-2: no max(1,…) silent clamp

        if ($product_id < 1)
            throw new Exception("Invalid product in return list.");
        if ($qty < 1)
            throw new Exception("Return quantity must be at least 1 for each selected item.");

        // Server-side price from the original sale record
        $si_q = $conn->prepare("SELECT qty, price FROM sales_items WHERE sale_id = ? AND product_id = ? LIMIT 1");
        $si_q->bind_param("ii", $sale_id, $product_id); $si_q->execute();
        $si = $si_q->get_result()->fetch_assoc();
        if (!$si) throw new Exception("Item (product #$product_id) not found on original receipt.");
        if ($qty > intval($si['qty']))
            throw new Exception("Cannot return $qty unit(s) — only {$si['qty']} were purchased on this receipt.");

        $unit_price = floatval($si['price']);
        $line_total = round($qty * $unit_price, 4);
        $total_return_value += $line_total;

        $validated_returns[] = compact('product_id', 'qty', 'unit_price', 'line_total');
    }

    // 3. Validate & price REPLACEMENT items server-side
    //    Unit price comes from the current products row (FOR UPDATE), not from POST.
    $total_new_value    = 0.0;
    $validated_new_items = [];

    foreach ($new_items as $n) {
        $product_id = intval($n['product_id'] ?? 0);
        $qty        = intval($n['qty']        ?? 0);  // EXC-2: validate, don't clamp

        if ($product_id < 1)
            throw new Exception("Invalid product in replacement list.");
        if ($qty < 1)
            throw new Exception("Replacement quantity must be at least 1 for each item.");

        // Lock the row so concurrent exchanges can't over-sell (also fixes POS-2 equivalent)
        $pq = $conn->prepare(
            "SELECT name, quantity, price, status FROM products
             WHERE id = ? AND status = '" . PRODUCT_ACTIVE . "' LIMIT 1 FOR UPDATE"
        );
        $pq->bind_param("i", $product_id); $pq->execute();
        $prod = $pq->get_result()->fetch_assoc();
        if (!$prod) throw new Exception("Replacement product #$product_id is not available.");
        if ($prod['quantity'] < $qty)
            throw new Exception("Insufficient stock for \"{$prod['name']}\" (need $qty, have {$prod['quantity']}).");

        $unit_price = floatval($prod['price']);
        $line_total = round($qty * $unit_price, 4);
        $total_new_value += $line_total;

        $validated_new_items[] = [
            'product_id' => $product_id,
            'qty'        => $qty,
            'unit_price' => $unit_price,
            'line_total' => $line_total,
            'prod'       => $prod,
        ];
    }

    // 4. EXC-1: Compute delta server-side — POST values for delta are IGNORED
    $server_delta = round($total_new_value - $total_return_value, 2);
    if (abs($server_delta) < 0.01) {
        $delta_type   = 'none';
        $delta_amount = 0.0;
    } elseif ($server_delta > 0) {
        $delta_type   = 'collect';
        $delta_amount = $server_delta;
        // For digital payment, reference number is required
        if ($payment_mode !== PAY_METHOD_CASH && empty($reference_no))
            throw new Exception("Reference number is required for {$payment_mode} payment.");
    } else {
        $delta_type   = 'refund';
        $delta_amount = abs($server_delta);
    }

    // 5. Generate exchange number and insert header
    $exchange_no = "EXC-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -4));

    $ex_stmt = $conn->prepare("INSERT INTO exchanges
        (exchange_no, original_sale_id, original_receipt_no, delta_type, delta_amount,
         payment_mode, reference_no, processed_by, processed_username, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $ex_stmt->bind_param("ssssdssiss",
        $exchange_no, $sale_id, $receipt_no,
        $delta_type, $delta_amount,
        $payment_mode, $reference_no,
        $user_id, $username, $notes
    );
    $ex_stmt->execute();
    $exchange_id = $conn->insert_id;

    // 6. Apply RETURN items — restore stock
    foreach ($validated_returns as $r) {
        $rs = $conn->prepare(
            "UPDATE products SET quantity = quantity + ?, status = '" . PRODUCT_ACTIVE . "', archived_at = NULL WHERE id = ?"
        );
        $rs->bind_param("ii", $r['qty'], $r['product_id']); $rs->execute();

        $ei = $conn->prepare(
            "INSERT INTO exchange_items (exchange_id, direction, product_id, product_name, qty, unit_price, line_total)
             SELECT ?, 'return', p.id, p.name, ?, ?, ? FROM products p WHERE p.id = ?"
        );
        $ei->bind_param("iiddi", $exchange_id, $r['qty'], $r['unit_price'], $r['line_total'], $r['product_id']);
        $ei->execute();
    }

    // 7. Apply OUTGOING (replacement) items — deduct stock
    foreach ($validated_new_items as $n) {
        $new_qty    = $n['prod']['quantity'] - $n['qty'];
        $new_status = ($new_qty <= 0) ? PRODUCT_ARCHIVED : PRODUCT_ACTIVE;

        $ds = $conn->prepare(
            "UPDATE products SET quantity = ?, status = ?,
             archived_at = IF(? = '" . PRODUCT_ARCHIVED . "', NOW(), archived_at) WHERE id = ?"
        );
        $ds->bind_param("isis", $new_qty, $new_status, $new_status, $n['product_id']); $ds->execute();

        $ei = $conn->prepare(
            "INSERT INTO exchange_items (exchange_id, direction, product_id, product_name, qty, unit_price, line_total)
             SELECT ?, 'outgoing', p.id, p.name, ?, ?, ? FROM products p WHERE p.id = ?"
        );
        $ei->bind_param("iiddi", $exchange_id, $n['qty'], $n['unit_price'], $n['line_total'], $n['product_id']);
        $ei->execute();
    }

    // 8. Activity log
    $log_msg  = "Exchange #{$exchange_no} for receipt {$receipt_no}. Delta: {$delta_type} ₱" . number_format($delta_amount, 2);
    $log_type = LOG_EXCHANGE;
    $al = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?,?,?,?)");
    $al->bind_param("isis", $user_id, $log_type, $exchange_id, $log_msg); $al->execute();

    $conn->commit();

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
