<?php
// 1. 🛡️ SUPERIOR CACHE KILLER
header("Cache-Control: no-cache, no-store, must-revalidate"); 
header("Pragma: no-cache"); 
header("Expires: 0"); 

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/../config/db.php';

// Generate a CSRF token once per session
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// M-01: Idle session timeout — 2 hours of inactivity logs the user out
$_session_timeout = 7200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $_session_timeout) {
    session_unset();
    session_destroy();
    header("Location: /project/auth/login.php?error=" . urlencode("Session expired due to inactivity. Please log in again."));
    exit();
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['user_id'])) {
    header("Location: /project/auth/login.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
$username = $_SESSION['username'] ?? "User";
$role = strtolower($_SESSION['role'] ?? ROLE_STAFF);
$sys_version = "1.2.8"; // Bump version to force CSS refresh
// L-02: session signature for SPA cache invalidation on role/access change
$session_sig  = md5(($_SESSION['user_id'] ?? '') . '|' . $role);

// 📂 INFORMATION ARCHITECTURE
$titles = [
    'pos.php'               => 'Point of Sale',
    'stock_management.php'  => 'Live Stock Levels',
    'product_info.php'      => 'Product Master',
    'suppliers.php'         => 'Supply Vouchers',
    'delivery_receive.php'  => 'Receiving Station',
    'dashboard.php'         => 'Business Analytics',
    'activity_logs.php'     => 'System Audit Logs',    
    'price_maintenance.php' => 'Master Price Table',
    'discount.php'          => 'Promotions',
    'users.php'             => 'Staff Accounts',
    'settings.php'          => 'App Settings',
    'help.php'                      => 'Support',
    'delivery_return_ticket.php'    => 'Return Ticket',
    // Phase 2 additions
    'customer_groups.php'           => 'Customer Groups',
    'backup.php'                    => 'Data Backup',
    'ip_restrictions.php'           => 'IP Restrictions',
    // Phase 3 additions
    'bundles.php'                   => 'Bundle Deals',
    // Phase 4 — Procurement Pipeline
    'receive_batch.php'       => 'Receive Stock',
    'receive_items.php'       => 'Encode Items',
    'batches_pending.php'     => 'Create Vouchers',
    'validator_request.php'   => 'Validator Request',
    'validate_batch.php'      => 'Validate Batches',
    'validate_items.php'      => 'Price Validation',
    'discrepancy_resolve.php' => 'Discrepancy',
    'damage_review.php'       => 'Damage Tickets',
    'supplier_payments.php'   => 'Supplier Payments',
    'price_checker.php'       => 'Price Checker',
];

// 🎨 ICON MAP (Inline SVG - Zero lag, zero external calls)
$icons = [
    'pos.php'               => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
    'stock_management.php'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
    'help.php'              => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'dashboard.php'         => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>',
    'activity_logs.php'     => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'price_maintenance.php' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>',
    'product_info.php'      => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
    'suppliers.php'         => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'delivery_receive.php'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>',
    'discount.php'          => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg>',
    'users.php'             => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
    'settings.php'          => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>',
    // Phase 2 icons
    'customer_groups.php'   => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    'backup.php'            => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>',
    'ip_restrictions.php'   => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>',
    // Phase 3 icons
    'bundles.php'           => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
    'damage_review.php'   => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>',
    // Phase 4 — Procurement Pipeline icons
    'receive_batch.php'       => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4m8-9v9"/></svg>',
    'receive_items.php'       => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>',
    'batches_pending.php'     => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
    'validator_request.php'   => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'validate_batch.php'      => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'validate_items.php'      => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>',
    'discrepancy_resolve.php' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
    'price_checker.php'       => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>',
    'supplier_payments.php'   => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
];

// ── Navigation URL map (absolute paths — safe from any page depth) ───────────
$hrefs = [
    'pos.php'               => '/project/staff/pos/pos.php',
    'stock_management.php'  => '/project/staff/inventory/stock_management.php',
    'product_info.php'      => '/project/staff/inventory/product_info.php',
    'suppliers.php'         => '/project/staff/suppliers/suppliers.php',
    'delivery_receive.php'  => '/project/staff/procurement/delivery_receive.php',
    'dashboard.php'         => '/project/staff/dashboard.php',
    'activity_logs.php'     => '/project/staff/activity_logs.php',
    'price_maintenance.php' => '/project/staff/inventory/price_maintenance.php',
    'discount.php'          => '/project/staff/pos/discount.php',
    'users.php'             => '/project/staff/users/users.php',
    'settings.php'          => '/project/staff/settings.php',
    'help.php'              => '/project/staff/help.php',
    'delivery_return_ticket.php' => '/project/staff/procurement/delivery_return_ticket.php',
    // Phase 2 hrefs
    'customer_groups.php'   => '/project/staff/pos/customer_groups.php',
    'backup.php'            => '/project/staff/reports/backup.php',
    'ip_restrictions.php'   => '/project/staff/settings/ip_restrictions.php',
    // Phase 3 hrefs
    'bundles.php'           => '/project/staff/pos/bundles.php',
    // Phase 4 — Procurement Pipeline hrefs
    'receive_batch.php'       => '/project/staff/procurement/receive_batch.php',
    'receive_items.php'       => '/project/staff/procurement/receive_items.php',
    'batches_pending.php'     => '/project/staff/procurement/batches_pending.php',
    'validator_request.php'   => '/project/staff/procurement/validator_request.php',
    'validate_batch.php'      => '/project/staff/procurement/validate_batch.php',
    'validate_items.php'      => '/project/staff/procurement/validate_items.php',
    'discrepancy_resolve.php' => '/project/staff/procurement/discrepancy_resolve.php',
    'damage_review.php'       => '/project/staff/procurement/damage_review.php',
    'supplier_payments.php'   => '/project/staff/procurement/supplier_payments.php',
    'price_checker.php'       => '/project/staff/procurement/price_checker.php',
];

$pipeline_steps = ['batches_pending.php', 'discrepancy_resolve.php', 'damage_review.php', 'supplier_payments.php'];
// Admins can also perform the Receiver / Validator / Price-Checker tasks, so their
// Procurement nav includes those screens in pipeline order.
$admin_pipeline = ['receive_batch.php', 'batches_pending.php', 'validate_batch.php',
                   'discrepancy_resolve.php', 'damage_review.php', 'price_checker.php', 'supplier_payments.php'];

$support_open_count = 0;
$support_reply_count = 0;
if ($role === ROLE_STAFF) {
    // Support: badge only when admin replied and staff hasn't replied back (last message is not theirs)
    $srv = $conn->prepare("
        SELECT COUNT(*) AS c FROM support_tickets st
        WHERE st.user_id = ? AND st.status = '" . TICKET_IN_PROGRESS . "'
        AND (SELECT sender_id FROM support_messages WHERE ticket_id = st.id ORDER BY created_at DESC LIMIT 1) != ?
    ");
    $srv->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
    $srv->execute();
    $support_reply_count = intval($srv->get_result()->fetch_assoc()['c'] ?? 0);
} elseif (in_array($role, ROLES_ADMIN_AND_UP)) {
    // Support: open tickets + in_progress tickets where staff last replied (admin needs to respond)
    $soc_q = $conn->query("
        SELECT COUNT(*) AS c FROM support_tickets st
        WHERE st.status = '" . TICKET_OPEN . "'
        OR (st.status = '" . TICKET_IN_PROGRESS . "'
            AND (SELECT sender_role FROM support_messages WHERE ticket_id = st.id ORDER BY created_at DESC LIMIT 1) = '" . ROLE_STAFF . "')
    ");
    $support_open_count = intval($soc_q ? $soc_q->fetch_assoc()['c'] ?? 0 : 0);

    // Notification bell count for admin/superadmin
    $notif_count = 0;
    $nq = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE recipient_role IN ('admin','superadmin') AND is_read = 0");
    if ($nq) $notif_count = intval($nq->fetch_assoc()['c'] ?? 0);

    // Auto-apply deferred price requests when the product stock hits zero
    $def_q = $conn->query("SELECT * FROM price_update_requests WHERE status = '" . PRICE_REQ_DEFERRED . "'");
    if ($def_q && $def_q->num_rows > 0) {
        while ($def = $def_q->fetch_assoc()) {
            $stk = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS total FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND status = '" . PRODUCT_ACTIVE . "'");
            $stk->bind_param("s", $def['product_name']); $stk->execute();
            $total_stock    = intval($stk->get_result()->fetch_assoc()['total'] ?? 1);
            $effective_stock = $total_stock - intval($def['locked_qty'] ?? 0);
            if ($effective_stock <= 0) {
                $conn->begin_transaction();
                try {
                    $ph = $conn->prepare("INSERT INTO price_history (product_id, old_price, new_price) VALUES (?, ?, ?)");
                    $ph->bind_param("idd", $def['product_id'], $def['current_price'], $def['proposed_price']); $ph->execute();

                    $up = $conn->prepare("UPDATE products SET price = ?, tiers_locked = 1 WHERE id = ?");
                    $up->bind_param("di", $def['proposed_price'], $def['product_id']); $up->execute();

                    $ur = $conn->prepare("UPDATE price_update_requests SET status='" . PRICE_REQ_APPLIED . "', applied_username='system', applied_at=NOW() WHERE id=?");
                    $ur->bind_param("i", $def['id']); $ur->execute();

                    $conn->commit();

                    $lg = $conn->prepare("INSERT INTO price_update_logs (request_id, action, actor_id, actor_username, old_price, new_price, note) VALUES (?, 'auto_applied', 0, 'system', ?, ?, 'Auto-applied on stockout')");
                    $lg->bind_param("idd", $def['id'], $def['current_price'], $def['proposed_price']); $lg->execute();

                    $note = "PRICE AUTO-APPLIED: {$def['product_name']} ₱" . number_format($def['current_price'],2) . " → ₱" . number_format($def['proposed_price'],2) . " (Request #{$def['id']}) — stockout triggered";
                    $al = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message, old_value, new_value) VALUES (0, '" . LOG_PRICES . "', ?, ?, ?, ?)");
                    $al->bind_param("isss", $def['product_id'], $note, $def['current_price'], $def['proposed_price']); $al->execute();
                } catch (Throwable $_ae) { $conn->rollback(); }
            }
        }
    }
}

// Notification count for procurement specialist roles
$notif_count = $notif_count ?? 0;
if (in_array($role, ROLES_PROCUREMENT_STAFF)) {
    $nq2 = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE (recipient_id = ? OR recipient_role = ?) AND is_read = 0");
    $nq2->bind_param("is", $_SESSION['user_id'], $role);
    $nq2->execute();
    $notif_count = intval($nq2->get_result()->fetch_assoc()['c'] ?? 0);
}

if ($role === ROLE_STAFF) {
    $nav_sections = [
        'Overview'  => ['dashboard.php'],
        'Sales'     => ['pos.php'],
        'Inventory' => ['stock_management.php'],
        'Help'      => ['help.php'],
    ];
} elseif ($role === ROLE_RECEIVER) {
    $nav_sections = [
        'Overview'    => ['dashboard.php'],
        'Sales'       => ['pos.php'],
        'Inventory'   => ['stock_management.php'],
        'Procurement' => ['receive_batch.php'],
        'Help'        => ['help.php'],
    ];
} elseif ($role === ROLE_VALIDATOR) {
    $nav_sections = [
        'Overview'    => ['dashboard.php'],
        'Sales'       => ['pos.php'],
        'Inventory'   => ['stock_management.php'],
        'Procurement' => ['validate_batch.php'],
        'Help'        => ['help.php'],
    ];
} elseif ($role === ROLE_PRICE_CHECKER) {
    $nav_sections = [
        'Overview'    => ['dashboard.php'],
        'Sales'       => ['pos.php'],
        'Inventory'   => ['stock_management.php'],
        'Reports'     => ['price_checker.php'],
        'Help'        => ['help.php'],
    ];
} elseif (in_array($role, ROLES_ADMIN_OWNER)) {
    $nav_sections = [
        'Overview'       => ['dashboard.php'],
        'Sales'          => ['pos.php', 'discount.php'],
        'Inventory'      => ['stock_management.php', 'price_maintenance.php'],
        'Procurement'    => $admin_pipeline,
        'Administration' => ['activity_logs.php', 'users.php', 'backup.php', 'ip_restrictions.php', 'help.php'],
    ];
} else { // superadmin
    $nav_sections = [
        'Overview'       => ['dashboard.php'],
        'Sales'          => ['pos.php', 'discount.php'],
        'Inventory'      => ['stock_management.php', 'price_maintenance.php'],
        'Procurement'    => $admin_pipeline,
        'Administration' => ['activity_logs.php', 'users.php', 'backup.php', 'ip_restrictions.php', 'help.php'],
        'System'         => ['settings.php'],
    ];
}

$page_title = $titles[$current_page] ?? 'Business ERP';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | Business ERP</title>
    <link rel="stylesheet" href="/project/assets/css/style.css?v=<?= $sys_version ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; overflow-x: hidden; }
        .serif-title { font-family: 'Playfair Display', serif; }
        .sidebar { transition: width 0.3s ease; width: 280px; height: 100vh; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; background: white; z-index: 50; border-right: 1px solid #e2e8f0; }
        .sidebar.collapsed { width: 85px; }
        .main-content { transition: margin-left 0.3s ease; margin-left: 280px; }
        .main-content.expanded { margin-left: 85px; }
        .nav-item { transition: all 0.2s; color: #64748b; display: flex; align-items: center; gap: 1rem; padding: 0.85rem 1.25rem; border-radius: 0.75rem; margin: 0.25rem 0.75rem; cursor: pointer; text-decoration: none; }
        .nav-item:hover { background: #f1f5f9; color: #00a651; }
        .nav-item.active { background: linear-gradient(135deg, #00a651, #059669) !important; color: white !important; box-shadow: 0 10px 15px -3px rgba(0, 166, 81, 0.2); }
        .nav-item.pending { background: #fffbeb; color: #b45309; }
        .nav-item.pending:hover { background: #fef3c7 !important; color: #b45309 !important; }
        .nav-item.pending svg { color: #b45309 !important; }
        .nav-item.locked { color: #94a3b8 !important; opacity: .7; }
        .nav-item.locked svg { color: #94a3b8 !important; }
        .nav-item.locked:hover { background: #f8fafc !important; opacity: 1; }
        .sidebar-section-label { font-size: 9px; font-weight: 900; color: #cbd5e1; text-transform: uppercase; letter-spacing: 0.15em; padding: 1.5rem 2rem 0.5rem; }
        #page-content { transition: opacity 0.12s ease; }
        .content-loading { opacity: 0 !important; pointer-events: none; cursor: wait; }
        /* Siguraduhin na ang icon ay pumuti rin pag active */
        .nav-item.active svg { color: white !important; }
        .collapsed .nav-text, .collapsed .sidebar-header-text, .collapsed .role-badge, .collapsed .sidebar-section-label { display: none; }
        .collapsed .nav-item { justify-content: center; margin: 4px 12px; }
        /* Explicit icon sizing — prevents external CSS from collapsing SVG icons to 0/tiny */
        .nav-item svg { display: block; width: 1.25rem !important; height: 1.25rem !important; flex-shrink: 0; }
        /* Hide native up/down spinner arrows on all number inputs (UI only — typing/values unchanged) */
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; appearance: textfield; }
    </style>
</head>
<body>

<aside id="sidebar" class="sidebar">
    <div class="p-6 flex items-center justify-between flex-shrink-0">
        <div class="sidebar-header-text">
            <h1 class="serif-title text-xl font-bold text-slate-900 leading-none">Business ERP</h1>
            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-1">Cynthia Bersabe</p>
        </div>
        <button onclick="toggleSidebar()" class="p-2 hover:bg-slate-100 rounded-lg text-slate-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M4 12h16M4 6h16M4 18h16" stroke-width="2"/></svg>
        </button>
    </div>

    <div class="px-6 mb-2 role-badge">
        <?php
        $badge_class = match($role) {
            ROLE_SUPERADMIN   => 'bg-rose-50 text-rose-600 border-rose-200',
            ROLE_ADMIN, ROLE_OWNER => 'bg-purple-50 text-purple-600 border-purple-100',
            ROLE_RECEIVER     => 'bg-sky-50 text-sky-600 border-sky-200',
            ROLE_VALIDATOR    => 'bg-teal-50 text-teal-600 border-teal-200',
            ROLE_PRICE_CHECKER => 'bg-orange-50 text-orange-600 border-orange-200',
            default           => 'bg-blue-50 text-blue-500 border-blue-100',
        };
        $badge_label = match($role) {
            ROLE_SUPERADMIN   => '★ Super Admin',
            ROLE_PRICE_CHECKER => 'Price Checker',
            default           => ucfirst($role),
        };
        ?>
        <div class="<?= $badge_class ?> text-[9px] font-black tracking-[0.2em] py-2 px-4 rounded-xl border text-center uppercase"><?= $badge_label ?></div>
    </div>

    <div class="flex-1 overflow-y-auto no-scrollbar pb-10">
        <nav class="space-y-1">
            <?php foreach($nav_sections as $section => $files): ?>
                <p class="sidebar-section-label"><?= $section ?></p>
                <?php foreach($files as $file):
                    $navClass = ($current_page == $file) ? 'active' : '';
                    $showSupportBadge = $file === 'help.php' && (
                        in_array($role, ROLES_ADMIN_AND_UP) && $support_open_count > 0 ||
                        $role === ROLE_STAFF && $support_reply_count > 0
                    );
                ?>
                    <a href="<?= $hrefs[$file] ?? $file ?>" class="nav-item <?= $navClass ?>">
                        <?= $icons[$file] ?? '' ?>
                        <span class="nav-text font-bold text-sm flex-1"><?= $titles[$file] ?? $file ?></span>
                        <?php if ($showSupportBadge): ?>
                            <span class="nav-text ml-auto bg-violet-500 text-white text-[8px] font-black w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0 animate-pulse">
                                <?= in_array($role, ROLES_ADMIN_AND_UP) ? $support_open_count : $support_reply_count ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
    </div>
    
    <div class="p-4 border-t border-slate-50 flex-shrink-0 bg-white">
        <a href="javascript:void(0)" onclick="confirmLogout()" class="nav-item text-rose-500 hover:bg-rose-50 border border-transparent hover:border-rose-100">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            <span class="nav-text font-bold text-sm">Logout Session</span>
        </a>
    </div>
</aside>

<main id="main-content" class="main-content min-h-screen relative">
    <div id="loading-bar" class="fixed top-0 left-0 right-0 h-1 bg-emerald-500 z-[100] transition-all duration-300" style="width: 0%; opacity: 0;"></div>
    <header class="bg-white/80 backdrop-blur-md border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40">
        <div>
            <h2 id="page-title-display" class="serif-title text-2xl font-black text-slate-900 leading-none mb-1 uppercase tracking-tighter"><?php echo $page_title; ?></h2>
            <p class="text-[10px] text-slate-400 font-bold uppercase"><?php echo date("l, F j, Y"); ?></p>
        </div>
        <div class="flex items-center gap-3">
            <?php if (in_array($role, ROLES_ADMIN_AND_UP) || in_array($role, ROLES_PROCUREMENT_STAFF)): ?>
            <div class="relative" id="notif-bell-wrap">
                <button id="notif-bell-btn" onclick="toggleNotifDropdown()" class="relative w-10 h-10 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-full flex items-center justify-center transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <?php if ($notif_count > 0): ?>
                    <span id="notif-badge" class="absolute -top-1 -right-1 bg-rose-500 text-white text-[9px] font-black w-5 h-5 flex items-center justify-center rounded-full animate-pulse"><?= min($notif_count, 99) ?></span>
                    <?php else: ?>
                    <span id="notif-badge" class="hidden absolute -top-1 -right-1 bg-rose-500 text-white text-[9px] font-black w-5 h-5 flex items-center justify-center rounded-full"></span>
                    <?php endif; ?>
                </button>
                <div id="notif-dropdown" class="hidden absolute right-0 top-12 w-80 bg-white border border-slate-200 rounded-2xl shadow-xl z-50 overflow-hidden">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                        <span class="text-xs font-black uppercase tracking-widest text-slate-500">Notifications</span>
                        <button onclick="markAllRead()" class="text-[10px] text-emerald-600 font-bold hover:underline">Mark all read</button>
                    </div>
                    <div id="notif-list" class="max-h-80 overflow-y-auto divide-y divide-slate-50">
                        <div class="px-4 py-6 text-center text-slate-400 text-xs font-bold">Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Subtle "new notification arrived" pop-up (shown by the badge poller) -->
            <style>
                #notif-pop { transition: opacity .3s ease, transform .3s ease; opacity: 0; transform: translateY(-8px); }
                #notif-pop.notif-pop-show { opacity: 1; transform: translateY(0); }
                #notif-pop-msg { display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            </style>
            <div id="notif-pop" class="hidden fixed top-20 right-8 z-[450] w-72 cursor-pointer" onclick="openNotifFromPop()">
                <div class="bg-white border border-slate-200 shadow-xl rounded-2xl px-4 py-3 flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-500 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-widest text-rose-500">New notification</p>
                        <p id="notif-pop-msg" class="text-xs font-bold text-slate-700 leading-snug">You have a new notification.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="w-10 h-10 bg-emerald-500 text-white rounded-full flex items-center justify-center font-black shadow-lg shadow-emerald-200"><?php echo substr($username, 0, 1); ?></div>
        </div>
    </header>

    <!-- ── FORCE LOGOUT MODAL (shown when this account is signed out by an admin) ── -->
    <div id="force-logout-modal" class="hidden fixed inset-0 z-[1000] flex items-center justify-center bg-slate-900/70 backdrop-blur-sm">
        <div class="bg-white rounded-3xl shadow-2xl p-10 max-w-md w-full mx-4 text-center">
            <div class="w-16 h-16 bg-orange-100 rounded-2xl flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </div>
            <h3 class="serif-title text-2xl font-black text-slate-800 mb-2">You've been signed out</h3>
            <p class="text-slate-500 font-bold text-sm mb-1">
                Logged out by <span id="fl-by" class="text-slate-800">an administrator</span>
                <span id="fl-role-badge" class="ml-1 align-middle inline-block px-2 py-0.5 rounded-lg text-[10px] font-black uppercase tracking-wider bg-slate-100 text-slate-500"></span>
            </p>
            <p class="text-slate-400 text-xs font-bold mb-6">Redirecting to the login page in <span id="fl-countdown">3</span>s…</p>
            <button onclick="window.location.href='/project/auth/logout.php'"
                class="w-full bg-orange-500 hover:bg-orange-600 text-white font-black text-sm uppercase tracking-widest py-3.5 rounded-2xl transition-all shadow-lg shadow-orange-100">
                Log out now
            </button>
        </div>
    </div>

    <div id="page-content" class="p-8 animate-in"><div id="spa-state" data-batch="<?= isset($_SESSION['active_batch_id']) ? '1' : '0' ?>" data-sig="<?= $session_sig ?>" class="hidden"></div>

<script>
const pageCache = new Map(); 

function confirmLogout() {
    customConfirm('You will be redirected to the landing page.', 'End Session?').then(function(ok) {
        if (ok) { pageCache.clear(); window.location.href = "/project/auth/logout.php"; }
    });
}

async function navigate(url, formData = null, isSilent = false) {
    if (typeof hideFlash === 'function') hideFlash();
    const loader = document.getElementById('loading-bar');
    const content = document.getElementById('page-content');

    // Serve GET requests from cache instantly — no server round-trip
    if (!formData && pageCache.has(url)) {
        renderPage(pageCache.get(url), true);
        return;
    }

    if (!isSilent) {
        loader.style.opacity = '1';
        loader.style.width = '30%';
        content.classList.add('content-loading');
    }

    const separator = url.indexOf('?') > -1 ? '&' : '?';
    const ajaxUrl = url + separator + 't=' + Date.now();

    try {
        const response = await fetch(ajaxUrl, {
            method: formData ? 'POST' : 'GET',
            body: formData,
            cache: 'no-cache',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const text = await response.text();
        if (response.redirected) {
            const redirectTo = new URL(response.url);
            const keepFullReload = ['checkout_process.php'].some(p => url.includes(p))
                                || !redirectTo.pathname.includes('/staff/');
            if (keepFullReload) {
                window.location.href = response.url;
                return;
            }
            window.history.pushState({}, '', response.url);
            if (formData) pageCache.clear();
            renderPage(text, isSilent);
            return;
        }
        if (formData) {
            pageCache.clear();
        } else {
            pageCache.set(url, text);
        }
        renderPage(text, isSilent);

    } catch (err) {
        console.error("SPA failure:", err);
        if (!isSilent) window.location.href = url;
    } finally {
        loader.style.width = '100%';
        setTimeout(() => {
            loader.style.opacity = '0';
            document.getElementById('page-content').classList.remove('content-loading');
            loader.style.width = '0%';
        }, isSilent ? 0 : 150);
    }
}

function renderPage(htmlText, isSilent = false) {
    const content = document.getElementById('page-content');
    const parser = new DOMParser();
    const doc = parser.parseFromString(htmlText, 'text/html');
    const newContent = doc.getElementById('page-content');

    if (newContent) {
        // L-02: if session state changed (role/procurement) wipe stale cached pages
        const oldSig = document.querySelector('#spa-state')?.dataset?.sig;
        const newSig = newContent.querySelector('#spa-state')?.dataset?.sig;
        if (oldSig && newSig && oldSig !== newSig) pageCache.clear();

        content.innerHTML = newContent.innerHTML;
        content.querySelectorAll('script').forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });

        const newTitle = doc.getElementById('page-title-display')?.innerText;
        if (newTitle) document.getElementById('page-title-display').innerText = newTitle;
        
        const currentPath = window.location.pathname.split('/').pop() || 'pos.php';
        var spaState = content.querySelector('#spa-state');
        var hasBatch = spaState ? spaState.dataset.batch === '1' : false;
        document.querySelectorAll('.nav-item').forEach(nav => {
            const navUrl = (nav.getAttribute('href') ?? '').split('/').pop().split('?')[0];
            nav.classList.toggle('active', currentPath.includes(navUrl));
            if (navUrl === 'product_info.php') {
                nav.classList.toggle('pending', hasBatch && !currentPath.includes('product_info.php'));
            }
        });

        var flashUrl = new URL(window.location.href);
        var sm = flashUrl.searchParams.get('success');
        var em = flashUrl.searchParams.get('error');
        if (sm && typeof showFlash === 'function') {
            showFlash(sm, 'success');
            flashUrl.searchParams.delete('success');
            history.replaceState({}, '', flashUrl.toString());
        }
        if (em && typeof showFlash === 'function') {
            showFlash(em, 'error');
            flashUrl.searchParams.delete('error');
            history.replaceState({}, '', flashUrl.toString());
        }
        var wm = flashUrl.searchParams.get('warning');
        if (wm && typeof showFlash === 'function') {
            showFlash(wm, 'warning');
            flashUrl.searchParams.delete('warning');
            history.replaceState({}, '', flashUrl.toString());
        }
    }
}

document.addEventListener('click', e => {
    const a = e.target.closest('a');
    if (!a || !a.href || !a.href.includes(window.location.origin) || a.href.includes('logout') || a.href.includes('javascript:')) return;
    e.preventDefault();
    window.history.pushState({}, '', a.href);
    navigate(a.href, null, false); 
});

document.addEventListener('submit', e => {
    var _action = e.target.getAttribute('action') || window.location.href;
    if (_action.includes('logout')) return;
    e.preventDefault();

    // Respect GET forms (search / filters): serialize fields into the query string
    // and navigate via GET so PHP can read them from $_GET and the URL updates.
    var _method = (e.target.getAttribute('method') || 'get').toLowerCase();
    if (_method === 'get') {
        const params = new URLSearchParams(new FormData(e.target));
        if (e.submitter && e.submitter.name) params.append(e.submitter.name, e.submitter.value);
        const base = _action.split('?')[0];
        const qs = params.toString();
        const getUrl = base + (qs ? '?' + qs : '');
        window.history.pushState({}, '', getUrl);
        navigate(getUrl, null, false);
        return;
    }

    const fd = new FormData(e.target);
    if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);

    const silentList = ['pos_process.php', 'refund_process.php', 'officialize_stock.php', 'api/'];
    const isSilent = silentList.some(p => _action.includes(p));

    navigate(_action, fd, isSilent);
});

window.addEventListener('popstate', () => navigate(window.location.href));

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('main-content').classList.toggle('expanded');
}

// ── LIVE SEARCH FILTER (client-side, name-only, contains-match) ───────────────
// Any <input data-live="#rowsContainer"> instantly hides non-matching rows as you
// type. It matches against each row's ".live-name" cell (name only). Enter still
// submits the form → full server-side search of the whole dataset (the fallback).
// Delegated on document, so it keeps working after every SPA page swap.
document.addEventListener('input', function (e) {
    const input = e.target;
    if (!input || !input.matches || !input.matches('[data-live]')) return;

    const container = document.querySelector(input.getAttribute('data-live'));
    if (!container) return;

    const q    = input.value.trim().toLowerCase();
    const rows = container.querySelectorAll(':scope > tr');
    let shown  = 0;
    let lastMatched = true;   // tracks the most recent filterable row's result

    rows.forEach(function (row) {
        if (row.classList.contains('live-empty')) return;       // skip our own message row

        // Detail/sub rows follow their parent: hide if parent didn't match,
        // otherwise leave their own (collapsed) state untouched.
        if (row.hasAttribute('data-live-detail')) {
            row.style.display = (q !== '' && !lastMatched) ? 'none' : '';
            return;
        }

        const nameEl = row.querySelector('.live-name');
        if (!nameEl) {                                          // header/placeholder row
            row.style.display = (q !== '') ? 'none' : '';
            return;
        }

        const match = q === '' || nameEl.textContent.toLowerCase().indexOf(q) !== -1;
        row.style.display = match ? '' : 'none';
        lastMatched = match;
        if (match) shown++;
    });

    // Manage a single "no matches in loaded rows" message row
    let empty = container.querySelector('tr.live-empty');
    if (q !== '' && shown === 0) {
        if (!empty) {
            empty = document.createElement('tr');
            empty.className = 'live-empty';
            const td = document.createElement('td');
            td.colSpan = 99;
            td.className = 'text-center text-slate-400 font-bold text-xs py-4';
            empty.appendChild(td);
            container.appendChild(empty);
        }
        empty.firstChild.textContent = 'No matches in loaded rows — press Enter to search all.';
        empty.style.display = '';
    } else if (empty) {
        empty.style.display = 'none';
    }
});

// ── NOTIFICATION BELL ─────────────────────────────────────────────────────────
let _notifOpen = false;
function toggleNotifDropdown() {
    _notifOpen = !_notifOpen;
    const dd = document.getElementById('notif-dropdown');
    dd.classList.toggle('hidden', !_notifOpen);
    if (_notifOpen) loadNotifications();
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('#notif-bell-wrap')) {
        _notifOpen = false;
        const dd = document.getElementById('notif-dropdown');
        if (dd) dd.classList.add('hidden');
    }
});
function loadNotifications() {
    fetch('/project/staff/api/notifications.php?action=list')
        .then(r => r.json())
        .then(data => {
            const list = document.getElementById('notif-list');
            if (!data.length) {
                list.innerHTML = '<div class="px-4 py-6 text-center text-slate-400 text-xs font-bold">No notifications</div>';
                return;
            }
            const typeIcon = { discrepancy:'🔴', price_change:'🟡', override:'🟢', batch_rejected:'⚫' };
            list.innerHTML = data.map(n => `
                <div class="px-4 py-3 ${n.is_read ? '' : 'bg-blue-50'} hover:bg-slate-50 cursor-pointer text-xs" onclick="markOneRead(${n.id}, this)">
                    <div class="flex gap-2">
                        <span class="text-base leading-none mt-0.5">${typeIcon[n.type] ?? '🔔'}</span>
                        <div class="flex-1">
                            <p class="font-bold text-slate-700 leading-snug">${n.message}</p>
                            <p class="text-slate-400 mt-0.5">${n.created_at}</p>
                        </div>
                    </div>
                </div>`).join('');
        }).catch(() => {
            document.getElementById('notif-list').innerHTML = '<div class="px-4 py-6 text-center text-red-400 text-xs font-bold">Failed to load</div>';
        });
}
function markOneRead(id, el) {
    fetch('/project/staff/api/notifications.php?action=mark_read&id=' + id);
    el.classList.remove('bg-blue-50');
    refreshNotifBadge();
}
function markAllRead() {
    fetch('/project/staff/api/notifications.php?action=mark_all_read');
    document.querySelectorAll('#notif-list > div').forEach(d => d.classList.remove('bg-blue-50'));
    refreshNotifBadge(0);
}
function refreshNotifBadge(forceCount) {
    if (forceCount !== undefined) {
        updateNotifBadge(forceCount);
        return;
    }
    fetch('/project/staff/api/notifications.php?action=count')
        .then(r => r.json())
        .then(d => updateNotifBadge(d.count ?? 0));
}
function updateNotifBadge(count) {
    const badge = document.getElementById('notif-badge');
    window._lastNotifCount = count;                   // keep the poller's tracker in sync
    if (!badge) return;
    if (count > 0) {
        badge.textContent = Math.min(count, 99);
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

// ── Subtle "new notification" pop-up ──────────────────────────────────────────
function showNotifPop(message) {
    var pop = document.getElementById('notif-pop');
    if (!pop) return;
    var msgEl = document.getElementById('notif-pop-msg');
    if (msgEl) msgEl.textContent = message || 'You have a new notification.';
    pop.classList.remove('hidden');
    pop.classList.remove('notif-pop-show'); void pop.offsetWidth;   // restart transition
    pop.classList.add('notif-pop-show');
    clearTimeout(window._notifPopTimer);
    window._notifPopTimer = setTimeout(hideNotifPop, 5000);
}
function hideNotifPop() {
    var pop = document.getElementById('notif-pop');
    if (!pop) return;
    pop.classList.remove('notif-pop-show');
    setTimeout(function () { pop.classList.add('hidden'); }, 300);  // wait for fade-out
}
function showNotifPopLatest() {
    fetch('/project/staff/api/notifications.php?action=list', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) {
            var ct = r.headers.get('content-type') || '';
            return (r.ok && ct.indexOf('application/json') !== -1) ? r.json() : null;
        })
        .then(function (rows) { showNotifPop(rows && rows.length ? rows[0].message : null); })
        .catch(function () { showNotifPop(null); });
}
function openNotifFromPop() {
    hideNotifPop();
    if (typeof toggleNotifDropdown === 'function' && !_notifOpen) toggleNotifDropdown();
}

// ── REALTIME: notification badge polling ──────────────────────────────────────
// Safest live-update: only refreshes the bell badge count. It NEVER re-renders
// page content, so it can't wipe inputs, close modals, or reset scroll.
(function () {
    var POLL_MS = 15000;
    // Baseline = whatever the server already rendered, so we don't pop for
    // notifications that were already unread when the page loaded.
    (function () {
        var b = document.getElementById('notif-badge');
        window._lastNotifCount = (b && !b.classList.contains('hidden')) ? (parseInt(b.textContent, 10) || 0) : 0;
    })();

    function safeBadgePoll() {
        if (document.hidden) return;                 // pause when the tab isn't visible
        fetch('/project/staff/api/notifications.php?action=count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) {
            // If the session expired, the server returns a redirect/HTML, not JSON.
            var ct = r.headers.get('content-type') || '';
            return (r.ok && ct.indexOf('application/json') !== -1) ? r.json() : null;
        })
        .then(function (d) {
            if (!d || typeof d.count === 'undefined') return;
            var prev = (typeof window._lastNotifCount === 'number') ? window._lastNotifCount : d.count;
            if (d.count > prev) showNotifPopLatest();   // count went up → something new arrived
            updateNotifBadge(d.count);                  // also refreshes window._lastNotifCount
        })
        .catch(function () {});                       // silent on network blips
    }
    if (window._notifBadgeTimer) clearInterval(window._notifBadgeTimer);
    window._notifBadgeTimer = setInterval(safeBadgePoll, POLL_MS);
    // Refresh immediately when the tab regains focus (catch up on what was missed)
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) safeBadgePoll();
    });
})();

// ── REALTIME: force-logout detection ──────────────────────────────────────────
// Polls session_status.php; if this account was signed out by an admin, shows a
// blocking modal naming who did it and redirects to login. Self-contained so it
// runs for every role (not just those with the notification bell).
(function () {
    var FL_POLL_MS = 12000;
    var ROLE_LABELS = {
        superadmin: 'Super Admin', admin: 'Administrator', owner: 'Owner',
        staff: 'Staff', receiver: 'Receiver', validator: 'Validator', price_checker: 'Price Checker'
    };

    function triggerForceLogout(by, byRole) {
        if (window._forceLogoutShown) return;     // only ever fire once
        window._forceLogoutShown = true;

        var modal = document.getElementById('force-logout-modal');
        if (!modal) { window.location.href = '/project/auth/logout.php'; return; }

        var byEl = document.getElementById('fl-by');
        if (byEl) byEl.textContent = by ? ('@' + by) : 'an administrator';
        var badge = document.getElementById('fl-role-badge');
        if (badge) {
            var lbl = ROLE_LABELS[byRole] || '';
            if (lbl) { badge.textContent = lbl; badge.classList.remove('hidden'); }
            else     { badge.classList.add('hidden'); }
        }
        modal.classList.remove('hidden');

        // Stop background pollers so nothing else fires mid-redirect.
        if (window._notifBadgeTimer)  clearInterval(window._notifBadgeTimer);
        if (window._forceLogoutTimer) clearInterval(window._forceLogoutTimer);

        var n = 3;
        var cd = document.getElementById('fl-countdown');
        var t = setInterval(function () {
            n -= 1;
            if (cd) cd.textContent = String(Math.max(0, n));
            if (n <= 0) { clearInterval(t); window.location.href = '/project/auth/logout.php'; }
        }, 1000);
    }

    function checkForceLogout() {
        if (document.hidden) return;
        fetch('/project/staff/api/session_status.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                var ct = r.headers.get('content-type') || '';
                return (r.ok && ct.indexOf('application/json') !== -1) ? r.json() : null;
            })
            .then(function (d) {
                if (d && d.forced) triggerForceLogout(d.by, d.by_role);
            })
            .catch(function () {});   // silent on network blips
    }

    if (window._forceLogoutTimer) clearInterval(window._forceLogoutTimer);
    window._forceLogoutTimer = setInterval(checkForceLogout, FL_POLL_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) checkForceLogout();
    });
    checkForceLogout();   // run once on load too
})();

// ── IDLE SESSION WARNING (M-01) ───────────────────────────────────────────────
// Server timeout = 7200s (2 hrs). Warn at 1:55:00, auto-redirect at 2:00:00.
(function() {
    var TIMEOUT_MS = 7200000;
    var WARN_MS    = TIMEOUT_MS - 300000; // warn 5 min before
    var _warn, _kick;

    function resetIdle() {
        clearTimeout(_warn);
        clearTimeout(_kick);
        var modal = document.getElementById('idle-warning-modal');
        if (modal) modal.classList.add('hidden');
        _warn = setTimeout(showIdleWarning, WARN_MS);
        _kick = setTimeout(function() {
            window.location.href = '/project/auth/login.php?error=' + encodeURIComponent('Session expired due to inactivity.');
        }, TIMEOUT_MS);
    }

    function showIdleWarning() {
        var modal = document.getElementById('idle-warning-modal');
        if (modal) modal.classList.remove('hidden');
        // countdown in modal
        var secs = 300;
        var cdEl = document.getElementById('idle-countdown');
        var iv = setInterval(function() {
            secs--;
            if (cdEl) {
                var m = Math.floor(secs / 60);
                var s = secs % 60;
                cdEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            }
            if (secs <= 0) clearInterval(iv);
        }, 1000);
    }

    window._resetIdleTimer = resetIdle;
    ['click','keydown','mousemove','scroll','touchstart'].forEach(function(ev) {
        document.addEventListener(ev, resetIdle, { passive: true });
    });
    resetIdle();
})();
</script>

<!-- ── F-10: AUTO-BACKUP CHECK (admin pages only, silently fires when due) ────── -->
<?php if (in_array($_SESSION['role'] ?? '', ROLES_ADMIN_AND_UP)): ?>
<script>
(function() {
    // Fires once per page load; the endpoint only triggers if the interval has elapsed.
    fetch('/project/staff/reports/backup.php?auto_check=1')
        .then(r => r.json())
        .then(d => {
            if (d.triggered && !d.ok) {
                // Only surface failed auto-backups so admin knows
                console.warn('[Backup] Auto-backup failed:', d.msg);
            }
        })
        .catch(() => {}); // Never surface errors to the UI
})();
</script>
<?php endif; ?>

<!-- ── IDLE SESSION WARNING MODAL ─────────────────────────────────────────── -->
<div id="idle-warning-modal" class="hidden fixed inset-0 z-[200] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-[2.5rem] p-10 max-w-sm w-full mx-4 text-center shadow-2xl animate-in">
        <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-5">
            <svg class="w-8 h-8 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <h3 class="serif-title text-2xl font-black text-slate-800 mb-2">Still there?</h3>
        <p class="text-slate-400 text-sm font-bold mb-2">Your session will expire in</p>
        <p class="text-5xl font-black text-amber-500 tracking-tighter mb-6" id="idle-countdown">5:00</p>
        <p class="text-slate-400 text-xs font-bold mb-8">Due to inactivity. Click below to stay logged in.</p>
        <button onclick="window._resetIdleTimer()" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-black py-4 rounded-2xl text-sm uppercase tracking-widest transition-all shadow-lg shadow-emerald-100">
            Keep Me Logged In
        </button>
    </div>
</div>