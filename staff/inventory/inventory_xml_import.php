<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = $_SESSION['user_id'] ?? null;
$role    = strtolower($_SESSION['role'] ?? 'staff');

if (!$user_id) {
    header("Location: ../../auth/login.php");
    exit();
}

if ($role === ROLE_STAFF) {
    header("Location: stock_management.php?error=" . urlencode("Unauthorized: only admin/superadmin can import inventory."));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['xml_file'])) {
    header("Location: stock_management.php?error=" . urlencode("No file received."));
    exit();
}

$file = $_FILES['xml_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    header("Location: stock_management.php?error=" . urlencode("Upload error code: " . $file['error']));
    exit();
}

if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xml') {
    header("Location: stock_management.php?error=" . urlencode("Only .xml files are accepted."));
    exit();
}

// Restrict file size to 2 MB
if ($file['size'] > 2 * 1024 * 1024) {
    header("Location: stock_management.php?error=" . urlencode("File too large. Maximum 2 MB."));
    exit();
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($file['tmp_name']);

if ($xml === false) {
    $errs   = libxml_get_errors();
    $errmsg = !empty($errs) ? trim($errs[0]->message) : "Unknown XML parse error.";
    header("Location: stock_management.php?error=" . urlencode("XML error: $errmsg"));
    exit();
}

// Accept <product> or <item> child elements
$items = $xml->product ?? $xml->item ?? [];

if (empty($items) || count($items) === 0) {
    header("Location: stock_management.php?error=" . urlencode("No <product> or <item> elements found in XML."));
    exit();
}

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$skip_reasons = [];

$conn->begin_transaction();
try {
    foreach ($items as $item) {
        $name     = trim((string)($item->name     ?? ''));
        $barcode  = trim((string)($item->barcode  ?? ''));
        $price    = floatval($item->price    ?? 0);
        $quantity = intval($item->quantity   ?? 0);
        $category = trim((string)($item->category ?? 'General'));
        $category = $category !== '' ? $category : 'General';

        if ($name === '' || $barcode === '') {
            $skipped++;
            $skip_reasons[] = "Missing name or barcode near '{$name}'.";
            continue;
        }

        if ($price < 0 || $quantity < 0) {
            $skipped++;
            $skip_reasons[] = "Negative value for '{$name}' — skipped.";
            continue;
        }

        // Upsert by barcode
        $chk = $conn->prepare("SELECT id, name, quantity, status FROM products WHERE barcode = ? LIMIT 1");
        $chk->bind_param("s", $barcode);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if ($existing) {
            $new_qty = $existing['quantity'] + $quantity;
            $upd = $conn->prepare("UPDATE products SET name=?, price=?, quantity=?, category=?, status='" . PRODUCT_ACTIVE . "' WHERE barcode=?");
            $upd->bind_param("sdiss", $name, $price, $new_qty, $category, $barcode);
            $upd->execute();

            $lmsg = "XML Import: Updated '{$name}' (#{$barcode}) — qty +{$quantity} → {$new_qty}, price ₱" . number_format($price, 2) . ".";
            $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
            if ($lg) { $lg->bind_param("iis", $user_id, $existing['id'], $lmsg); $lg->execute(); }
            $updated++;
        } else {
            $ins = $conn->prepare("INSERT INTO products (name, barcode, price, quantity, category, status) VALUES (?, ?, ?, ?, ?, '" . PRODUCT_ACTIVE . "')");
            $ins->bind_param("ssdis", $name, $barcode, $price, $quantity, $category);
            $ins->execute();
            $new_id = $conn->insert_id;

            $lmsg = "XML Import: Added '{$name}' (#{$barcode}), qty {$quantity}, price ₱" . number_format($price, 2) . ".";
            $lg = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
            if ($lg) { $lg->bind_param("iis", $user_id, $new_id, $lmsg); $lg->execute(); }
            $inserted++;
        }
    }

    $conn->commit();

    $msg = "Import complete: {$inserted} added, {$updated} updated";
    if ($skipped > 0) $msg .= ", {$skipped} skipped";
    $msg .= ".";
    header("Location: stock_management.php?success=" . urlencode($msg));
} catch (\Throwable $e) {
    $conn->rollback();
    header("Location: stock_management.php?error=" . urlencode("Import failed: " . $e->getMessage()));
}
exit();
