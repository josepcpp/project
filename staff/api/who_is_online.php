<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);
header('Content-Type: application/json');

$threshold_minutes = 15;

$q = $conn->query(
    "SELECT id, username, full_name, role, last_seen_at, last_seen_page
     FROM users
     WHERE last_seen_at >= NOW() - INTERVAL {$threshold_minutes} MINUTE
       AND status = '" . USER_ACTIVE . "'
     ORDER BY last_seen_at DESC"
);

// Friendly page label map
$page_labels = [
    '/project/staff/dashboard.php'                          => 'Dashboard',
    '/project/staff/pos/pos.php'                            => 'Point of Sale',
    '/project/staff/pos/checkout.php'                       => 'Checkout',
    '/project/staff/procurement/receive_batch.php'          => 'Receive Stock',
    '/project/staff/procurement/receive_items.php'          => 'Encoding Batch',
    '/project/staff/procurement/validate_batch.php'         => 'Validation Queue',
    '/project/staff/procurement/validate_items.php'         => 'Validating Batch',
    '/project/staff/procurement/price_checker.php'          => 'Price Checker',
    '/project/staff/procurement/supplier_payments.php'      => 'Supplier Payments',
    '/project/staff/procurement/batches_pending.php'        => 'Create Voucher',
    '/project/staff/inventory/stock_management.php'         => 'Live Stock Levels',
    '/project/staff/inventory/price_maintenance.php'        => 'Master Price Table',
    '/project/staff/inventory/product_info.php'             => 'Product Info',
    '/project/staff/sales/returns_exchange.php'             => 'Returns & Exchange',
    '/project/staff/users/users.php'                        => 'User Management',
    '/project/staff/settings.php'                           => 'Settings',
    '/project/staff/activity_logs.php'                      => 'Activity Logs',
];

$users = [];
if ($q) {
    while ($row = $q->fetch_assoc()) {
        $page     = $row['last_seen_page'] ?? '';
        $label    = $page_labels[$page] ?? ucwords(str_replace(['/project/staff/', '.php', '_'], ['', '', ' '], $page));
        $mins_ago = $row['last_seen_at']
            ? (int)floor((time() - strtotime($row['last_seen_at'])) / 60)
            : null;

        $users[] = [
            'id'         => (int)$row['id'],
            'username'   => $row['username'],
            'full_name'  => $row['full_name'] ?? '',
            'role'       => $row['role'],
            'page_label' => $label,
            'mins_ago'   => $mins_ago,
            'status'     => $mins_ago !== null && $mins_ago <= 5 ? 'active' : 'idle',
        ];
    }
}

echo json_encode(['users' => $users, 'ts' => time()]);
