<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = $_SESSION['user_id'] ?? null;
$role    = strtolower($_SESSION['role'] ?? '');

if (!$user_id) {
    header("Location: ../index.php");
    exit();
}

// Fetch current user's username once
$uq = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
$uq->bind_param("i", $user_id);
$uq->execute();
$username = $uq->get_result()->fetch_assoc()['username'] ?? 'Unknown';

$action = $_POST['action'] ?? '';

switch ($action) {

    // ── REQUEST: Any authenticated user marks a payment for approval ──────────
    case 'request_payment':
        $payment_id = intval($_POST['payment_id']);

        $pq = $conn->prepare("SELECT id, invoice_no, amount, status FROM supplier_payments WHERE id = ? LIMIT 1");
        $pq->bind_param("i", $payment_id);
        $pq->execute();
        $pmt = $pq->get_result()->fetch_assoc();

        if (!$pmt || $pmt['status'] !== SUP_PAY_UNPAID) {
            header("Location: payments.php?error=" . urlencode("Payment not found or already settled."));
            exit();
        }

        // Block duplicate pending request
        $dq = $conn->prepare("SELECT id FROM payment_approvals WHERE payment_id = ? AND status IN ('" . APPROVAL_PENDING_STEP1 . "','" . APPROVAL_PENDING_STEP2 . "') LIMIT 1");
        $dq->bind_param("i", $payment_id);
        $dq->execute();
        if ($dq->get_result()->num_rows > 0) {
            header("Location: payments.php?error=" . urlencode("An approval request for this invoice already exists."));
            exit();
        }

        $ins = $conn->prepare("INSERT INTO payment_approvals (payment_id, requested_by, requested_by_username) VALUES (?, ?, ?)");
        $ins->bind_param("iis", $payment_id, $user_id, $username);
        $ins->execute();

        $lmsg = "Payment approval requested for Invoice #{$pmt['invoice_no']} (₱" . number_format($pmt['amount'], 2) . ") by $username.";
        $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PAYMENTS . "', ?, ?)");
        $lg->bind_param("iis", $user_id, $payment_id, $lmsg);
        $lg->execute();

        header("Location: payments.php?success=" . urlencode("Request submitted. Awaiting Step 1 review."));
        exit();

    // ── STEP 1 APPROVE: Admin or Superadmin ───────────────────────────────────
    case 'approve_step1':
        if (!in_array($role, ROLES_PAYMENT_APPROVERS)) {
            header("Location: payments.php?error=" . urlencode("Unauthorized."));
            exit();
        }

        $approval_id = intval($_POST['approval_id']);
        $aq = $conn->prepare(
            "SELECT pa.*, sp.invoice_no, sp.amount
             FROM payment_approvals pa
             JOIN supplier_payments sp ON sp.id = pa.payment_id
             WHERE pa.id = ? AND pa.status = '" . APPROVAL_PENDING_STEP1 . "' LIMIT 1"
        );
        $aq->bind_param("i", $approval_id);
        $aq->execute();
        $ap = $aq->get_result()->fetch_assoc();

        if (!$ap) {
            header("Location: payments.php?error=" . urlencode("Approval not found or already processed."));
            exit();
        }

        // Admin cannot approve their own request
        if ($ap['requested_by'] == $user_id && $role !== ROLE_SUPERADMIN) {
            header("Location: payments.php?error=" . urlencode("You cannot approve a request you submitted."));
            exit();
        }

        $upd = $conn->prepare("UPDATE payment_approvals SET step1_approver_id=?, step1_username=?, step1_at=NOW(), step1_action='" . APPROVAL_APPROVED . "', status='" . APPROVAL_PENDING_STEP2 . "' WHERE id=?");
        $upd->bind_param("isi", $user_id, $username, $approval_id);
        $upd->execute();

        $lmsg = "Step 1 approved for Invoice #{$ap['invoice_no']} (₱" . number_format($ap['amount'], 2) . ") by $username.";
        $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PAYMENTS . "', ?, ?)");
        $lg->bind_param("iis", $user_id, $ap['payment_id'], $lmsg);
        $lg->execute();

        header("Location: payments.php?success=" . urlencode("Step 1 approved. Awaiting final (Step 2) confirmation."));
        exit();

    // ── STEP 2 APPROVE: Superadmin only → marks payment as PAID ──────────────
    case 'approve_step2':
        if ($role !== ROLE_SUPERADMIN) {
            header("Location: payments.php?error=" . urlencode("Only superadmin can give final approval."));
            exit();
        }

        $approval_id = intval($_POST['approval_id']);
        $aq = $conn->prepare(
            "SELECT pa.*, sp.invoice_no, sp.amount
             FROM payment_approvals pa
             JOIN supplier_payments sp ON sp.id = pa.payment_id
             WHERE pa.id = ? AND pa.status = '" . APPROVAL_PENDING_STEP2 . "' LIMIT 1"
        );
        $aq->bind_param("i", $approval_id);
        $aq->execute();
        $ap = $aq->get_result()->fetch_assoc();

        if (!$ap) {
            header("Location: payments.php?error=" . urlencode("Approval not found or already processed."));
            exit();
        }

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE payment_approvals SET step2_approver_id=?, step2_username=?, step2_at=NOW(), step2_action='" . APPROVAL_APPROVED . "', status='" . APPROVAL_APPROVED . "' WHERE id=?");
            $upd->bind_param("isi", $user_id, $username, $approval_id);
            $upd->execute();

            $pay = $conn->prepare("UPDATE supplier_payments SET status='" . SUP_PAY_PAID . "' WHERE id=?");
            $pay->bind_param("i", $ap['payment_id']);
            $pay->execute();

            $lmsg = "PAYMENT FINALIZED: Invoice #{$ap['invoice_no']} (₱" . number_format($ap['amount'], 2) . ") marked PAID. Final approval by $username.";
            $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PAYMENTS . "', ?, ?)");
            $lg->bind_param("iis", $user_id, $ap['payment_id'], $lmsg);
            $lg->execute();

            $conn->commit();
            header("Location: payments.php?success=" . urlencode("Payment finalized — Invoice #{$ap['invoice_no']} is now PAID."));
        } catch (\Throwable $e) {
            $conn->rollback();
            header("Location: payments.php?error=" . urlencode("Error finalizing payment: " . $e->getMessage()));
        }
        exit();

    // ── DENY: Admin or Superadmin at any pending step ─────────────────────────
    case 'deny_payment':
        if (!in_array($role, ROLES_PAYMENT_APPROVERS)) {
            header("Location: payments.php?error=" . urlencode("Unauthorized."));
            exit();
        }

        $approval_id = intval($_POST['approval_id']);
        $aq = $conn->prepare(
            "SELECT pa.*, sp.invoice_no, sp.amount
             FROM payment_approvals pa
             JOIN supplier_payments sp ON sp.id = pa.payment_id
             WHERE pa.id = ? AND pa.status IN ('" . APPROVAL_PENDING_STEP1 . "','" . APPROVAL_PENDING_STEP2 . "') LIMIT 1"
        );
        $aq->bind_param("i", $approval_id);
        $aq->execute();
        $ap = $aq->get_result()->fetch_assoc();

        if (!$ap) {
            header("Location: payments.php?error=" . urlencode("Approval request not found."));
            exit();
        }

        $upd = $conn->prepare("UPDATE payment_approvals SET status='" . APPROVAL_DENIED . "' WHERE id=?");
        $upd->bind_param("i", $approval_id);
        $upd->execute();

        $lmsg = "Payment approval DENIED for Invoice #{$ap['invoice_no']} (₱" . number_format($ap['amount'], 2) . ") by $username.";
        $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PAYMENTS . "', ?, ?)");
        $lg->bind_param("iis", $user_id, $ap['payment_id'], $lmsg);
        $lg->execute();

        header("Location: payments.php?success=" . urlencode("Request denied for Invoice #{$ap['invoice_no']}."));
        exit();

    // ── REVERT: Admin/Superadmin reverts PAID → UNPAID (logged + flagged) ─────
    case 'revert_payment':
        if (!in_array($role, ROLES_PAYMENT_APPROVERS)) {
            header("Location: payments.php?error=" . urlencode("Unauthorized."));
            exit();
        }

        $payment_id = intval($_POST['payment_id']);
        $pq = $conn->prepare("SELECT id, invoice_no, amount FROM supplier_payments WHERE id = ? AND status = '" . SUP_PAY_PAID . "' LIMIT 1");
        $pq->bind_param("i", $payment_id);
        $pq->execute();
        $pmt = $pq->get_result()->fetch_assoc();

        if (!$pmt) {
            header("Location: payments.php?error=" . urlencode("Payment not found or not currently PAID."));
            exit();
        }

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE supplier_payments SET status='" . SUP_PAY_UNPAID . "' WHERE id=?");
            $upd->bind_param("i", $payment_id);
            $upd->execute();

            $sf_msg = "Payment reversed: Invoice #{$pmt['invoice_no']} (₱" . number_format($pmt['amount'], 2) . ") reverted to UNPAID by $username (ID: $user_id).";
            $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_PAYMENT_REVERSAL . "','" . SEV_MEDIUM . "',?,'payment',?)");
            $sf->bind_param("is", $payment_id, $sf_msg);
            $sf->execute();

            $lmsg = "PAYMENT REVERSED: Invoice #{$pmt['invoice_no']} (₱" . number_format($pmt['amount'], 2) . ") reverted to UNPAID by $username.";
            $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PAYMENTS . "', ?, ?)");
            $lg->bind_param("iis", $user_id, $payment_id, $lmsg);
            $lg->execute();

            $conn->commit();
            header("Location: payments.php?success=" . urlencode("Payment reverted to UNPAID. A security flag has been recorded."));
        } catch (\Throwable $e) {
            $conn->rollback();
            header("Location: payments.php?error=" . urlencode("Error reverting payment: " . $e->getMessage()));
        }
        exit();

    default:
        header("Location: payments.php");
        exit();
}
