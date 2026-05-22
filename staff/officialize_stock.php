<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_POST['p_ids']) || empty($_POST['p_ids'])) {
    die("Error: No data received from form. Please go back and try again.");
}

$p_ids        = $_POST['p_ids'];
$p_names      = $_POST['p_names'];
$p_barcodes   = $_POST['p_barcodes'];
$prices       = $_POST['prices'];
$final_qtys   = $_POST['final_qtys'];
$batch_qtys   = $_POST['batch_qtys'] ?? [];
$expiry_dates = $_POST['expiry_dates'] ?? [];
$t1_qtys      = $_POST['t1_qtys'];
$t1_prices    = $_POST['t1_prices'];
$t2_qtys      = $_POST['t2_qtys'];
$t2_prices    = $_POST['t2_prices'];

$user_id = $_SESSION['user_id'] ?? null;
$invoice = $_SESSION['active_invoice'] ?? 'N/A';
$sup_id  = $_SESSION['active_batch_id'] ?? 0;
$uname   = $_SESSION['username'] ?? 'Unknown';

$sup_name = '';
if ($sup_id) {
    $sn_q = $conn->prepare("SELECT name FROM suppliers WHERE id = ?");
    $sn_q->bind_param("i", $sup_id); $sn_q->execute();
    $sn_r = $sn_q->get_result()->fetch_assoc();
    $sup_name = $sn_r['name'] ?? '';
}

$conn->begin_transaction();

$price_flags = 0;
$final_ids   = [];

try {
    foreach ($p_ids as $index => $draft_id) {
        $barcode   = $p_barcodes[$index];
        $item_name = $p_names[$index];
        $price     = floatval($prices[$index]);
        $batch_qty = intval($final_qtys[$index]);
        $ordered_qty = intval($batch_qtys[$index] ?? 0);
        $has_discrepancy = ($ordered_qty > 0 && $ordered_qty !== $batch_qty);
        $qty_to_add = $has_discrepancy ? $ordered_qty : $batch_qty;

        if ($batch_qty < 0) {
            throw new Exception("Quantity for '$item_name' cannot be negative.");
        }

        // Lookup by barcode + supplier + expiry — never merge across different expiry lots or suppliers
        $expiry = $expiry_dates[$index] ?? null;
        $expiry = ($expiry === '') ? null : $expiry;
        $master_q = $conn->prepare("SELECT id, price, quantity FROM products WHERE barcode = ? AND supplier_id = ? AND expiry_date <=> ? AND status IN ('" . PRODUCT_ACTIVE . "','" . PRODUCT_ARCHIVED . "') LIMIT 1");
        $master_q->bind_param("sis", $barcode, $sup_id, $expiry); $master_q->execute();
        $master_res = $master_q->get_result();

        // Separate price reference across all suppliers (for spike detection only)
        $price_ref_q = $conn->prepare("SELECT id, price FROM products WHERE barcode = ? AND status IN ('" . PRODUCT_ACTIVE . "','" . PRODUCT_ARCHIVED . "') ORDER BY id ASC LIMIT 1");
        $price_ref_q->bind_param("s", $barcode); $price_ref_q->execute();
        $price_ref = $price_ref_q->get_result()->fetch_assoc();

        if ($master_res->num_rows > 0) {
            $master_row   = $master_res->fetch_assoc();
            $master_id    = $master_row['id'];
            $old_price    = floatval($master_row['price']);
            $old_quantity = intval($master_row['quantity']);

            // Price spike detection: configured multiplier increase from previous price
            if ($old_price > 0 && $price > $old_price * DEFAULT_PRICE_SPIKE_MULTIPLIER) {
                $price_flags++;
                $spike_pct = round((($price - $old_price) / $old_price) * 100);
                $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_PRICE_SPIKE . "','" . SEV_HIGH . "',?,'product',?)");
                $sf_msg = "Price spike on '{$item_name}' (#{$barcode}): ₱" . number_format($old_price, 2) . " → ₱" . number_format($price, 2) . " (+{$spike_pct}%). Invoice: {$invoice}.";
                $sf->bind_param("is", $master_id, $sf_msg);
                $sf->execute();
            }

            // max_quantity = new total after this batch (used for 10% low-stock threshold)
            $new_max      = $old_quantity + $qty_to_add;
            $price_changed = ($old_price > 0 && abs($price - $old_price) > 0.001);

            if ($price_changed) {
                // Queue a new price update request — each delivery at a new price gets its own request
                $pur = $conn->prepare("INSERT INTO price_update_requests (product_id, product_name, barcode, current_price, proposed_price, supplier_id, supplier_name, invoice, submitted_by, submitted_username, locked_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $pur->bind_param("issddissisi", $master_id, $item_name, $barcode, $old_price, $price, $sup_id, $sup_name, $invoice, $user_id, $uname, $qty_to_add);
                $pur->execute();
                $pur_id = $conn->insert_id;
                $pul = $conn->prepare("INSERT INTO price_update_logs (request_id, action, actor_id, actor_username, old_price, new_price) VALUES (?, 'submitted', ?, ?, ?, ?)");
                $pul->bind_param("iisdd", $pur_id, $user_id, $uname, $old_price, $price);
                $pul->execute();
                // Quantity-only update — price stays unchanged pending approval
                $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ?, max_quantity = ?, bulk_qty_half = ?, price_half_box = ?, bulk_qty_full = ?, price_full_box = ?, status = '" . PRODUCT_ACTIVE . "' WHERE id = ?");
                $stmt->bind_param("iiididi", $qty_to_add, $new_max, $t1_qtys[$index], $t1_prices[$index], $t2_qtys[$index], $t2_prices[$index], $master_id);
                $stmt->execute();
            } else {
                // Price unchanged: normal full update
                $stmt = $conn->prepare("UPDATE products SET price = ?, quantity = quantity + ?, max_quantity = ?, bulk_qty_half = ?, price_half_box = ?, bulk_qty_full = ?, price_full_box = ?, status = '" . PRODUCT_ACTIVE . "' WHERE id = ?");
                $stmt->bind_param("diiididi", $price, $qty_to_add, $new_max, $t1_qtys[$index], $t1_prices[$index], $t2_qtys[$index], $t2_prices[$index], $master_id);
                $stmt->execute();
            }

            $conn->query("DELETE FROM products WHERE id = " . intval($draft_id));
            $final_id = $master_id;
        } else {
            // No existing row for this supplier + barcode.
            $existing_price = $price_ref ? floatval($price_ref['price']) : 0;

            // Cross-supplier price spike check
            if ($existing_price > 0 && $price > $existing_price * DEFAULT_PRICE_SPIKE_MULTIPLIER) {
                $price_flags++;
                $spike_pct = round((($price - $existing_price) / $existing_price) * 100);
                $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_PRICE_SPIKE . "','" . SEV_HIGH . "',?,'product',?)");
                $sf_msg = "Price spike on '{$item_name}' (#{$barcode}) from new supplier '{$sup_name}': market ref " . number_format($existing_price, 2) . " -> " . number_format($price, 2) . " (+{$spike_pct}%). Invoice: {$invoice}.";
                $sf->bind_param("is", $draft_id, $sf_msg);
                $sf->execute();
            }

            $cross_price_changed = $existing_price > 0 && abs($price - $existing_price) > 0.001;

            if ($cross_price_changed) {
                // Price differs from existing stock — queue through approval workflow.
                // Activate new row at existing price so all lots stay in sync; proposed price queued for admin.
                $ref_product_id = intval($price_ref['id']);
                $pur = $conn->prepare("INSERT INTO price_update_requests (product_id, product_name, barcode, current_price, proposed_price, supplier_id, supplier_name, invoice, submitted_by, submitted_username, locked_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $pur->bind_param("issddissisi", $ref_product_id, $item_name, $barcode, $existing_price, $price, $sup_id, $sup_name, $invoice, $user_id, $uname, $qty_to_add);
                $pur->execute();
                $pur_id = $conn->insert_id;
                $pul = $conn->prepare("INSERT INTO price_update_logs (request_id, action, actor_id, actor_username, old_price, new_price) VALUES (?, 'submitted', ?, ?, ?, ?)");
                $pul->bind_param("iisdd", $pur_id, $user_id, $uname, $existing_price, $price);
                $pul->execute();
                $stmt = $conn->prepare("UPDATE products SET price = ?, quantity = ?, max_quantity = ?, bulk_qty_half = ?, price_half_box = ?, bulk_qty_full = ?, price_full_box = ?, status = '" . PRODUCT_ACTIVE . "' WHERE id = ?");
                $stmt->bind_param("diiididi", $existing_price, $qty_to_add, $qty_to_add, $t1_qtys[$index], $t1_prices[$index], $t2_qtys[$index], $t2_prices[$index], $draft_id);
                $stmt->execute();
            } else {
                // No existing stock or price matches — activate at specified price directly
                $stmt = $conn->prepare("UPDATE products SET price = ?, quantity = ?, max_quantity = ?, bulk_qty_half = ?, price_half_box = ?, bulk_qty_full = ?, price_full_box = ?, status = '" . PRODUCT_ACTIVE . "' WHERE id = ?");
                $stmt->bind_param("diiididi", $price, $qty_to_add, $qty_to_add, $t1_qtys[$index], $t1_prices[$index], $t2_qtys[$index], $t2_prices[$index], $draft_id);
                $stmt->execute();
            }
            $final_id = $draft_id;
        }

        $final_ids[$index] = $final_id;

        $log_msg = "Officialized Batch ($invoice): Added $qty_to_add units to $item_name.";
        $audit = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
        $audit->bind_param("iis", $user_id, $final_id, $log_msg);
        $audit->execute();
    }

    $conn->commit();

    // ── Post-commit: quantity discrepancy flagging ────────────────────────────
    $discrepancy_count = 0;
    foreach ($p_ids as $index => $draft_id) {
        $batch_qty    = intval($batch_qtys[$index] ?? 0);
        $received_qty = intval($final_qtys[$index]);
        if ($batch_qty > 0 && $batch_qty !== $received_qty) {
            $alert_product_id = intval($final_ids[$index] ?? 0);
            $alert = $conn->prepare("INSERT INTO quantity_alerts (product_name, barcode, invoice, supplier_id, batch_qty, received_qty, flagged_by, product_id, expected_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $alert->bind_param("sssiiiiii", $p_names[$index], $p_barcodes[$index], $invoice, $sup_id, $batch_qty, $received_qty, $user_id, $alert_product_id, $batch_qty);
            $alert->execute();
            $discrepancy_count++;
        }
    }

    // ── Post-commit: update procurement_batches lifecycle row ─────────────────
    $total_items   = count($p_ids);
    $batch_status  = $discrepancy_count > 0 ? BATCH_COMPLETE_ERRORS : BATCH_COMPLETE_CLEAN;
    $proc_batch_id = intval($_SESSION['proc_batch_id'] ?? 0);

    if ($proc_batch_id > 0) {
        // Calculate minutes from encoding start to now
        $pb_q = $conn->prepare("SELECT encoding_started_at FROM procurement_batches WHERE id = ?");
        $pb_q->bind_param("i", $proc_batch_id); $pb_q->execute();
        $pb_row  = $pb_q->get_result()->fetch_assoc();
        $minutes = null;
        if ($pb_row && $pb_row['encoding_started_at']) {
            $diff    = (new DateTime())->diff(new DateTime($pb_row['encoding_started_at']));
            $minutes = $diff->h * 60 + $diff->i;
        }

        $pb_upd = $conn->prepare("UPDATE procurement_batches SET status=?, officialized_at=NOW(), item_count=?, discrepancy_count=?, price_flag_count=?, minutes_to_complete=? WHERE id=?");
        $pb_upd->bind_param("siiiii", $batch_status, $total_items, $discrepancy_count, $price_flags, $minutes, $proc_batch_id);
        $pb_upd->execute();

        // Speed anomaly: batch completed suspiciously fast
        if ($minutes !== null && $minutes < DEFAULT_SPEED_ANOMALY_MINUTES && $total_items >= DEFAULT_SPEED_ANOMALY_MIN_ITEMS) {
            $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_SPEED_ANOMALY . "','" . SEV_HIGH . "',?,'procurement_batch',?)");
            $sf_msg = "Batch #{$proc_batch_id} ({$total_items} items, invoice {$invoice}) was completed in {$minutes} min. Possible rubber-stamping.";
            $sf->bind_param("is", $proc_batch_id, $sf_msg);
            $sf->execute();
        }
    }

    // Repeat discrepancy pattern: same staff with 3+ error batches
    if ($discrepancy_count > 0 && $user_id) {
        $rd_q = $conn->prepare("SELECT COUNT(*) as c FROM procurement_batches WHERE staff_id = ? AND status = '" . BATCH_COMPLETE_ERRORS . "'");
        $rd_q->bind_param("i", $user_id); $rd_q->execute();
        $rd_count = intval($rd_q->get_result()->fetch_assoc()['c'] ?? 0);
        if ($rd_count >= DEFAULT_REPEAT_DISCREPANCY_COUNT) {
            // Only insert if not already open for this user
            $rd_exists = $conn->prepare("SELECT id FROM security_flags WHERE flag_type='" . FLAG_REPEAT_DISCREPANCY . "' AND reference_id=? AND status='" . FLAG_OPEN . "' LIMIT 1");
            $rd_exists->bind_param("i", $user_id); $rd_exists->execute();
            if ($rd_exists->get_result()->num_rows === 0) {
                $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_REPEAT_DISCREPANCY . "','" . SEV_MEDIUM . "',?,'user',?)");
                $sf_msg = "Staff ID {$user_id} has had {$rd_count} batches with quantity discrepancies. Review recommended.";
                $sf->bind_param("is", $user_id, $sf_msg);
                $sf->execute();
            }
        }
    }

    // Clear batch session state
    unset(
        $_SESSION['active_batch_id'],
        $_SESSION['active_batch_name'],
        $_SESSION['active_invoice'],
        $_SESSION['verification_in_progress'],
        $_SESSION['proc_batch_id'],
        $_SESSION['receiving_stage_logged']
    );

    // Revoke procurement access — single-use approval, closes after batch completes
    if ($user_id) {
        $revoke = $conn->prepare("UPDATE users SET procurement_access = '" . PROC_NONE . "', procurement_denial_reason = NULL, locked_supplier_id = NULL WHERE id = ?");
        if ($revoke) { $revoke->bind_param("i", $user_id); $revoke->execute(); }
        $_SESSION['procurement_access'] = PROC_NONE;
        $pal = $conn->prepare("INSERT INTO procurement_access_log (staff_id, staff_username, action) VALUES (?, ?, 'consumed')");
        if ($pal) { $pal->bind_param("is", $user_id, $uname); $pal->execute(); }
    }

    if ($discrepancy_count > 0) {
        header("Location: stock_management.php?success=" . urlencode("Batch officialized — {$discrepancy_count} quantity discrepancy(ies) flagged for admin review."));
    } else {
        header("Location: stock_management.php?success=" . urlencode("Inventory Officialized. All quantities verified."));
    }
    exit();

} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: stock_management.php?error=" . urlencode("Officialize failed: " . $e->getMessage()));
    exit();
}
