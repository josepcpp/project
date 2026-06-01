<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }

// Get user role
$user_id = $_SESSION['user_id'];
$user_q = $conn->prepare("SELECT role FROM users WHERE id = ?");
$user_q->bind_param("i", $user_id);
$user_q->execute();
$user = $user_q->get_result()->fetch_assoc();
$role = $user['role'] ?? ROLE_STAFF;

// ── Security flag dismiss handler (before HTML) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dismiss_flag'])) {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        header("Location: dashboard.php?error=" . urlencode("Invalid request."));
        exit();
    }
    $fid  = intval($_POST['flag_id']);
    $uid  = intval($_SESSION['user_id']);
    $uname = $_SESSION['username'] ?? 'admin';
    $st   = $conn->prepare("UPDATE security_flags SET status='" . FLAG_DISMISSED . "', reviewed_by=?, reviewed_at=NOW() WHERE id=?");
    $st->bind_param("ii", $uid, $fid);
    $st->execute();
    // M-02: audit trail — log every flag dismissal
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_SECURITY . "', ?, ?)");
    $log_msg = "Security flag ID #{$fid} dismissed by @{$uname}.";
    $log->bind_param("iis", $uid, $fid, $log_msg);
    $log->execute();
    header("Location: dashboard.php");
    exit();
}

// Auto-detect duplicate refunds and insert open flags if not already flagged
$dup_detect = $conn->query("SELECT r.sale_id, s.receipt_no, COUNT(r.id) as cnt FROM refunds r JOIN sales s ON r.sale_id = s.id GROUP BY r.sale_id HAVING cnt > 1");
if ($dup_detect) {
    while ($dd = $dup_detect->fetch_assoc()) {
        $ex = $conn->prepare("SELECT id FROM security_flags WHERE flag_type='" . FLAG_DUPLICATE_REFUND . "' AND reference_id=? AND status='" . FLAG_OPEN . "' LIMIT 1");
        $ex->bind_param("i", $dd['sale_id']); $ex->execute();
        if ($ex->get_result()->num_rows === 0) {
            $ins = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_DUPLICATE_REFUND . "','" . SEV_HIGH . "',?,'sale',?)");
            $ins_msg = "Receipt #{$dd['receipt_no']} has {$dd['cnt']} refund entries — possible double refund.";
            $ins->bind_param("is", $dd['sale_id'], $ins_msg);
            $ins->execute();
        }
    }
}

include 'layout_top.php';

// ── STAFF DASHBOARD ───────────────────────────────────────────────────────────
if ($role === ROLE_STAFF):
    $staff_name      = $_SESSION['username'] ?? 'Staff';
    $today_sales_q   = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as amt FROM sales WHERE DATE(created_at) = CURDATE()");
    $today_sales     = $today_sales_q->fetch_assoc();
    $set_q     = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'low_stock_threshold'");
    $threshold = intval($set_q ? ($set_q->fetch_assoc()['setting_value'] ?? 10) : 10);
    $low_q     = $conn->query("SELECT COUNT(*) as c FROM (
        SELECT SUM(quantity) as sq, SUM(max_quantity) as smq
        FROM products WHERE status='" . PRODUCT_ACTIVE . "'
        GROUP BY LOWER(TRIM(name))
        HAVING sq > 0 AND ((smq > 0 AND sq <= FLOOR(smq * " . DEFAULT_LOW_STOCK_PCT . ")) OR (smq = 0 AND sq <= {$threshold}))
    ) _ls");
    $low_count = intval($low_q ? $low_q->fetch_assoc()['c'] : 0);
    $my_logs_q       = $conn->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY id DESC LIMIT 5");
    $my_logs_q->bind_param("i", $_SESSION['user_id']); $my_logs_q->execute();
    $my_logs         = $my_logs_q->get_result();
    $expiry_soon_q   = $conn->query("SELECT p.name, p.barcode, p.expiry_date, s.name AS supplier_name, s.invoice_number FROM products p JOIN suppliers s ON p.supplier_id = s.id WHERE p.status='" . PRODUCT_ACTIVE . "' AND p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . DEFAULT_EXPIRY_WARNING_DAYS . " DAY) ORDER BY p.expiry_date ASC LIMIT 15");
    $expiry_soon     = $expiry_soon_q ? $expiry_soon_q->fetch_all(MYSQLI_ASSOC) : [];
    $has_expiry_soon = count($expiry_soon) > 0;
?>
<div class="max-w-5xl mx-auto space-y-8 pb-20 animate-in">
    <div class="bg-slate-900 rounded-[3rem] p-10 flex items-center justify-between text-white shadow-2xl">
        <div>
            <p class="text-[10px] font-black uppercase text-emerald-400 tracking-[0.2em] mb-2">Staff Workspace</p>
            <h2 class="serif-title text-4xl font-bold mb-2">Welcome back, <?= htmlspecialchars($staff_name) ?>.</h2>
            <p class="text-slate-400 text-sm font-bold"><?= date("l, F j, Y") ?></p>
        </div>
        <div class="hidden md:flex items-center gap-4">
            <div class="text-center bg-white/5 border border-white/10 rounded-2xl px-6 py-4">
                <p class="text-3xl font-black text-white"><?= $today_sales['cnt'] ?></p>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-1">Sales Today</p>
            </div>
        </div>
    </div>
    <?php if ($has_expiry_soon): ?>
    <div class="bg-orange-50 border-2 border-orange-300 rounded-[2.5rem] overflow-hidden shadow-lg shadow-orange-100">
        <div class="p-7 border-b border-orange-200 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-orange-500 rounded-2xl flex items-center justify-center flex-shrink-0 animate-pulse">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <h3 class="font-black text-orange-900 text-lg uppercase tracking-wide">Expiry Alert</h3>
                    <p class="text-orange-600 text-xs font-bold"><?= count($expiry_soon) ?> item<?= count($expiry_soon) > 1 ? 's' : '' ?> expiring within 7 days or already expired.</p>
                </div>
            </div>
            <a href="inventory/stock_management.php" class="hidden sm:flex items-center gap-2 bg-orange-500 text-white font-black text-[10px] uppercase tracking-widest px-5 py-3 rounded-2xl hover:bg-orange-600 transition-all shadow-md">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                View Inventory
            </a>
        </div>
        <div class="divide-y divide-orange-100">
            <?php foreach ($expiry_soon as $ei):
                $ei_days = (int)ceil((strtotime($ei['expiry_date']) - strtotime('today')) / 86400);
                $ei_badge_cls = $ei_days <= 0 ? 'bg-rose-500 text-white' : 'bg-orange-200 text-orange-800';
                $ei_badge_lbl = $ei_days < 0 ? 'Expired ' . abs($ei_days) . 'd ago' : ($ei_days === 0 ? 'Expires Today' : "In {$ei_days}d");
            ?>
            <div class="px-7 py-4 flex items-center justify-between gap-4 hover:bg-orange-50/80 transition-all">
                <div class="flex-1 min-w-0">
                    <p class="font-black text-slate-800 text-sm leading-tight truncate"><?= htmlspecialchars($ei['name']) ?></p>
                    <div class="flex flex-wrap gap-3 mt-1">
                        <code class="text-[10px] text-slate-400 font-bold">#<?= htmlspecialchars($ei['barcode']) ?></code>
                        <span class="text-[10px] text-slate-400 font-bold">Batch: <?= htmlspecialchars($ei['invoice_number']) ?> — <?= htmlspecialchars($ei['supplier_name']) ?></span>
                    </div>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-[10px] font-black <?= $ei_badge_cls ?> px-3 py-1.5 rounded-xl uppercase tracking-widest whitespace-nowrap"><?= $ei_badge_lbl ?></span>
                    <span class="font-bold text-slate-500 text-sm whitespace-nowrap"><?= date('M j, Y', strtotime($ei['expiry_date'])) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <a href="pos/pos.php" class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-7 hover:shadow-lg hover:border-emerald-200 transition-all group">
            <div class="w-10 h-10 bg-emerald-500 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Today's Sales</p>
            <p class="text-2xl font-black text-slate-800"><?= $today_sales['cnt'] ?> <span class="text-sm text-slate-300 font-bold italic">txns</span></p>
            <p class="text-sm font-black text-emerald-600 mt-1">₱<?= number_format($today_sales['amt'], 2) ?></p>
        </a>
        <a href="inventory/stock_management.php?filter=low_stock" class="bg-white rounded-[2rem] border <?= $low_count > 0 ? 'border-red-200 bg-red-50' : 'border-slate-100' ?> shadow-md p-7 hover:shadow-lg transition-all">
            <div class="w-10 h-10 <?= $low_count > 0 ? 'bg-red-500 animate-pulse' : 'bg-slate-100' ?> rounded-xl flex items-center justify-center mb-4">
                <svg class="w-5 h-5 <?= $low_count > 0 ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-width="2"/></svg>
            </div>
            <p class="<?= $low_count > 0 ? 'text-red-500' : 'text-slate-400' ?> text-[10px] font-black uppercase tracking-widest mb-1">Low Stock Alerts</p>
            <p class="text-2xl font-black <?= $low_count > 0 ? 'text-red-700' : 'text-slate-800' ?>"><?= $low_count ?> <span class="text-sm font-bold opacity-30 italic">items</span></p>
        </a>
        <a href="sales/refund_management.php?tab=queue" class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-7 hover:shadow-lg transition-all group">
            <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform">
                <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
            </div>
            <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Refund Queue</p>
            <p class="text-2xl font-black text-slate-800">Manage <span class="text-sm text-slate-300 font-bold">&rarr;</span></p>
        </a>
    </div>
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-7 border-b border-slate-50 flex justify-between items-center">
            <div>
                <h4 class="font-black text-slate-800 text-lg">My Recent Activity</h4>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-0.5">Your last 5 logged actions</p>
            </div>
            <a href="activity_logs.php" class="text-[10px] font-black text-emerald-600 bg-emerald-50 px-4 py-2 rounded-xl hover:bg-emerald-100 transition-all uppercase tracking-widest border border-emerald-100">Full Log →</a>
        </div>
        <div class="divide-y divide-slate-50">
            <?php if ($my_logs->num_rows > 0): while ($log = $my_logs->fetch_assoc()):
                $tc = match($log['log_type']) { LOG_SALES => 'text-emerald-500', LOG_DELIVERIES => 'text-blue-500', LOG_DISPOSAL => 'text-rose-500', LOG_PRICES => 'text-purple-500', default => 'text-slate-400' };
            ?>
            <div class="px-7 py-5 flex items-center gap-6 hover:bg-slate-50 transition-colors">
                <div class="w-20 flex-shrink-0 text-center">
                    <p class="text-sm font-black text-slate-800"><?= date("M d", strtotime($log['created_at'])) ?></p>
                    <p class="text-[9px] font-black text-slate-300 uppercase"><?= date("h:i A", strtotime($log['created_at'])) ?></p>
                </div>
                <div class="flex-1 min-w-0">
                    <span class="text-[8px] font-black uppercase px-2 py-0.5 rounded <?= str_replace('text','bg',$tc) ?>/10 <?= $tc ?> mb-1 inline-block"><?= $log['log_type'] ?></span>
                    <p class="text-slate-600 font-bold text-sm truncate"><?= htmlspecialchars($log['message']) ?></p>
                </div>
            </div>
            <?php endwhile; else: ?>
            <div class="p-16 text-center text-slate-300 font-black italic">No activity logged yet.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
// ── ADMIN / OWNER / SUPERADMIN DASHBOARD ─────────────────────────────────────
else:

// ═══════════════════════════════════════════════════════════════════
// ALL QUERIES — run upfront before any HTML
// ═══════════════════════════════════════════════════════════════════

// ── Section A: Business Overview ─────────────────────────────────
$rev_all  = $conn->query("SELECT COALESCE(SUM(total),0) as r, COUNT(*) as t FROM sales")->fetch_assoc();
$rev_mon  = $conn->query("SELECT COALESCE(SUM(total),0) as r, COUNT(*) as t FROM sales WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetch_assoc();
$rev_yr   = $conn->query("SELECT COALESCE(SUM(total),0) as r, COUNT(*) as t FROM sales WHERE YEAR(created_at)=YEAR(NOW())")->fetch_assoc();
$rev_prev_mon = $conn->query("SELECT COALESCE(SUM(total),0) as r FROM sales WHERE YEAR(created_at)=YEAR(DATE_SUB(NOW(),INTERVAL 1 MONTH)) AND MONTH(created_at)=MONTH(DATE_SUB(NOW(),INTERVAL 1 MONTH))")->fetch_assoc();

// ── Section B: Live Operations ────────────────────────────────────
$last_sale_q   = $conn->query("SELECT * FROM sales ORDER BY id DESC LIMIT 1");
$last_sale     = $last_sale_q?->fetch_assoc();

// ── Section C: Procurement Pipeline ──────────────────────────────
$pip_suppliers = $conn->query("SELECT COUNT(*) as c FROM suppliers")->fetch_assoc()['c'] ?? 0;
$pip_draft_q   = $conn->query("SELECT COUNT(DISTINCT supplier_id) as batches, COUNT(*) as items FROM products WHERE status='" . PRODUCT_DRAFT . "'");
$pip_draft     = $pip_draft_q->fetch_assoc();
$pip_archived      = $conn->query("SELECT COUNT(*) as c FROM products WHERE status='" . PRODUCT_ARCHIVED . "'")->fetch_assoc()['c'] ?? 0;
$pip_new_archived  = $conn->query("SELECT COUNT(*) as c FROM products WHERE status='" . PRODUCT_ARCHIVED . "' AND archived_at >= NOW() - INTERVAL 24 HOUR")->fetch_assoc()['c'] ?? 0;
// Supplier payments awaiting settlement (validated batches not yet paid) — net of approved damage
$pip_payments  = $conn->query(
    "SELECT COUNT(*) AS c,
            COALESCE(SUM(rb.control_subtotal - COALESCE(d.ded,0)),0) AS total
     FROM receiving_batches rb
     LEFT JOIN (SELECT batch_id, SUM(CASE WHEN status='approved' THEN total_deduction ELSE 0 END) AS ded
                FROM delivery_damage_tickets GROUP BY batch_id) d ON d.batch_id = rb.id
     WHERE rb.validated_at IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM procurement_payments p WHERE p.batch_id = rb.id)"
);
$pip_pay       = $pip_payments ? $pip_payments->fetch_assoc() : ['c' => 0, 'total' => 0];
$dr_tickets_q  = $conn->query("
    SELECT drr.id, drr.invoice_no, drr.supplier_name, drr.purpose, drr.status,
           drr.ticket_no, drr.requested_username, drr.reviewed_username,
           drr.created_at, drr.reviewed_at,
           (SELECT COUNT(*) FROM delivery_return_request_items i WHERE i.request_id = drr.id) AS item_count
    FROM delivery_return_requests drr
    ORDER BY drr.created_at DESC
    LIMIT 10
");
$dr_tickets    = $dr_tickets_q ? $dr_tickets_q->fetch_all(MYSQLI_ASSOC) : [];
$dr_pending_ct = intval($conn->query("SELECT COUNT(*) AS c FROM delivery_return_requests WHERE status='" . DR_PENDING . "'")->fetch_assoc()['c'] ?? 0);

// ── Section D: Inventory Health ───────────────────────────────────
$set_q     = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='low_stock_threshold'");
$threshold = intval($set_q ? ($set_q->fetch_assoc()['setting_value'] ?? 10) : 10);
$inv_low_q = $conn->query("SELECT COUNT(*) as c FROM (
    SELECT SUM(quantity) as sq, SUM(max_quantity) as smq
    FROM products WHERE status='" . PRODUCT_ACTIVE . "'
    GROUP BY LOWER(TRIM(name))
    HAVING sq > 0 AND ((smq > 0 AND sq <= FLOOR(smq * " . DEFAULT_LOW_STOCK_PCT . ")) OR (smq = 0 AND sq <= {$threshold}))
) _ls");
$inv_low   = intval($inv_low_q ? $inv_low_q->fetch_assoc()['c'] : 0);
$inv_out   = $conn->query("SELECT COUNT(*) as c FROM products WHERE status='" . PRODUCT_ACTIVE . "' AND quantity<=0")->fetch_assoc()['c'] ?? 0;
$inv_arch  = $conn->query("SELECT COUNT(*) as c FROM products WHERE status='" . PRODUCT_ARCHIVED . "'")->fetch_assoc()['c'] ?? 0;
$disposed  = $conn->query("SELECT COUNT(*) as c FROM activity_logs WHERE log_type='" . LOG_DISPOSAL . "' AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetch_assoc()['c'] ?? 0;
$price_chg = $conn->query("SELECT message, created_at FROM activity_logs WHERE log_type='" . LOG_PRICES . "' ORDER BY created_at DESC LIMIT 5");
$expiry_alert_q = $conn->query("SELECT p.name, p.barcode, p.expiry_date, s.name AS supplier_name, s.invoice_number FROM products p JOIN suppliers s ON p.supplier_id = s.id WHERE p.status='" . PRODUCT_ACTIVE . "' AND p.expiry_date IS NOT NULL AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . DEFAULT_EXPIRY_WARNING_DAYS . " DAY) ORDER BY p.expiry_date ASC LIMIT 20");
$expiry_alerts  = $expiry_alert_q ? $expiry_alert_q->fetch_all(MYSQLI_ASSOC) : [];
$expiry_alert_count = count($expiry_alerts);

// ── Section E: Refund Health ──────────────────────────────────────
$ref_pend  = $conn->query("SELECT COUNT(*) as c FROM refunds WHERE status='" . REFUND_PENDING . "'")->fetch_assoc()['c'] ?? 0;
$ref_today = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(amount_refunded),0) as amt FROM refunds WHERE DATE(created_at)=CURDATE()")->fetch_assoc();
$ref_queue_res = $conn->query("
    SELECT r.id, r.qty, r.disposition, r.amount_refunded,
           s.receipt_no, p.name AS product_name,
           u.username AS requested_by
    FROM refunds r
    JOIN sales s    ON r.sale_id    = s.id
    JOIN products p ON r.product_id = p.id
    LEFT JOIN users u ON r.requested_by = u.id
    WHERE r.status = '" . REFUND_PENDING . "'
    ORDER BY r.created_at DESC
    LIMIT 20
");
$ref_queue_items = $ref_queue_res ? $ref_queue_res->fetch_all(MYSQLI_ASSOC) : [];

// ── Section F: Delivery Monitoring ───────────────────────────────
$del_pend  = $conn->query("SELECT COUNT(*) as c FROM deliveries WHERE status='" . DEL_PENDING . "'")->fetch_assoc()['c'] ?? 0;
$del_rec   = $conn->query("SELECT d.*, s.name as supplier FROM deliveries d JOIN suppliers s ON s.id=d.supplier_id ORDER BY d.id DESC LIMIT 5");

// ── Section H: Security Flags ─────────────────────────────────────
$sec_flags = $conn->query("SELECT * FROM security_flags WHERE status='" . FLAG_OPEN . "' ORDER BY FIELD(severity,'" . SEV_HIGH . "','" . SEV_MEDIUM . "','" . SEV_LOW . "'), created_at DESC LIMIT 20");
$sec_count = $conn->query("SELECT COUNT(*) as c FROM security_flags WHERE status='" . FLAG_OPEN . "'")->fetch_assoc()['c'] ?? 0;

// ── Section I: Staff Activity ─────────────────────────────────────
$staff_q = $conn->query("
    SELECT u.id, u.username, u.full_name, u.status as acc_status,
           al.message as last_msg, al.log_type as last_type, al.created_at as last_at
    FROM users u
    LEFT JOIN activity_logs al ON al.id = (SELECT id FROM activity_logs WHERE user_id=u.id ORDER BY id DESC LIMIT 1)
    WHERE u.role='" . ROLE_STAFF . "'
    ORDER BY last_at DESC
");
$flagged_ids = [];

// ── Section J: Activity Trail ─────────────────────────────────────
// M-04: admin should not see superadmin-generated logs; superadmin sees all
if ($role === ROLE_SUPERADMIN) {
    $logs_q = $conn->query("SELECT al.*, u.username FROM activity_logs al LEFT JOIN users u ON al.user_id=u.id ORDER BY al.id DESC LIMIT 8");
} else {
    $logs_q = $conn->query("SELECT al.*, u.username FROM activity_logs al LEFT JOIN users u ON al.user_id=u.id WHERE u.role NOT IN ('" . ROLE_SUPERADMIN . "') OR al.user_id IS NULL ORDER BY al.id DESC LIMIT 8");
}

// Month-over-month change
$mon_change = $rev_prev_mon['r'] > 0 ? round((($rev_mon['r'] - $rev_prev_mon['r']) / $rev_prev_mon['r']) * 100, 1) : null;
?>

<div class="max-w-[1600px] mx-auto space-y-10 pb-20 animate-in">

<!-- ═══════════════════ DASHBOARD ═══════════════════ -->
<div class="space-y-5">
    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Dashboard</p>

    <!-- Business Analytics -->
    <div class="grid grid-cols-2 lg:grid-cols-3 gap-5">
        <div class="stat-card-base col-span-2 lg:col-span-1 bg-slate-900 text-white border-slate-800">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">All-Time Revenue</p>
            <h3 class="text-3xl font-black text-white tracking-tighter">₱<?= number_format($rev_all['r'], 2) ?></h3>
            <p class="text-slate-500 text-xs font-bold mt-2"><?= number_format($rev_all['t']) ?> total transactions</p>
        </div>
        <div class="stat-card-base">
            <div class="flex justify-between items-start mb-3">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?= date('F Y') ?></p>
                <?php if ($mon_change !== null): ?>
                <span class="text-[9px] font-black px-2 py-0.5 rounded-full <?= $mon_change >= 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?>">
                    <?= $mon_change >= 0 ? '+' : '' ?><?= $mon_change ?>%
                </span>
                <?php endif; ?>
            </div>
            <h3 class="text-3xl font-black text-slate-800 tracking-tighter">₱<?= number_format($rev_mon['r'], 2) ?></h3>
            <p class="text-slate-400 text-xs font-bold mt-2"><?= number_format($rev_mon['t']) ?> transactions this month</p>
        </div>
        <div class="stat-card-base">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3"><?= date('Y') ?> Year-to-Date</p>
            <h3 class="text-3xl font-black text-slate-800 tracking-tighter">₱<?= number_format($rev_yr['r'], 2) ?></h3>
            <p class="text-slate-400 text-xs font-bold mt-2"><?= number_format($rev_yr['t']) ?> transactions this year</p>
        </div>
    </div>

    <!-- Procurement Access Requests + System Audit Logs -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        <!-- Delivery Return Tickets -->
        <div class="bg-white rounded-[2.5rem] border <?= $dr_pending_ct > 0 ? 'border-rose-200' : 'border-slate-100' ?> shadow-xl overflow-hidden">
            <div class="p-6 border-b border-slate-50 flex items-center justify-between">
                <div>
                    <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">Delivery Return Tickets</h4>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Return request monitoring</p>
                </div>
                <div class="flex items-center gap-2">
                    <?php if ($dr_pending_ct > 0): ?>
                    <span class="bg-rose-500 text-white text-[9px] font-black px-3 py-1 rounded-full"><?= $dr_pending_ct ?> pending</span>
                    <?php endif; ?>
                    <a href="sales/refund_management.php?tab=queue" class="text-[9px] font-black text-rose-600 bg-rose-50 px-3 py-1.5 rounded-xl hover:bg-rose-100 transition-all uppercase tracking-widest border border-rose-100">Queue →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-50">
                <?php if (empty($dr_tickets)): ?>
                <div class="p-10 text-center text-slate-300 font-black italic text-sm">No return tickets yet.</div>
                <?php else: foreach ($dr_tickets as $drt):
                    $drt_st = match($drt['status']) {
                        DR_APPROVED => ['bg-emerald-50 text-emerald-600 border-emerald-100', 'Approved'],
                        DR_REJECTED => ['bg-rose-50 text-rose-500 border-rose-100',          'Rejected'],
                        default     => ['bg-amber-50 text-amber-600 border-amber-100',       'Pending'],
                    };
                ?>
                <div class="px-6 py-3.5 flex items-center gap-3 hover:bg-slate-50 transition-colors">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-black text-slate-800 text-sm truncate"><?= htmlspecialchars($drt['supplier_name'] ?? '—') ?></p>
                            <?php if ($drt['ticket_no']): ?>
                            <code class="text-[9px] font-bold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded"><?= htmlspecialchars($drt['ticket_no']) ?></code>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-3 mt-0.5 flex-wrap">
                            <code class="text-[10px] text-slate-300 font-mono"><?= htmlspecialchars($drt['invoice_no']) ?></code>
                            <span class="text-[9px] text-slate-400 font-bold"><?= intval($drt['item_count']) ?> item<?= $drt['item_count'] != 1 ? 's' : '' ?></span>
                            <span class="text-[9px] text-slate-400 font-bold truncate max-w-[120px]" title="<?= htmlspecialchars($drt['purpose'] ?? '') ?>"><?= htmlspecialchars(mb_strimwidth($drt['purpose'] ?? '—', 0, 28, '…')) ?></span>
                        </div>
                        <p class="text-[9px] text-slate-300 font-bold mt-0.5">
                            by @<?= htmlspecialchars($drt['requested_username'] ?? '?') ?>
                            <?php if ($drt['reviewed_username']): ?> · reviewed by @<?= htmlspecialchars($drt['reviewed_username']) ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="text-right flex-shrink-0 space-y-1">
                        <span class="text-[9px] font-black px-2 py-0.5 rounded-full border <?= $drt_st[0] ?> uppercase block"><?= $drt_st[1] ?></span>
                        <p class="text-[9px] text-slate-300 font-bold"><?= date('M d, g:i A', strtotime($drt['created_at'])) ?></p>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- System Audit Logs (unified activity trail) -->
        <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
            <div class="p-6 border-b border-slate-50 flex justify-between items-center">
                <div>
                    <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">System Audit Logs</h4>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Last 8 system-wide actions</p>
                </div>
                <a href="activity_logs.php" class="text-[9px] font-black text-emerald-600 bg-emerald-50 px-4 py-2 rounded-xl hover:bg-emerald-100 transition-all uppercase tracking-widest border border-emerald-100">All →</a>
            </div>
            <div class="divide-y divide-slate-50">
                <?php if ($logs_q && $logs_q->num_rows > 0): while ($log = $logs_q->fetch_assoc()):
                    $tc = match($log['log_type']) { LOG_SALES => 'text-emerald-500', LOG_DELIVERIES => 'text-blue-500', LOG_DISPOSAL => 'text-rose-500', LOG_PRICES => 'text-purple-500', LOG_USERS => 'text-violet-500', LOG_PAYMENTS => 'text-sky-500', default => 'text-slate-400' };
                ?>
                <div class="px-6 py-4 flex items-start gap-4 hover:bg-slate-50 transition-colors">
                    <div class="w-20 flex-shrink-0 text-center pt-0.5">
                        <p class="text-xs font-black text-slate-800"><?= date("M d", strtotime($log['created_at'])) ?></p>
                        <p class="text-[9px] font-black text-slate-300 uppercase"><?= date("h:i A", strtotime($log['created_at'])) ?></p>
                        <?php if ($log['username']): ?>
                        <p class="text-[8px] font-black text-slate-400 mt-0.5">@<?= htmlspecialchars($log['username']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <span class="text-[8px] font-black uppercase px-2 py-0.5 rounded <?= str_replace('text', 'bg', $tc) ?>/10 <?= $tc ?> mb-1 inline-block"><?= $log['log_type'] ?></span>
                        <p class="text-slate-600 font-bold text-sm truncate"><?= htmlspecialchars($log['message']) ?></p>
                    </div>
                </div>
                <?php endwhile; else: ?>
                <div class="p-10 text-center text-slate-300 font-black italic text-sm">No activity logged yet.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- ═══════════════════ SECTION B: LIVE OPERATIONS ═══════════════════ -->
<div class="space-y-4">
    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Live Operations</p>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <!-- Last Sale + Refund Today -->
        <div class="space-y-4">
            <?php if ($last_sale): ?>
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-6 flex items-center gap-5">
                <div class="w-12 h-12 bg-emerald-500 rounded-2xl flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </div>
                <div class="flex-1">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Last Sale</p>
                    <p class="font-black text-slate-800 text-lg">₱<?= number_format($last_sale['total'], 2) ?></p>
                    <p class="text-[10px] text-slate-400 font-bold"><?= date('M d, h:i A', strtotime($last_sale['created_at'])) ?></p>
                </div>
                <a href="pos/pos.php" class="text-[9px] font-black text-emerald-500 hover:underline uppercase tracking-widest">POS →</a>
            </div>
            <?php endif; ?>
            <div id="refund-widget" class="bg-white rounded-[2rem] border <?= $ref_pend > 0 ? 'border-amber-200' : 'border-slate-100' ?> shadow-md overflow-hidden">
                <!-- Header row — click to expand when there are pending refunds -->
                <div onclick="<?= $ref_pend > 0 ? 'toggleRefundList()' : 'void(0)' ?>"
                     class="p-6 flex items-center gap-5 <?= $ref_pend > 0 ? 'cursor-pointer hover:bg-amber-50/40' : '' ?> <?= $ref_pend > 0 ? 'bg-amber-50/20' : '' ?> transition-all select-none">
                    <div class="w-12 h-12 <?= $ref_pend > 0 ? 'bg-amber-100' : 'bg-slate-100' ?> rounded-2xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6 <?= $ref_pend > 0 ? 'text-amber-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                    </div>
                    <div class="flex-1">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Refunds Today / Pending</p>
                        <p class="font-black text-slate-800 text-lg"><?= $ref_today['cnt'] ?> today · <span class="<?= $ref_pend > 0 ? 'text-amber-600' : 'text-slate-400' ?>"><?= $ref_pend ?> pending</span></p>
                        <p class="text-[10px] text-slate-400 font-bold">₱<?= number_format($ref_today['amt'], 2) ?> returned today</p>
                    </div>
                    <?php if ($ref_pend > 0): ?>
                    <div id="refund-chevron" class="w-8 h-8 bg-amber-100 rounded-xl flex items-center justify-center flex-shrink-0 transition-transform duration-200">
                        <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                    </div>
                    <?php else: ?>
                    <a href="sales/refund_management.php?tab=queue" onclick="event.stopPropagation()" class="text-[9px] font-black text-amber-500 hover:underline uppercase tracking-widest flex-shrink-0">View →</a>
                    <?php endif; ?>
                </div>

                <?php if ($ref_pend > 0): ?>
                <!-- Expandable pending list -->
                <div id="refund-list" class="hidden border-t border-amber-100">
                    <?php if (!empty($ref_queue_items)): ?>
                    <div class="divide-y divide-slate-50">
                        <?php foreach ($ref_queue_items as $rq): ?>
                        <div class="px-5 py-4 flex items-center gap-3 hover:bg-slate-50/50 transition-colors">
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-slate-800 text-sm truncate"><?= htmlspecialchars($rq['product_name']) ?></p>
                                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                    <code class="text-[9px] text-slate-400 font-mono">#<?= htmlspecialchars($rq['receipt_no']) ?></code>
                                    <span class="text-slate-200">·</span>
                                    <span class="text-[9px] font-bold text-slate-500">Qty: <?= $rq['qty'] ?></span>
                                    <span class="text-[9px] font-black text-emerald-600">₱<?= number_format($rq['amount_refunded'], 2) ?></span>
                                    <?php if ($rq['requested_by']): ?>
                                    <span class="text-[9px] text-slate-400 font-bold">by @<?= htmlspecialchars($rq['requested_by']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="<?= $rq['disposition'] === 'restock' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500' ?> text-[9px] font-black px-2 py-1 rounded-lg flex-shrink-0">
                                <?= $rq['disposition'] === 'restock' ? 'Restock' : 'Dispose' ?>
                            </span>
                            <button onclick="dashApproveRefund(<?= $rq['id'] ?>, '<?= htmlspecialchars(addslashes($rq['product_name'])) ?>', '<?= number_format($rq['amount_refunded'], 2) ?>')"
                                class="bg-emerald-500 text-white px-3 py-1.5 rounded-xl font-black text-[9px] uppercase hover:bg-emerald-600 transition-all shadow-sm flex-shrink-0">
                                Approve
                            </button>
                            <button onclick="dashOpenReject(<?= $rq['id'] ?>, '<?= htmlspecialchars(addslashes($rq['product_name'])) ?>')"
                                class="bg-rose-500 text-white px-3 py-1.5 rounded-xl font-black text-[9px] uppercase hover:bg-rose-600 transition-all shadow-sm flex-shrink-0">
                                Reject
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="p-4 border-t border-amber-100 text-center">
                        <a href="sales/returns_exchange.php" class="text-[9px] font-black text-amber-500 hover:underline uppercase tracking-widest">Open Returns &amp; Exchange →</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-6 flex items-center gap-5">
                <div class="w-12 h-12 <?= $del_pend > 0 ? 'bg-blue-100' : 'bg-slate-100' ?> rounded-2xl flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 <?= $del_pend > 0 ? 'text-blue-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <div class="flex-1">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Deliveries Pending Validation</p>
                    <p class="font-black <?= $del_pend > 0 ? 'text-blue-700' : 'text-slate-800' ?> text-lg"><?= $del_pend ?> <span class="text-sm text-slate-300 font-bold italic">pending</span></p>
                </div>
                <a href="procurement/deliveries.php?filter=pending" class="text-[9px] font-black text-blue-500 hover:underline uppercase tracking-widest">View →</a>
            </div>
        </div>
    </div>
</div>


<!-- ═══════════════════ SECTION H: SECURITY FLAGS (high priority) ═══════════════════ -->
<?php if ($sec_count > 0): ?>
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Security & Anomaly Feed</p>
        <span class="bg-rose-500 text-white text-[9px] font-black px-3 py-1 rounded-full"><?= $sec_count ?> open</span>
    </div>
    <div class="bg-white rounded-[2.5rem] border border-rose-100 shadow-xl overflow-hidden">
        <div class="divide-y divide-slate-50">
            <?php while ($sf = $sec_flags->fetch_assoc()):
                $sev_cfg = match($sf['severity']) {
                    SEV_HIGH   => ['bg-rose-100 text-rose-700',   'bg-rose-500',  'HIGH'],
                    SEV_MEDIUM => ['bg-amber-100 text-amber-700', 'bg-amber-500', 'MED'],
                    default    => ['bg-blue-50 text-blue-600',    'bg-blue-400',  'LOW'],
                };
                $type_labels = [FLAG_PRICE_SPIKE => 'Price Spike', FLAG_SPEED_ANOMALY => 'Speed Anomaly', FLAG_REPEAT_DISCREPANCY => 'Repeat Discrepancy', FLAG_STAFF_CHANGE => 'Staff Change', FLAG_ACCESS_EVENT => 'Access Event', FLAG_DUPLICATE_REFUND => 'Duplicate Refund'];
                $type_label  = $type_labels[$sf['flag_type']] ?? ucwords(str_replace('_', ' ', $sf['flag_type']));
            ?>
            <div class="px-7 py-5 flex items-center gap-5 hover:bg-slate-50/50 transition-colors">
                <div class="w-2 h-2 <?= $sev_cfg[1] ?> rounded-full flex-shrink-0"></div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-[8px] font-black px-2 py-0.5 rounded <?= $sev_cfg[0] ?> uppercase tracking-widest"><?= $sev_cfg[2] ?></span>
                        <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest"><?= $type_label ?></span>
                        <span class="text-[9px] text-slate-300 font-bold"><?= date('M d, h:i A', strtotime($sf['created_at'])) ?></span>
                    </div>
                    <p class="text-slate-700 font-bold text-sm"><?= htmlspecialchars($sf['message']) ?></p>
                </div>
                <form method="POST" class="flex-shrink-0">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="dismiss_flag" value="1">
                    <input type="hidden" name="flag_id" value="<?= $sf['id'] ?>">
                    <button class="text-[9px] font-black text-slate-400 hover:text-slate-700 border border-slate-200 px-3 py-1.5 rounded-xl hover:bg-slate-50 transition-all uppercase tracking-widest">Dismiss</button>
                </form>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════ SECTION C: PROCUREMENT PIPELINE ═══════════════════ -->
<div class="space-y-4">
    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Procurement Pipeline</p>
    <!-- 4-Step Pipeline -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <?php
        $steps = [
            ['label' => 'Suppliers',     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',                                                                                                                                                                       'val' => $pip_suppliers,        'sub' => 'active suppliers',                                                                 'href' => 'suppliers.php',                         'color' => 'text-slate-600',                                          'bg' => 'bg-slate-100',  'badge' => 0],
            ['label' => 'Low Stock Items','icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',                                                                                                                                          'val' => $inv_low,              'sub' => $inv_low > 0 ? 'items need restocking' : 'all stock levels healthy',   'href' => 'stock_management.php?filter=low_stock', 'color' => $inv_low > 0 ? 'text-red-600' : 'text-slate-600',          'bg' => $inv_low > 0 ? 'bg-red-100' : 'bg-slate-100', 'badge' => 0],
            ['label' => 'Archived Items', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>',                                                                                                                                                                                             'val' => $pip_archived,         'sub' => $pip_archived > 0 ? 'items out of stock / deactivated' : 'no archived items', 'href' => 'stock_management.php?stock=archived',    'color' => $pip_archived > 0 ? 'text-slate-600' : 'text-slate-400',  'bg' => $pip_archived > 0 ? 'bg-slate-200' : 'bg-slate-100', 'badge' => $pip_new_archived],
            ['label' => 'Supplier Payments','icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>',                                                                                                                       'val' => intval($pip_pay['c']), 'sub' => '₱' . number_format($pip_pay['total'], 0) . ' to settle',                'href' => 'procurement/supplier_payments.php',     'color' => intval($pip_pay['c']) > 0 ? 'text-rose-600' : 'text-slate-600', 'bg' => intval($pip_pay['c']) > 0 ? 'bg-rose-50' : 'bg-slate-100', 'badge' => 0],
        ];
        foreach ($steps as $n => $s): ?>
        <a href="<?= $s['href'] ?>" class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-6 hover:shadow-lg transition-all group">
            <div class="flex items-center justify-between mb-4">
                <div class="relative w-9 h-9 <?= $s['bg'] ?> rounded-xl flex items-center justify-center group-hover:scale-110 transition-transform">
                    <svg class="w-5 h-5 <?= $s['color'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $s['icon'] ?></svg>
                    <?php if (!empty($s['badge']) && $s['badge'] > 0): ?>
                    <span class="absolute -top-1.5 -right-1.5 bg-rose-500 text-white text-[8px] font-black min-w-[16px] h-4 px-1 rounded-full flex items-center justify-center shadow-sm shadow-rose-200 leading-none"><?= $s['badge'] ?></span>
                    <?php endif; ?>
                </div>
                <span class="text-[9px] font-black text-slate-300 bg-slate-50 w-6 h-6 flex items-center justify-center rounded-full"><?= $n+1 ?></span>
            </div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1"><?= $s['label'] ?></p>
            <p class="text-2xl font-black <?= $s['color'] ?>"><?= $s['val'] ?></p>
            <p class="text-[10px] text-slate-400 font-bold mt-1"><?= $s['sub'] ?></p>
        </a>
        <?php endforeach; ?>
    </div>

</div>

<!-- ═══════════════════ SECTION D: INVENTORY HEALTH ═══════════════════ -->
<div class="space-y-4">
    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Inventory Health</p>

    <?php if ($expiry_alert_count > 0): ?>
    <div class="bg-orange-50 border-2 border-orange-200 rounded-[2.5rem] overflow-hidden">
        <div class="p-6 border-b border-orange-100 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-4">
                <div class="w-11 h-11 bg-orange-500 rounded-2xl flex items-center justify-center flex-shrink-0 animate-pulse">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <h4 class="font-black text-orange-900 text-sm uppercase tracking-widest">Expiry Alert — <?= $expiry_alert_count ?> Item<?= $expiry_alert_count > 1 ? 's' : '' ?></h4>
                    <p class="text-orange-600 text-[10px] font-bold mt-0.5">Products expiring within 7 days or already expired. Review and consider disposal.</p>
                </div>
            </div>
            <a href="inventory/stock_management.php" class="text-[9px] font-black text-orange-600 border border-orange-200 px-4 py-2 rounded-xl hover:bg-orange-500 hover:text-white transition-all uppercase tracking-widest">View Inventory →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-orange-100/50 border-b border-orange-100">
                        <th class="px-6 py-3 font-black text-orange-700 uppercase tracking-widest">Product</th>
                        <th class="px-4 py-3 font-black text-orange-700 uppercase tracking-widest">Batch / Invoice</th>
                        <th class="px-4 py-3 font-black text-orange-700 uppercase tracking-widest text-center">Expiry Date</th>
                        <th class="px-4 py-3 font-black text-orange-700 uppercase tracking-widest text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-orange-50">
                <?php foreach ($expiry_alerts as $ea):
                    $ea_days = (int)ceil((strtotime($ea['expiry_date']) - strtotime('today')) / 86400);
                    $ea_badge_cls = $ea_days <= 0 ? 'bg-rose-100 text-rose-700' : 'bg-orange-100 text-orange-700';
                    $ea_badge_lbl = $ea_days < 0 ? 'Expired ' . abs($ea_days) . 'd ago' : ($ea_days === 0 ? 'Expires Today' : "In {$ea_days}d");
                ?>
                <tr class="hover:bg-orange-50/80 transition-all">
                    <td class="px-6 py-3">
                        <p class="font-bold text-slate-800 truncate max-w-[200px]"><?= htmlspecialchars($ea['name']) ?></p>
                        <code class="text-[10px] text-slate-400">#<?= htmlspecialchars($ea['barcode']) ?></code>
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-bold text-slate-700"><?= htmlspecialchars($ea['supplier_name']) ?></p>
                        <p class="text-[10px] text-slate-400 font-mono uppercase"><?= htmlspecialchars($ea['invoice_number']) ?></p>
                    </td>
                    <td class="px-4 py-3 text-center font-bold text-slate-700 whitespace-nowrap"><?= date('M j, Y', strtotime($ea['expiry_date'])) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-[9px] font-black px-2.5 py-1 rounded-full <?= $ea_badge_cls ?> uppercase tracking-widest whitespace-nowrap"><?= $ea_badge_lbl ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <!-- Stock Status Cards -->
        <div class="space-y-4">
            <a href="inventory/stock_management.php?filter=low_stock" class="block bg-white rounded-[2rem] border <?= $inv_low > 0 ? 'border-red-200 bg-red-50' : 'border-slate-100' ?> shadow-md p-6 hover:shadow-lg transition-all">
                <p class="text-[9px] font-black <?= $inv_low > 0 ? 'text-red-500' : 'text-slate-400' ?> uppercase tracking-widest mb-1">Low Stock Items</p>
                <p class="text-3xl font-black <?= $inv_low > 0 ? 'text-red-700' : 'text-slate-700' ?>"><?= $inv_low ?> <span class="text-sm font-bold opacity-30 italic">items</span></p>
            </a>
            <a href="inventory/stock_management.php?filter=out_of_stock" class="block bg-white rounded-[2rem] border border-slate-100 shadow-md p-6 hover:shadow-lg transition-all">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Zero Stock</p>
                <p class="text-3xl font-black text-slate-700"><?= $inv_out ?> <span class="text-sm font-bold opacity-30 italic">items</span></p>
            </a>
            <div class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-6 flex justify-between items-center">
                <div>
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Archived</p>
                    <p class="text-3xl font-black text-slate-700"><?= $inv_arch ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Disposed (7d)</p>
                    <p class="text-2xl font-black text-slate-700"><?= $disposed ?></p>
                </div>
            </div>
        </div>

        <!-- Recent Price Changes -->
        <div class="lg:col-span-2 bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
            <div class="p-6 border-b border-slate-50 flex justify-between items-center">
                <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">Recent Price Changes</h4>
                <a href="inventory/price_maintenance.php" class="text-[9px] font-black text-purple-500 hover:underline uppercase tracking-widest">Manage →</a>
            </div>
            <div class="divide-y divide-slate-50">
                <?php if ($price_chg && $price_chg->num_rows > 0): while ($pc = $price_chg->fetch_assoc()): ?>
                <div class="px-6 py-4 flex items-center gap-4">
                    <div class="w-7 h-7 bg-purple-50 rounded-xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-slate-700 font-bold text-sm truncate"><?= htmlspecialchars($pc['message']) ?></p>
                        <p class="text-[9px] text-slate-300 font-bold"><?= date('M d, h:i A', strtotime($pc['created_at'])) ?></p>
                    </div>
                </div>
                <?php endwhile; else: ?>
                <div class="p-10 text-center text-slate-300 font-black italic text-sm">No price changes recorded.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════ SECTION F: DELIVERY MONITORING ═══════════════════ -->
<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Delivery Monitoring</p>
        <a href="procurement/deliveries.php" class="text-[9px] font-black text-blue-500 hover:underline uppercase tracking-widest">All Deliveries →</a>
    </div>
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-100">
                        <th class="px-7 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th class="px-4 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest">Supplier</th>
                        <th class="px-4 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                        <th class="px-7 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if ($del_rec && $del_rec->num_rows > 0): while ($d = $del_rec->fetch_assoc()):
                        $del_status_cfg = $d['status'] === DEL_VERIFIED
                            ? 'bg-emerald-50 text-emerald-700'
                            : 'bg-amber-50 text-amber-700';
                    ?>
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-7 py-5 font-bold text-slate-700 text-sm"><?= date("M d, Y", strtotime($d['delivery_date'])) ?></td>
                        <td class="px-4 py-5 text-slate-600 font-bold text-sm"><?= htmlspecialchars($d['supplier']) ?></td>
                        <td class="px-4 py-5 text-center"><span class="text-[9px] font-black px-3 py-1 rounded-full <?= $del_status_cfg ?>"><?= $d['status'] ?></span></td>
                        <td class="px-7 py-5 text-right"><a href="procurement/delivery_view.php?id=<?= $d['id'] ?>" class="text-[9px] font-black text-blue-500 hover:underline uppercase tracking-widest">View →</a></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="4" class="px-7 py-12 text-center text-slate-300 font-black italic text-sm">No deliveries found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════ SECTION I: STAFF ACTIVITY ═══════════════════ -->
<div class="space-y-4">
    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Staff Activity Monitor</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php if ($staff_q && $staff_q->num_rows > 0): while ($su = $staff_q->fetch_assoc()):
            $tc = match($su['last_type'] ?? '') { 'Sales' => 'text-emerald-500', 'Deliveries' => 'text-blue-500', 'Disposal' => 'text-rose-500', 'Prices' => 'text-purple-500', default => 'text-slate-400' };
        ?>
        <div class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-6">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-9 h-9 bg-slate-900 rounded-2xl flex items-center justify-center text-white font-black text-sm flex-shrink-0">
                    <?= strtoupper(substr($su['username'], 0, 1)) ?>
                </div>
                <div>
                    <p class="font-black text-slate-800 text-sm leading-tight">@<?= htmlspecialchars($su['username']) ?></p>
                    <p class="text-[9px] text-slate-400 font-bold"><?= htmlspecialchars($su['full_name'] ?? '') ?></p>
                </div>
            </div>
            <?php if ($su['last_msg']): ?>
            <p class="text-[10px] text-slate-500 font-bold truncate"><?= htmlspecialchars($su['last_msg']) ?></p>
            <p class="text-[9px] text-slate-300 font-bold mt-1"><?= $su['last_at'] ? date('M d, h:i A', strtotime($su['last_at'])) : 'No activity' ?></p>
            <?php else: ?>
            <p class="text-[10px] text-slate-300 font-black italic">No activity logged.</p>
            <?php endif; ?>
        </div>
        <?php endwhile; else: ?>
        <div class="col-span-3 bg-white rounded-[2rem] border border-slate-100 shadow-md p-12 text-center text-slate-300 font-black italic">No staff accounts found.</div>
        <?php endif; ?>
    </div>
</div>


</div>

<!-- ── Dashboard refund action form ───────────────────────────────────────── -->
<form id="dash-refund-form" method="POST" action="sales/refund_approve.php" class="hidden">
    <input type="hidden" name="action"    id="drf-action">
    <input type="hidden" name="refund_id" id="drf-refund-id">
    <input type="hidden" name="note"      id="drf-note">
</form>

<!-- ── Dashboard reject reason modal ──────────────────────────────────────── -->
<div id="dashRejectModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[200] hidden items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-sm p-8 animate-in">
        <h3 class="text-lg font-black text-slate-800 mb-1">Reject Refund Request</h3>
        <p id="dashRejectLabel" class="text-slate-400 text-sm mb-5"></p>
        <div class="mb-5">
            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Reason <span class="text-rose-500">*</span></label>
            <textarea id="dashRejectReason" rows="3" placeholder="State the reason..."
                      class="input-modern w-full resize-none"></textarea>
        </div>
        <div class="flex gap-3">
            <button onclick="closeDashReject()" class="flex-1 bg-slate-100 text-slate-600 font-black py-3 rounded-2xl hover:bg-slate-200 transition-all text-sm uppercase">Cancel</button>
            <button onclick="submitDashReject()" class="flex-1 bg-rose-500 text-white font-black py-3 rounded-2xl hover:bg-rose-600 transition-all shadow-lg text-sm uppercase">Reject</button>
        </div>
    </div>
</div>

<script>
let _dashRefundOpen = false;
let _dashRejectId   = null;

function toggleRefundList() {
    const list    = document.getElementById('refund-list');
    const chevron = document.getElementById('refund-chevron');
    if (!list) return;
    _dashRefundOpen = !_dashRefundOpen;
    list.classList.toggle('hidden', !_dashRefundOpen);
    if (chevron) chevron.style.transform = _dashRefundOpen ? 'rotate(180deg)' : '';
}

async function dashApproveRefund(id, name, amount) {
    const ok = await customConfirm(`Refund of ₱${amount} for "${name}" will be approved.`, 'Approve Refund?');
    if (!ok) return;
    document.getElementById('drf-action').value    = 'approve';
    document.getElementById('drf-refund-id').value = id;
    document.getElementById('drf-note').value      = '';
    navigate('sales/refund_approve.php', new FormData(document.getElementById('dash-refund-form')));
}

function dashOpenReject(id, name) {
    _dashRejectId = id;
    document.getElementById('dashRejectLabel').textContent = 'Refund request for: ' + name;
    document.getElementById('dashRejectReason').value = '';
    const m = document.getElementById('dashRejectModal');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => document.getElementById('dashRejectReason').focus(), 80);
}

function closeDashReject() {
    const m = document.getElementById('dashRejectModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

async function submitDashReject() {
    const reason = document.getElementById('dashRejectReason').value.trim();
    if (!reason) { alert('Please provide a rejection reason.'); return; }
    const ok = await customConfirm('This refund request will be rejected.', 'Reject Request?');
    if (!ok) return;
    document.getElementById('drf-action').value    = 'reject';
    document.getElementById('drf-refund-id').value = _dashRejectId;
    document.getElementById('drf-note').value      = reason;
    navigate('sales/refund_approve.php', new FormData(document.getElementById('dash-refund-form')));
    closeDashReject();
}

document.getElementById('dashRejectModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeDashReject();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDashReject(); });
</script>

<?php endif; ?>

<?php if (in_array($role, ROLES_ADMIN_AND_UP)):
    // ── Disposed items (all disposals: inventory write-offs + refund-disposed) ──
    $disp_tot = $conn->query(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(pd.qty),0) AS units,
                COALESCE(SUM(pd.qty * COALESCE(p.cost_price,0)),0) AS loss_value
         FROM product_disposals pd
         LEFT JOIN products p ON p.id = pd.product_id
         WHERE pd.status = '" . DISPOSAL_APPROVED . "'"
    );
    $disp_stats = $disp_tot ? $disp_tot->fetch_assoc() : ['cnt'=>0,'units'=>0,'loss_value'=>0];
    $disp_recent = $conn->query(
        "SELECT pd.product_name, pd.barcode, pd.qty, pd.reason, pd.notes, pd.status,
                pd.approved_username, pd.created_at,
                (pd.notes LIKE '%refund%') AS from_refund
         FROM product_disposals pd
         ORDER BY pd.created_at DESC LIMIT 10"
    );
?>
<!-- ── DISPOSED ITEMS ───────────────────────────────────────────────────── -->
<div class="max-w-[1600px] mx-auto pb-6 animate-in">
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl p-8">
        <div class="flex items-center gap-4 mb-6 flex-wrap">
            <div class="w-10 h-10 bg-rose-100 rounded-2xl flex items-center justify-center text-rose-500">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <h4 class="font-black text-slate-800 text-sm">Disposed Items</h4>
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Write-offs &amp; refund-disposed stock</p>
            </div>
            <div class="flex gap-6">
                <div class="text-right">
                    <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest">Approved</p>
                    <p class="font-black text-slate-700 text-lg"><?= intval($disp_stats['cnt']) ?> <span class="text-xs text-slate-400"><?= intval($disp_stats['units']) ?> units</span></p>
                </div>
                <div class="text-right">
                    <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest">Est. Loss (cost)</p>
                    <p class="font-black text-rose-600 text-lg">₱<?= number_format(floatval($disp_stats['loss_value']), 2) ?></p>
                </div>
            </div>
        </div>

        <?php if ($disp_recent && $disp_recent->num_rows > 0): ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                        <th class="py-2 pr-3">Product</th>
                        <th class="py-2 px-3 text-center">Qty</th>
                        <th class="py-2 px-3">Reason</th>
                        <th class="py-2 px-3">Source</th>
                        <th class="py-2 px-3">By</th>
                        <th class="py-2 pl-3 text-right">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                <?php while ($d = $disp_recent->fetch_assoc()): ?>
                    <tr class="hover:bg-slate-50/50">
                        <td class="py-2.5 pr-3">
                            <p class="font-bold text-slate-700"><?= htmlspecialchars($d['product_name'] ?? '—') ?></p>
                            <?php if (!empty($d['barcode'])): ?><code class="text-[10px] text-slate-400">#<?= htmlspecialchars($d['barcode']) ?></code><?php endif; ?>
                        </td>
                        <td class="py-2.5 px-3 text-center font-black text-slate-700"><?= intval($d['qty']) ?></td>
                        <td class="py-2.5 px-3 text-slate-500"><?= htmlspecialchars($d['reason'] ?? '—') ?></td>
                        <td class="py-2.5 px-3">
                            <?php if (!empty($d['from_refund'])): ?>
                                <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 uppercase">Refund</span>
                            <?php else: ?>
                                <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 uppercase">Inventory</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2.5 px-3 text-slate-500">@<?= htmlspecialchars($d['approved_username'] ?? '—') ?></td>
                        <td class="py-2.5 pl-3 text-right text-slate-400 text-xs"><?= date('M j, Y', strtotime($d['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="text-slate-400 text-sm font-bold text-center py-8">No disposed items on record.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($role, ROLES_ADMIN_AND_UP)): ?>
<!-- ── EXPORT PANEL ──────────────────────────────────────────────────────── -->
<div class="max-w-[1600px] mx-auto pb-10 animate-in">
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl p-8">
        <div class="flex items-center gap-4 mb-6">
            <div class="w-10 h-10 bg-slate-100 rounded-2xl flex items-center justify-center text-slate-500">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            </div>
            <div>
                <h4 class="font-black text-slate-800 text-sm">Export Data</h4>
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Download CSV for Excel / spreadsheet</p>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Sales Export -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100">
                <p class="font-black text-slate-700 text-xs uppercase tracking-widest mb-3">Sales Report</p>
                <div class="flex gap-2 mb-3">
                    <input type="date" id="exp-sales-from" value="<?= date('Y-m-01') ?>" class="flex-1 text-xs border border-slate-200 rounded-xl px-3 py-2 font-bold text-slate-600 bg-white">
                    <span class="text-slate-300 font-bold self-center">→</span>
                    <input type="date" id="exp-sales-to" value="<?= date('Y-m-d') ?>" class="flex-1 text-xs border border-slate-200 rounded-xl px-3 py-2 font-bold text-slate-600 bg-white">
                </div>
                <a id="exp-sales-btn" href="#" onclick="exportCSV('sales','exp-sales-from','exp-sales-to')" class="block text-center bg-emerald-500 hover:bg-emerald-600 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-xl transition-all">
                    ↓ Export Sales CSV
                </a>
            </div>
            <!-- Inventory Export -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100">
                <p class="font-black text-slate-700 text-xs uppercase tracking-widest mb-3">Inventory Snapshot</p>
                <p class="text-slate-400 text-[10px] font-bold mb-3">Full product list with cost price, margin %, and stock levels as of today.</p>
                <a href="/project/staff/reports/export.php?type=inventory" class="block text-center bg-blue-500 hover:bg-blue-600 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-xl transition-all">
                    ↓ Export Inventory CSV
                </a>
            </div>
            <!-- Refunds Export -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100">
                <p class="font-black text-slate-700 text-xs uppercase tracking-widest mb-3">Refunds Report</p>
                <div class="flex gap-2 mb-3">
                    <input type="date" id="exp-ref-from" value="<?= date('Y-m-01') ?>" class="flex-1 text-xs border border-slate-200 rounded-xl px-3 py-2 font-bold text-slate-600 bg-white">
                    <span class="text-slate-300 font-bold self-center">→</span>
                    <input type="date" id="exp-ref-to" value="<?= date('Y-m-d') ?>" class="flex-1 text-xs border border-slate-200 rounded-xl px-3 py-2 font-bold text-slate-600 bg-white">
                </div>
                <a href="#" onclick="exportCSV('refunds','exp-ref-from','exp-ref-to')" class="block text-center bg-rose-500 hover:bg-rose-600 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-xl transition-all">
                    ↓ Export Refunds CSV
                </a>
            </div>
        </div>
    </div>
</div>
<script>
function exportCSV(type, fromId, toId) {
    var from = document.getElementById(fromId)?.value || '';
    var to   = document.getElementById(toId)?.value   || '';
    window.location.href = '/project/staff/reports/export.php?type=' + type + '&date_from=' + from + '&date_to=' + to;
}
</script>
<?php endif; ?>

<?php include 'layout_bottom.php'; ?>
