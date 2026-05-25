<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action  = $_POST['action'];
    $user_id = $_SESSION['user_id'] ?? null;

    if ($action === 'save_product') {
        $name        = trim($_POST['name']);
        $barcode     = trim($_POST['barcode']);
        $price       = floatval($_POST['price']);
        $qty         = intval($_POST['quantity']);
        $supplier_id = intval($_POST['supplier_id']);

        $check = $conn->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
        $check->bind_param("s", $name);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {
            $existing = $res->fetch_assoc();
            $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ?, price = ?, supplier_id = ? WHERE id = ?");
            $stmt->bind_param("idii", $qty, $price, $supplier_id, $existing['id']);
        } else {
            if (empty($barcode)) $barcode = "628" . substr(time(), -7);
            $stmt = $conn->prepare("INSERT INTO products (name, barcode, price, quantity, supplier_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssdis", $name, $barcode, $price, $qty, $supplier_id);
        }

        if ($stmt->execute()) {
            header("Location: inventory.php?success=Stock Updated");
            exit();
        }
    }
}
