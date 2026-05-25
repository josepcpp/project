<?php
// Included by procurement pages when staff doesn't have approved access.
// Handles the access request POST and renders the appropriate gate UI.

// ── HANDLE ACCESS REQUEST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_procurement_access'])) {
    $uid   = intval($_SESSION['user_id']);
    $uname = $_SESSION['username'] ?? '';
    $stmt  = $conn->prepare("UPDATE users SET procurement_access = '" . PROC_PENDING . "' WHERE id = ? AND role = '" . ROLE_STAFF . "'");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $_SESSION['procurement_access'] = PROC_PENDING;

    $log = $conn->prepare("INSERT INTO procurement_access_log (staff_id, staff_username, action) VALUES (?, ?, 'requested')");
    $log->bind_param("is", $uid, $uname);
    $log->execute();
}

$pa_status = $_SESSION['procurement_access'] ?? PROC_NONE;

$denial_reason = '';
if ($pa_status === PROC_DENIED) {
    $dr_uid = intval($_SESSION['user_id'] ?? 0);
    $dr_q   = $conn->prepare("SELECT procurement_denial_reason FROM users WHERE id = ?");
    $dr_q->bind_param("i", $dr_uid); $dr_q->execute();
    $denial_reason = $dr_q->get_result()->fetch_assoc()['procurement_denial_reason'] ?? '';
}
?>

<div class="max-w-2xl mx-auto pt-10 pb-20 animate-in">
    <?php if ($pa_status === PROC_PENDING): ?>

        <!-- PENDING: Awaiting owner approval -->
        <div class="bg-amber-50 border-2 border-amber-200 rounded-[3rem] p-14 text-center shadow-inner">
            <div class="w-20 h-20 bg-amber-100 rounded-3xl flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <span class="inline-block bg-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-[0.2em] px-4 py-2 rounded-full mb-5">Request Pending</span>
            <h3 class="serif-title text-3xl font-bold text-slate-800 mb-3">Awaiting Owner Approval</h3>
            <p class="text-slate-400 text-sm leading-relaxed max-w-sm mx-auto">
                Your request to access the Procurement module has been sent to the owner.
                You will be able to use this section once it is approved.
            </p>
        </div>

    <?php else: ?>

        <!-- ACCESS LOCKED: none or denied -->
        <div class="bg-white border border-slate-100 rounded-[3rem] p-14 text-center shadow-xl">
            <div class="w-20 h-20 <?= $pa_status === PROC_DENIED ? 'bg-rose-50' : 'bg-slate-100' ?> rounded-3xl flex items-center justify-center mx-auto mb-6">
                <svg class="w-10 h-10 <?= $pa_status === PROC_DENIED ? 'text-rose-400' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>

            <?php if ($pa_status === PROC_DENIED): ?>
                <span class="inline-block bg-rose-100 text-rose-600 text-[9px] font-black uppercase tracking-[0.2em] px-4 py-2 rounded-full mb-5">Access Denied</span>
                <h3 class="serif-title text-3xl font-bold text-slate-800 mb-3">Access Not Granted</h3>
                <p class="text-slate-400 text-sm leading-relaxed max-w-sm mx-auto mb-10">
                    Your previous request was denied by the owner. You may submit a new request for reconsideration.
                </p>
                <?php if (!empty($denial_reason)): ?>
                <div class="bg-rose-50 border border-rose-200 rounded-2xl px-5 py-4 mb-6 text-left">
                    <p class="text-[9px] font-black uppercase tracking-widest text-rose-500 mb-1">Reason for Denial</p>
                    <p class="text-sm text-rose-800 leading-relaxed"><?= htmlspecialchars($denial_reason) ?></p>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <span class="inline-block bg-slate-100 text-slate-500 text-[9px] font-black uppercase tracking-[0.2em] px-4 py-2 rounded-full mb-5">Restricted Area</span>
                <h3 class="serif-title text-3xl font-bold text-slate-800 mb-3">Procurement Module</h3>
                <p class="text-slate-400 text-sm leading-relaxed max-w-sm mx-auto mb-10">
                    Access to Supply Vouchers, Product Master, Receiving Station, and Outgoing Payments
                    requires owner approval. Submit a request to get started.
                </p>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="request_procurement_access" value="1">
                <button type="submit" class="btn-pos-primary px-12 py-4 text-sm shadow-lg uppercase tracking-widest">
                    Send Access Request to Owner
                </button>
            </form>
        </div>

    <?php endif; ?>
</div>
