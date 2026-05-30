<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN, ROLE_SUPERADMIN]);

header('Content-Type: application/json');

$barcode = trim($_GET['barcode'] ?? '');
if ($barcode === '') {
    echo json_encode(['found' => false]);
    exit();
}

// Most-recent product row matching this barcode
$q = $conn->prepare(
    "SELECT name, expiry_date FROM products WHERE barcode = ? ORDER BY id DESC LIMIT 1"
);
$q->bind_param("s", $barcode);
$q->execute();
$row = $q->get_result()->fetch_assoc();

if ($row) {
    echo json_encode([
        'found'       => true,
        'name'        => $row['name'],
        'expiry_date' => $row['expiry_date'] ?? '',
    ]);
} else {
    echo json_encode(['found' => false]);
}
