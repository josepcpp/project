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

// Presence stamp — keeps the viewer on the Who's Online list while on the dashboard.
// Defensive (@) so it no-ops if the v1.7.7 columns aren't migrated yet.
if ($_dash_seen = @$conn->prepare("UPDATE users SET last_seen_at = NOW(), last_seen_page = ? WHERE id = ?")) {
    $_dash_page = substr($_SERVER['PHP_SELF'] ?? '', 0, 200);
    @$_dash_seen->bind_param("si", $_dash_page, $user_id);
    @$_dash_seen->execute();
}

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

$_dash_uname = htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$_dash_hour  = (int)date('G');
$_dash_greet = $_dash_hour < 12 ? 'Good morning' : ($_dash_hour < 18 ? 'Good afternoon' : 'Good evening');

// ── RECEIVER DASHBOARD ────────────────────────────────────────────────────────
if ($role === ROLE_RECEIVER):
    // Vouchers the Admin created that no Receiver has claimed yet
    $rv_vouchers_q = $conn->query("SELECT COUNT(*) AS c FROM receiving_batches WHERE status = 'pending_request' AND receiver_id IS NULL");
    $rv_vouchers   = intval($rv_vouchers_q->fetch_assoc()['c'] ?? 0);

    // My in-progress batch (claimed but not submitted)
    $rv_active_q = $conn->prepare("SELECT rb.id, rb.supplier_name, rb.created_at, COUNT(ri.id) AS item_count FROM receiving_batches rb LEFT JOIN receiving_items ri ON ri.batch_id = rb.id WHERE rb.receiver_id = ? AND rb.status = 'pending_request' GROUP BY rb.id LIMIT 1");
    $rv_active_q->bind_param("i", $user_id); $rv_active_q->execute();
    $rv_active = $rv_active_q->get_result()->fetch_assoc();

    // My recent batch history
    $rv_hist_q = $conn->prepare("SELECT rb.id, rb.supplier_name, rb.status, rb.created_at, COUNT(ri.id) AS item_count FROM receiving_batches rb LEFT JOIN receiving_items ri ON ri.batch_id = rb.id WHERE rb.receiver_id = ? GROUP BY rb.id ORDER BY rb.created_at DESC LIMIT 10");
    $rv_hist_q->bind_param("i", $user_id); $rv_hist_q->execute();
    $rv_history = $rv_hist_q->get_result()->fetch_all(MYSQLI_ASSOC);

    // This month's stats
    $rv_mon_q = $conn->prepare("SELECT COUNT(*) AS batches FROM receiving_batches WHERE receiver_id = ? AND YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())");
    $rv_mon_q->bind_param("i", $user_id); $rv_mon_q->execute();
    $rv_mon = $rv_mon_q->get_result()->fetch_assoc();

    $rv_items_q = $conn->prepare("SELECT COALESCE(SUM(ic.cnt),0) AS items FROM receiving_batches rb LEFT JOIN (SELECT batch_id, COUNT(*) AS cnt FROM receiving_items GROUP BY batch_id) ic ON ic.batch_id = rb.id WHERE rb.receiver_id = ? AND YEAR(rb.created_at) = YEAR(NOW()) AND MONTH(rb.created_at) = MONTH(NOW())");
    $rv_items_q->bind_param("i", $user_id); $rv_items_q->execute();
    $rv_items = intval($rv_items_q->get_result()->fetch_assoc()['items'] ?? 0);

    $rv_notifs_q = $conn->prepare("SELECT message, created_at FROM notifications WHERE recipient_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $rv_notifs_q->bind_param("i", $user_id); $rv_notifs_q->execute();
    $rv_notifs = $rv_notifs_q->get_result()->fetch_all(MYSQLI_ASSOC);

    $rv_status_map = ['pending_request'=>['Encoding','bg-sky-100 text-sky-700'],'pending_validation'=>['In Review','bg-amber-100 text-amber-700'],'validated_tally'=>['Validated','bg-emerald-100 text-emerald-700'],'on_hold'=>['On Hold','bg-rose-100 text-rose-700'],'completed'=>['Completed','bg-slate-100 text-slate-500'],'rejected'=>['Rejected','bg-rose-200 text-rose-800']];
?>
<div class="max-w-4xl mx-auto space-y-6 pb-20 animate-in">

    <!-- Greeting -->
    <div class="bg-slate-900 rounded-[3rem] p-8 flex flex-wrap items-center justify-between gap-4 text-white shadow-2xl">
        <div>
            <p class="text-[10px] font-black text-sky-400 uppercase tracking-[0.2em] mb-1">Receiver Workspace</p>
            <h2 class="serif-title text-3xl font-bold"><?= $_dash_greet ?>, <?= $_dash_uname ?>.</h2>
            <p class="text-slate-400 text-sm font-bold mt-1"><?= date('l, F j, Y') ?></p>
        </div>
        <div class="flex gap-3">
            <div class="text-center bg-white/5 border border-white/10 rounded-2xl px-5 py-3">
                <p class="text-2xl font-black text-white"><?= intval($rv_mon['batches'] ?? 0) ?></p>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Batches This Month</p>
            </div>
            <div class="text-center bg-white/5 border border-white/10 rounded-2xl px-5 py-3">
                <p class="text-2xl font-black text-white"><?= $rv_items ?></p>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Items Encoded</p>
            </div>
        </div>
    </div>

    <!-- Active Batch + Available Vouchers -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div class="bg-white rounded-[2.5rem] border <?= $rv_active ? 'border-sky-200 bg-sky-50/30' : 'border-slate-100' ?> shadow-xl p-6">
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">My Active Batch</p>
            <?php if ($rv_active): ?>
                <p class="font-black text-slate-800 text-lg leading-tight"><?= htmlspecialchars($rv_active['supplier_name'] ?? '—') ?></p>
                <p class="text-[10px] text-slate-400 font-bold mt-1">Batch #<?= $rv_active['id'] ?> · <?= intval($rv_active['item_count']) ?> item<?= $rv_active['item_count'] != 1 ? 's' : '' ?> encoded</p>
                <a href="procurement/receive_items.php?batch_id=<?= $rv_active['id'] ?>" class="mt-4 inline-block bg-sky-600 hover:bg-sky-500 text-white font-black text-[10px] uppercase tracking-widest px-5 py-2.5 rounded-xl transition-all shadow-md">
                    Continue Encoding →
                </a>
            <?php else: ?>
                <p class="text-slate-400 font-bold text-sm">No batch in progress.</p>
                <a href="procurement/receive_batch.php" class="mt-3 inline-block text-sky-600 font-black text-[10px] uppercase tracking-widest hover:underline">View Available Vouchers →</a>
            <?php endif; ?>
        </div>
        <div class="bg-white rounded-[2.5rem] border <?= $rv_vouchers > 0 ? 'border-emerald-200' : 'border-slate-100' ?> shadow-xl p-6">
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">Available Vouchers</p>
            <p class="text-4xl font-black <?= $rv_vouchers > 0 ? 'text-emerald-600' : 'text-slate-300' ?>"><?= $rv_vouchers ?></p>
            <p class="text-[10px] text-slate-400 font-bold mt-1">Admin-created, unclaimed</p>
            <?php if ($rv_vouchers > 0): ?>
            <a href="procurement/receive_batch.php" class="mt-3 inline-block bg-emerald-600 hover:bg-emerald-500 text-white font-black text-[10px] uppercase tracking-widest px-5 py-2.5 rounded-xl transition-all shadow-md">
                Claim Voucher →
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notifications -->
    <?php if (!empty($rv_notifs)): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-[2.5rem] overflow-hidden">
        <div class="px-6 py-4 border-b border-amber-100 flex items-center gap-2">
            <span class="w-2 h-2 bg-amber-500 rounded-full"></span>
            <p class="font-black text-amber-800 text-sm uppercase tracking-widest"><?= count($rv_notifs) ?> Unread Notification<?= count($rv_notifs) != 1 ? 's' : '' ?></p>
        </div>
        <div class="divide-y divide-amber-100">
        <?php foreach ($rv_notifs as $n): ?>
            <div class="px-6 py-3 text-sm text-amber-800 font-bold flex items-start gap-3">
                <span class="text-amber-400 mt-0.5 flex-shrink-0">•</span>
                <div class="flex-1 min-w-0">
                    <p><?= htmlspecialchars($n['message']) ?></p>
                    <p class="text-[9px] text-amber-500 font-bold mt-0.5"><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Batch History -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-50 flex items-center justify-between">
            <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">My Batch History</h4>
            <a href="procurement/receive_batch.php" class="text-[9px] font-black text-sky-600 bg-sky-50 px-3 py-1.5 rounded-xl border border-sky-100 hover:bg-sky-100 transition-all uppercase tracking-widest">All Batches →</a>
        </div>
        <div class="divide-y divide-slate-50">
        <?php if (empty($rv_history)): ?>
            <p class="px-6 py-12 text-center text-slate-300 font-black italic text-sm">No batches submitted yet.</p>
        <?php else: foreach ($rv_history as $b):
            [$slabel, $scls] = $rv_status_map[$b['status']] ?? ['Unknown', 'bg-slate-100 text-slate-400'];
        ?>
            <div class="px-6 py-4 flex items-center gap-4 hover:bg-slate-50/50 transition-colors">
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-slate-800 text-sm truncate"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></p>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Batch #<?= $b['id'] ?> · <?= intval($b['item_count']) ?> items · <?= date('M j, Y', strtotime($b['created_at'])) ?></p>
                </div>
                <span class="text-[9px] font-black px-2.5 py-1 rounded-full <?= $scls ?> uppercase tracking-widest whitespace-nowrap"><?= $slabel ?></span>
                <?php if ($b['status'] === 'pending_request'): ?>
                <a href="procurement/receive_items.php?batch_id=<?= $b['id'] ?>" class="text-[9px] font-black text-sky-600 hover:underline uppercase tracking-widest">Open →</a>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php
// ── VALIDATOR DASHBOARD ───────────────────────────────────────────────────────
elseif ($role === ROLE_VALIDATOR):
    require_once '../includes/batch_lock.php';   // provides BATCH_LOCK_TTL_MIN
    // Pending validation queue
    $vl_pending_q = $conn->query("SELECT rb.id, rb.supplier_name, rb.created_at, COUNT(ri.id) AS item_count, (rb.working_by IS NOT NULL AND rb.working_at >= (NOW() - INTERVAL " . BATCH_LOCK_TTL_MIN . " MINUTE)) AS working_active, rb.working_username FROM receiving_batches rb LEFT JOIN receiving_items ri ON ri.batch_id = rb.id WHERE rb.status = 'pending_validation' GROUP BY rb.id ORDER BY rb.created_at ASC");
    $vl_pending = $vl_pending_q ? $vl_pending_q->fetch_all(MYSQLI_ASSOC) : [];

    // Pending reprice queue
    $vl_reprice_q = $conn->query("SELECT rb.id, rb.supplier_name, rb.created_at, COUNT(ri.id) AS item_count FROM receiving_batches rb LEFT JOIN receiving_items ri ON ri.batch_id = rb.id WHERE rb.status = 'pending_reprice' GROUP BY rb.id ORDER BY rb.created_at ASC");
    $vl_reprice = $vl_reprice_q ? $vl_reprice_q->fetch_all(MYSQLI_ASSOC) : [];

    // My validation history
    $vl_hist_q = $conn->prepare("SELECT id, supplier_name, tally_result, validated_at, computed_subtotal FROM receiving_batches WHERE validator_id = ? ORDER BY validated_at DESC LIMIT 10");
    $vl_hist_q->bind_param("i", $user_id); $vl_hist_q->execute();
    $vl_history = $vl_hist_q->get_result()->fetch_all(MYSQLI_ASSOC);

    // Match rate this month
    $vl_match_q = $conn->prepare("SELECT COUNT(*) AS total, SUM(tally_result = 'match') AS matched FROM receiving_batches WHERE validator_id = ? AND YEAR(validated_at) = YEAR(NOW()) AND MONTH(validated_at) = MONTH(NOW())");
    $vl_match_q->bind_param("i", $user_id); $vl_match_q->execute();
    $vl_match = $vl_match_q->get_result()->fetch_assoc();
    $vl_match_rate = ($vl_match['total'] > 0) ? round(($vl_match['matched'] / $vl_match['total']) * 100, 1) : null;

    $vl_notifs_q = $conn->prepare("SELECT message, created_at FROM notifications WHERE recipient_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $vl_notifs_q->bind_param("i", $user_id); $vl_notifs_q->execute();
    $vl_notifs = $vl_notifs_q->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<div class="max-w-4xl mx-auto space-y-6 pb-20 animate-in">

    <!-- Greeting -->
    <div class="bg-slate-900 rounded-[3rem] p-8 flex flex-wrap items-center justify-between gap-4 text-white shadow-2xl">
        <div>
            <p class="text-[10px] font-black text-amber-400 uppercase tracking-[0.2em] mb-1">Validator Workspace</p>
            <h2 class="serif-title text-3xl font-bold"><?= $_dash_greet ?>, <?= $_dash_uname ?>.</h2>
            <p class="text-slate-400 text-sm font-bold mt-1"><?= date('l, F j, Y') ?></p>
        </div>
        <div class="flex gap-3">
            <div class="text-center bg-white/5 border border-white/10 rounded-2xl px-5 py-3">
                <p class="text-2xl font-black text-white"><?= intval($vl_match['total'] ?? 0) ?></p>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Validated This Month</p>
            </div>
            <?php if ($vl_match_rate !== null): ?>
            <div class="text-center bg-white/5 border border-white/10 rounded-2xl px-5 py-3">
                <p class="text-2xl font-black <?= $vl_match_rate >= 80 ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $vl_match_rate ?>%</p>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Match Rate</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Notifications -->
    <?php if (!empty($vl_notifs)): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-[2.5rem] overflow-hidden">
        <div class="px-6 py-4 border-b border-amber-100 flex items-center gap-2">
            <span class="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></span>
            <p class="font-black text-amber-800 text-sm uppercase tracking-widest"><?= count($vl_notifs) ?> Unread Notification<?= count($vl_notifs) != 1 ? 's' : '' ?></p>
        </div>
        <div class="divide-y divide-amber-100">
        <?php foreach ($vl_notifs as $n): ?>
            <div class="px-6 py-3 text-sm text-amber-800 font-bold flex items-start gap-3">
                <span class="text-amber-400 mt-0.5 flex-shrink-0">•</span>
                <div class="flex-1"><p><?= htmlspecialchars($n['message']) ?></p><p class="text-[9px] text-amber-500 font-bold mt-0.5"><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></p></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pending Validation + Reprice -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <!-- Pending Validation -->
        <div class="bg-white rounded-[2.5rem] border <?= !empty($vl_pending) ? 'border-amber-200' : 'border-slate-100' ?> shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-50 flex items-center justify-between">
                <p class="font-black text-slate-800 text-sm uppercase tracking-widest">Awaiting Validation</p>
                <?php if (!empty($vl_pending)): ?><span class="bg-amber-500 text-white text-[9px] font-black px-2.5 py-1 rounded-full"><?= count($vl_pending) ?></span><?php endif; ?>
            </div>
            <div class="divide-y divide-slate-50">
            <?php if (empty($vl_pending)): ?>
                <p class="px-6 py-8 text-center text-slate-300 font-black italic text-sm">No batches pending.</p>
            <?php else: foreach ($vl_pending as $b): ?>
                <div class="px-6 py-4">
                    <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></p>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Batch #<?= $b['id'] ?> · <?= intval($b['item_count']) ?> items · <?= date('M j', strtotime($b['created_at'])) ?></p>
                    <?php if ($b['working_active']): ?>
                    <span class="mt-1.5 inline-block text-[9px] font-black text-amber-600 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-full">⏳ @<?= htmlspecialchars($b['working_username']) ?> working</span>
                    <?php else: ?>
                    <a href="procurement/validate_items.php?batch_id=<?= $b['id'] ?>" class="mt-1.5 inline-block bg-amber-500 hover:bg-amber-400 text-white font-black text-[9px] uppercase tracking-widest px-4 py-1.5 rounded-xl transition-all">Validate →</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Pending Reprice -->
        <div class="bg-white rounded-[2.5rem] border <?= !empty($vl_reprice) ? 'border-rose-200' : 'border-slate-100' ?> shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-50 flex items-center justify-between">
                <p class="font-black text-slate-800 text-sm uppercase tracking-widest">Pending Reprice</p>
                <?php if (!empty($vl_reprice)): ?><span class="bg-rose-500 text-white text-[9px] font-black px-2.5 py-1 rounded-full"><?= count($vl_reprice) ?></span><?php endif; ?>
            </div>
            <div class="divide-y divide-slate-50">
            <?php if (empty($vl_reprice)): ?>
                <p class="px-6 py-8 text-center text-slate-300 font-black italic text-sm">No reprices pending.</p>
            <?php else: foreach ($vl_reprice as $b): ?>
                <div class="px-6 py-4">
                    <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></p>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Batch #<?= $b['id'] ?> · <?= intval($b['item_count']) ?> items</p>
                    <a href="procurement/validate_items.php?batch_id=<?= $b['id'] ?>" class="mt-1.5 inline-block bg-rose-500 hover:bg-rose-400 text-white font-black text-[9px] uppercase tracking-widest px-4 py-1.5 rounded-xl transition-all">Reprice →</a>
                </div>
            <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- My Validation History -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-50">
            <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">My Validation History</h4>
        </div>
        <div class="divide-y divide-slate-50">
        <?php if (empty($vl_history)): ?>
            <p class="px-6 py-12 text-center text-slate-300 font-black italic text-sm">No validations yet.</p>
        <?php else: foreach ($vl_history as $b): ?>
            <div class="px-6 py-4 flex items-center gap-4 hover:bg-slate-50/50 transition-colors">
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-slate-800 text-sm truncate"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></p>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Batch #<?= $b['id'] ?> · <?= $b['validated_at'] ? date('M j, Y', strtotime($b['validated_at'])) : '—' ?></p>
                </div>
                <div class="text-right shrink-0">
                    <span class="text-[9px] font-black px-2.5 py-1 rounded-full uppercase <?= $b['tally_result'] === 'match' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                        <?= $b['tally_result'] === 'match' ? '✓ Match' : '✗ Discrepancy' ?>
                    </span>
                    <?php if ($b['computed_subtotal']): ?><p class="text-[9px] text-slate-400 font-bold mt-0.5">₱<?= number_format($b['computed_subtotal'], 2) ?></p><?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php
// ── PRICE CHECKER DASHBOARD ───────────────────────────────────────────────────
elseif ($role === ROLE_PRICE_CHECKER):
    // Pipeline counts
    $pc_pipeline_q = $conn->query("SELECT status, COUNT(*) AS c FROM receiving_batches GROUP BY status");
    $pc_pipeline = [];
    if ($pc_pipeline_q) while ($r = $pc_pipeline_q->fetch_assoc()) $pc_pipeline[$r['status']] = intval($r['c']);

    // On-hold batches
    $pc_onhold_q = $conn->query("SELECT rb.id, rb.supplier_name, rb.computed_subtotal, rb.control_subtotal, rb.validated_at FROM receiving_batches rb WHERE rb.status = 'on_hold' ORDER BY rb.validated_at DESC LIMIT 10");
    $pc_onhold = $pc_onhold_q ? $pc_onhold_q->fetch_all(MYSQLI_ASSOC) : [];

    // Recent price history
    $pc_prices_q = $conn->query("SELECT p.name, ph.old_price, ph.new_price, ph.change_date FROM price_history ph JOIN products p ON p.id = ph.product_id ORDER BY ph.id DESC LIMIT 10");
    $pc_prices = $pc_prices_q ? $pc_prices_q->fetch_all(MYSQLI_ASSOC) : [];

    // Audit feed
    $pc_audit_q = $conn->query("SELECT pal.action, pal.tally_result, pal.created_at, pal.actor_username, pal.actor_role, rb.supplier_name, pal.batch_id FROM procurement_audit_log pal LEFT JOIN receiving_batches rb ON rb.id = pal.batch_id ORDER BY pal.created_at DESC LIMIT 15");
    $pc_audit = $pc_audit_q ? $pc_audit_q->fetch_all(MYSQLI_ASSOC) : [];
?>
<div class="max-w-5xl mx-auto space-y-6 pb-20 animate-in">

    <!-- Greeting -->
    <div class="bg-slate-900 rounded-[3rem] p-8 flex flex-wrap items-center justify-between gap-4 text-white shadow-2xl">
        <div>
            <p class="text-[10px] font-black text-purple-400 uppercase tracking-[0.2em] mb-1">Price Checker Workspace</p>
            <h2 class="serif-title text-3xl font-bold"><?= $_dash_greet ?>, <?= $_dash_uname ?>.</h2>
            <p class="text-slate-400 text-sm font-bold mt-1"><?= date('l, F j, Y') ?></p>
        </div>
        <a href="procurement/price_checker.php" class="bg-white/10 hover:bg-white/20 text-white font-black text-[10px] uppercase tracking-widest px-5 py-3 rounded-2xl transition-all">
            Open Price Checker →
        </a>
    </div>

    <!-- Pipeline Funnel -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl p-6">
        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-4">Procurement Pipeline</p>
        <div class="grid grid-cols-3 md:grid-cols-6 gap-3">
            <?php
            $funnel = [
                ['pending_request',    'Encoding',    'bg-sky-100 text-sky-700'],
                ['pending_validation', 'Validating',  'bg-amber-100 text-amber-700'],
                ['on_hold',            'On Hold',     'bg-rose-100 text-rose-600'],
                ['validated_tally',    'Validated',   'bg-emerald-100 text-emerald-700'],
                ['pending_reprice',    'Reprice',     'bg-purple-100 text-purple-700'],
                ['completed',          'Completed',   'bg-slate-100 text-slate-500'],
            ];
            foreach ($funnel as [$key, $label, $cls]):
                $cnt = $pc_pipeline[$key] ?? 0;
            ?>
            <div class="text-center p-3 rounded-2xl <?= $cls ?>">
                <p class="text-2xl font-black"><?= $cnt ?></p>
                <p class="text-[8px] font-black uppercase tracking-widest mt-0.5"><?= $label ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <!-- On-Hold Batches -->
        <div class="bg-white rounded-[2.5rem] border <?= !empty($pc_onhold) ? 'border-rose-200' : 'border-slate-100' ?> shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-50 flex items-center justify-between">
                <p class="font-black text-slate-800 text-sm uppercase tracking-widest">On-Hold Batches</p>
                <?php if (!empty($pc_onhold)): ?><span class="bg-rose-500 text-white text-[9px] font-black px-2.5 py-1 rounded-full"><?= count($pc_onhold) ?></span><?php endif; ?>
            </div>
            <div class="divide-y divide-slate-50">
            <?php if (empty($pc_onhold)): ?>
                <p class="px-6 py-8 text-center text-slate-300 font-black italic text-sm">No batches on hold.</p>
            <?php else: foreach ($pc_onhold as $b):
                $delta = floatval($b['computed_subtotal']) - floatval($b['control_subtotal']);
            ?>
                <div class="px-6 py-4">
                    <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></p>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Batch #<?= $b['id'] ?> · <?= $b['validated_at'] ? date('M j, Y', strtotime($b['validated_at'])) : '—' ?></p>
                    <p class="text-[10px] font-black mt-1 <?= $delta >= 0 ? 'text-rose-600' : 'text-emerald-600' ?>">Δ <?= $delta >= 0 ? '+' : '' ?>₱<?= number_format($delta, 2) ?></p>
                </div>
            <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Recent Price Changes -->
        <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-50">
                <p class="font-black text-slate-800 text-sm uppercase tracking-widest">Recent Price Changes</p>
            </div>
            <div class="divide-y divide-slate-50">
            <?php if (empty($pc_prices)): ?>
                <p class="px-6 py-8 text-center text-slate-300 font-black italic text-sm">No price changes recorded.</p>
            <?php else: foreach ($pc_prices as $p):
                $diff = floatval($p['new_price']) - floatval($p['old_price']);
            ?>
                <div class="px-6 py-3.5 flex items-center gap-3 hover:bg-slate-50/50 transition-colors">
                    <div class="flex-1 min-w-0">
                        <p class="font-bold text-slate-800 text-sm truncate"><?= htmlspecialchars($p['name']) ?></p>
                        <p class="text-[9px] text-slate-400 font-bold mt-0.5"><?= !empty($p['change_date']) ? date('M j, Y', strtotime($p['change_date'])) : '—' ?></p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-[10px] font-black text-slate-500"><span class="line-through">₱<?= number_format($p['old_price'], 2) ?></span> → <span class="<?= $diff >= 0 ? 'text-rose-600' : 'text-emerald-600' ?>">₱<?= number_format($p['new_price'], 2) ?></span></p>
                        <p class="text-[9px] font-black <?= $diff >= 0 ? 'text-rose-500' : 'text-emerald-500' ?>"><?= $diff >= 0 ? '+' : '' ?>₱<?= number_format($diff, 2) ?></p>
                    </div>
                </div>
            <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- Audit Feed -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-50 flex items-center justify-between">
            <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">Procurement Audit Feed</h4>
            <a href="procurement/price_checker.php" class="text-[9px] font-black text-purple-600 bg-purple-50 px-3 py-1.5 rounded-xl border border-purple-100 hover:bg-purple-100 transition-all uppercase tracking-widest">Full Report →</a>
        </div>
        <div class="divide-y divide-slate-50">
        <?php if (empty($pc_audit)): ?>
            <p class="px-6 py-12 text-center text-slate-300 font-black italic text-sm">No audit events yet.</p>
        <?php else: foreach ($pc_audit as $a):
            $a_action_map = ['voucher_created'=>'Voucher Created','items_encoded'=>'Items Encoded','validated_tally'=>'Validated ✓','validated_discrepancy'=>'Discrepancy ✗','inventory_pushed'=>'Pushed to Inventory','supplier_paid'=>'Supplier Paid'];
            $a_label = $a_action_map[$a['action']] ?? ucwords(str_replace('_',' ',$a['action']));
            $a_cls   = in_array($a['action'],['validated_tally','inventory_pushed','supplier_paid']) ? 'text-emerald-600' : (in_array($a['action'],['validated_discrepancy']) ? 'text-rose-600' : 'text-slate-500');
        ?>
            <div class="px-6 py-3.5 flex items-center gap-4 hover:bg-slate-50/50 transition-colors">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[9px] font-black <?= $a_cls ?> uppercase tracking-widest"><?= $a_label ?></span>
                        <span class="text-[9px] text-slate-400 font-bold">Batch #<?= $a['batch_id'] ?></span>
                        <?php if ($a['supplier_name']): ?><span class="text-[9px] text-slate-400">· <?= htmlspecialchars($a['supplier_name']) ?></span><?php endif; ?>
                    </div>
                    <p class="text-[9px] text-slate-300 font-bold mt-0.5">by @<?= htmlspecialchars($a['actor_username'] ?? '—') ?> (<?= htmlspecialchars($a['actor_role'] ?? '—') ?>)</p>
                </div>
                <p class="text-[9px] text-slate-300 font-bold whitespace-nowrap flex-shrink-0"><?= date('M j, g:i A', strtotime($a['created_at'])) ?></p>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php
// ── STAFF DASHBOARD ───────────────────────────────────────────────────────────
elseif ($role === ROLE_STAFF):
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
        <a href="inventory/stock_management.php?stock=low" class="bg-white rounded-[2rem] border <?= $low_count > 0 ? 'border-red-200 bg-red-50' : 'border-slate-100' ?> shadow-md p-7 hover:shadow-lg transition-all">
            <div class="w-10 h-10 <?= $low_count > 0 ? 'bg-red-500 animate-pulse' : 'bg-slate-100' ?> rounded-xl flex items-center justify-center mb-4">
                <svg class="w-5 h-5 <?= $low_count > 0 ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-width="2"/></svg>
            </div>
            <p class="<?= $low_count > 0 ? 'text-red-500' : 'text-slate-400' ?> text-[10px] font-black uppercase tracking-widest mb-1">Low Stock Alerts</p>
            <p class="text-2xl font-black <?= $low_count > 0 ? 'text-red-700' : 'text-slate-800' ?>"><?= $low_count ?> <span class="text-sm font-bold opacity-30 italic">items</span></p>
        </a>
        <a href="sales/returns_exchange.php?refunds=1" class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-7 hover:shadow-lg transition-all group">
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

    <!-- ═══════════════════ SALES GRAPH + WHO'S ONLINE ═══════════════════ -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">

        <!-- Sales Graph (spans 2 cols) -->
        <div class="xl:col-span-2 bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
            <div class="p-6 border-b border-slate-50 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">Sales Performance</h4>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Current period vs. previous period</p>
                </div>
                <div class="flex gap-1 bg-slate-100 p-1 rounded-xl" id="sales-graph-tabs">
                    <button type="button" data-period="day"   class="sg-tab px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all bg-white text-slate-800 shadow-sm">Day</button>
                    <button type="button" data-period="week"  class="sg-tab px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all text-slate-400 hover:text-slate-600">Week</button>
                    <button type="button" data-period="month" class="sg-tab px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all text-slate-400 hover:text-slate-600">Month</button>
                    <button type="button" data-period="year"  class="sg-tab px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all text-slate-400 hover:text-slate-600">Year</button>
                </div>
            </div>
            <div class="p-6">
                <div class="flex items-center gap-5 mb-4 flex-wrap">
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-emerald-500"></span><span id="sg-curr-label" class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Today</span><span id="sg-curr-total" class="text-[10px] font-black text-emerald-600">₱0.00</span></div>
                    <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-slate-300"></span><span id="sg-prev-label" class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Yesterday</span><span id="sg-prev-total" class="text-[10px] font-black text-slate-400">₱0.00</span></div>
                </div>
                <div class="relative" style="height:280px;">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Who's Online -->
        <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden flex flex-col">
            <div class="p-6 border-b border-slate-50 flex items-center justify-between">
                <div>
                    <h4 class="font-black text-slate-800 text-sm uppercase tracking-widest">Who's Online</h4>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Active in the last 15 minutes</p>
                </div>
                <span class="flex items-center gap-1.5 text-[9px] font-black text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-full uppercase tracking-widest">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                    <span id="online-count">0</span> live
                </span>
            </div>
            <div id="online-list" class="divide-y divide-slate-50 flex-1 overflow-y-auto" style="max-height:320px;">
                <p class="px-6 py-12 text-center text-slate-300 font-black italic text-sm">Loading…</p>
            </div>
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
                    <a href="sales/returns_exchange.php?refunds=1" class="text-[9px] font-black text-rose-600 bg-rose-50 px-3 py-1.5 rounded-xl hover:bg-rose-100 transition-all uppercase tracking-widest border border-rose-100">Queue →</a>
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
                    <a href="sales/returns_exchange.php?refunds=1" onclick="event.stopPropagation()" class="text-[9px] font-black text-amber-500 hover:underline uppercase tracking-widest flex-shrink-0">View →</a>
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
            ['label' => 'Suppliers',     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',                                                                                                                                                                       'val' => $pip_suppliers,        'sub' => 'active suppliers',                                                                 'href' => 'inventory/stock_management.php',        'color' => 'text-slate-600',                                          'bg' => 'bg-slate-100',  'badge' => 0],
            ['label' => 'Low Stock Items','icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>',                                                                                                                                          'val' => $inv_low,              'sub' => $inv_low > 0 ? 'items need restocking' : 'all stock levels healthy',   'href' => 'inventory/stock_management.php?stock=low', 'color' => $inv_low > 0 ? 'text-red-600' : 'text-slate-600',          'bg' => $inv_low > 0 ? 'bg-red-100' : 'bg-slate-100', 'badge' => 0],
            ['label' => 'Archived Items', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>',                                                                                                                                                                                             'val' => $pip_archived,         'sub' => $pip_archived > 0 ? 'items out of stock / deactivated' : 'no archived items', 'href' => 'inventory/stock_management.php?stock=archived', 'color' => $pip_archived > 0 ? 'text-slate-600' : 'text-slate-400',  'bg' => $pip_archived > 0 ? 'bg-slate-200' : 'bg-slate-100', 'badge' => $pip_new_archived],
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
            <a href="inventory/stock_management.php?stock=low" class="block bg-white rounded-[2rem] border <?= $inv_low > 0 ? 'border-red-200 bg-red-50' : 'border-slate-100' ?> shadow-md p-6 hover:shadow-lg transition-all">
                <p class="text-[9px] font-black <?= $inv_low > 0 ? 'text-red-500' : 'text-slate-400' ?> uppercase tracking-widest mb-1">Low Stock Items</p>
                <p class="text-3xl font-black <?= $inv_low > 0 ? 'text-red-700' : 'text-slate-700' ?>"><?= $inv_low ?> <span class="text-sm font-bold opacity-30 italic">items</span></p>
            </a>
            <a href="inventory/stock_management.php?stock=zero" class="block bg-white rounded-[2rem] border border-slate-100 shadow-md p-6 hover:shadow-lg transition-all">
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
    if (!reason) { showFlash('Please provide a rejection reason.', 'error'); return; }
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
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            <!-- Sales Export -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100">
                <p class="font-black text-slate-700 text-xs uppercase tracking-widest mb-3">Sales Report</p>
                <div class="space-y-2 mb-3">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">From</label>
                        <input type="date" id="exp-sales-from" value="<?= date('Y-m-01') ?>" class="w-full text-xs border border-slate-200 rounded-xl px-3 py-2.5 font-bold text-slate-600 bg-white">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">To</label>
                        <input type="date" id="exp-sales-to" value="<?= date('Y-m-d') ?>" class="w-full text-xs border border-slate-200 rounded-xl px-3 py-2.5 font-bold text-slate-600 bg-white">
                    </div>
                </div>
                <button type="button" onclick="exportCSV('sales','exp-sales-from','exp-sales-to')" class="block w-full text-center bg-emerald-500 hover:bg-emerald-600 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-xl transition-all">
                    ↓ Export Sales CSV
                </button>
            </div>
            <!-- Stock Level Export (rich) -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100">
                <p class="font-black text-slate-700 text-xs uppercase tracking-widest mb-3">Stock Level</p>
                <p class="text-slate-400 text-[10px] font-bold mb-3">Live + held stock with expiry dates, cost &amp; retail value, markup %, and VAT status.</p>
                <button type="button" onclick="triggerDownload('inventory/export_inventory_csv.php?include_draft=1')" class="block w-full text-center bg-blue-500 hover:bg-blue-600 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-xl transition-all">
                    ↓ Export Stock CSV
                </button>
            </div>
            <!-- Master Prices Export -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100">
                <p class="font-black text-slate-700 text-xs uppercase tracking-widest mb-3">Master Prices</p>
                <p class="text-slate-400 text-[10px] font-bold mb-3">Current prices, cost, markup %, bulk tiers, VAT status, and last price change.</p>
                <button type="button" onclick="triggerDownload('inventory/export_prices.php')" class="block w-full text-center bg-purple-500 hover:bg-purple-600 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-xl transition-all">
                    ↓ Export Prices CSV
                </button>
            </div>
            <!-- Refunds Export -->
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100">
                <p class="font-black text-slate-700 text-xs uppercase tracking-widest mb-3">Refunds Report</p>
                <div class="space-y-2 mb-3">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">From</label>
                        <input type="date" id="exp-ref-from" value="<?= date('Y-m-01') ?>" class="w-full text-xs border border-slate-200 rounded-xl px-3 py-2.5 font-bold text-slate-600 bg-white">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">To</label>
                        <input type="date" id="exp-ref-to" value="<?= date('Y-m-d') ?>" class="w-full text-xs border border-slate-200 rounded-xl px-3 py-2.5 font-bold text-slate-600 bg-white">
                    </div>
                </div>
                <button type="button" onclick="exportCSV('refunds','exp-ref-from','exp-ref-to')" class="block w-full text-center bg-rose-500 hover:bg-rose-600 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-xl transition-all">
                    ↓ Export Refunds CSV
                </button>
            </div>
        </div>
    </div>
</div>
<script>
// Date-ranged exports (sales / refunds) still flow through reports/export.php,
// but download silently via triggerDownload so the SPA never swallows the click.
function exportCSV(type, fromId, toId) {
    var from = document.getElementById(fromId)?.value || '';
    var to   = document.getElementById(toId)?.value   || '';
    triggerDownload('/project/staff/reports/export.php?type=' + type + '&date_from=' + from + '&date_to=' + to);
}
</script>
<?php endif; ?>

<?php if (in_array($role, ROLES_ADMIN_AND_UP)):
    $is_superadmin_dash = ($role === ROLE_SUPERADMIN);
?>
<!-- ── SALES GRAPH + WHO'S ONLINE — scripts ──────────────────────────────────── -->
<script>
(function () {
    // ── Who's Online polling ──────────────────────────────────────────────────
    var IS_SUPERADMIN = <?= $is_superadmin_dash ? 'true' : 'false' ?>;

    var roleBadge = {
        receiver:      ['Receiver',      'bg-sky-100 text-sky-700'],
        validator:     ['Validator',     'bg-amber-100 text-amber-700'],
        price_checker: ['Price Checker', 'bg-purple-100 text-purple-700'],
        admin:         ['Admin',         'bg-slate-200 text-slate-700'],
        superadmin:    ['Superadmin',    'bg-slate-900 text-white'],
        owner:         ['Owner',         'bg-emerald-100 text-emerald-700'],
        staff:         ['Cashier',       'bg-emerald-100 text-emerald-700']
    };

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderOnline() {
        var listEl  = document.getElementById('online-list');
        var countEl = document.getElementById('online-count');
        if (!listEl) return false; // page changed — stop

        fetch('/project/staff/api/who_is_online.php', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!document.getElementById('online-list')) return;
                var users = (d && d.users) || [];
                if (countEl) countEl.textContent = users.length;
                if (!users.length) {
                    listEl.innerHTML = '<p class="px-6 py-12 text-center text-slate-300 font-black italic text-sm">No one else is online.</p>';
                    return;
                }
                var html = '';
                users.forEach(function (u) {
                    var rb    = roleBadge[u.role] || [u.role, 'bg-slate-100 text-slate-500'];
                    var dot   = u.status === 'active' ? 'bg-emerald-500' : 'bg-amber-400';
                    var ago   = u.mins_ago === 0 ? 'now' : (u.mins_ago + 'm ago');
                    var initial = esc((u.username || '?').charAt(0).toUpperCase());
                    html += '<div class="px-5 py-3.5 flex items-center gap-3 hover:bg-slate-50/50 transition-colors">'
                          +   '<div class="relative flex-shrink-0">'
                          +     '<div class="w-9 h-9 bg-slate-900 rounded-2xl flex items-center justify-center text-white font-black text-sm">' + initial + '</div>'
                          +     '<span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 ' + dot + ' rounded-full border-2 border-white"></span>'
                          +   '</div>'
                          +   '<div class="flex-1 min-w-0">'
                          +     '<div class="flex items-center gap-1.5 flex-wrap">'
                          +       '<p class="font-black text-slate-800 text-sm truncate">@' + esc(u.username) + '</p>'
                          +       '<span class="text-[8px] font-black px-1.5 py-0.5 rounded ' + rb[1] + ' uppercase tracking-widest">' + esc(rb[0]) + '</span>'
                          +     '</div>'
                          +     '<p class="text-[10px] text-slate-400 font-bold truncate">' + esc(u.page_label) + ' · ' + ago + '</p>'
                          +   '</div>'
                          + (IS_SUPERADMIN
                                ? '<a href="users/users.php" class="text-[9px] font-black text-rose-400 hover:text-rose-600 uppercase tracking-widest flex-shrink-0" title="Manage in Users">Manage</a>'
                                : '')
                          + '</div>';
                });
                listEl.innerHTML = html;
            })
            .catch(function () { /* network blip — keep last render */ });
        return true;
    }

    // Clear any prior interval so SPA re-navigation doesn't stack timers
    if (window._whoOnlineTimer) { clearInterval(window._whoOnlineTimer); window._whoOnlineTimer = null; }
    renderOnline();
    window._whoOnlineTimer = setInterval(function () {
        if (!renderOnline()) { clearInterval(window._whoOnlineTimer); window._whoOnlineTimer = null; }
    }, 30000);

    // ── Sales Graph (Chart.js) ────────────────────────────────────────────────
    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function initSalesChart() {
        var canvas = document.getElementById('salesChart');
        if (!canvas || typeof Chart === 'undefined') return;

        // Destroy a prior instance if re-navigated
        if (window._salesChart) { try { window._salesChart.destroy(); } catch (e) {} window._salesChart = null; }

        var ctx = canvas.getContext('2d');
        window._salesChart = new Chart(ctx, {
            type: 'bar',
            data: { labels: [], datasets: [
                { label: 'Current',  data: [], backgroundColor: 'rgba(16,185,129,0.85)', borderRadius: 6, categoryPercentage: 0.7, barPercentage: 0.9 },
                { label: 'Previous', data: [], backgroundColor: 'rgba(203,213,225,0.7)', borderRadius: 6, categoryPercentage: 0.7, barPercentage: 0.9 }
            ]},
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + peso(c.parsed.y); } } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function (v) { return '₱' + Number(v).toLocaleString('en-PH'); }, font: { weight: '700' } }, grid: { color: '#f1f5f9' } },
                    x: { ticks: { font: { weight: '700' }, maxRotation: 0, autoSkip: true, maxTicksLimit: 16 }, grid: { display: false } }
                }
            }
        });
        loadSalesData('day');
    }

    function loadSalesData(period) {
        fetch('/project/staff/api/sales_chart.php?period=' + encodeURIComponent(period))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!window._salesChart) return;
                window._salesChart.data.labels = d.labels;
                window._salesChart.data.datasets[0].data = d.current;
                window._salesChart.data.datasets[0].label = d.curr_label;
                window._salesChart.data.datasets[1].data = d.previous;
                window._salesChart.data.datasets[1].label = d.prev_label;
                window._salesChart.update();

                var sum = function (a) { return (a || []).reduce(function (x, y) { return x + Number(y || 0); }, 0); };
                var cl = document.getElementById('sg-curr-label'), ct = document.getElementById('sg-curr-total');
                var pl = document.getElementById('sg-prev-label'), pt = document.getElementById('sg-prev-total');
                if (cl) cl.textContent = d.curr_label;
                if (pl) pl.textContent = d.prev_label;
                if (ct) ct.textContent = peso(sum(d.current));
                if (pt) pt.textContent = peso(sum(d.previous));
            })
            .catch(function () {});
    }

    // Tab handlers
    var tabWrap = document.getElementById('sales-graph-tabs');
    if (tabWrap) {
        tabWrap.querySelectorAll('.sg-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                tabWrap.querySelectorAll('.sg-tab').forEach(function (b) {
                    b.classList.remove('bg-white', 'text-slate-800', 'shadow-sm');
                    b.classList.add('text-slate-400', 'hover:text-slate-600');
                });
                btn.classList.add('bg-white', 'text-slate-800', 'shadow-sm');
                btn.classList.remove('text-slate-400', 'hover:text-slate-600');
                loadSalesData(btn.dataset.period);
            });
        });
    }

    // Load Chart.js once, then init
    if (typeof Chart === 'undefined') {
        if (!window._chartJsLoading) {
            window._chartJsLoading = true;
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
            s.onload = function () { window._chartJsLoading = false; initSalesChart(); };
            document.head.appendChild(s);
        } else {
            var waitChart = setInterval(function () {
                if (typeof Chart !== 'undefined') { clearInterval(waitChart); initSalesChart(); }
            }, 150);
        }
    } else {
        initSalesChart();
    }
})();
</script>
<?php endif; ?>

<?php
// ── USER MANUAL — pop-up once per login for procurement staff ────────────────
if (!empty($_SESSION['manual_pending']) && in_array($role, ROLES_PROCUREMENT_STAFF)):
    unset($_SESSION['manual_pending']);   // consume: show only once this login
    $manual_role = $role;
?>
<div id="manual-login-modal" class="fixed inset-0 z-[300] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col animate-in">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
            <h4 class="serif-title text-lg font-black text-slate-800">Welcome — your quick guide</h4>
            <button type="button" onclick="closeManualModal()" class="text-slate-400 hover:text-slate-700 text-3xl font-black leading-none">&times;</button>
        </div>
        <div class="px-6 py-5 overflow-y-auto">
            <?php include 'includes/manual_content.php'; ?>
        </div>
        <div class="px-6 py-4 border-t border-slate-100 flex items-center justify-between gap-4 flex-shrink-0">
            <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" id="manual-login-toggle" checked onchange="saveManualPrefModal(this.checked)" class="w-4 h-4 accent-emerald-500">
                <span class="text-xs font-bold text-slate-500">Show this guide at every login</span>
            </label>
            <button type="button" onclick="closeManualModal()" class="bg-slate-900 hover:bg-emerald-600 text-white font-black text-[10px] uppercase tracking-widest px-6 py-3 rounded-xl transition-all">Got it</button>
        </div>
    </div>
</div>
<script>
function closeManualModal() {
    var m = document.getElementById('manual-login-modal');
    if (m) m.remove();
}
async function saveManualPrefModal(show) {
    try {
        var fd = new FormData();
        fd.append('show', show ? '1' : '0');
        await fetch('/project/staff/api/manual_pref.php', { method: 'POST', body: fd });
        if (typeof showFlash === 'function') {
            showFlash(show ? 'Guide will show at every login.' : 'Guide will no longer show at login.', 'success');
        }
    } catch (_) {}
}
</script>
<?php endif; ?>

<?php include 'layout_bottom.php'; ?>
