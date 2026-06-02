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

// Match a PER-ITEM barcode first, then a BOX (case) barcode. Report which matched
// so the receiver screen can fill the correct field.
$find = function (string $col) use ($conn, $barcode) {
    $q = $conn->prepare("SELECT name, barcode, box_barcode, box_units FROM products WHERE $col = ? ORDER BY id DESC LIMIT 1");
    $q->bind_param("s", $barcode);
    $q->execute();
    return $q->get_result()->fetch_assoc() ?: null;
};

$row  = $find('barcode');
$type = 'item';
if (!$row) { $row = $find('box_barcode'); $type = 'box'; }

if ($row) {
    echo json_encode([
        'found'       => true,
        'match'       => $type,                       // 'item' or 'box' — which code was scanned
        'name'        => $row['name'],
        'barcode'     => $row['barcode'] ?? '',        // product's per-item code (may be blank)
        'box_barcode' => $row['box_barcode'] ?? '',
        'box_units'   => intval($row['box_units'] ?? 1),
    ]);
} else {
    echo json_encode(['found' => false]);
}
