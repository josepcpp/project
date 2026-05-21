<?php
include '../../config/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id']);
    $qty = intval($_POST['qty']);
    $cost = floatval($_POST['cost']);

    if ($qty < 0 || $cost < 0) {
        echo json_encode(['success' => false, 'message' => 'Values cannot be negative']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE delivery_queue SET qty = ?, cost = ? WHERE id = ?");
    $stmt->bind_param("idi", $qty, $cost, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}