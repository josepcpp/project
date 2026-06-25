<?php
include '../includes/auth_check.php';
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id     = $_SESSION['user_id'] ?? null;
$viewer_role = strtolower($_SESSION['role'] ?? '');

// Access: the three procurement roles + admin/superadmin/owner (reference).
$allowed = array_merge(ROLES_PROCUREMENT_STAFF, ROLES_ADMIN_AND_UP);
if (!in_array($viewer_role, $allowed)) {
    header('Location: dashboard.php');
    exit();
}

$is_admin_viewer = in_array($viewer_role, ROLES_ADMIN_AND_UP);

// Which guide to show. Admins can switch; procurement staff see their own.
$role_options = [
    ROLE_RECEIVER      => 'Receiver',
    ROLE_VALIDATOR     => 'Validator',
    ROLE_PRICE_CHECKER => 'Price Checker',
];
if ($is_admin_viewer) {
    $selected_role = $_GET['role'] ?? ROLE_RECEIVER;
    if (!isset($role_options[$selected_role])) $selected_role = ROLE_RECEIVER;
} else {
    $selected_role = $viewer_role;
}

// Current "show on login" preference for this user
$pref = 1;
$pq = $conn->prepare("SELECT show_manual_on_login FROM users WHERE id = ? LIMIT 1");
if ($pq) { $pq->bind_param("i", $user_id); $pq->execute(); $pref = intval($pq->get_result()->fetch_assoc()['show_manual_on_login'] ?? 1); }

include 'layout_top.php';
?>

<div class="max-w-3xl mx-auto space-y-6 pb-20 animate-in">

    <!-- Header -->
    <div class="bg-slate-900 rounded-[2.5rem] p-8 text-white shadow-2xl flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-emerald-400 mb-1">User Manual</p>
            <h2 class="serif-title text-3xl font-bold">How your workflow works</h2>
            <p class="text-slate-400 text-sm font-bold mt-1">Step-by-step guide for your role.</p>
        </div>
        <svg class="w-12 h-12 text-emerald-400 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
    </div>

    <?php if ($is_admin_viewer): ?>
    <!-- Admin role switcher -->
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-2 flex gap-1">
        <?php foreach ($role_options as $rk => $rlabel):
            $active = $rk === $selected_role;
        ?>
        <a href="manual.php?role=<?= urlencode($rk) ?>"
           class="flex-1 text-center px-4 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all <?= $active ? 'bg-slate-900 text-white shadow' : 'text-slate-400 hover:text-slate-600' ?>">
            <?= htmlspecialchars($rlabel) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Guide content -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl p-8">
        <?php $manual_role = $selected_role; include 'includes/manual_content.php'; ?>
    </div>

    <?php if (!$is_admin_viewer): ?>
    <!-- Show-on-login toggle (procurement roles only) -->
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-md p-6 flex items-center justify-between gap-4">
        <div>
            <p class="font-black text-slate-800 text-sm">Show this guide at every login</p>
            <p class="text-slate-400 text-xs font-bold mt-0.5">Turn off once you know the steps. You can always reopen this tab.</p>
        </div>
        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
            <input type="checkbox" id="manual-toggle" class="sr-only peer" <?= $pref ? 'checked' : '' ?> onchange="saveManualPref(this.checked)">
            <div class="w-14 h-7 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[4px] after:start-[4px] after:bg-white after:rounded-full after:h-5 after:w-6 after:transition-all peer-checked:bg-emerald-500 shadow-inner"></div>
        </label>
    </div>
    <?php else: ?>
    <p class="text-center text-[11px] font-bold text-slate-400">You are viewing these guides for reference. The login pop-up only shows for Receiver, Validator, and Price Checker accounts.</p>
    <?php endif; ?>
</div>

<script>
async function saveManualPref(show) {
    try {
        const fd = new FormData();
        fd.append('show', show ? '1' : '0');
        const res  = await fetch('/project/staff/api/manual_pref.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showFlash(show ? 'The manual will show at every login.' : 'The manual will no longer show at login.', 'success');
        } else {
            showFlash(data.error || 'Could not save your preference.', 'error');
        }
    } catch (_) {
        showFlash('Connection error. Try again.', 'error');
    }
}
</script>

<?php include 'layout_bottom.php'; ?>
