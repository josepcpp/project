<?php
include '../config/db.php';
include '../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: refund_management.php?tab=queue");
    exit();
}
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    header("Location: refund_management.php?tab=queue&error=" . urlencode("Security token mismatch. Please try again."));
    exit();
}

$request_id    = intval($_POST['request_id'] ?? 0);
$action        = trim($_POST['action']       ?? '');
$reject_reason = trim($_POST['reject_reason'] ?? '');
$user_id       = $_SESSION['user_id']   ?? null;
$uname         = $_SESSION['username']  ?? 'Unknown';
$back_url      = "refund_management.php?tab=queue";

if (!$request_id || !in_array($action, ['approve', 'reject'])) {
    header("Location: {$back_url}&error=" . urlencode("Invalid action."));
    exit();
}

// ── REJECT ────────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    if (empty($reject_reason)) {
        header("Location: {$back_url}&error=" . urlencode("A rejection reason is required."));
        exit();
    }
    // C-04 + H-05: transaction with row lock prevents concurrent rejects
    $conn->begin_transaction();
    try {
        $rq = $conn->prepare("SELECT * FROM delivery_return_requests WHERE id = ? FOR UPDATE");
        $rq->bind_param("i", $request_id); $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        if (!$req || $req['status'] !== DR_PENDING) throw new Exception("Request not found or already processed.");

        $upd = $conn->prepare("UPDATE delivery_return_requests
            SET status='" . DR_REJECTED . "', reviewed_by=?, reviewed_username=?, reviewed_at=NOW(), reject_reason=?
            WHERE id=?");
        $upd->bind_param("issi", $user_id, $uname, $reject_reason, $request_id);
        $upd->execute();

        $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
        $log_msg = "DELIVERY RETURN REQUEST #{$request_id} REJECTED by {$uname}. Invoice: {$req['invoice_no']}. Reason: {$reject_reason}";
        $log->bind_param("iis", $user_id, $request_id, $log_msg);
        $log->execute();

        $conn->commit();
    } catch (\Throwable $e) {
        $conn->rollback();
        header("Location: {$back_url}&error=" . urlencode($e->getMessage()));
        exit();
    }

    header("Location: refund_management.php?tab=queue&success=" . urlencode("Return request #{$request_id} has been rejected."));
    exit();
}

// ── APPROVE ───────────────────────────────────────────────────────────────────
$conn->begin_transaction();
try {
    // H-05: lock the row before reading status — prevents concurrent approvals
    $rq = $conn->prepare("SELECT * FROM delivery_return_requests WHERE id = ? FOR UPDATE");
    $rq->bind_param("i", $request_id); $rq->execute();
    $req = $rq->get_result()->fetch_assoc();
    if (!$req || $req['status'] !== DR_PENDING) throw new Exception("Request not found or already processed.");

    // Load items
    $its = $conn->prepare("SELECT * FROM delivery_return_request_items WHERE request_id = ?");
    $its->bind_param("i", $request_id); $its->execute();
    $items = $its->get_result()->fetch_all(MYSQLI_ASSOC);
    if (empty($items)) throw new Exception("No items found in this return request.");
    // Generate ticket number
    $ticket_no = 'DRT-' . date('Ymd') . '-' . str_pad($request_id, 4, '0', STR_PAD_LEFT);

    $total_deduct = 0;

    foreach ($items as $item) {
        $product_id = intval($item['product_id']);
        $return_qty = intval($item['qty']);

        // Validate stock
        $pq = $conn->prepare("SELECT name, quantity, price FROM products WHERE id = ? AND supplier_id = ?");
        $pq->bind_param("ii", $product_id, $req['supplier_id']); $pq->execute();
        $product = $pq->get_result()->fetch_assoc();

        // C-05: fail the entire approval if any referenced product no longer exists
        if (!$product) throw new Exception("Product ID {$product_id} not found or does not belong to this supplier. Approval cancelled.");

        $new_qty    = max(0, $product['quantity'] - $return_qty);
        $new_status = ($new_qty <= 0) ? PRODUCT_ARCHIVED : PRODUCT_ACTIVE;

        $upd_p = $conn->prepare("UPDATE products SET quantity = ?, status = ?, archived_at = IF(? = '" . PRODUCT_ARCHIVED . "', NOW(), archived_at) WHERE id = ?");
        $upd_p->bind_param("isis", $new_qty, $new_status, $new_status, $product_id);
        $upd_p->execute();

        // Record in delivery_returns (legacy table for audit)
        $ins_dr = $conn->prepare("INSERT INTO delivery_returns (supplier_id, product_id, qty, reason, deduct_pay)
            VALUES (?, ?, ?, ?, ?)");
        $ins_dr->bind_param("iiisi", $req['supplier_id'], $product_id, $return_qty, $item['reason'], $req['deduct_pay']);
        $ins_dr->execute();

        if ($req['deduct_pay']) {
            $unit_price   = floatval($item['unit_price'] ?: $product['price']);
            $total_deduct += $return_qty * $unit_price;
        }
    }

    // Deduct from unpaid supplier payment
    if ($req['deduct_pay'] && $total_deduct > 0) {
        $pay_upd = $conn->prepare("UPDATE supplier_payments
            SET amount = GREATEST(0, amount - ?)
            WHERE supplier_id = ? AND status = '" . SUP_PAY_UNPAID . "'
            ORDER BY id DESC LIMIT 1");
        $pay_upd->bind_param("di", $total_deduct, $req['supplier_id']);
        $pay_upd->execute();
    }

    // Update request status
    $upd_r = $conn->prepare("UPDATE delivery_return_requests
        SET status='" . DR_APPROVED . "', ticket_no=?, reviewed_by=?, reviewed_username=?, reviewed_at=NOW()
        WHERE id=?");
    $upd_r->bind_param("sisi", $ticket_no, $user_id, $uname, $request_id);
    $upd_r->execute();

    $conn->commit();

    // Activity log (after commit to avoid rollback)
    $item_count  = count($items);
    $deduct_note = ($req['deduct_pay'] && $total_deduct > 0)
        ? ' | ₱' . number_format($total_deduct, 2) . ' deducted from supplier balance.'
        : '';
    $log_msg = "DELIVERY RETURN TICKET {$ticket_no} APPROVED by {$uname}. Invoice: {$req['invoice_no']} ({$req['supplier_name']}). {$item_count} item type(s) returned.{$deduct_note}";
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
    if ($log) {
        $log->bind_param("iis", $user_id, $request_id, $log_msg);
        $log->execute();
    }

    header("Location: refund_management.php?tab=queue&success=" . urlencode("Ticket {$ticket_no} generated. Return processed successfully.") . "&ticket=" . urlencode($ticket_no) . "&view_id={$request_id}");
} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: {$back_url}&error=" . urlencode($e->getMessage()));
}
exit();
