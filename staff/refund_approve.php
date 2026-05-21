<?php
include '../config/db.php';
include '../config/settings.php';
include '../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: refund_management.php?tab=queue");
    exit();
}

$action    = $_POST['action'] ?? '';
$refund_id = intval($_POST['refund_id']);
$reviewer  = $_SESSION['user_id'] ?? null;
$role      = strtolower($_SESSION['role'] ?? '');
$note      = trim($_POST['note'] ?? '');

$back = "refund_management.php?tab=queue";

// ── REJECT ────────────────────────────────────────────────────────────────────
if ($action === 'reject') {
    $conn->begin_transaction();
    try {
        // H-05: lock the row so concurrent rejects don't double-write
        $rq = $conn->prepare("SELECT id, status, sale_id, product_id FROM refunds WHERE id = ? FOR UPDATE");
        $rq->bind_param("i", $refund_id);
        $rq->execute();
        $refund = $rq->get_result()->fetch_assoc();
        if (!$refund) throw new Exception("Refund record not found.");
        if ($refund['status'] !== REFUND_PENDING) throw new Exception("This refund is no longer pending.");

        $now  = date('Y-m-d H:i:s');
        // H-01: write to reject_note, not override_note
        $stmt = $conn->prepare("UPDATE refunds SET status='" . REFUND_REJECTED . "', reviewed_by=?, reviewed_at=?, reject_note=? WHERE id=?");
        $stmt->bind_param("issi", $reviewer, $now, $note, $refund_id);
        $stmt->execute();

        $msg = "REFUND REJECTED: Request ID #{$refund_id} for sale ID #{$refund['sale_id']}. Reason: {$note}";
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DISPOSAL . "', ?, ?)");
        if ($log) {
            $log->bind_param("iis", $reviewer, $refund['product_id'], $msg);
            $log->execute();
        }

        $conn->commit();
    } catch (\Throwable $e) {
        $conn->rollback();
        header("Location: {$back}&error=" . urlencode($e->getMessage()));
        exit();
    }
    header("Location: {$back}&success=" . urlencode("Refund request #$refund_id rejected."));
    exit();
}

// ── APPROVE or OVERRIDE ───────────────────────────────────────────────────────
if ($action === 'approve' || $action === 'override') {
    if ($action === 'override' && $role !== ROLE_SUPERADMIN) {
        header("Location: {$back}&error=" . urlencode("Only Super Admin can override refunds."));
        exit();
    }

    $conn->begin_transaction();

    try {
        // H-05: lock the row inside the transaction — prevents concurrent approvals
        $rq = $conn->prepare("SELECT * FROM refunds WHERE id = ? FOR UPDATE");
        $rq->bind_param("i", $refund_id);
        $rq->execute();
        $refund = $rq->get_result()->fetch_assoc();
        if (!$refund) throw new Exception("Refund record not found.");
        if ($action === 'approve' && $refund['status'] !== REFUND_PENDING) throw new Exception("This refund is no longer pending.");
        if ($action === 'override' && $refund['status'] !== REFUND_REJECTED) throw new Exception("Override is only available for rejected refunds.");

    $sale_id     = intval($refund['sale_id']);
    $product_id  = intval($refund['product_id']);
    $refund_qty  = intval($refund['qty']);
    $disposition = $refund['disposition'];
        // Fetch sale header
        $sq = $conn->prepare("SELECT * FROM sales WHERE id = ?");
        $sq->bind_param("i", $sale_id);
        $sq->execute();
        $sale = $sq->get_result()->fetch_assoc();
        if (!$sale) throw new Exception("Original sale record not found.");

        $old_grand_total   = floatval($sale['total']);
        $original_discount = floatval($sale['discount_amount'] ?? 0);

        // Product name for logging
        $pn = $conn->prepare("SELECT name FROM products WHERE id = ?");
        $pn->bind_param("i", $product_id);
        $pn->execute();
        $prod_name = $pn->get_result()->fetch_assoc()['name'] ?? 'Product';

        // Fetch sale items for re-pricing
        $iq = $conn->prepare("
            SELECT si.*, p.price as master_retail,
                   p.bulk_qty_half, p.price_half_box,
                   p.bulk_qty_full, p.price_full_box
            FROM sales_items si
            JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = ?
        ");
        $iq->bind_param("i", $sale_id);
        $iq->execute();
        $rows = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

        // Re-price calculation
        $new_raw_subtotal = 0;
        foreach ($rows as $row) {
            $qty = intval($row['qty']);
            if (intval($row['product_id']) === $product_id) {
                $qty -= $refund_qty;
                if ($qty < 0) throw new Exception("Refund quantity exceeds remaining items on receipt.");
            }
            if ($row['bulk_qty_full'] > 0 && $qty >= $row['bulk_qty_full']) {
                $extra = $qty - $row['bulk_qty_full'];
                $new_raw_subtotal += floatval($row['price_full_box']) + ($extra * floatval($row['master_retail']));
            } elseif ($row['bulk_qty_half'] > 0 && $qty >= $row['bulk_qty_half']) {
                $extra = $qty - $row['bulk_qty_half'];
                $new_raw_subtotal += floatval($row['price_half_box']) + ($extra * floatval($row['master_retail']));
            } else {
                $new_raw_subtotal += $qty * floatval($row['master_retail']);
            }
        }

        $new_net_total   = max(0, $new_raw_subtotal - $original_discount);
        $new_grand_total = round($new_net_total * (1 + TAX_RATE), 2);
        $cash_to_return  = round($old_grand_total - $new_grand_total, 2);

        // Update sales_items quantities
        foreach ($rows as $row) {
            $qty = intval($row['qty']);
            if (intval($row['product_id']) === $product_id) $qty -= $refund_qty;
            $up = $conn->prepare("UPDATE sales_items SET qty = ? WHERE id = ?");
            $up->bind_param("ii", $qty, $row['id']);
            $up->execute();
        }

        // Restock if applicable
        if ($disposition === DISP_RESTOCK) {
            $up_p = $conn->prepare("UPDATE products SET quantity = quantity + ?, status = '" . PRODUCT_ACTIVE . "' WHERE id = ?");
            $up_p->bind_param("ii", $refund_qty, $product_id);
            $up_p->execute();
        }

        // Update sale total
        $up_s = $conn->prepare("UPDATE sales SET total = ? WHERE id = ?");
        $up_s->bind_param("di", $new_grand_total, $sale_id);
        $up_s->execute();

        // Update refund record — H-01: override_note only written for override actions
        $new_status = ($action === 'override') ? REFUND_OVERRIDDEN : REFUND_APPROVED;
        $now        = date('Y-m-d H:i:s');
        if ($action === 'override') {
            $up_r = $conn->prepare("UPDATE refunds SET status=?, amount_refunded=?, reviewed_by=?, reviewed_at=?, override_note=? WHERE id=?");
            $up_r->bind_param("sdissi", $new_status, $cash_to_return, $reviewer, $now, $note, $refund_id);
        } else {
            $up_r = $conn->prepare("UPDATE refunds SET status=?, amount_refunded=?, reviewed_by=?, reviewed_at=? WHERE id=?");
            $up_r->bind_param("sdisi", $new_status, $cash_to_return, $reviewer, $now, $refund_id);
        }
        $up_r->execute();

        // Log — non-blocking: a missing/failed log must never roll back the approval
        $receipt_no = $sale['receipt_no'];
        $prefix     = strtoupper($action);
        $msg = "REFUND {$prefix} #$receipt_no: $refund_qty pcs of \"$prod_name\". ₱" . number_format($cash_to_return, 2) . " issued.";
        if ($note) $msg .= " Note: $note";
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message, old_value, new_value) VALUES (?, '" . LOG_DISPOSAL . "', ?, ?, ?, ?)");
        if ($log) {
            $old_val = (string)$old_grand_total;
            $new_val = (string)$new_grand_total;
            $log->bind_param("iisss", $reviewer, $product_id, $msg, $old_val, $new_val);
            $log->execute();
        }

        $conn->commit();
        $label = $action === 'override' ? 'Overridden & processed' : 'Approved';
        header("Location: {$back}&success=" . urlencode("$label: ₱" . number_format($cash_to_return, 2) . " refund for $prod_name."));

    } catch (\Throwable $e) {
        $conn->rollback();
        header("Location: {$back}&error=" . urlencode($e->getMessage()));
    }
    exit();
}

header("Location: {$back}");
exit();
