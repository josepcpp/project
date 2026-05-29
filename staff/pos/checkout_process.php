<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../config/settings.php';
include '../../includes/csrf.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = $_SESSION['user_id'] ?? null;

// SEC-1: Reject forged cross-origin POST requests
csrf_verify('checkout.php');

// 1. Guard: cart must exist
if (empty($_SESSION['cart'])) {
    header("Location: pos.php");
    exit();
}

// 2. Collect payment inputs — total is NOT trusted from POST; recalculated server-side (POS-1)
$cash              = floatval($_POST['cash'] ?? 0);
$payment_mode      = $_POST['payment_mode'] ?? 'Cash';
$reference_no      = !empty($_POST['reference_no']) ? trim($_POST['reference_no']) : null;
$discount_id       = intval($_POST['discount_id'] ?? 0);
$promo_typed       = trim($_POST['promo_code'] ?? '');
$tax_enabled       = intval($_POST['tax_enabled'] ?? 1); // 1 = VAT on, 0 = exempt
$customer_group_id = intval($_POST['customer_group_id'] ?? 0); // F-06

$discount_name      = "None";
$target_discount_id = 0;
$discount_type      = 'Fixed';
$discount_value     = 0.0;

// F-11: Load price rounding rule
$rounding_rule = 'none';
$rr_q = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='price_rounding_rule' LIMIT 1");
if ($rr_q && $rr_row = $rr_q->fetch_assoc()) $rounding_rule = $rr_row['setting_value'] ?? 'none';

// F-11: Rounding helper — mirrors JS applyRounding() in checkout.php.
// GAP-20: Both implementations MUST stay in sync. When adding a new rounding case
// here, add the identical case to the JS applyRounding() function in checkout.php.
function applyRounding(float $total, string $rule): float {
    switch ($rule) {
        case 'nearest_25c':   return round($total * 4) / 4;
        case 'nearest_50c':   return round($total * 2) / 2;
        case 'nearest_peso':  return round($total);
        case 'nearest_5peso': return round($total / 5) * 5;
        default:              return $total;
    }
}

$conn->begin_transaction();

try {
    // 3. Identify & lock discount INSIDE transaction (POS-3: prevents race condition on usage limit)
    $discount_scope             = 'store';
    $discount_target_product_id = 0;
    $discount_target_category   = '';

    $disc_fields = "id, name, type, value, usage_limit, used_count, start_date, end_date, scope, target_product_id, target_category";
    $today_date  = date('Y-m-d');

    if ($discount_id > 0) {
        $disc_q = $conn->prepare("SELECT {$disc_fields} FROM discounts WHERE id = ? AND is_active = 1 FOR UPDATE");
        $disc_q->bind_param("i", $discount_id);
        $disc_q->execute();
        $row = $disc_q->get_result()->fetch_assoc();
    } elseif (!empty($promo_typed)) {
        $disc_q = $conn->prepare("SELECT {$disc_fields} FROM discounts WHERE promo_code = ? AND is_active = 1 LIMIT 1 FOR UPDATE");
        $disc_q->bind_param("s", $promo_typed);
        $disc_q->execute();
        $row = $disc_q->get_result()->fetch_assoc();
    } else {
        $row = null;
    }

    if ($row) {
        if ($row['type'] === 'Percentage' && $row['value'] > 100)
            throw new Exception("Invalid discount: percentage cannot exceed 100%.");
        if ($row['usage_limit'] > 0 && $row['used_count'] >= $row['usage_limit'])
            throw new Exception("Discount \"{$row['name']}\" has reached its usage limit.");
        // Schedule validation
        if (!empty($row['start_date']) && $today_date < $row['start_date'])
            throw new Exception("Promo \"{$row['name']}\" has not started yet (starts " . date('M d, Y', strtotime($row['start_date'])) . ").");
        if (!empty($row['end_date']) && $today_date > $row['end_date'])
            throw new Exception("Promo \"{$row['name']}\" has already expired.");

        $discount_name              = !empty($promo_typed) ? $row['name'] . " (Code: $promo_typed)" : $row['name'];
        $target_discount_id         = $row['id'];
        $discount_type              = $row['type'];
        $discount_value             = floatval($row['value']);
        $discount_scope             = $row['scope'] ?? 'store';
        $discount_target_product_id = intval($row['target_product_id'] ?? 0);
        $discount_target_category   = $row['target_category'] ?? '';
    }

    // 4. Generate receipt number
    $receipt = "RCPT-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -4));

    // 5. First pass: re-fetch live prices from DB, validate stock, recalculate line totals (POS-2)
    $server_subtotal = 0.0;
    $items_to_insert = [];

    foreach ($_SESSION['cart'] as $pid => $item) {
        // POS-2: FOR UPDATE locks the row so two concurrent checkouts can't both
        // pass the stock check and oversell the last unit(s).
        $p_query = $conn->prepare(
            "SELECT name, quantity, barcode, price, bulk_qty_full, bulk_qty_half, price_full_box, price_half_box
             FROM products WHERE id = ? FOR UPDATE"
        );
        $p_query->bind_param("i", $pid);
        $p_query->execute();
        $product_data = $p_query->get_result()->fetch_assoc();

        if (!$product_data) throw new Exception("Product ID $pid was recently deleted.");

        $cart_qty = intval($item['qty']);
        if ($product_data['quantity'] < $cart_qty)
            throw new Exception("Not enough stock for " . $product_data['name']);

        // CALC-5: Also check effective_qty (total across lots minus price-locked units)
        // This prevents selling stock that is reserved for a pending price update.
        $bc = $product_data['barcode'] ?? '';
        if ($bc !== '') {
            $eff_chk = $conn->prepare(
                "SELECT COALESCE(SUM(quantity),0) AS tq FROM products WHERE barcode = ? AND status = '" . PRODUCT_ACTIVE . "'"
            );
            $eff_chk->bind_param("s", $bc); $eff_chk->execute();
            $avail_total = intval($eff_chk->get_result()->fetch_assoc()['tq']);

            $lk_chk = $conn->prepare(
                "SELECT COALESCE(SUM(locked_qty),0) AS lq FROM price_update_requests WHERE barcode = ? AND status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')"
            );
            $lk_chk->bind_param("s", $bc); $lk_chk->execute();
            $avail_locked = intval($lk_chk->get_result()->fetch_assoc()['lq']);

            if (max(0, $avail_total - $avail_locked) < $cart_qty)
                throw new Exception("Not enough available stock for \"" . $product_data['name'] . "\" — some units are reserved for a pending price update.");
        }

        // Recalculate line_total from current DB price — same bulk logic as pos_process.php
        $retail = floatval($product_data['price']);
        $bqf    = intval($product_data['bulk_qty_full']   ?? 0);
        $bqh    = intval($product_data['bulk_qty_half']   ?? 0);
        $pfb    = floatval($product_data['price_full_box'] ?? 0);
        $phb    = floatval($product_data['price_half_box'] ?? 0);

        if ($bqf > 0 && $cart_qty >= $bqf) {
            $extra      = $cart_qty - $bqf;
            $line_total = $pfb + $extra * $retail;
        } elseif ($bqh > 0 && $cart_qty >= $bqh) {
            $extra      = $cart_qty - $bqh;
            $line_total = $phb + $extra * $retail;
        } else {
            $line_total = $cart_qty * $retail;
        }

        $effective_unit = ($cart_qty > 0) ? ($line_total / $cart_qty) : $retail;
        $server_subtotal += $line_total;

        $items_to_insert[] = [
            'pid'        => intval($pid),
            'qty'        => $cart_qty,
            'price'      => $effective_unit,
            'line_total' => $line_total,
            'data'       => $product_data,
        ];
    }

    // 6a. F-06: Server-side customer-group discount (applied before promo)
    $group_discount_amt  = 0.0;
    $group_discount_name = '';
    if ($customer_group_id > 0) {
        $grp_q = $conn->prepare("SELECT name, label, discount_type, discount_value FROM customer_groups WHERE id = ? AND is_active = 1");
        $grp_q->bind_param("i", $customer_group_id);
        $grp_q->execute();
        $grp_row = $grp_q->get_result()->fetch_assoc();
        if ($grp_row) {
            $grp_type  = $grp_row['discount_type'];
            $grp_value = floatval($grp_row['discount_value']);
            $group_discount_amt = ($grp_type === DISCOUNT_PERCENTAGE)
                ? ($server_subtotal * ($grp_value / 100))
                : min($grp_value, $server_subtotal);
            $group_discount_name = $grp_row['label'] ?: $grp_row['name'];
        }
    }
    $after_group_subtotal = max(0.0, $server_subtotal - $group_discount_amt);

    // 6a-bundle. F-13: Bundle discounts from session — applied after group, before promo.
    // Each bundle's discount is already pre-computed in pos_process.php (individual_total − bundle_price).
    // We cap each bundle's discount at the remaining subtotal to prevent negative totals.
    $bundle_discount_total = 0.0;
    $bundle_names          = [];
    foreach (($_SESSION['bundle_discounts'] ?? []) as $bd) {
        $bundle_discount_total += floatval($bd['amount']);
        $bundle_names[]         = $bd['name'] . ($bd['qty'] > 1 ? ' ×'.$bd['qty'] : '');
    }
    $bundle_discount_total    = min($bundle_discount_total, $after_group_subtotal);
    $after_bundle_subtotal    = max(0.0, $after_group_subtotal - $bundle_discount_total);
    if (!empty($bundle_names) && $bundle_discount_total > 0) {
        $group_discount_name = trim(($group_discount_name ? "$group_discount_name group" : '')
            . ($group_discount_name ? ' + ' : '')
            . 'Bundle: ' . implode(', ', $bundle_names));
    }

    // 6b. Server-side promo discount — applied on post-bundle subtotal, scope-aware (POS-1)
    $discount_amt = 0.0;
    if ($target_discount_id > 0) {
        if ($discount_scope === 'product' && $discount_target_product_id > 0) {
            $discountable = 0.0;
            foreach ($items_to_insert as $e) {
                if ($e['pid'] === $discount_target_product_id) $discountable += $e['line_total'];
            }
        } elseif ($discount_scope === 'category' && $discount_target_category !== '') {
            $discountable = 0.0;
            foreach ($items_to_insert as $e) {
                if (($e['data']['category'] ?? '') === $discount_target_category) $discountable += $e['line_total'];
            }
        } else {
            $discountable = $after_bundle_subtotal; // store-wide on post-bundle subtotal
        }
        $discount_amt = ($discount_type === DISCOUNT_PERCENTAGE)
            ? $discountable * ($discount_value / 100)
            : min($discount_value, $discountable);
    }

    // 6c. Compose readable discount label for the receipt
    $full_discount_label = trim(
        ($group_discount_name ? $group_discount_name : '')
        . ($group_discount_name && $discount_name !== 'None' ? ' + ' : '')
        . ($discount_name !== 'None' ? $discount_name : '')
    ) ?: 'None';

    $net_subtotal = max(0.0, $after_bundle_subtotal - $discount_amt);

    // 6d. F-14: Tax calculation — exclusive (default) or inclusive mode
    // exclusive: tax is added on top of prices → total = net + tax
    // inclusive: tax is already embedded in prices → total unchanged (VAT ON), or strip VAT (exempt)
    $tax_display_mode = 'exclusive';
    $tdm_q = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='tax_display_mode' LIMIT 1");
    if ($tdm_q && $tdm_row = $tdm_q->fetch_assoc()) $tax_display_mode = $tdm_row['setting_value'] ?? 'exclusive';

    if ($tax_display_mode === 'inclusive') {
        // VAT already embedded in every price
        $tax_amt = $tax_enabled
            ? $net_subtotal * (TAX_RATE / (1 + TAX_RATE))   // extract embedded VAT for audit
            : 0.0;
        $pre_round_total = $tax_enabled ? $net_subtotal : $net_subtotal / (1 + TAX_RATE); // exempt strips it
    } else {
        // Exclusive (default): add VAT on top
        $tax_amt         = $tax_enabled ? $net_subtotal * TAX_RATE : 0.0;
        $pre_round_total = $net_subtotal + $tax_amt;
    }

    // 6e. F-11: Apply price rounding rule
    $total = applyRounding($pre_round_total, $rounding_rule);

    // Reject insufficient cash payment (POS-5: underpayment guard)
    if ($payment_mode === PAY_METHOD_CASH && $cash < $total) {
        throw new Exception("Insufficient payment. ₱" . number_format($cash, 2) . " tendered for ₱" . number_format($total, 2) . " total.");
    }

    $change = max(0.0, $cash - $total); // CALC-4

    // 7. Save sale header (with group + bundle discount columns F-06/F-13)
    $stmt = $conn->prepare("INSERT INTO sales (receipt_no, total, cash, change_amt, payment_mode, reference_no, discount_name, customer_group_id, group_discount_amt, bundle_discount_amt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sdddsssisd", $receipt, $total, $cash, $change, $payment_mode, $reference_no, $full_discount_label, $customer_group_id, $group_discount_amt, $bundle_discount_total);
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

            if ($locked > 0 && $rem_qty - $locked <= 0) {
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

                    // GAP-8: Log the auto-apply so there is a clear audit trail
                    $ap_msg = "AUTO-APPLIED deferred price for barcode {$bc_check}: "
                            . "₱" . number_format($def_req['current_price'], 2)
                            . " → ₱" . number_format($def_req['proposed_price'], 2)
                            . " (request #{$def_req['id']} triggered by sale #{$receipt} — old-price stock exhausted)";
                    $ap_log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PRICES . "', ?, ?)");
                    $ap_log->bind_param("iis", $user_id, $def_req['product_id'], $ap_msg);
                    $ap_log->execute();
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
    $_SESSION['cart']             = [];
    $_SESSION['bundle_discounts'] = []; // F-13: clear bundle discounts with cart
    header("Location: receipt.php");
    exit();

} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: pos.php?error=" . urlencode($e->getMessage()));
    exit();
}
