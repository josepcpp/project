<?php
include '../includes/auth_check.php';
include '../config/db.php';

if (!isset($_POST['action']) || $_POST['action'] !== 'verify') {
    header("Location: deliveries.php");
    exit();
}

// ── INPUT ─────────────────────────────────────────────────────────────────────
$delivery_id = intval($_POST['delivery_id']);

// ── LOAD DELIVERY ITEMS ───────────────────────────────────────────────────────
$items_q = $conn->prepare("
    SELECT di.product_id,
           di.delivered_qty,
           di.delivered_price,
           p.quantity    AS current_qty,
           p.cost_price  AS current_cost
    FROM delivery_items di
    JOIN products p ON p.id = di.product_id
    WHERE di.delivery_id = ?
");
$items_q->bind_param("i", $delivery_id);
$items_q->execute();
$rows = $items_q->get_result()->fetch_all(MYSQLI_ASSOC);

// ── VERIFY & UPDATE ───────────────────────────────────────────────────────────
$conn->begin_transaction();
try {
    foreach ($rows as $row) {
        $product_id = intval($row['product_id']);
        $new_qty    = intval($row['delivered_qty']);
        $new_cost   = floatval($row['delivered_price']);
        $old_qty    = intval($row['current_qty']);
        $old_cost   = floatval($row['current_cost']);
        $total_qty  = $old_qty + $new_qty;

        // Weighted average cost: blends existing stock cost with new delivery cost.
        // Selling price (products.price) is intentionally never modified here.
        $avg_cost = ($old_cost > 0 && $total_qty > 0)
            ? (($old_qty * $old_cost) + ($new_qty * $new_cost)) / $total_qty
            : $new_cost;

        $upd = $conn->prepare("UPDATE products SET quantity = quantity + ?, cost_price = ? WHERE id = ?");
        $upd->bind_param("idi", $new_qty, $avg_cost, $product_id);
        $upd->execute();
    }

    $mark = $conn->prepare("UPDATE deliveries SET status = '" . DEL_VERIFIED . "' WHERE id = ?");
    $mark->bind_param("i", $delivery_id);
    $mark->execute();

    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: deliveries.php?error=" . urlencode("Verification failed: " . $e->getMessage()));
    exit();
}

header("Location: deliveries.php");
exit();
