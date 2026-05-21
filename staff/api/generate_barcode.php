<?php
include '../../config/db.php';
header('Content-Type: application/json');

function generateUniqueBarcode($conn) {
    do {
        // Format: 628 (Prefix) + Time (Last 6 digits) + Cryptographic random (4 digits)
        $newBarcode = "628" . substr(time(), -6) . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $check = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
        $check->bind_param("s", $newBarcode);
        $check->execute();
    } while ($check->get_result()->num_rows > 0);

    return $newBarcode;
}

echo json_encode(['barcode' => generateUniqueBarcode($conn)]);