<?php
include '../../config/db.php';
header('Content-Type: application/json');

if (isset($_GET['product_id'])) {
    $pid = intval($_GET['product_id']);
    
    $query = "SELECT old_price, new_price, change_date
              FROM price_history
              WHERE product_id = ?
              ORDER BY change_date DESC";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while($row = $result->fetch_assoc()) {
        $row['formatted_date'] = date("M d, Y | h:i A", strtotime($row['change_date']));
        $history[] = $row;
    }
    
    echo json_encode($history);
}