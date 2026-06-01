<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../config/settings.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// All staff-level roles may submit refunds (non-admins create pending requests;
// Admin+ auto-processes). Only fully unknown/unauthenticated roles are blocked.
if (!in_array(strtolower($_SESSION['role'] ?? ''), [ROLE_STAFF, ROLE_ADMIN, ROLE_OWNER, ROLE_SUPERADMIN, ROLE_RECEIVER, ROLE_VALIDATOR, ROLE_PRICE_CHECKER])) {
    header("Location: ../dashboard.php?error=" . urlencode("Insufficient permissions to process refunds."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: refund_management.php");
    exit();
}

// C-01: CSRF validation
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    header("Location: refund_management.php?error=" . urlencode("Invalid request. Please try again."));
    exit();
}

$sale_id     = intval($_POST['sale_id']);
$product_id  = intval($_POST['product_id']);
$refund_qty  = intval($_POST['qty']);
$receipt_no  = trim($_POST['receipt_no'] ?? '');
$disposition = $_POST['disposition'] ?? 'restock';
$user_id     = $_SESSION['user_id'] ?? null;
$role        = strtolower($_SESSION['role'] ?? '');
$is_admin    = in_array($role, ROLES_ADMIN_AND_UP);

$back_ok  = "refund_management.php?tab=sales&receipt_no=" . urlencode($receipt_no);
$back_err = "refund_management.php?tab=sales&receipt_no=" . urlencode($receipt_no);

if ($refund_qty <= 0) {
    header("Location: {$back_err}&error=" . urlencode("Return quantity must be greater than zero."));
    exit();
}

$conn->begin_transaction();

try {
    // ── FETCH SALE HEADER ────────────────────────────────────────────────────
    $s = $conn->prepare("SELECT * FROM sales WHERE id = ?");
    $s->bind_param("i", $sale_id);
    $s->execute();
    $sale = $s->get_result()->fetch_assoc();
    if (!$sale) throw new Exception("Receipt not found.");

    // C-02: Verify product belongs to this sale
    $chk = $conn->prepare("SELECT id FROM sales_items WHERE sale_id = ? AND product_id = ? LIMIT 1");
    $chk->bind_param("ii", $sale_id, $product_id);
    $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) throw new Exception("Product does not belong to this receipt.");

    $old_grand_total   = floatval($sale['total']);
    $original_discount = floatval($sale['discount_amount'] ?? 0);

    // ── FETCH PRODUCT NAME ───────────────────────────────────────────────────
    $pn = $conn->prepare("SELECT name FROM products WHERE id = ?");
    $pn->bind_param("i", $product_id);
    $pn->execute();
    $prod_name = $pn->get_result()->fetch_assoc()['name'] ?? 'Product';

    // ── RE-PRICING CALCULATION (shared by both paths) ────────────────────────
    $items_q = $conn->prepare("
        SELECT si.*, p.price as master_retail,
               p.bulk_qty_half, p.price_half_box,
               p.bulk_qty_full, p.price_full_box
        FROM sales_items si
        JOIN products p ON si.product_id = p.id
        WHERE si.sale_id = ?
    ");
    $items_q->bind_param("i", $sale_id);
    $items_q->execute();
    $rows = $items_q->get_result()->fetch_all(MYSQLI_ASSOC);

    // RR-1: account for already-processed refunds to block double-refunding
    $already_q = $conn->prepare("SELECT COALESCE(SUM(qty), 0) AS already FROM refunds WHERE sale_id = ? AND product_id = ? AND status != '" . REFUND_REJECTED . "'");
    $already_q->bind_param("ii", $sale_id, $product_id);
    $already_q->execute();
    $already_refunded = intval($already_q->get_result()->fetch_assoc()['already'] ?? 0);

    $new_raw_subtotal = 0;
    foreach ($rows as $row) {
        $qty = intval($row['qty']);
        if (intval($row['product_id']) === $product_id) {
            $qty -= ($refund_qty + $already_refunded);
            if ($qty < 0) throw new Exception("Cannot refund more units than were purchased or have already been refunded.");
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

    $new_net_total       = max(0, $new_raw_subtotal - $original_discount);
    $new_grand_total     = round($new_net_total * (1 + TAX_RATE), 2);
    $estimated_refund    = round($old_grand_total - $new_grand_total, 2);

    // ════════════════════════════════════════════════════════════════════════
    //  STAFF PATH — create pending request, no DB accounting changes
    // ════════════════════════════════════════════════════════════════════════
    if (!$is_admin) {
        $ins = $conn->prepare("
            INSERT INTO refunds
                (sale_id, product_id, qty, disposition, amount_refunded, reason, status, requested_by)
            VALUES (?, ?, ?, ?, ?, 'Customer Return', '" . REFUND_PENDING . "', ?)
        ");
        $ins->bind_param("iiisdi", $sale_id, $product_id, $refund_qty, $disposition, $estimated_refund, $user_id);
        $ins->execute();

        $msg = "REFUND REQUEST #$receipt_no: $refund_qty pcs of \"$prod_name\" — awaiting admin approval. Est. ₱" . number_format($estimated_refund, 2);
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DISPOSAL . "', ?, ?)");
        if ($log) {
            $log->bind_param("iis", $user_id, $product_id, $msg);
            $log->execute();
        }

        $conn->commit();
        header("Location: {$back_ok}&success=" . urlencode("Refund request submitted. Awaiting admin approval. Estimated: ₱" . number_format($estimated_refund, 2)));
        exit();
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ADMIN / SUPERADMIN PATH — process immediately
    // ════════════════════════════════════════════════════════════════════════

    // Update sales_items quantities
    foreach ($rows as $row) {
        $qty = intval($row['qty']);
        if (intval($row['product_id']) === $product_id) $qty -= $refund_qty;
        $up = $conn->prepare("UPDATE sales_items SET qty = ? WHERE id = ?");
        $up->bind_param("ii", $qty, $row['id']);
        $up->execute();
    }

    // Restock if applicable
    if ($disposition === 'restock') {
        // RR-4: only restore status to ACTIVE when qty was 0 (auto-archived); don't touch deliberately-archived products
        $up_p = $conn->prepare(
            "UPDATE products
             SET quantity = quantity + ?,
                 status   = IF(quantity = 0, '" . PRODUCT_ACTIVE . "', status),
                 archived_at = IF(quantity = 0, NULL, archived_at)
             WHERE id = ?"
        );
        $up_p->bind_param("ii", $refund_qty, $product_id);
        $up_p->execute();
    }

    // Update sale total
    $up_sale = $conn->prepare("UPDATE sales SET total = ? WHERE id = ?");
    $up_sale->bind_param("di", $new_grand_total, $sale_id);
    $up_sale->execute();

    // Insert approved refund record
    $now = date('Y-m-d H:i:s');
    $ins = $conn->prepare("
        INSERT INTO refunds
            (sale_id, product_id, qty, disposition, amount_refunded, reason, status, requested_by, reviewed_by, reviewed_at)
        VALUES (?, ?, ?, ?, ?, 'Customer Return', '" . REFUND_APPROVED . "', ?, ?, ?)
    ");
    $ins->bind_param("iiisdiis", $sale_id, $product_id, $refund_qty, $disposition, $estimated_refund, $user_id, $user_id, $now);
    $ins->execute();

    // Log
    $msg = "REFUND #$receipt_no: $refund_qty pcs of \"$prod_name\". Cash: ₱" . number_format($estimated_refund, 2);
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message, old_value, new_value) VALUES (?, '" . LOG_DISPOSAL . "', ?, ?, ?, ?)");
    if ($log) {
        $old_v = (string)$old_grand_total;
        $new_v = (string)$new_grand_total;
        $log->bind_param("iisss", $user_id, $product_id, $msg, $old_v, $new_v);
        $log->execute();
    }

    $conn->commit();
    header("Location: {$back_ok}&success=" . urlencode("Refund processed: ₱" . number_format($estimated_refund, 2) . " issued."));

} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: {$back_err}&error=" . urlencode($e->getMessage()));
}
exit();
