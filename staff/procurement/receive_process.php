<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN, ROLE_SUPERADMIN]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: receive_batch.php");
    exit();
}
csrf_verify('receive_batch.php');

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');
$action   = $_POST['action'] ?? '';

if ($action === 'save_items') {
    $batch_id      = intval($_POST['batch_id'] ?? 0);
    $submit_action = trim($_POST['submit_action'] ?? 'save');
    $items_post    = $_POST['items'] ?? [];

    if (!$batch_id) {
        header("Location: receive_batch.php?error=" . urlencode("Invalid batch."));
        exit();
    }

    // Receiver can act on their own batches OR unclaimed admin-created vouchers
    if ($role === ROLE_RECEIVER) {
        $bq = $conn->prepare("SELECT id, status, receiver_id FROM receiving_batches WHERE id = ? AND (receiver_id = ? OR receiver_id IS NULL) LIMIT 1");
        $bq->bind_param("ii", $batch_id, $user_id);
    } else {
        $bq = $conn->prepare("SELECT id, status, receiver_id FROM receiving_batches WHERE id = ? LIMIT 1");
        $bq->bind_param("i", $batch_id);
    }
    $bq->execute();
    $batch = $bq->get_result()->fetch_assoc();

    if (!$batch || $batch['status'] !== 'pending_request') {
        header("Location: receive_batch.php?error=" . urlencode("Batch is not editable."));
        exit();
    }

    // Validate items
    $validated = [];
    foreach ($items_post as $row) {
        $desc = trim($row['description'] ?? '');
        $qty  = intval($row['qty'] ?? 0);

        if ($desc === '') continue;
        if ($qty < 1) {
            header("Location: receive_items.php?batch_id=$batch_id&error=" . urlencode("Quantity must be at least 1 for each item."));
            exit();
        }

        $barcode     = trim($row['barcode']     ?? '') ?: null;
        $expiry_date = trim($row['expiry_date'] ?? '') ?: null;

        $validated[] = compact('barcode', 'desc', 'qty', 'expiry_date');
    }

    if (empty($validated)) {
        header("Location: receive_items.php?batch_id=$batch_id&error=" . urlencode("Add at least one item before saving."));
        exit();
    }

    $conn->begin_transaction();
    try {
        // Replace items
        $del = $conn->prepare("DELETE FROM receiving_items WHERE batch_id = ?");
        $del->bind_param("i", $batch_id);
        $del->execute();

        $ins = $conn->prepare("INSERT INTO receiving_items (batch_id, barcode, description, quantity, expiry_date) VALUES (?,?,?,?,?)");
        foreach ($validated as $v) {
            $ins->bind_param("issis", $batch_id, $v['barcode'], $v['desc'], $v['qty'], $v['expiry_date']);
            $ins->execute();
        }

        if ($submit_action === 'submit') {
            // Assign receiver + promote to pending_validation in one step
            $upd = $conn->prepare(
                "UPDATE receiving_batches
                 SET status = 'pending_validation',
                     receiver_id = ?, receiver_username = ?
                 WHERE id = ?"
            );
            $upd->bind_param("isi", $user_id, $username, $batch_id);
            $upd->execute();

            $al = $conn->prepare("INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action) VALUES (?,?,?,?,'items_encoded')");
            $al->bind_param("iiss", $batch_id, $user_id, $username, $role);
            $al->execute();

            $conn->commit();
            header("Location: receive_batch.php?success=" . urlencode("Batch #$batch_id submitted. Validator will review shortly."));
        } else {
            // Save draft — claim the voucher if it's still unclaimed
            if (!$batch['receiver_id']) {
                $claim = $conn->prepare("UPDATE receiving_batches SET receiver_id = ?, receiver_username = ? WHERE id = ? AND receiver_id IS NULL");
                $claim->bind_param("isi", $user_id, $username, $batch_id);
                $claim->execute();
            }

            $conn->commit();
            header("Location: receive_items.php?batch_id=$batch_id&success=" . urlencode("Items saved."));
        }
        exit();

    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: receive_items.php?batch_id=$batch_id&error=" . urlencode($e->getMessage()));
        exit();
    }
}

header("Location: receive_batch.php");
exit();
