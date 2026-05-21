<?php
include '../../config/db.php';
header('Content-Type: application/json');

if (isset($_GET['query'])) {
    $q = trim($_GET['query']);
    $stmt = $conn->prepare("SELECT id, name, price, quantity, barcode,
                            bulk_qty_half, price_half_box,
                            bulk_qty_full, price_full_box
                            FROM products WHERE barcode = ? OR name = ? LIMIT 1");
    $stmt->bind_param("ss", $q, $q);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($product = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'product' => $product]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
    }
}