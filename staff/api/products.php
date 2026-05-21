<?php
include '../config/db.php';

$result = $conn->query("
    SELECT id, name, barcode, price, quantity
    FROM products
    ORDER BY name ASC
");

$products = [];

while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}

header('Content-Type: application/json');
echo json_encode($products);
