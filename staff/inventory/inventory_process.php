<?php
include '../../includes/auth_check.php';
include '../../config/db.php';

$barcode  = trim($_POST['barcode']  ?? '');
$name     = trim($_POST['name']     ?? '');
$price    = floatval($_POST['price']    ?? 0);
$quantity = intval($_POST['quantity']   ?? 0);

$stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
$stmt->bind_param("s", $barcode);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ?, price = ? WHERE barcode = ?");
    $stmt->bind_param("ids", $quantity, $price, $barcode);
    $stmt->execute();
} else {
    $stmt = $conn->prepare("INSERT INTO products (barcode, name, price, quantity) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssdi", $barcode, $name, $price, $quantity);
    $stmt->execute();
}

header("Location: /project/staff/inventory/inventory.php");
exit();
