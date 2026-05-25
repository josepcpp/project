<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../config/settings.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = $_SESSION['user_id'] ?? null;

// 1. Guard: cart must exist
if (empty($_SESSION['cart'])) {
    header("Location: pos.php");
    exit();
}

// 2. Collect payment inputs — total is NOT trusted from POST; recalculated server-side (POS-1)
$cash         = floatval($_POST['cash'] ?? 0);
$payment_mode = $_POST['payment_mode'] ?? 'Cash';
$reference_no = !empty($_POST['reference_no']) ? trim($_POST['reference_no']) : null;
$discount_id  = intval($_POST['discount_id'] ?? 0);
$promo_typed  = trim($_POST['promo_code'] ?? '');
$tax_enabled  = intval($_POST['tax_enabled'] ?? 1); // 1 = VAT on, 0 = exempt

$discount_name      = "None";
$target_discount_id = 0;
$discount_type      = 'Fixed';
$discount_value     = 0.0;

$conn->begin_transaction();

try {
    // 3. Identify & lock discount INSIDE transaction (POS-3: prevents race condition on usage limit)
    if ($discount_id > 0) {
        $disc_q = $conn->prepare("SELECT id, name, type, value, usage_limit, used_count FROM discounts WHERE id = ? AND is_active = 1 FOR UPDATE");
        $disc_q->bind_param("i", $discount_id);
        $disc_q->execute();
        if ($row = $disc_q->get_result()->fetch_assoc()) {
            if ($row['type'] === 'Percentage' && $row['value'] > 100)
                throw new Exception("Invalid discount: percentage cannot exceed 100%.");
            if ($row['usage_limit'] > 0 && $row['used_count'] >= $row['usage_limit'])
                throw new Exception("Discount \"{$row['name']}\" has reached its usage limit.");
            $discount_name      = $row['name'];
            $target_discount_id = $discount_id;
            $discount_type      = $row['type'];
            $discount_value     = floatval($row['value']);
        }
    } elseif (!empty($promo_typed)) {
        $promo_q = $conn->prepare("SELECT id, name, type, value, usage_limit, used_count FROM discounts WHERE promo_code = ? AND is_active = 1 LIMIT 1 FOR UPDATE");
        $promo_q->bind_param("s", $promo_typed);
        $promo_q->execute();
        if ($row = $promo_q->get_result()->fetch_assoc()) {
            if ($row['type'] === 'Percentage' && $row['value'] > 100)
                throw new Exception("Invalid discount: percentage cannot exceed 100%.");
            if ($row['usage_limit'] > 0 && $row['used_count'] >= $row['usage_limit'])
                throw new Exception("Promo code \"$promo_typed\" has reached its usage limit.");
            $discount_name      = $row['name'] . " (Code: $promo_typed)";
            $target_discount_id = $row['id'];
            $discount_type      = $row['type'];
            $discount_value     = floatval($row['value']);
        }
    }

    // 4. Generate receipt number
    $receipt = "RCPT-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -4));

    // 5. First pass: re-fetch live prices from DB, validate stock, recalculate line totals (POS-2)
    $server_subtotal = 0.0;
    $items_to_insert = [];

    foreach ($_SESSION['cart'] as $pid => $item) {
        $p_query = $conn->prepare(
            "SELECT name, quantity, barcode, price, bulk_qty_full, bulk_qty_half, price_full_box, price_half_box
             FROM products WHERE id = ?"
        );
        $p_query->bind_param("i", $pid);
        $p_query->execute();
        $product_data = $p_query->get_result()->fetch_assoc();

        if (!$product_data) throw new Exception("Product ID $pid was recently deleted.");

        $cart_qty = intval($item['qty']);
        if ($product_data['quantity'] < $cart_qty)
            throw new Exception("Not enough stock for " . $product_data['name']);

        // Recalculate line_total from current DB price — same bulk logic as pos_process.php
        $retail = floatval($product_data['price']);
        $bqf    = intval($product_data['bulk_qty_full']   ?? 0);
        $bqh    = intval($product_data['bulk_qty_half']   ?? 0);
        $pfb    = floatval($product_data['price_full_box'] ?? 0);
        $phb    = floatval($product_data['price_half_box'] ?? 0);

        if ($bqf > 0 && $cart_qty >= $bqf) {
            $extra      = $cart_qty - $bqf;
            $line_total = $pfb + ($extra * $retail);
        } elseif ($bqh > 0 && $cart_qty >= $bqh) {
            $extra      = $cart_qty - $bqh;
            $line_total = $phb + ($extra * $retail);
        } else {
            $line_total = $cart_qty * $retail;
        }

        $effective_unit = ($cart_qty > 0) ? ($line_total / $cart_qty) : $retail;
        $server_subtotal += $line_total;

        $items_to_insert[] = [
            'pid'   => intval($pid),
            'qty'   => $cart_qty,
            'price' => $effective_unit,
            'data'  => $product_data,
        ];
    }

    // 6. Server-side total calculation (POS-1)
    $discount_amt = 0.0;
    if ($target_discount_id > 0) {
        $discount_amt = ($discount_type === DISCOUNT_PERCENTAGE)
            ? ($server_subtotal * ($discount_value / 100))
            : $discount_value;
    }
    $net_subtotal = max(0.0, $server_subtotal - $discount_amt);
    $tax_amt      = $tax_enabled ? ($net_subtotal * TAX_RATE) : 0.0;
    $total        = round($net_subtotal + $tax_amt, 2);
    $change       = $total <= 0 ? 0.0 : max(0.0, $cash - $total);

    // 7. Save sale header
    $stmt = $conn->prepare("INSERT INTO sales (receipt_no, total, cash, change_amt, payment_mode, reference_no, discount_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sdddsss", $receipt, $total, $cash, $change, $payment_mode, $reference_no, $discount_name);
    $stmt->execute();
    $sale_id = $conn->insert_id;

    // 8. Log sale activity
    $log_type = LOG_SALES;
    $log_msg  = "Completed sale #$receipt. Mode: $payment_mode. Total: ₱" . number_format($total, 2);
    $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, ?, ?, ?)");
    if ($log_stmt) {
        $log_stmt->bind_param("isis", $user_id, $log_type, $sale_id, $log_msg);
        $log_stmt->execute();
    }

    // 9. Increment discount usage
    if ($target_discount_id > 0) {
        $stmt_disc = $conn->prepare("UPDATE discounts SET used_count = used_count + 1 WHERE id = ?");
        $stmt_disc->bind_param("i", $target_discount_id);
        $stmt_disc->execute();
    }

    // 10. Second pass: insert sales_items, deduct stock, auto-apply deferred price
    foreach ($items_to_insert as $entry) {
        $pid          = $entry['pid'];
        $cart_qty     = $entry['qty'];
        $eff_price    = $entry['price'];
        $product_data = $entry['data'];

        $stmt_item = $conn->prepare("INSERT INTO sales_items (sale_id, product_id, qty, price) VALUES (?, ?, ?, ?)");
        $stmt_item->bind_param("iiid", $sale_id, $pid, $cart_qty, $eff_price);
        $stmt_item->execute();

        // 🛠️ Automated soft-archive when stock hits zero
        $new_qty    = $product_data['quantity'] - $cart_qty;
        $new_status = ($new_qty <= 0) ? PRODUCT_ARCHIVED : PRODUCT_ACTIVE;

        $update_stock = $conn->prepare("UPDATE products SET quantity = ?, status = ?, archived_at = IF(? = '" . PRODUCT_ARCHIVED . "', NOW(), archived_at) WHERE id = ?");
        $update_stock->bind_param("isis", $new_qty, $new_status, $new_status, $pid);
        $update_stock->execute();

        // Auto-apply deferred price when old-price stock is exhausted
        $bc_check = $product_data['barcode'] ?? '';
        if ($bc_check !== '') {
            $tq_s = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS tq FROM products WHERE barcode = ? AND status = '" . PRODUCT_ACTIVE . "'");
            $tq_s->bind_param("s", $bc_check); $tq_s->execute();
            $rem_qty = intval($tq_s->get_result()->fetch_assoc()['tq'] ?? 0);

            $lq_s = $conn->prepare("SELECT COALESCE(SUM(locked_qty),0) AS lq FROM price_update_requests WHERE barcode = ? AND status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')");
            $lq_s->bind_param("s", $bc_check); $lq_s->execute();
            $locked = intval($lq_s->get_result()->fetch_assoc()['lq'] ?? 0);

            if ($locked > 0 && ($rem_qty - $locked) <= 0) {
                $def_s = $conn->prepare("SELECT * FROM price_update_requests WHERE barcode = ? AND status = '" . PRICE_REQ_DEFERRED . "' ORDER BY id ASC LIMIT 1");
                $def_s->bind_param("s", $bc_check); $def_s->execute();
                $def_req = $def_s->get_result()->fetch_assoc();
                if ($def_req) {
                    $auto_uname = $_SESSION['username'] ?? 'system';

                    $ph_s = $conn->prepare("INSERT INTO price_history (product_id, old_price, new_price) VALUES (?, ?, ?)");
                    $ph_s->bind_param("idd", $def_req['product_id'], $def_req['current_price'], $def_req['proposed_price']); $ph_s->execute();

                    $upd_p = $conn->prepare("UPDATE products SET price = ?, tiers_locked = 1 WHERE barcode = ? AND status IN ('" . PRODUCT_ACTIVE . "','" . PRODUCT_ARCHIVED . "')");
                    $upd_p->bind_param("ds", $def_req['proposed_price'], $bc_check); $upd_p->execute();

                    $upd_r = $conn->prepare("UPDATE price_update_requests SET status='" . PRICE_REQ_APPLIED . "', applied_by=?, applied_username=?, applied_at=NOW() WHERE id=?");
                    $upd_r->bind_param("isi", $user_id, $auto_uname, $def_req['id']); $upd_r->execute();

                    $upd_c = $conn->prepare("UPDATE price_update_requests SET current_price = ? WHERE barcode = ? AND id != ? AND status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')");
                    $upd_c->bind_param("dsi", $def_req['proposed_price'], $bc_check, $def_req['id']); $upd_c->execute();
                }
            }
        }
    }

    $conn->commit();

    $_SESSION['receipt'] = [
        'no'           => $receipt,
        'total'        => $total,
        'cash'         => $cash,
        'change'       => $change,
        'payment_mode' => $payment_mode,
        'discount'     => $discount_name,
        'date'         => date("M d, Y h:i A"),
    ];
    $_SESSION['cart'] = [];
    header("Location: receipt.php");
    exit();

} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: pos.php?error=" . urlencode($e->getMessage()));
    exit();
}
