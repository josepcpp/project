<?php
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── GUARDS ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'], $_POST['disposal_id'])) {
    header("Location: stock_management.php?stock=disposed");
    exit();
}

$uid         = intval($_SESSION['user_id'] ?? 0);
$uname       = $_SESSION['username'] ?? 'Unknown';
$action      = $_POST['action'];
$disposal_id = intval($_POST['disposal_id']);

// ── FETCH DISPOSAL RECORD ─────────────────────────────────────────────────────
$dq = $conn->prepare("SELECT * FROM product_disposals WHERE id = ? AND status = '" . DISPOSAL_PENDING . "' LIMIT 1");
$dq->bind_param("i", $disposal_id);
$dq->execute();
$disposal = $dq->get_result()->fetch_assoc();

if (!$disposal) {
    header("Location: stock_management.php?stock=disposed&error=" . urlencode("Disposal record not found or already actioned."));
    exit();
}

// ── APPROVE ───────────────────────────────────────────────────────────────────
if ($action === 'approve') {
    $conn->begin_transaction();
    try {
        $barcode   = $disposal['barcode'] ?? '';
        $remaining = intval($disposal['qty']);

        if ($barcode !== '') {
            // FIFO deduction: drain earliest-expiry active lots first (same order as POS checkout).
            $lots_q = $conn->prepare(
                "SELECT id, quantity FROM products
                 WHERE barcode = ? AND status = '" . PRODUCT_ACTIVE . "' AND quantity > 0
                 ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, id ASC
                 FOR UPDATE"
            );
            $lots_q->bind_param("s", $barcode);
            $lots_q->execute();
            $lots = $lots_q->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($lots as $lot) {
                if ($remaining <= 0) break;
                $take  = min($remaining, intval($lot['quantity']));
                $new_q = intval($lot['quantity']) - $take;
                $new_s = ($new_q <= 0) ? PRODUCT_ARCHIVED : PRODUCT_ACTIVE;
                $upd_l = $conn->prepare("UPDATE products SET quantity = ?, status = ?, archived_at = IF(? = '" . PRODUCT_ARCHIVED . "', NOW(), archived_at) WHERE id = ?");
                $upd_l->bind_param("isis", $new_q, $new_s, $new_s, $lot['id']);
                $upd_l->execute();
                $remaining -= $take;
            }
        } else {
            // No barcode (box-only item recorded without per-item code) — deduct directly.
            $upd = $conn->prepare("UPDATE products SET quantity = GREATEST(0, quantity - ?) WHERE id = ? AND status = '" . PRODUCT_ACTIVE . "'");
            $upd->bind_param("ii", $disposal['qty'], $disposal['product_id']);
            $upd->execute();
        }

        $app = $conn->prepare("UPDATE product_disposals SET status = '" . DISPOSAL_APPROVED . "', approved_by = ?, approved_username = ?, approved_at = NOW() WHERE id = ?");
        $app->bind_param("isi", $uid, $uname, $disposal_id);
        $app->execute();

        $conn->commit();

        $log_msg = "DISPOSAL APPROVED: {$disposal['qty']}x '{$disposal['product_name']}' (#{$disposal['barcode']}) written off. "
                 . "Reason: {$disposal['reason']}. Requested by {$disposal['requested_username']}. Approved by {$uname}.";
        $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_INVENTORY . "', ?, ?)");
        if ($lg) { $lg->bind_param("iis", $uid, $disposal['product_id'], $log_msg); $lg->execute(); }

        header("Location: stock_management.php?stock=disposed&success=" . urlencode("Disposal approved. {$disposal['qty']} pcs of '{$disposal['product_name']}' deducted from inventory."));
    } catch (\Throwable $e) {
        $conn->rollback();
        header("Location: stock_management.php?stock=disposed&error=" . urlencode("Failed: " . $e->getMessage()));
    }
    exit();
}

// ── REJECT ────────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $reason = trim($_POST['reject_reason'] ?? 'Rejected by admin');

    $rej = $conn->prepare("UPDATE product_disposals SET status = '" . DISPOSAL_REJECTED . "', reject_reason = ?, approved_by = ?, approved_username = ?, approved_at = NOW() WHERE id = ?");
    $rej->bind_param("sisi", $reason, $uid, $uname, $disposal_id);
    $rej->execute();

    $log_msg = "DISPOSAL REJECTED: '{$disposal['product_name']}' (#{$disposal['barcode']}) — reason: {$reason}. Rejected by {$uname}.";
    $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_INVENTORY . "', ?, ?)");
    if ($lg) { $lg->bind_param("iis", $uid, $disposal['product_id'], $log_msg); $lg->execute(); }

    header("Location: stock_management.php?stock=disposed&success=" . urlencode("Disposal request rejected for '{$disposal['product_name']}'."));
    exit();
}

header("Location: stock_management.php?stock=disposed");
exit();
