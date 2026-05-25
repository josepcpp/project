<?php
include '../../includes/auth_check.php';
include '../../config/db.php';

// ── GUARDS ────────────────────────────────────────────────────────────────────
$supplier_id = intval($_SESSION['delivery_supplier'] ?? 0);
$cart        = $_SESSION['delivery_cart'] ?? [];

if ($supplier_id <= 0 || empty($cart)) {
    header("Location: delivery_add.php");
    exit();
}

// ── SAVE DELIVERY ─────────────────────────────────────────────────────────────
$conn->begin_transaction();
try {
    $date = date("Y-m-d");
    $ins = $conn->prepare("INSERT INTO deliveries (supplier_id, delivery_date, status) VALUES (?, ?, '" . DEL_PENDING . "')");
    $ins->bind_param("is", $supplier_id, $date);
    $ins->execute();
    $delivery_id = $conn->insert_id;

    $item_stmt = $conn->prepare("INSERT INTO delivery_items (delivery_id, product_id, delivered_qty, delivered_price) VALUES (?, ?, ?, ?)");
    foreach ($cart as $pid => $item) {
        $pid  = intval($pid);
        $qty  = intval($item['qty']);
        $price = floatval($item['price']);
        $item_stmt->bind_param("iiid", $delivery_id, $pid, $qty, $price);
        $item_stmt->execute();
    }

    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: delivery_add.php?error=" . urlencode("Failed to save delivery: " . $e->getMessage()));
    exit();
}

unset($_SESSION['delivery_supplier'], $_SESSION['delivery_cart']);
header("Location: deliveries.php");
exit();
