<?php
/**
 * validate_process.php — Processes validator price submission.
 *
 * SECURITY: control_subtotal is fetched here ONLY for comparison.
 * It is NEVER echoed, included in any response, or logged to a user-visible channel.
 */
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_once '../../includes/batch_lock.php';
require_role([ROLE_VALIDATOR, ROLE_PRICE_CHECKER, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

// Price Checker reprices reopened batches; everyone else validates. Landing page differs.
$home_page = ($role === ROLE_PRICE_CHECKER) ? 'price_checker.php?tab=reprice' : 'validate_batch.php';
$home_sep  = (strpos($home_page, '?') !== false) ? '&' : '?';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$home_page}");
    exit();
}
csrf_verify('validate_batch.php');

$batch_id   = intval($_POST['batch_id'] ?? 0);
$items_post = $_POST['items'] ?? [];

if (!$batch_id || empty($items_post)) {
    header("Location: {$home_page}{$home_sep}error=" . urlencode("Invalid submission."));
    exit();
}

$conn->begin_transaction();
try {
    // Accept both first-pass validation and reopened reprice. control_subtotal ONLY for internal comparison.
    $bq = $conn->prepare(
        "SELECT id, status, control_subtotal FROM receiving_batches WHERE id = ? AND status IN ('pending_validation','pending_reprice') LIMIT 1 FOR UPDATE"
    );
    $bq->bind_param("i", $batch_id);
    $bq->execute();
    $batch = $bq->get_result()->fetch_assoc();

    if (!$batch) throw new Exception("Batch not found or not awaiting price entry.");

    $is_reprice = ($batch['status'] === 'pending_reprice');

    $control_subtotal = floatval($batch['control_subtotal']);
    $computed_subtotal = 0.0;
    $validated_items   = [];

    foreach ($items_post as $row) {
        $item_id   = intval($row['item_id']    ?? 0);
        $qty       = intval($row['qty']        ?? 0);
        $base_price = floatval($row['base_price'] ?? 0);

        if ($item_id < 1) throw new Exception("Invalid item reference.");
        if ($base_price <= 0) throw new Exception("Base price must be greater than ₱0.00 for all items.");
        if ($qty < 1) throw new Exception("Item quantity must be at least 1.");

        // Verify item belongs to this batch
        $iv = $conn->prepare("SELECT id, quantity FROM receiving_items WHERE id = ? AND batch_id = ? LIMIT 1");
        $iv->bind_param("ii", $item_id, $batch_id);
        $iv->execute();
        $item = $iv->get_result()->fetch_assoc();
        if (!$item) throw new Exception("Item #$item_id does not belong to this batch.");

        $amount = round($base_price * $qty, 4);
        $computed_subtotal += $amount;
        $validated_items[] = compact('item_id', 'base_price', 'amount');
    }

    $computed_subtotal = round($computed_subtotal, 2);

    // Save base_price and amount to each receiving_items row
    $ui = $conn->prepare("UPDATE receiving_items SET base_price = ?, amount = ?, match_flag = 1 WHERE id = ?");
    foreach ($validated_items as $vi) {
        $ui->bind_param("ddi", $vi['base_price'], $vi['amount'], $vi['item_id']);
        $ui->execute();
    }

    // BLIND TALLY — compare computed vs control (tolerance: ₱0.01)
    $delta = abs($computed_subtotal - $control_subtotal);
    if ($delta <= 0.01) {
        $tally_result  = 'match';
        $new_status    = 'validated_tally';
    } else {
        $tally_result  = 'discrepancy';
        $new_status    = 'on_hold';
    }

    $upd = $conn->prepare(
        "UPDATE receiving_batches SET
            computed_subtotal = ?, tally_result = ?, status = ?,
            validator_id = ?, validated_at = NOW()
         WHERE id = ?"
    );
    $upd->bind_param("dssii", $computed_subtotal, $tally_result, $new_status, $user_id, $batch_id);
    $upd->execute();

    // Batch has been validated → release this user's processing lock.
    batch_lock_release($conn, $batch_id, $user_id);

    // Audit log
    $al = $conn->prepare(
        "INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action, tally_result)
         VALUES (?,?,?,?,?,?)"
    );
    $audit_action = ($tally_result === 'match') ? 'validated_tally' : 'validated_discrepancy';
    $al->bind_param("iissss", $batch_id, $user_id, $username, $role, $audit_action, $tally_result);
    $al->execute();

    if ($tally_result === 'discrepancy') {
        // A reprice already passed through (and was rejected from) the damage flow —
        // never send it back to create another damage ticket.
        $has_damage = false;
        if (!$is_reprice) {
            $dq = $conn->prepare("SELECT COUNT(*) AS c FROM receiving_items WHERE batch_id = ? AND damaged_qty > 0");
            $dq->bind_param("i", $batch_id);
            $dq->execute();
            $has_damage = intval($dq->get_result()->fetch_assoc()['c']) > 0;
        }

        $conn->commit();

        if ($has_damage) {
            // Damage ticket will send its own notification — skip the generic discrepancy alert
            header("Location: damage_ticket.php?batch_id=$batch_id");
        } else {
            // Notify admin of counting discrepancy
            $msg = ($is_reprice ? "Reprice of Batch #$batch_id still shows a discrepancy." : "Batch #$batch_id has a subtotal discrepancy.")
                 . " Computed: ₱" . number_format($computed_subtotal, 2) . ". Admin review required.";
            $notif = $conn->prepare(
                "INSERT INTO notifications (recipient_role, type, batch_id, message) VALUES ('admin', 'discrepancy', ?, ?)"
            );
            $notif->bind_param("is", $batch_id, $msg);
            $notif->execute();
            header("Location: {$home_page}{$home_sep}success=" . urlencode(($is_reprice ? "Reprice submitted" : "Validation submitted") . " — discrepancy detected. Admin has been notified."));
        }
    } else {
        $conn->commit();
        // Tally match — trigger inventory push immediately
        include 'push_inventory.php';
        push_inventory($batch_id, $user_id, $username, $role, $conn);
        header("Location: {$home_page}{$home_sep}success=" . urlencode(($is_reprice ? "Reprice complete" : "Validation complete") . ". Tally matched — inventory updated."));
    }
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: validate_items.php?batch_id=$batch_id&error=" . urlencode($e->getMessage()));
    exit();
}
