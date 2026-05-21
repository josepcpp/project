<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── GUARDS ────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$role    = strtolower($_SESSION['role'] ?? ROLE_STAFF);
$user_id = intval($_SESSION['user_id']);
$uname   = $_SESSION['username'] ?? 'Unknown';

// Only staff submit physical counts; admins review and finalize.
if (in_array($role, ROLES_ADMIN_AND_UP)) {
    header("Location: dashboard.php?error=" . urlencode("Admins review recounts — only staff submit physical counts."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['alert_ids'])) {
    header("Location: dashboard.php");
    exit();
}

// ── INPUT ─────────────────────────────────────────────────────────────────────
$alert_ids   = $_POST['alert_ids'];
$actual_qtys = $_POST['actual_qtys'];

$submitted        = 0;
$mismatched       = 0;
$newly_supervised = [];

// ── PROCESS EACH ALERT ────────────────────────────────────────────────────────
foreach ($alert_ids as $i => $raw_id) {
    $alert_id   = intval($raw_id);
    $actual_qty = intval($actual_qtys[$i] ?? 0);

    if ($actual_qty < 0 || $alert_id <= 0) continue;

    // Lock the row to the recounting status — prevents acting on already-resolved alerts.
    $aq = $conn->prepare("SELECT * FROM quantity_alerts WHERE id = ? AND status = '" . ALERT_RECOUNTING . "' LIMIT 1");
    $aq->bind_param("i", $alert_id);
    $aq->execute();
    $alert = $aq->get_result()->fetch_assoc();
    if (!$alert) continue;

    // Resolve expected quantity: use the locked baseline set at approval time, or
    // fall back to current live stock if the baseline was never set.
    $expected = intval($alert['expected_qty'] ?? 0);
    if ($expected === 0) {
        if (!empty($alert['product_id'])) {
            $pq    = $conn->prepare("SELECT COALESCE(quantity,0) AS total FROM products WHERE id = ? AND status = '" . PRODUCT_ACTIVE . "'");
            $pq_id = intval($alert['product_id']);
            $pq->bind_param("i", $pq_id);
        } else {
            $pq = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS total FROM products WHERE barcode = ? AND status = '" . PRODUCT_ACTIVE . "'");
            $pq->bind_param("s", $alert['barcode']);
        }
        $pq->execute();
        $expected = intval($pq->get_result()->fetch_assoc()['total'] ?? 0);
    }

    $variance         = $expected - $actual_qty;
    $current_fails    = intval($alert['fail_count'] ?? 0);

    if ($variance !== 0) {
        // ── MISMATCH: increment fail counter, keep in recounting ─────────────
        $new_fail_count = $current_fails + 1;
        $mismatched++;

        $stmt = $conn->prepare("
            UPDATE quantity_alerts
               SET actual_qty   = ?,
                   expected_qty = ?,
                   variance     = ?,
                   submitted_by = ?,
                   submitted_at = NOW(),
                   fail_count   = ?
             WHERE id = ? AND status = '" . ALERT_RECOUNTING . "'
        ");
        $stmt->bind_param("iiiiii", $actual_qty, $expected, $variance, $user_id, $new_fail_count, $alert_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $submitted++;

            // ── Log the mismatch for audit trail ─────────────────────────────
            $ml_prod_id = intval($alert['product_id']  ?? 0);
            $ml_sup_id  = intval($alert['supplier_id'] ?? 0);
            $ml_pname   = $alert['product_name'] ?? '';
            $ml_barcode = $alert['barcode']      ?? '';
            $ml_invoice = $alert['invoice']      ?? '';

            $ml = $conn->prepare("
                INSERT INTO recount_mismatch_log
                    (alert_id, product_id, product_name, barcode, invoice, supplier_id,
                     expected_qty, submitted_qty, variance, fail_number, submitted_by, submitted_username)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if ($ml) {
                $ml->bind_param("iisssiiiiiis",
                    $alert_id, $ml_prod_id, $ml_pname, $ml_barcode,
                    $ml_invoice, $ml_sup_id,
                    $expected, $actual_qty, $variance, $new_fail_count, $user_id, $uname);
                $ml->execute();
            }

            $msg = "RECOUNT MISMATCH (fail #{$new_fail_count}): Alert #{$alert_id} '{$alert['product_name']}' "
                 . "— expected: {$expected}, actual: {$actual_qty}, variance: {$variance}. Returned to recounting.";
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_INVENTORY . "', ?, ?)");
            if ($log) { $log->bind_param("iis", $user_id, $alert_id, $msg); $log->execute(); }

            // ── DOUBLE FAIL: flag all involved staff for supervision ──────────
            if ($new_fail_count >= 2) {
                $involved = array_unique(array_filter([
                    $user_id,
                    intval($alert['flagged_by']   ?? 0),
                    intval($alert['submitted_by'] ?? 0),
                ]));

                // Pull the procurement batch staff for cross-reference.
                if (!empty($ml_invoice)) {
                    $pb_q = $conn->prepare("SELECT staff_id FROM procurement_batches WHERE invoice = ? LIMIT 1");
                    $pb_q->bind_param("s", $ml_invoice);
                    $pb_q->execute();
                    $pb_row = $pb_q->get_result()->fetch_assoc();
                    if ($pb_row && $pb_row['staff_id']) $involved[] = intval($pb_row['staff_id']);
                    $involved = array_unique(array_filter($involved));
                }

                foreach ($involved as $inv_uid) {
                    if ($inv_uid <= 0) continue;
                    $flag_q = $conn->prepare("
                        UPDATE users
                           SET supervision_flag = '" . SUPERVISION_SUPERVISED . "',
                               supervision_flagged_at = NOW()
                         WHERE id = ? AND role = '" . ROLE_STAFF . "' AND supervision_flag = '" . SUPERVISION_NONE . "'
                    ");
                    $flag_q->bind_param("i", $inv_uid);
                    $flag_q->execute();
                    if ($flag_q->affected_rows > 0) $newly_supervised[] = $inv_uid;
                }

                $sf_msg = "SUPERVISION TRIGGERED: Alert #{$alert_id} '{$alert['product_name']}' "
                        . "(Invoice: {$alert['invoice']}) failed tally {$new_fail_count} times. "
                        . "Users flagged: " . implode(', ', $involved);
                $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_RECOUNT_DOUBLE_FAIL . "','" . SEV_HIGH . "',?,'quantity_alert',?)");
                if ($sf) { $sf->bind_param("is", $alert_id, $sf_msg); $sf->execute(); }

                $flag_msg = "SUPERVISION FLAG SET: Alert #{$alert_id} failed recount twice. "
                          . count($newly_supervised) . " staff account(s) flagged for supervision.";
                $flag_log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_INVENTORY . "', ?, ?)");
                if ($flag_log) { $flag_log->bind_param("iis", $user_id, $alert_id, $flag_msg); $flag_log->execute(); }
            }
        }

    } else {
        // ── EXACT MATCH: promote to submitted for admin finalization ─────────
        $stmt = $conn->prepare("
            UPDATE quantity_alerts
               SET status       = '" . ALERT_SUBMITTED . "',
                   actual_qty   = ?,
                   expected_qty = ?,
                   variance     = 0,
                   submitted_by = ?,
                   submitted_at = NOW()
             WHERE id = ? AND status = '" . ALERT_RECOUNTING . "'
        ");
        $stmt->bind_param("iiii", $actual_qty, $expected, $user_id, $alert_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $submitted++;
            $msg = "RECOUNT SUBMITTED (matched): Alert #{$alert_id} '{$alert['product_name']}' — count verified at {$actual_qty} units.";
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_INVENTORY . "', ?, ?)");
            if ($log) { $log->bind_param("iis", $user_id, $alert_id, $msg); $log->execute(); }
        }
    }
}

// ── CLEAR RECOUNT ACCESS IF NO MORE ITEMS PENDING ─────────────────────────────
$remaining_q = $conn->query("SELECT COUNT(*) AS c FROM quantity_alerts WHERE status = '" . ALERT_RECOUNTING . "'");
if (intval($remaining_q->fetch_assoc()['c'] ?? 1) === 0) {
    unset($_SESSION['procurement_access']);
}

// ── REDIRECT WITH RESULT MESSAGE ──────────────────────────────────────────────
if ($mismatched > 0) {
    $supervision_note = count($newly_supervised) > 0 ? ' Staff accounts have been flagged for supervision.' : '';
    $msg = "Count mismatch on {$mismatched} item(s). Items returned to Pending Recounts — an admin must resolve the discrepancy.{$supervision_note}";
} elseif ($submitted > 0) {
    $msg = "Physical count submitted for {$submitted} item" . ($submitted > 1 ? 's' : '') . ". Waiting for admin verification.";
} else {
    $msg = "No items were updated. Please try again.";
}

header("Location: delivery_receive.php?success=" . urlencode($msg));
exit();
