<?php
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── GUARDS ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'], $_POST['alert_id'])) {
    header("Location: delivery_receive.php");
    exit();
}

$uid      = intval($_SESSION['user_id'] ?? 0);
$uname    = $_SESSION['username'] ?? 'Unknown';
$alert_id = intval($_POST['alert_id']);
$action   = $_POST['action'];

// ── FETCH ALERT ───────────────────────────────────────────────────────────────
$aq = $conn->prepare("SELECT * FROM quantity_alerts WHERE id = ? AND status = '" . ALERT_SUBMITTED . "' LIMIT 1");
$aq->bind_param("i", $alert_id);
$aq->execute();
$alert = $aq->get_result()->fetch_assoc();

if (!$alert) {
    header("Location: delivery_receive.php?error=" . urlencode("Recount record not found or already finalized."));
    exit();
}

// Double-fail gate: only superadmin can finalize an alert that failed twice.
// Normal admins are locked out to enforce escalation.
if (intval($alert['fail_count'] ?? 0) >= 2) {
    $approver_role = strtolower($_SESSION['role'] ?? '');
    if ($approver_role !== ROLE_SUPERADMIN) {
        header("Location: delivery_receive.php?error=" . urlencode("This item failed recount twice and requires Super Admin approval to resolve."));
        exit();
    }
}

// ── APPROVE ───────────────────────────────────────────────────────────────────
if ($action === 'approve') {
    $actual_qty   = intval($alert['actual_qty']);
    $expected_qty = intval($alert['expected_qty']);
    $product_id   = intval($alert['product_id'] ?? 0);
    $barcode      = $alert['barcode'];
    $delta        = $actual_qty - $expected_qty; // negative = short, positive = over

    $conn->begin_transaction();
    try {
        if ($product_id > 0) {
            // Targeted delta: adjust only the specific delivery batch row.
            // Other inventory rows with the same barcode are intentionally untouched.
            $upd = $conn->prepare("UPDATE products SET quantity = GREATEST(0, quantity + ?) WHERE id = ? AND status = '" . PRODUCT_ACTIVE . "'");
            $upd->bind_param("ii", $delta, $product_id);
            $upd->execute();

            // Sync locked_qty in price_update_requests so POS effective-qty
            // (total - locked) stays accurate after the recount adjustment.
            $upd_lock = $conn->prepare("UPDATE price_update_requests SET locked_qty = ? WHERE product_id = ? AND status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')");
            $upd_lock->bind_param("ii", $actual_qty, $product_id);
            $upd_lock->execute();
        } else {
            // Legacy alert (no product_id): absolute update by barcode.
            $upd = $conn->prepare("UPDATE products SET quantity = ? WHERE barcode = ? AND status = '" . PRODUCT_ACTIVE . "'");
            $upd->bind_param("is", $actual_qty, $barcode);
            $upd->execute();
        }

        $close = $conn->prepare("UPDATE quantity_alerts SET status = '" . ALERT_RESOLVED . "', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $close->bind_param("ii", $uid, $alert_id);
        $close->execute();

        $conn->commit();

        $note  = "RECOUNT FINALIZED: '{$alert['product_name']}' (#{$barcode}) — "
               . "expected: {$expected_qty}, actual: {$actual_qty}, "
               . "variance: {$alert['variance']} (delta: {$delta}). Stock updated by {$uname}.";
        $old_v = strval($expected_qty);
        $new_v = strval($actual_qty);
        $lg    = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message, old_value, new_value) VALUES (?, '" . LOG_INVENTORY . "', ?, ?, ?, ?)");
        if ($lg) { $lg->bind_param("iisss", $uid, $alert_id, $note, $old_v, $new_v); $lg->execute(); }

        header("Location: delivery_receive.php?success=" . urlencode("Stock updated for '{$alert['product_name']}'. Batch confirmed: {$actual_qty} pcs."));
    } catch (\Throwable $e) {
        $conn->rollback();
        header("Location: delivery_receive.php?error=" . urlencode("Failed to update stock: " . $e->getMessage()));
    }
    exit();
}

// ── REJECT ────────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $reason = trim($_POST['reject_reason'] ?? 'Rejected by admin');

    $rej = $conn->prepare("UPDATE quantity_alerts SET status = '" . ALERT_REJECTED . "', reject_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
    $rej->bind_param("sii", $reason, $uid, $alert_id);
    $rej->execute();

    $note = "RECOUNT REJECTED: '{$alert['product_name']}' (#{$alert['barcode']}) — reason: {$reason}. Rejected by {$uname}.";
    $lg   = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_INVENTORY . "', ?, ?)");
    if ($lg) { $lg->bind_param("iis", $uid, $alert_id, $note); $lg->execute(); }

    header("Location: delivery_receive.php?success=" . urlencode("Recount rejected for '{$alert['product_name']}'."));
    exit();
}

header("Location: delivery_receive.php");
exit();
