<?php
include '../../config/db.php';
header('Content-Type: application/json');

// ── Barcode reverse-lookup (scanner fills name/category/expiry) ───────────────
$barcode = trim($_GET['barcode'] ?? '');
if ($barcode !== '') {
    $stmt = $conn->prepare("SELECT name, barcode, category, price, expiry_date,
                                   bulk_qty_half, price_half_box, bulk_qty_full, price_full_box
                            FROM products
                            WHERE barcode = ? AND status IN ('" . PRODUCT_ACTIVE . "','" . PRODUCT_ARCHIVED . "')
                            ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("s", $barcode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode($row ?: null);
    exit;
}

// ── Name suggestion (case + space insensitive fuzzy search) ───────────────────
$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) { echo json_encode([]); exit; }

// Normalize: strip spaces and lowercase for comparison
$normalized = '%' . str_replace(' ', '', strtolower($query)) . '%';
$like       = '%' . $query . '%';

$stmt = $conn->prepare(
    "SELECT name, barcode, category, price, expiry_date,
            bulk_qty_half, price_half_box, bulk_qty_full, price_full_box,
            status
     FROM products
     WHERE status IN ('" . PRODUCT_ACTIVE . "','" . PRODUCT_ARCHIVED . "')
       AND (name LIKE ? OR REPLACE(LOWER(name),' ','') LIKE ?)
     GROUP BY LOWER(TRIM(name))
     ORDER BY
         CASE WHEN status='" . PRODUCT_ACTIVE . "' THEN 0 ELSE 1 END,
         CASE WHEN LOWER(name) LIKE ? THEN 0 ELSE 1 END,
         name ASC
     LIMIT 8"
);
$stmt->bind_param("sss", $like, $normalized, $like);
$stmt->execute();
$res = $stmt->get_result();

$suggestions = [];
while ($row = $res->fetch_assoc()) {
    $suggestions[] = $row;
}

echo json_encode($suggestions);
