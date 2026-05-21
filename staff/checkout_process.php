<?php
include '../includes/auth_check.php';
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Get the user ID for logging purposes
$user_id = $_SESSION['user_id'] ?? null;

// 1. Check if Cart is empty
if (empty($_SESSION['cart'])) {
    header("Location: pos.php");
    exit();
}

// 2. Collect Data
$total         = floatval($_POST['total'] ?? 0);
$cash          = floatval($_POST['cash'] ?? 0);

if ($total < 0) {
    header("Location: pos.php?error=" . urlencode("Invalid total amount."));
    exit();
}
$payment_mode  = $_POST['payment_mode'] ?? 'Cash';
$reference_no  = !empty($_POST['reference_no']) ? trim($_POST['reference_no']) : null;
$discount_id   = intval($_POST['discount_id'] ?? 0);
$promo_typed   = trim($_POST['promo_code'] ?? '');

$discount_name = "None";
$target_discount_id = 0;

// 3. Identify Discount
if ($discount_id > 0) {
    $disc_q = $conn->prepare("SELECT id, name, type, value, usage_limit, used_count FROM discounts WHERE id = ? AND is_active = 1");
    $disc_q->bind_param("i", $discount_id);
    $disc_q->execute();
    if ($row = $disc_q->get_result()->fetch_assoc()) {
        if ($row['type'] === 'Percentage' && $row['value'] > 100) {
            header("Location: pos.php?error=" . urlencode("Invalid discount: percentage cannot exceed 100%."));
            exit();
        }
        if ($row['usage_limit'] > 0 && $row['used_count'] >= $row['usage_limit']) {
            header("Location: pos.php?error=" . urlencode("Discount \"{$row['name']}\" has reached its usage limit."));
            exit();
        }
        $discount_name = $row['name'];
        $target_discount_id = $discount_id;
    }
}
elseif (!empty($promo_typed)) {
    $promo_q = $conn->prepare("SELECT id, name, type, value, usage_limit, used_count FROM discounts WHERE promo_code = ? AND is_active = 1 LIMIT 1");
    $promo_q->bind_param("s", $promo_typed);
    $promo_q->execute();
    if ($row = $promo_q->get_result()->fetch_assoc()) {
        if ($row['type'] === 'Percentage' && $row['value'] > 100) {
            header("Location: pos.php?error=" . urlencode("Invalid discount: percentage cannot exceed 100%."));
            exit();
        }
        if ($row['usage_limit'] > 0 && $row['used_count'] >= $row['usage_limit']) {
            header("Location: pos.php?error=" . urlencode("Promo code \"$promo_typed\" has reached its usage limit."));
            exit();
        }
        $discount_name = $row['name'] . " (Code: $promo_typed)";
        $target_discount_id = $row['id'];
    }
}

$conn->begin_transaction();

try {
    // 🛠️ Step 4: Feature 5 - Unique ID with RCPT- prefix
    $receipt = "RCPT-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -4));
    $change = $total <= 0 ? 0.0 : max(0.0, $cash - $total);

    // 4. Save Sale Header
    $stmt = $conn->prepare("INSERT INTO sales (receipt_no, total, cash, change_amt, payment_mode, reference_no, discount_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sdddsss", $receipt, $total, $cash, $change, $payment_mode, $reference_no, $discount_name);
    $stmt->execute();
    $sale_id = $conn->insert_id;

    // ── Log Sale Activity ─────────────────────────────────────────────────────
    $log_type = LOG_SALES;
    $log_msg  = "Completed sale #$receipt. Mode: $payment_mode. Total: ₱" . number_format($total, 2);
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, ?, ?, ?)");
    if ($log_stmt) {
        $log_stmt->bind_param("isis", $user_id, $log_type, $sale_id, $log_msg);
        $log_stmt->execute();
    }

    // 5. Update Promo Usage
    if ($target_discount_id > 0) {
        $stmt_disc = $conn->prepare("UPDATE discounts SET used_count = used_count + 1 WHERE id = ?");
        $stmt_disc->bind_param("i", $target_discount_id);
        $stmt_disc->execute();
    }

    // 6. Process Items
    foreach ($_SESSION['cart'] as $pid => $item) {
        $p_query = $conn->prepare("SELECT name, quantity FROM products WHERE id = ?");
        $p_query->bind_param("i", $pid);
        $p_query->execute();
        $product_data = $p_query->get_result()->fetch_assoc();

        if (!$product_data) {
            throw new Exception("Product ID $pid was recently deleted.");
        }

        if ($product_data['quantity'] < $item['qty']) {
            throw new Exception("Not enough stock for " . $product_data['name']);
        }

        // Insert into sales_items
        $stmt_item = $conn->prepare("INSERT INTO sales_items (sale_id, product_id, qty, price) VALUES (?, ?, ?, ?)");
        $stmt_item->bind_param("iiid", $sale_id, $pid, $item['qty'], $item['price']);
        $stmt_item->execute();

        /**
         * 🛠️ Step 2 Logic: Automated Soft-Archive
         * If the quantity becomes 0 after this sale, mark as archived.
         */
        $new_qty = $product_data['quantity'] - $item['qty'];
        $new_status = ($new_qty <= 0) ? PRODUCT_ARCHIVED : PRODUCT_ACTIVE;

        $update_stock = $conn->prepare("UPDATE products SET quantity = ?, status = ?, archived_at = IF(? = '" . PRODUCT_ARCHIVED . "', NOW(), archived_at) WHERE id = ?");
        $update_stock->bind_param("isis", $new_qty, $new_status, $new_status, $pid);
        $update_stock->execute();
    }

    $conn->commit();

    // Prepare session for receipt view
    $_SESSION['receipt'] = [
        'no' => $receipt, 'total' => $total, 'cash' => $cash, 'change' => $change,
        'payment_mode' => $payment_mode, 'discount' => $discount_name, 'date' => date("M d, Y h:i A")
    ];

    $_SESSION['cart'] = []; 
    header("Location: receipt.php");
    exit();

} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: pos.php?error=" . urlencode($e->getMessage()));
    exit();
}