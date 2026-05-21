<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── GUARDS ────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: refund_management.php?tab=disposal");
    exit();
}
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    header("Location: refund_management.php?tab=disposal&error=" . urlencode("Security token mismatch. Please try again."));
    exit();
}

// ── INPUT ─────────────────────────────────────────────────────────────────────
$role     = strtolower($_SESSION['role'] ?? ROLE_STAFF);
$is_admin = in_array($role, ROLES_ADMIN_AND_UP);
$user_id  = intval($_SESSION['user_id']);
$username = $_SESSION['username'] ?? 'Unknown';

$product_id  = intval($_POST['product_id']  ?? 0);
$qty         = intval($_POST['qty']         ?? 0);
$reason      = $_POST['reason']             ?? DISPOSE_EXPIRED;
$expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
$notes       = trim($_POST['notes']         ?? '');

// Sanitize reason against the allowed set — fall back to 'Other' if tampered.
if (!in_array($reason, DISPOSAL_REASONS)) {
    $reason = DISPOSE_OTHER;
}

if ($product_id <= 0 || $qty <= 0) {
    header("Location: refund_management.php?tab=disposal&error=" . urlencode("Invalid product or quantity."));
    exit();
}

// ── VALIDATE PRODUCT ──────────────────────────────────────────────────────────
$pq = $conn->prepare("SELECT id, name, barcode, quantity FROM products WHERE id = ? AND status = '" . PRODUCT_ACTIVE . "'");
$pq->bind_param("i", $product_id);
$pq->execute();
$product = $pq->get_result()->fetch_assoc();

if (!$product) {
    header("Location: refund_management.php?tab=disposal&error=" . urlencode("Product not found."));
    exit();
}
if ($qty > intval($product['quantity'])) {
    header("Location: refund_management.php?tab=disposal&error=" . urlencode("Disposal qty exceeds available stock ({$product['quantity']} pcs)."));
    exit();
}

// ── CREATE DISPOSAL RECORD ────────────────────────────────────────────────────
$conn->begin_transaction();
try {
    // Admins self-approve; staff submissions go through an approval queue.
    $status = $is_admin ? DISPOSAL_APPROVED : DISPOSAL_PENDING;

    $ins = $conn->prepare("
        INSERT INTO product_disposals
            (product_id, product_name, barcode, qty, reason, expiry_date, notes,
             requested_by, requested_username, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->bind_param("issiissiis",
        $product_id, $product['name'], $product['barcode'],
        $qty, $reason, $expiry_date, $notes,
        $user_id, $username, $status);
    $ins->execute();
    $disposal_id = $conn->insert_id;

    if ($is_admin) {
        // Immediately deduct stock and mark as approved.
        $upd = $conn->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
        $upd->bind_param("ii", $qty, $product_id);
        $upd->execute();

        $app = $conn->prepare("UPDATE product_disposals SET approved_by = ?, approved_username = ?, approved_at = NOW() WHERE id = ?");
        $app->bind_param("isi", $user_id, $username, $disposal_id);
        $app->execute();
    }

    $conn->commit();

    $log_msg = $is_admin
        ? "DISPOSAL APPLIED: {$qty}x '{$product['name']}' (#{$product['barcode']}) written off. Reason: {$reason}. By {$username}."
        : "DISPOSAL REQUESTED: {$qty}x '{$product['name']}' (#{$product['barcode']}). Reason: {$reason}. By {$username}. Pending approval.";
    $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_INVENTORY . "', ?, ?)");
    if ($lg) { $lg->bind_param("iis", $user_id, $product_id, $log_msg); $lg->execute(); }

    $redirect_msg = $is_admin
        ? "Disposal applied. {$qty} pcs of '{$product['name']}' written off."
        : "Disposal request submitted. Awaiting admin approval.";
    header("Location: refund_management.php?tab=disposal&success=" . urlencode($redirect_msg));
} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: refund_management.php?tab=disposal&error=" . urlencode("Failed: " . $e->getMessage()));
}
exit();
