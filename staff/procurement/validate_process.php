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
require_role([ROLE_VALIDATOR, ROLE_ADMIN, ROLE_SUPERADMIN]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: validate_batch.php");
    exit();
}
csrf_verify('validate_batch.php');

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$batch_id   = intval($_POST['batch_id'] ?? 0);
$items_post = $_POST['items'] ?? [];

if (!$batch_id || empty($items_post)) {
    header("Location: validate_batch.php?error=" . urlencode("Invalid submission."));
    exit();
}

$conn->begin_transaction();
try {
    // Fetch batch with control_subtotal — ONLY for internal comparison
    $bq = $conn->prepare(
        "SELECT id, status, control_subtotal FROM receiving_batches WHERE id = ? AND status = 'pending_validation' LIMIT 1 FOR UPDATE"
    );
    $bq->bind_param("i", $batch_id);
    $bq->execute();
    $batch = $bq->get_result()->fetch_assoc();

    if (!$batch) throw new Exception("Batch not found or not in pending_validation status.");

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

    // Audit log
    $al = $conn->prepare(
        "INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action, tally_result)
         VALUES (?,?,?,?,?,?)"
    );
    $audit_action = ($tally_result === 'match') ? 'validated_tally' : 'validated_discrepancy';
    $al->bind_param("iissss", $batch_id, $user_id, $username, $role, $audit_action, $tally_result);
    $al->execute();

    if ($tally_result === 'discrepancy') {
        // Check if receiver logged any damaged items on this batch
        $dq = $conn->prepare("SELECT COUNT(*) AS c FROM receiving_items WHERE batch_id = ? AND damaged_qty > 0");
        $dq->bind_param("i", $batch_id);
        $dq->execute();
        $has_damage = intval($dq->get_result()->fetch_assoc()['c']) > 0;

        $conn->commit();

        if ($has_damage) {
            // Damage ticket will send its own notification — skip the generic discrepancy alert
            header("Location: damage_ticket.php?batch_id=$batch_id");
        } else {
            // No damage reported — notify admin of counting discrepancy
            $msg = "Batch #$batch_id has a subtotal discrepancy. Computed: ₱" . number_format($computed_subtotal, 2) . ". Admin review required.";
            $notif = $conn->prepare(
                "INSERT INTO notifications (recipient_role, type, batch_id, message) VALUES ('admin', 'discrepancy', ?, ?)"
            );
            $notif->bind_param("is", $batch_id, $msg);
            $notif->execute();
            header("Location: validate_batch.php?success=" . urlencode("Validation submitted — discrepancy detected. Admin has been notified."));
        }
    } else {
        $conn->commit();
        // Tally match — trigger inventory push immediately
        include 'push_inventory.php';
        push_inventory($batch_id, $user_id, $username, $role, $conn);
        header("Location: validate_batch.php?success=" . urlencode("Validation complete. Tally matched — inventory updated."));
    }
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: validate_items.php?batch_id=$batch_id&error=" . urlencode($e->getMessage()));
    exit();
}
