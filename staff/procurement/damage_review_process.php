<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: damage_review.php");
    exit();
}

$user_id     = $_SESSION['user_id']  ?? null;
$username    = $_SESSION['username'] ?? 'unknown';
$ticket_id   = intval($_POST['ticket_id'] ?? 0);
$decision    = $_POST['decision'] ?? '';
$admin_notes = trim($_POST['admin_notes'] ?? '') ?: null;

if (!$ticket_id || !in_array($decision, ['approve', 'reject'])) {
    header("Location: damage_review.php?error=" . urlencode("Invalid request."));
    exit();
}

// Load ticket — use snapshot_discrepancy so approve/auto-match logic is immune to reprice changes
$tq = $conn->prepare(
    "SELECT ddt.*,
            COALESCE(ddt.snapshot_discrepancy, ABS(rb.control_subtotal - rb.computed_subtotal)) AS effective_discrepancy
     FROM delivery_damage_tickets ddt
     JOIN receiving_batches rb ON rb.id = ddt.batch_id
     WHERE ddt.id = ? AND ddt.status = 'pending' LIMIT 1"
);
$tq->bind_param("i", $ticket_id);
$tq->execute();
$ticket = $tq->get_result()->fetch_assoc();

if (!$ticket) {
    header("Location: damage_review.php?error=" . urlencode("Ticket not found or already reviewed."));
    exit();
}

$batch_id        = intval($ticket['batch_id']);
$total_deduction = floatval($ticket['total_deduction']);
$discrepancy     = round(floatval($ticket['effective_discrepancy']), 2);
$auto_match      = abs($discrepancy - $total_deduction) <= 0.01;

$conn->begin_transaction();
try {
    // Update ticket
    $new_status = ($decision === 'approve') ? 'approved' : 'rejected';
    $upd = $conn->prepare(
        "UPDATE delivery_damage_tickets
         SET status = ?, reviewed_by = ?, reviewed_by_username = ?, reviewed_at = NOW(), admin_notes = ?
         WHERE id = ?"
    );
    $upd->bind_param("siiss", $new_status, $user_id, $username, $admin_notes, $ticket_id);
    $upd->execute();

    if ($decision === 'approve' && $auto_match) {
        // Damage fully explains discrepancy — mark as match and push inventory
        $fix = $conn->prepare(
            "UPDATE receiving_batches SET tally_result = 'match', status = 'validated_tally' WHERE id = ?"
        );
        $fix->bind_param("i", $batch_id);
        $fix->execute();

        $conn->commit();

        include 'push_inventory.php';
        push_inventory($batch_id, $user_id, $username, strtolower($_SESSION['role'] ?? ''), $conn);

        header("Location: damage_review.php?success=" . urlencode("Ticket approved. Damage explained the discrepancy — inventory pushed for Batch #$batch_id."));
    } elseif ($decision === 'approve') {
        // Deduction approved but gap still exists — keep on_hold for manual resolution
        $conn->commit();
        header("Location: discrepancy_resolve.php?batch_id=$batch_id&info=" . urlencode("Damage ticket approved (₱" . number_format($total_deduction, 2) . " deduction recorded). Remaining discrepancy of ₱" . number_format(abs($discrepancy - $total_deduction), 2) . " still needs manual resolution."));
    } else {
        // Rejected — reopen the batch to the Price Checker for repricing
        $reopen = $conn->prepare(
            "UPDATE receiving_batches
             SET status = 'pending_reprice',
                 resolution_action = 'reopen_price_checker',
                 resolution_by = ?, resolution_reason = ?, resolution_at = NOW()
             WHERE id = ?"
        );
        $reason = "Damage ticket rejected." . ($admin_notes ? " Note: $admin_notes" : '');
        $reopen->bind_param("isi", $user_id, $reason, $batch_id);
        $reopen->execute();

        // Audit log
        $al = $conn->prepare(
            "INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action, reason)
             VALUES (?,?,?,?,'reopen_price_checker',?)"
        );
        $arole = strtolower($_SESSION['role'] ?? '');
        $al->bind_param("iisss", $batch_id, $user_id, $username, $arole, $reason);
        $al->execute();

        // Notify the Price Checker
        $msg = "Batch #$batch_id reopened for repricing — its damage ticket was rejected by @{$username}." . ($admin_notes ? " Note: $admin_notes" : '');
        $notif = $conn->prepare(
            "INSERT INTO notifications (recipient_role, type, batch_id, message) VALUES ('price_checker', 'override', ?, ?)"
        );
        $notif->bind_param("is", $batch_id, $msg);
        $notif->execute();

        $conn->commit();
        header("Location: damage_review.php?success=" . urlencode("Ticket rejected. Batch #$batch_id reopened to the Price Checker for repricing."));
    }
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: damage_review.php?error=" . urlencode($e->getMessage()));
    exit();
}
