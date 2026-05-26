<?php
/**
 * product_search.php — Simple product search API used by exchange.php and other pages.
 * Returns active products matching a name or barcode query.
 */
include '../../config/db.php';
include '../../includes/auth_check.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$q    = trim($_GET['q'] ?? '');
$mode = trim($_GET['mode'] ?? 'general'); // 'exchange' limits to in-stock only

if (strlen($q) < 2) { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$base_status = PRODUCT_ACTIVE;

if ($mode === 'exchange') {
    // Only return in-stock products for exchange replacements
    $stmt = $conn->prepare("
        SELECT MIN(p.id) AS id, p.name, p.barcode, MAX(p.price) AS price, SUM(p.quantity) AS total_qty
        FROM products p
        WHERE p.status = ? AND p.quantity > 0
          AND (p.expiry_date IS NULL OR p.expiry_date > CURDATE())
          AND (p.name LIKE ? OR p.barcode LIKE ?)
        GROUP BY p.name
        ORDER BY p.name ASC
        LIMIT 20
    ");
    $stmt->bind_param("sss", $base_status, $like, $like);
} else {
    $stmt = $conn->prepare("
        SELECT MIN(p.id) AS id, p.name, p.barcode, MAX(p.price) AS price, SUM(p.quantity) AS total_qty
        FROM products p
        WHERE p.status = ?
          AND (p.name LIKE ? OR p.barcode LIKE ?)
        GROUP BY p.name
        ORDER BY p.name ASC
        LIMIT 20
    ");
    $stmt->bind_param("sss", $base_status, $like, $like);
}

$stmt->execute();
$rows = $stmt->get_result();
$out  = [];
while ($r = $rows->fetch_assoc()) $out[] = $r;
echo json_encode($out);
