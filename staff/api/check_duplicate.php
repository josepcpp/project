<?php
include '../../config/db.php';
header('Content-Type: application/json');

$name    = trim($_GET['name'] ?? '');
$barcode = trim($_GET['barcode'] ?? '');

if (empty($name)) {
    echo json_encode(['exists' => false]);
    exit;
}

$stmt = $conn->prepare("SELECT barcode, category FROM products WHERE TRIM(LOWER(name)) = TRIM(LOWER(?)) ORDER BY id ASC LIMIT 1");
$stmt->bind_param("s", $name);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    if ($row['barcode'] !== $barcode) {
        echo json_encode([
            'exists'       => true,
            'old_barcode'  => $row['barcode'],
            'old_category' => $row['category']
        ]);
    } else {
        echo json_encode([
            'exists'       => false,
            'match'        => true,
            'old_category' => $row['category']
        ]);
    }
} else {
    echo json_encode(['exists' => false]);
}
