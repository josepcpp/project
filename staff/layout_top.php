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
$session_sig  = md5(($_SESSION['user_id'] ?? '') . '|' . $role . '|' . ($_SESSION['procurement_access'] ?? ''));

// 📂 INFORMATION ARCHITECTURE
$titles = [
    'pos.php'               => 'Point of Sale',
    'refund_management.php' => 'Refunds & Returns',
    'stock_management.php'  => 'Live Stock Levels',
    'product_info.php'      => 'Product Master',
    'suppliers.php'         => 'Supply Vouchers',
    'delivery_receive.php'  => 'Receiving Station',
    'dashboard.php'         => 'Business Analytics',
    'activity_logs.php'     => 'System Audit Logs',    
    'price_maintenance.php' => 'Master Price Table',
    'payments.php'          => 'Outgoing Payments',
    'discount.php'          => 'Promotions',
    'users.php'             => 'Staff Accounts',
    'settings.php'          => 'App Settings',
    'help.php'                      => 'Support',
    'delivery_return_ticket.php'    => 'Return Ticket',
    'refund_queue.php'              => 'Refund Queue',
];

// 🎨 ICON MAP (Inline SVG - Zero lag, zero external calls)
$icons = [
    'pos.php'               => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
    'refund_management.php' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 15v4a2 2 0 01-2 2H4a2 2 0 01-2-2V9a2 2 0 01-2-2h3m8-3l3 3m0 0l-3 3m3-3H9"/></svg>',
    'stock_management.php'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
    'help.php'              => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'dashboard.php'         => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>',
    'activity_logs.php'     => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    'price_maintenance.php' => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>',
    'product_info.php'      => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>',
    'suppliers.php'         => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'delivery_receive.php'  => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>',
    'payments.php'          => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>',
    'discount.php'          => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg>',
    'users.php'             => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
    'settings.php'          => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>',
    'refund_queue.php'      => '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>',
];

// ── Navigation URL map (absolute paths — safe from any page depth) ───────────
$hrefs = [
    'pos.php'               => '/project/staff/pos/pos.php',
    'refund_management.php' => '/project/staff/sales/refund_management.php',
    'stock_management.php'  => '/project/staff/inventory/stock_management.php',
    'product_info.php'      => '/project/staff/inventory/product_info.php',
    'suppliers.php'         => '/project/staff/suppliers/suppliers.php',
    'delivery_receive.php'  => '/project/staff/procurement/delivery_receive.php',
    'dashboard.php'         => '/project/staff/dashboard.php',
    'activity_logs.php'     => '/project/staff/activity_logs.php',
    'price_maintenance.php' => '/project/staff/inventory/price_maintenance.php',
    'payments.php'          => '/project/staff/sales/payments.php',
    'discount.php'          => '/project/staff/pos/discount.php',
    'users.php'             => '/project/staff/users/users.php',
    'settings.php'          => '/project/staff/settings.php',
    'help.php'              => '/project/staff/help.php',
    'delivery_return_ticket.php' => '/project/staff/procurement/delivery_return_ticket.php',
    'refund_queue.php'      => '/project/staff/sales/refund_queue.php',
];

$procurement_steps       = ['suppliers.php', 'product_info.php', 'delivery_receive.php', 'payments.php'];
$staff_procurement_steps = ['product_info.php', 'delivery_receive.php'];

// Load procurement access for staff
$staff_procurement = 'n/a';
$procurement_pending_count = 0;
$recount_count = 0;
$qty_alert_count = 0;
$refund_queue_count = 0;
$support_open_count = 0;
$support_reply_count = 0;
if ($role === ROLE_STAFF) {
    $pa_q = $conn->prepare("SELECT procurement_access FROM users WHERE id = ?");
    $pa_q->bind_param("i", $_SESSION['user_id']);
    $pa_q->execute();
    $db_procurement = $pa_q->get_result()->fetch_assoc()['procurement_access'] ?? PROC_NONE;

    // Always trust the DB value so admin can revoke access at any time mid-session
    $staff_procurement = $db_procurement;
    $_SESSION['procurement_access'] = $db_procurement;

    // Auto-unlock procurement when owner has requested a recount
    if ($staff_procurement !== PROC_APPROVED) {
        $rq = $conn->query("SELECT COUNT(*) as c FROM quantity_alerts WHERE status = '" . ALERT_RECOUNTING . "'");
        $recount_count = intval($rq->fetch_assoc()['c'] ?? 0);
        if ($recount_count > 0) {
            $staff_procurement = PROC_RECOUNT;
            $_SESSION['procurement_access'] = PROC_RECOUNT;
        }
    }
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
    $pr_q = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='" . ROLE_STAFF . "' AND procurement_access='" . PROC_PENDING . "'");
    $procurement_pending_count = intval($pr_q->fetch_assoc()['c'] ?? 0);
    $qa_q = $conn->query("SELECT COUNT(*) AS c FROM quantity_alerts WHERE status IN ('" . ALERT_PENDING . "','" . ALERT_RECOUNTING . "')");
    $qty_alert_count = intval($qa_q ? $qa_q->fetch_assoc()['c'] ?? 0 : 0);
    // Refund queue badge: pending sales refunds + pending delivery return requests
    $rq_q = $conn->query("SELECT (SELECT COUNT(*) FROM refunds WHERE status='" . REFUND_PENDING . "') + (SELECT COUNT(*) FROM delivery_return_requests WHERE status='" . DR_PENDING . "') AS c");
    $refund_queue_count = intval($rq_q ? $rq_q->fetch_assoc()['c'] ?? 0 : 0);
    // Support: open tickets + in_progress tickets where staff last replied (admin needs to respond)
    $soc_q = $conn->query("
        SELECT COUNT(*) AS c FROM support_tickets st
        WHERE st.status = '" . TICKET_OPEN . "'
        OR (st.status = '" . TICKET_IN_PROGRESS . "'
            AND (SELECT sender_role FROM support_messages WHERE ticket_id = st.id ORDER BY created_at DESC LIMIT 1) = '" . ROLE_STAFF . "')
    ");
    $support_open_count = intval($soc_q ? $soc_q->fetch_assoc()['c'] ?? 0 : 0);

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

if ($role === ROLE_STAFF) {
    $nav_sections = [
        'Overview'    => ['dashboard.php'],
        'Sales'       => ['pos.php', 'refund_management.php'],
        'Inventory'   => ['stock_management.php'],
        'Procurement' => $staff_procurement_steps,
        'Help'        => ['help.php'],
    ];
} elseif (in_array($role, ROLES_ADMIN_OWNER)) {
    $nav_sections = [
        'Overview'       => ['dashboard.php'],
        'Sales'          => ['pos.php', 'refund_management.php', 'discount.php'],
        'Inventory'      => ['stock_management.php', 'price_maintenance.php'],
        'Procurement'    => $procurement_steps,
        'Administration' => ['activity_logs.php', 'users.php', 'help.php'],
    ];
} else { // superadmin
    $nav_sections = [
        'Overview'       => ['dashboard.php'],
        'Sales'          => ['pos.php', 'refund_management.php', 'discount.php'],
        'Inventory'      => ['stock_management.php', 'price_maintenance.php'],
        'Procurement'    => $procurement_steps,
        'Administration' => ['activity_logs.php', 'users.php', 'help.php'],
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
            ROLE_SUPERADMIN => 'bg-rose-50 text-rose-600 border-rose-200',
            ROLE_ADMIN, ROLE_OWNER => 'bg-purple-50 text-purple-600 border-purple-100',
            default => 'bg-blue-50 text-blue-500 border-blue-100',
        };
        $badge_label = $role === ROLE_SUPERADMIN ? '★ Super Admin' : ucfirst($role);
        ?>
        <div class="<?= $badge_class ?> text-[9px] font-black tracking-[0.2em] py-2 px-4 rounded-xl border text-center uppercase"><?= $badge_label ?></div>
    </div>

    <div class="flex-1 overflow-y-auto no-scrollbar pb-10">
        <nav class="space-y-1">
            <?php foreach($nav_sections as $section => $files):
                $is_procurement = ($section === 'Procurement');
                $step = 1;
                // Staff procurement access state
                $proc_locked  = $is_procurement && $role === ROLE_STAFF && !in_array($staff_procurement, [PROC_APPROVED, PROC_RECOUNT]);
                $proc_waiting = $is_procurement && $role === ROLE_STAFF && $staff_procurement === PROC_PENDING;
                $proc_recount = $is_procurement && $role === ROLE_STAFF && $staff_procurement === PROC_RECOUNT;
            ?>
                <p class="sidebar-section-label <?= ($is_procurement && !$proc_locked) ? ($proc_recount ? 'text-amber-500' : 'text-emerald-400') : '' ?>">
                    <?= $section ?>
                    <?php if ($proc_recount): ?>
                        <span class="text-amber-400 normal-case font-bold animate-pulse"> · Recount Active</span>
                    <?php elseif ($is_procurement && !$proc_locked): ?>
                        <span class="opacity-50 normal-case font-bold"> · 4-step flow</span>
                    <?php elseif ($proc_waiting): ?>
                        <span class="text-amber-400 normal-case font-bold animate-pulse"> · Pending</span>
                    <?php elseif ($proc_locked): ?>
                        <span class="text-slate-300 normal-case font-bold"> · Request Access</span>
                    <?php endif; ?>
                </p>
                <?php foreach($files as $file):
                    $isPending = $file === 'product_info.php' && isset($_SESSION['active_batch_id']) && $current_page !== 'product_info.php';
                    if ($proc_locked) {
                        $navClass = 'locked';
                    } elseif ($current_page == $file) {
                        $navClass = 'active';
                    } elseif ($isPending) {
                        $navClass = 'pending';
                    } else {
                        $navClass = '';
                    }
                    // Admin badge on users.php for pending procurement requests
                    $showProcBadge  = $file === 'users.php' && $procurement_pending_count > 0;
                    // Admin badge on delivery_receive.php for quantity discrepancy alerts
                    $showAlertBadge = $file === 'delivery_receive.php' && ($qty_alert_count ?? 0) > 0;
                    // Staff badge on dashboard.php for recount tasks
                    $showRecountBadge = $file === 'dashboard.php' && $role === ROLE_STAFF && $recount_count > 0;
                    // Refund queue badge
                    $showRefundQueueBadge = $file === 'refund_queue.php' && ($refund_queue_count ?? 0) > 0;
                    // Support badge on help.php
                    $showSupportBadge = $file === 'help.php' && (
                        in_array($role, ROLES_ADMIN_AND_UP) && $support_open_count > 0 ||
                        $role === ROLE_STAFF && $support_reply_count > 0
                    );
                ?>
                    <a href="<?= $hrefs[$file] ?? $file ?>" class="nav-item <?= $navClass ?>">
                        <?= $icons[$file] ?? '' ?>
                        <span class="nav-text font-bold text-sm flex-1"><?= $titles[$file] ?></span>
                        <?php if ($showRecountBadge): ?>
                            <span class="nav-text ml-auto bg-amber-500 text-white text-[8px] font-black w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0 animate-pulse">
                                <?= $recount_count ?>
                            </span>
                        <?php elseif ($showProcBadge): ?>
                            <span class="nav-text ml-auto bg-amber-400 text-white text-[8px] font-black w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0">
                                <?= $procurement_pending_count ?>
                            </span>
                        <?php elseif ($showAlertBadge): ?>
                            <span class="nav-text ml-auto bg-rose-500 text-white text-[8px] font-black w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0">
                                <?= $qty_alert_count ?>
                            </span>
                        <?php elseif ($showRefundQueueBadge): ?>
                            <span class="nav-text ml-auto bg-amber-500 text-white text-[8px] font-black w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0 animate-pulse">
                                <?= $refund_queue_count ?>
                            </span>
                        <?php elseif ($showSupportBadge): ?>
                            <span class="nav-text ml-auto bg-violet-500 text-white text-[8px] font-black w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0 animate-pulse">
                                <?= in_array($role, ROLES_ADMIN_AND_UP) ? $support_open_count : $support_reply_count ?>
                            </span>
                        <?php elseif ($is_procurement): ?>
                            <span class="nav-text ml-auto text-[9px] font-black w-5 h-5 flex items-center justify-center rounded-full flex-shrink-0
                                <?= ($current_page == $file) ? 'bg-white/20 text-white' : ($isPending ? 'bg-amber-200 text-amber-700 animate-pulse' : ($proc_locked ? 'bg-slate-100 text-slate-300' : 'bg-slate-100 text-slate-400')) ?>">
                                <?= $step ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <?php $step++; ?>
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
        <div class="w-10 h-10 bg-emerald-500 text-white rounded-full flex items-center justify-center font-black shadow-lg shadow-emerald-200"><?php echo substr($username, 0, 1); ?></div>
    </header>

    <div id="page-content" class="p-8 animate-in"><div id="spa-state" data-batch="<?= isset($_SESSION['active_batch_id']) ? '1' : '0' ?>" data-procurement="<?= htmlspecialchars($staff_procurement) ?>" data-recount="<?= $recount_count ?>" data-sig="<?= $session_sig ?>" class="hidden"></div>

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