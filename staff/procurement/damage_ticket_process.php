<?php
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

$user_id          = $_SESSION['user_id']  ?? null;
$username         = $_SESSION['username'] ?? 'unknown';
$batch_id         = intval($_POST['batch_id']        ?? 0);
$total_deduction  = round(floatval($_POST['total_deduction'] ?? 0), 2);
$damage_summary   = trim($_POST['damage_summary'] ?? '');

if (!$batch_id || $damage_summary === '') {
    header("Location: damage_ticket.php?batch_id=$batch_id&error=" . urlencode("Summary is required."));
    exit();
}

$conn->begin_transaction();
try {
    $ins = $conn->prepare(
        "INSERT INTO delivery_damage_tickets (batch_id, raised_by, raised_by_username, damage_summary, total_deduction, status)
         VALUES (?, ?, ?, ?, ?, 'pending')"
    );
    $ins->bind_param("iissd", $batch_id, $user_id, $username, $damage_summary, $total_deduction);
    $ins->execute();

    $msg = "Damage Return Ticket raised for Batch #$batch_id by @{$username}. Deduction: ₱" . number_format($total_deduction, 2) . ". Review required.";
    $notif = $conn->prepare(
        "INSERT INTO notifications (recipient_role, type, batch_id, message) VALUES ('admin', 'discrepancy', ?, ?)"
    );
    $notif->bind_param("is", $batch_id, $msg);
    $notif->execute();

    $conn->commit();
    header("Location: validate_batch.php?success=" . urlencode("Damage ticket submitted for Batch #$batch_id. Admin will review the deduction."));
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    header("Location: damage_ticket.php?batch_id=$batch_id&error=" . urlencode($e->getMessage()));
    exit();
}
