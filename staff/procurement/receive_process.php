<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_once '../../includes/batch_lock.php';
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
    $is_ajax       = !empty($_POST['_ajax']);

    // Unified response helpers — JSON for AJAX calls, redirect otherwise.
    // $ajax_redirect (optional): when set, the AJAX client is told to navigate there
    // instead of just showing the error inline (used for "no longer editable" cases).
    $err = function (string $msg, string $fallback_url = '', string $ajax_redirect = '') use ($is_ajax, &$batch_id) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            $out = ['success' => false, 'error' => $msg];
            if ($ajax_redirect !== '') $out['redirect'] = $ajax_redirect;
            echo json_encode($out);
            exit();
        }
        $url = $fallback_url ?: "receive_items.php?batch_id={$batch_id}";
        header("Location: {$url}&error=" . urlencode($msg));
        exit();
    };
    $ok = function (string $url) use ($is_ajax) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'redirect' => $url]);
            exit();
        }
        header("Location: {$url}");
        exit();
    };

    if (!$batch_id) $err("Invalid batch.", "receive_batch.php?");

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

    // Distinct, accurate messages so the receiver knows WHY (and that an earlier
    // submit likely already succeeded) instead of a vague "not editable".
    if (!$batch) {
        $err("This batch belongs to another receiver or is no longer available.",
             "receive_batch.php?",
             "receive_batch.php?error=" . urlencode("This batch belongs to another receiver or is no longer available."));
    }
    if ($batch['status'] !== 'pending_request') {
        $status_labels = [
            'pending_validation'    => 'In Review',
            'validated_tally'       => 'Validated',
            'validated_discrepancy' => 'Discrepancy Found',
            'on_hold'               => 'On Hold',
            'completed'             => 'Completed',
            'rejected'              => 'Rejected',
        ];
        $status_label = $status_labels[$batch['status']] ?? ucwords(str_replace('_', ' ', $batch['status']));
        $msg = "Batch #{$batch_id} is already in \"{$status_label}\" stage and can no longer be edited.";
        // This is INFORMATIONAL, not an error — surface it in blue (info), not red.
        // Both the AJAX and full-page paths land on the batch list with ?info=.
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'redirect' => "receive_batch.php?info=" . urlencode($msg)]);
            exit();
        }
        header("Location: receive_batch.php?info=" . urlencode($msg));
        exit();
    }

    // Validate items
    $validated = [];
    foreach ($items_post as $row) {
        $desc = trim($row['description'] ?? '');
        $qty  = intval($row['qty'] ?? 0);

        if ($desc === '') continue;
        if ($qty < 1)     $err("Quantity must be at least 1 for each item.");

        $barcode     = trim($row['barcode']     ?? '') ?: null;
        $box_barcode = trim($row['box_barcode'] ?? '') ?: null;
        if ($barcode === null && $box_barcode === null) {
            $err("Each item needs at least one barcode (per-item or box).");
        }
        // NB- codes are system-generated; guard against the near-impossible collision.
        if ($barcode !== null && str_starts_with($barcode, 'NB-')) {
            $chk = $conn->prepare("SELECT id FROM products WHERE barcode = ? LIMIT 1");
            $chk->bind_param("s", $barcode);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $err("Internal ID collision for \"{$desc}\" — remove the row and re-add it to generate a new code.");
            }
        }
        $is_box_item = ($box_barcode !== null) || (intval($row['box_qty'] ?? 0) >= 1);
        $box_units   = $is_box_item ? max(1, intval($row['qty_per_box'] ?? 1)) : 1;
        $expiry_date = trim($row['expiry_date'] ?? '') ?: null;
        if (!empty($row['has_expiry']) && $expiry_date === null) {
            $err("An item marked \"with expiry\" is missing its expiry date.");
        }
        $damaged_qty  = max(0, intval($row['damaged_qty'] ?? 0));
        $damage_notes = trim($row['damage_notes'] ?? '') ?: null;

        $category = trim($row['category'] ?? '');
        if ($category === '' || !array_key_exists($category, PRODUCT_CATEGORIES)) {
            $err("Select a valid category for \"{$desc}\".");
        }

        $validated[] = compact('barcode', 'box_barcode', 'box_units', 'desc', 'qty', 'expiry_date', 'damaged_qty', 'damage_notes', 'category');
    }

    if (empty($validated)) $err("Add at least one item before saving.");

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM receiving_items WHERE batch_id = ?");
        $del->bind_param("i", $batch_id);
        $del->execute();

        $ins = $conn->prepare("INSERT INTO receiving_items (batch_id, barcode, box_barcode, box_units, description, category, quantity, expiry_date, damaged_qty, damage_notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($validated as $v) {
            $ins->bind_param("ississisis", $batch_id, $v['barcode'], $v['box_barcode'], $v['box_units'], $v['desc'], $v['category'], $v['qty'], $v['expiry_date'], $v['damaged_qty'], $v['damage_notes']);
            $ins->execute();
        }

        batch_lock_release($conn, $batch_id, $user_id);

        if ($submit_action === 'submit') {
            $upd = $conn->prepare(
                "UPDATE receiving_batches
                 SET status = 'pending_validation', receiver_id = ?, receiver_username = ?
                 WHERE id = ?"
            );
            $upd->bind_param("isi", $user_id, $username, $batch_id);
            $upd->execute();

            $al = $conn->prepare("INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action) VALUES (?,?,?,?,'items_encoded')");
            $al->bind_param("iiss", $batch_id, $user_id, $username, $role);
            $al->execute();

            $conn->commit();
            $ok("receive_batch.php?success=" . urlencode("Batch #$batch_id submitted. Validator will review shortly."));
        } else {
            if (!$batch['receiver_id']) {
                $claim = $conn->prepare("UPDATE receiving_batches SET receiver_id = ?, receiver_username = ? WHERE id = ? AND receiver_id IS NULL");
                $claim->bind_param("isi", $user_id, $username, $batch_id);
                $claim->execute();
            }
            $conn->commit();
            $ok("receive_items.php?batch_id=$batch_id&success=" . urlencode("Items saved."));
        }

    } catch (Throwable $e) {
        $conn->rollback();
        $err($e->getMessage());
    }
}

header("Location: receive_batch.php");
exit();
