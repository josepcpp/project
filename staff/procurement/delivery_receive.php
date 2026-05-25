<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── DISCARD BATCH ─────────────────────────────────────────────────────────────
// Clears all draft products for the active supplier and resets procurement session.
if (isset($_GET['clear_batch'])) {
    $stale_sid = intval($_SESSION['active_batch_id'] ?? 0);
    if ($stale_sid) {
        $del = $conn->prepare("DELETE FROM products WHERE supplier_id = ? AND status = '" . PRODUCT_DRAFT . "'");
        $del->bind_param("i", $stale_sid);
        $del->execute();
    }
    unset(
        $_SESSION['active_batch_id'],
        $_SESSION['active_batch_name'],
        $_SESSION['active_invoice'],
        $_SESSION['verification_in_progress'],
        $_SESSION['proc_batch_id'],
        $_SESSION['receiving_stage_logged']
    );
    header("Location: ../inventory/product_info.php");
    exit();
}

// ── ALERT ACTIONS (admin recount management) ──────────────────────────────────
// Processed before layout_top.php outputs HTML to allow clean redirects.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alert_action'])) {
    $alert_id     = intval($_POST['alert_id']);
    $alert_action = $_POST['alert_action'];
    $uid          = intval($_SESSION['user_id'] ?? 0);

    if ($alert_action === 'approve_request') {
        // Transition: pending → recounting. Snapshot the expected quantity at approval time
        // so the staff's physical count is compared against a locked baseline.
        $aq = $conn->prepare("SELECT * FROM quantity_alerts WHERE id = ? LIMIT 1");
        $aq->bind_param("i", $alert_id);
        $aq->execute();
        $alert_row = $aq->get_result()->fetch_assoc();

        $exp_qty = 0;
        if ($alert_row) {
            if (!empty($alert_row['product_id']) && !empty($alert_row['batch_qty'])) {
                // Delivery discrepancy: expected = PM-encoded qty (what stock was set to on officialize).
                $exp_qty = intval($alert_row['batch_qty']);
            } else {
                // Staff-initiated recount: snapshot live stock total at approval time.
                $pq = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS total FROM products WHERE barcode = ? AND status = '" . PRODUCT_ACTIVE . "'");
                $pq->bind_param("s", $alert_row['barcode']);
                $pq->execute();
                $exp_qty = intval($pq->get_result()->fetch_assoc()['total'] ?? 0);
            }
        }

        $stmt = $conn->prepare("UPDATE quantity_alerts SET status = '" . ALERT_RECOUNTING . "', expected_qty = ?, approved_by = ? WHERE id = ? AND status = '" . ALERT_PENDING . "'");
        $stmt->bind_param("iii", $exp_qty, $uid, $alert_id);
        $stmt->execute();

    } elseif ($alert_action === 'reject_request') {
        $reason = trim($_POST['reject_reason'] ?? 'Rejected by admin');
        $stmt   = $conn->prepare("UPDATE quantity_alerts SET status = '" . ALERT_REJECTED . "', reject_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ? AND status = '" . ALERT_PENDING . "'");
        $stmt->bind_param("sii", $reason, $uid, $alert_id);
        $stmt->execute();

    } elseif ($alert_action === 'resolve') {
        // Admin manually dismisses a delivery discrepancy (no inventory change).
        $stmt = $conn->prepare("UPDATE quantity_alerts SET status = '" . ALERT_RESOLVED . "' WHERE id = ?");
        $stmt->bind_param("i", $alert_id);
        $stmt->execute();
    }

    header("Location: delivery_receive.php");
    exit();
}

include '../layout_top.php';
// $role is set by layout_top.php (strtolower, defaults to ROLE_STAFF)
if (!isset($role)) {
    $role = ROLE_STAFF;
}

// ── ACCESS CONTROL ────────────────────────────────────────────────────────────
$staff_recount_mode = false;
if ($role === ROLE_STAFF) {
    // Trust the session value set by layout_top.php (always DB-synced there).
    $procurement_access = $_SESSION['procurement_access'] ?? PROC_NONE;
    if ($procurement_access === PROC_RECOUNT) {
        $staff_recount_mode = true;
    } elseif ($procurement_access !== PROC_APPROVED) {
        include '../procurement/procurement_gate.php';
        include '../layout_bottom.php';
        exit();
    }
}

// ── LOAD QUANTITY ALERTS (admin view) ─────────────────────────────────────────
// Split into three buckets so each panel can be rendered independently.
$alerts_pending     = []; // staff requests awaiting admin approval
$alerts_submitted   = []; // physical counts awaiting admin finalization
$alerts_discrepancy = []; // double-fail recounts requiring superadmin resolution

if (in_array($role, ROLES_ADMIN_AND_UP)) {
    $qa = $conn->query("
        SELECT * FROM quantity_alerts
        WHERE status IN ('" . ALERT_PENDING . "','" . ALERT_RECOUNTING . "','" . ALERT_SUBMITTED . "')
        ORDER BY created_at DESC
        LIMIT 60
    ");
    if ($qa) {
        while ($row = $qa->fetch_assoc()) {
            if ($row['status'] === ALERT_PENDING) {
                $alerts_pending[] = $row;
            } elseif ($row['status'] === ALERT_SUBMITTED) {
                $alerts_submitted[] = $row;
            } elseif ($row['status'] === ALERT_RECOUNTING && intval($row['fail_count'] ?? 0) >= 2) {
                // Only surface to admin once double-failed — single-fail items
                // are still in the staff's hands; no admin action required yet.
                $alerts_discrepancy[] = $row;
            }
        }
    }
}
$alert_count = count($alerts_pending) + count($alerts_submitted) + count($alerts_discrepancy);

// ── LOAD RECOUNT ITEMS (staff recount mode) ───────────────────────────────────
$recount_items = [];
if ($staff_recount_mode) {
    $rq = $conn->query("
        SELECT qa.*, COALESCE(p.quantity, qa.batch_qty) AS current_qty
        FROM quantity_alerts qa
        LEFT JOIN products p ON qa.product_id = p.id
        WHERE qa.status = '" . ALERT_RECOUNTING . "'
        ORDER BY qa.created_at DESC
    ");
    $recount_items = $rq ? $rq->fetch_all(MYSQLI_ASSOC) : [];
}

if (!isset($_SESSION['active_batch_id'])) {
    if ($staff_recount_mode && !empty($recount_items)):
?>
<!-- RECOUNT MODE: Staff has been asked to do a physical recount -->
<div class="max-w-5xl mx-auto space-y-6 pb-20 animate-in">
    <div class="bg-amber-500 rounded-[3rem] p-8 flex items-center gap-6 text-white shadow-2xl">
        <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center flex-shrink-0">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </div>
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-white/70 mb-1">Receiving Station — Recount Mode</p>
            <h3 class="serif-title text-3xl font-bold">Owner Requested a Recount</h3>
            <p class="text-white/70 text-sm mt-1"><?= count($recount_items) ?> item<?= count($recount_items) > 1 ? 's' : '' ?> require a physical recount. Enter the actual shelf count below.</p>
        </div>
    </div>

    <form method="POST" action="recount_submit.php" onsubmit="confirmForm(event, this, 'This will resolve all flagged discrepancies with your actual count.', 'Submit Physical Recount?')">
        <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-modern text-left min-w-full">
                    <thead>
                        <tr class="bg-amber-50/60">
                            <th class="px-8 py-6 text-[10px] font-black text-slate-500 uppercase tracking-widest" width="35%">Item Identity</th>
                            <th class="px-4 py-6 text-center text-[10px] font-black text-slate-500 uppercase tracking-widest">PM Encoded Qty</th>
                            <th class="px-4 py-6 text-center text-[10px] font-black text-rose-500 uppercase tracking-widest">Previous Count</th>
                            <th class="px-8 py-6 text-center text-[10px] font-black text-amber-600 uppercase tracking-widest">Actual Count (Physical)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($recount_items as $ri): ?>
                        <tr class="hover:bg-amber-50/20 transition-all">
                            <td class="px-8 py-7">
                                <input type="hidden" name="alert_ids[]" value="<?= $ri['id'] ?>">
                                <p class="font-bold text-slate-800 text-base leading-tight mb-1"><?= htmlspecialchars($ri['product_name']) ?></p>
                                <code class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">#<?= htmlspecialchars($ri['barcode']) ?></code>
                                <p class="text-[10px] text-slate-400 font-bold mt-0.5">Invoice: <?= htmlspecialchars($ri['invoice']) ?></p>
                            </td>
                            <td class="px-4 py-7 text-center">
                                <span class="text-lg font-black text-slate-700"><?= intval($ri['current_qty']) ?></span>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">units</p>
                            </td>
                            <td class="px-4 py-7 text-center">
                                <?php
                                    $prev   = intval($ri['received_qty']);
                                    $live   = intval($ri['current_qty']);
                                    $diff   = $prev - $live;
                                    $matched = $diff === 0;
                                    $diff_label = $matched ? 'matched' : (($diff > 0 ? '+' : '') . $diff . ' diff');
                                    $diff_cls   = $matched ? 'text-slate-400' : 'text-rose-400';
                                ?>
                                <span class="text-lg font-black text-rose-600"><?= $prev ?></span>
                                <p class="text-[9px] font-black <?= $diff_cls ?> uppercase tracking-widest"><?= $diff_label ?></p>
                            </td>
                            <td class="px-8 py-7 text-center">
                                <div class="bg-amber-50 border-2 border-amber-300 rounded-3xl py-3 shadow-inner inline-block min-w-[120px]">
                                    <input type="number" name="actual_qtys[]" required min="0" placeholder="—"
                                        class="w-full bg-transparent text-center text-xl font-black text-slate-900 outline-none">
                                    <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mt-1">Actual Count</p>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-10 bg-slate-900 flex flex-col items-center">
                <button type="submit" class="bg-amber-500 text-white font-black px-48 py-7 rounded-[3rem] shadow-2xl hover:scale-105 active:scale-95 transition-all text-xl uppercase tracking-widest">
                    SUBMIT RECOUNT ✔
                </button>
                <p class="text-slate-500 text-[10px] mt-5 font-bold uppercase tracking-[0.2em]">Submission will resolve all flagged items and notify the owner.</p>
            </div>
        </div>
    </form>
</div>
<?php
    else:
?>
<div class="max-w-2xl mx-auto pt-20 pb-20 animate-in text-center">
    <div class="card-modern p-16">
        <div class="w-20 h-20 bg-slate-100 rounded-3xl flex items-center justify-center mx-auto mb-8">
            <svg class="w-10 h-10 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
        </div>
        <h3 class="serif-title text-3xl font-bold text-slate-800 mb-4">No Active Batch</h3>
        <p class="text-slate-400 text-sm font-bold uppercase tracking-widest mb-10">
            You need to open a delivery shipment first before proceeding to price validation.
        </p>
        <a href="../inventory/product_info.php" class="btn-pos-primary px-16 py-5 text-sm uppercase tracking-widest shadow-lg shadow-emerald-100">
            Go to Product Master &rarr;
        </a>
    </div>
</div>
<?php
    // Show recount management panels for admin when no active batch
    if ($alert_count > 0): ?>
<div class="max-w-5xl mx-auto pb-20 animate-in space-y-6">

    <?php if (!empty($alerts_pending)): ?>
    <!-- Panel 1: Staff recount requests awaiting admin approval -->
    <div class="bg-amber-50 border border-amber-200 rounded-[2.5rem] overflow-hidden shadow-sm">
        <div class="p-6 border-b border-amber-100 flex items-center gap-3">
            <div class="w-8 h-8 bg-amber-500 rounded-xl flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <h4 class="font-black text-amber-800 text-sm uppercase tracking-widest">Recount Requests Awaiting Approval — <?= count($alerts_pending) ?></h4>
                <p class="text-amber-600 text-[10px] font-bold mt-0.5">Staff-submitted requests. Approve to assign recount task, or reject.</p>
            </div>
        </div>
        <div class="divide-y divide-amber-100">
            <?php foreach ($alerts_pending as $al): ?>
            <div class="p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <p class="font-bold text-slate-800"><?= htmlspecialchars($al['product_name']) ?></p>
                    <code class="text-[10px] text-slate-400 uppercase tracking-widest">#<?= htmlspecialchars($al['barcode']) ?></code>
                    <div class="flex flex-wrap gap-3 mt-1.5">
                        <?php if (!empty($al['invoice'])): ?>
                            <span class="text-[9px] font-black bg-rose-100 text-rose-600 px-2 py-0.5 rounded-full uppercase tracking-widest">Delivery Discrepancy</span>
                            <span class="text-xs font-black text-slate-500">Invoice: <span class="text-slate-700"><?= htmlspecialchars($al['invoice']) ?></span></span>
                            <span class="text-xs font-black text-slate-500">Batch: <span class="text-slate-700"><?= intval($al['batch_qty']) ?></span></span>
                            <span class="text-xs font-black text-slate-500">Received: <span class="text-rose-600"><?= intval($al['received_qty']) ?></span></span>
                        <?php else: ?>
                            <span class="text-[9px] font-black bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full uppercase tracking-widest">Staff Request</span>
                            <span class="text-xs font-black text-slate-500">Expected: <span class="text-slate-800"><?= intval($al['expected_qty']) ?> units</span></span>
                        <?php endif; ?>
                        <span class="text-[10px] text-slate-300">·</span>
                        <span class="text-xs font-black text-slate-400"><?= date('M d, Y', strtotime($al['created_at'])) ?></span>
                    </div>
                </div>
                <div class="flex gap-2 flex-shrink-0 items-start">
                    <form method="POST">
                        <input type="hidden" name="alert_id"    value="<?= $al['id'] ?>">
                        <input type="hidden" name="alert_action" value="approve_request">
                        <button class="px-4 py-2 bg-emerald-500 text-white font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-emerald-600 transition-all">Approve</button>
                    </form>
                    <form method="POST" class="flex gap-2 items-center">
                        <input type="hidden" name="alert_id"     value="<?= $al['id'] ?>">
                        <input type="hidden" name="alert_action" value="reject_request">
                        <input type="text" name="reject_reason" placeholder="Reason (optional)"
                            class="text-xs border border-slate-200 rounded-lg px-3 py-2 outline-none w-40 focus:border-rose-300">
                        <button class="px-4 py-2 bg-white border border-rose-200 text-rose-500 font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-rose-50 transition-all">Reject</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($alerts_submitted)): ?>
    <!-- Panel 2: Physical counts submitted by staff, awaiting admin finalization -->
    <div class="bg-blue-50 border border-blue-200 rounded-[2.5rem] overflow-hidden shadow-sm">
        <div class="p-6 border-b border-blue-100 flex items-center gap-3">
            <div class="w-8 h-8 bg-blue-500 rounded-xl flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            </div>
            <div>
                <h4 class="font-black text-blue-800 text-sm uppercase tracking-widest">Physical Counts Awaiting Finalization — <?= count($alerts_submitted) ?></h4>
                <p class="text-blue-600 text-[10px] font-bold mt-0.5">Review the count and approve to update inventory, or reject to send back.</p>
            </div>
        </div>
        <div class="divide-y divide-blue-100">
            <?php foreach ($alerts_submitted as $al):
                $variance       = intval($al['variance'] ?? 0);
                $fail_count     = intval($al['fail_count'] ?? 0);
                $needs_super    = $fail_count >= 2;
                $variance_color = $variance === 0 ? 'text-emerald-600' : ($variance > 0 ? 'text-rose-600' : 'text-amber-600');
                $variance_label = $variance === 0 ? 'Exact match' : ($variance > 0 ? "Short {$variance}" : 'Over ' . abs($variance));
            ?>
            <div class="p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 <?= $needs_super ? 'bg-rose-50/40' : '' ?>">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="font-bold text-slate-800"><?= htmlspecialchars($al['product_name']) ?></p>
                        <?php if ($needs_super): ?>
                            <span class="text-[9px] font-black bg-rose-100 text-rose-600 border border-rose-200 px-2 py-0.5 rounded-full uppercase tracking-widest">⚠ Super Admin Required</span>
                        <?php endif; ?>
                        <?php if ($fail_count > 0): ?>
                            <span class="text-[9px] font-black bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full uppercase"><?= $fail_count ?> failed recount<?= $fail_count > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                    </div>
                    <code class="text-[10px] text-slate-400 uppercase tracking-widest">#<?= htmlspecialchars($al['barcode']) ?></code>
                    <div class="flex flex-wrap gap-4 mt-2">
                        <span class="text-xs font-black text-slate-500">Expected: <span class="text-slate-700"><?= intval($al['expected_qty']) ?></span></span>
                        <span class="text-xs font-black text-slate-500">Counted: <span class="text-blue-700"><?= intval($al['actual_qty']) ?></span></span>
                        <span class="text-xs font-black <?= $variance_color ?>"><?= $variance_label ?></span>
                    </div>
                    <?php if ($al['submitted_at']): ?>
                    <p class="text-[10px] text-slate-400 font-bold mt-0.5">Submitted <?= date('M d, Y · g:i A', strtotime($al['submitted_at'])) ?></p>
                    <?php endif; ?>
                    <?php if ($needs_super): ?>
                    <p class="text-[10px] text-rose-500 font-bold mt-1">Staff accounts involved have been flagged for supervision. Only a Super Admin can approve this resolution.</p>
                    <?php endif; ?>
                </div>
                <div class="flex gap-2 flex-shrink-0 items-start">
                    <?php if (!$needs_super || ($role ?? '') === ROLE_SUPERADMIN): ?>
                    <form method="POST" action="recount_finalize.php">
                        <input type="hidden" name="alert_id" value="<?= $al['id'] ?>">
                        <input type="hidden" name="action"   value="approve">
                        <button class="px-4 py-2 bg-emerald-500 text-white font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-emerald-600 transition-all">Approve &amp; Update Stock</button>
                    </form>
                    <?php else: ?>
                    <span class="px-4 py-2 bg-slate-100 text-slate-400 font-black text-[10px] uppercase tracking-widest rounded-xl cursor-not-allowed">Super Admin Only</span>
                    <?php endif; ?>
                    <form method="POST" action="recount_finalize.php" class="flex gap-2 items-center">
                        <input type="hidden" name="alert_id"  value="<?= $al['id'] ?>">
                        <input type="hidden" name="action"    value="reject">
                        <input type="text"   name="reject_reason" placeholder="Reason (optional)"
                            class="text-xs border border-slate-200 rounded-lg px-3 py-2 outline-none w-40 focus:border-rose-300">
                        <button class="px-4 py-2 bg-white border border-rose-200 text-rose-500 font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-rose-50 transition-all">Reject</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($alerts_discrepancy)): ?>
    <!-- Panel 3: Delivery discrepancy alerts — staff recount in progress -->
    <div class="bg-rose-50 border border-rose-200 rounded-[2.5rem] overflow-hidden shadow-sm">
        <div class="p-6 border-b border-rose-100 flex items-center gap-3">
            <div class="w-8 h-8 bg-rose-500 rounded-xl flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </div>
            <h4 class="font-black text-rose-800 text-sm uppercase tracking-widest">Delivery Discrepancies — Recount In Progress — <?= count($alerts_discrepancy) ?></h4>
        </div>
        <div class="divide-y divide-rose-100">
            <?php foreach ($alerts_discrepancy as $al):
                $al_fail = intval($al['fail_count'] ?? 0);
            ?>
            <div class="p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 <?= $al_fail >= 2 ? 'bg-rose-100/40' : '' ?>">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="font-bold text-slate-800"><?= htmlspecialchars($al['product_name']) ?></p>
                        <?php if ($al_fail >= 2): ?>
                            <span class="text-[9px] font-black bg-rose-500 text-white px-2 py-0.5 rounded-full uppercase tracking-widest">⚠ Double Fail — Supervised</span>
                        <?php elseif ($al_fail === 1): ?>
                            <span class="text-[9px] font-black bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full uppercase">1st fail — 1 attempt remaining</span>
                        <?php endif; ?>
                    </div>
                    <code class="text-[10px] text-slate-400 uppercase tracking-widest">#<?= htmlspecialchars($al['barcode']) ?> · Invoice <?= htmlspecialchars($al['invoice'] ?? '—') ?></code>
                    <div class="flex gap-4 mt-2">
                        <span class="text-xs font-black text-slate-500">PM Encoded: <span class="text-slate-800"><?= $al['batch_qty'] ?></span></span>
                        <span class="text-xs font-black text-slate-500">Received: <span class="text-rose-600"><?= $al['received_qty'] ?></span></span>
                        <?php if ($al_fail > 0): ?>
                        <span class="text-xs font-black text-rose-500">Failed counts: <?= $al_fail ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($al_fail >= 2): ?>
                        <p class="text-[10px] text-rose-600 font-bold mt-1">Locked — Super Admin must resolve. Involved staff flagged for supervision.</p>
                    <?php else: ?>
                        <span class="inline-block mt-2 text-[9px] font-black bg-amber-100 text-amber-700 px-3 py-1 rounded-full uppercase tracking-widest">Awaiting staff count</span>
                    <?php endif; ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="alert_id"    value="<?= $al['id'] ?>">
                    <input type="hidden" name="alert_action" value="resolve">
                    <button class="px-4 py-2 bg-white border border-slate-200 text-slate-500 font-black text-[10px] uppercase tracking-widest rounded-xl hover:bg-slate-50 transition-all">Dismiss</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php
    endif;
    endif; // end if ($staff_recount_mode && !empty($recount_items))
    include '../layout_bottom.php';
    exit();
}

// ── BATCH LIFECYCLE TRACKING ──────────────────────────────────────────────────
// Advance procurement_batches to 'receiving' on the first load of this stage.
// The flag prevents a redundant UPDATE on every SPA navigation to this page.
if (!isset($_SESSION['receiving_stage_logged']) && isset($_SESSION['proc_batch_id'])) {
    $pb_id = intval($_SESSION['proc_batch_id']);
    $conn->query("UPDATE procurement_batches SET status='" . BATCH_RECEIVING . "', receiving_started_at=NOW() WHERE id={$pb_id} AND status='" . BATCH_ENCODING . "'");
    $_SESSION['receiving_stage_logged'] = true;
}

// ── LOAD BATCH DATA ───────────────────────────────────────────────────────────
$active_sid  = intval($_SESSION['active_batch_id']);
$active_name = $_SESSION['active_batch_name'] ?? '';
$active_inv  = $_SESSION['active_invoice']     ?? '';

// Group by barcode + expiry_date so same product with different expiry dates appear as separate line items.
$draft_stmt = $conn->prepare("
    SELECT MIN(id) AS id, name, barcode, expiry_date,
           SUM(quantity) AS total_qty, MAX(price) AS price, MAX(category) AS category
    FROM products
    WHERE supplier_id = ? AND status = '" . PRODUCT_DRAFT . "'
    GROUP BY barcode, expiry_date
    ORDER BY name ASC, expiry_date ASC
");
$draft_stmt->bind_param("i", $active_sid);
$draft_stmt->execute();
$items = $draft_stmt->get_result();
?>
<div class="max-w-7xl mx-auto space-y-8 animate-in pb-20">

    <!-- Discrepancy alert banner for admin (compact, shown above form) -->
    <?php if ($alert_count > 0): ?>
    <div class="bg-rose-50 border border-rose-200 rounded-2xl px-6 py-4 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-rose-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <p class="font-black text-rose-700 text-sm"><?= $alert_count ?> quantity discrepancy alert<?= $alert_count > 1 ? 's' : '' ?> pending review.</p>
        </div>
        <a href="delivery_receive.php?clear_batch=1" onclick="event.preventDefault(); history.pushState({},'','delivery_receive.php'); navigate('delivery_receive.php')"
           class="text-[10px] font-black text-rose-500 uppercase tracking-widest hover:underline">View Alerts</a>
    </div>
    <?php endif; ?>

    <!-- HEADER -->
    <div class="flex justify-between items-center bg-slate-900 p-8 rounded-[3rem] text-white shadow-2xl">
        <div class="flex items-center gap-6">
            <div class="w-16 h-16 bg-emerald-500 rounded-2xl flex items-center justify-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-emerald-400 tracking-[0.2em] mb-1">Receiving Station — Price & Qty Validation</p>
                <h3 class="serif-title text-3xl font-bold"><?= htmlspecialchars($active_name) ?></h3>
                <code class="text-slate-500 font-mono text-sm uppercase">Invoice: <?= $active_inv ?></code>
            </div>
        </div>
        <div class="text-right hidden md:block flex flex-col items-end gap-3">
            <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Batch Items</p>
            <h4 class="text-2xl font-black text-white"><?= $items->num_rows ?></h4>
            <button type="button"
                onclick="customConfirm('This will discard all drafted items for this batch and return you to Product Master.', 'Discard Batch?').then(ok => { if(ok) navigate('delivery_receive.php?clear_batch=1'); })"
                class="mt-3 text-[9px] font-black text-rose-400 hover:text-rose-300 uppercase tracking-widest transition-all border border-rose-500/30 hover:border-rose-400/60 px-4 py-1.5 rounded-full">
                Discard Batch
            </button>
        </div>
    </div>

    <!-- MASTER FORM -->
    <form method="POST" action="officialize_stock.php" onsubmit="confirmForm(event, this, 'Inventory stock levels will be updated across all locations.', 'Officialize this Batch?')">
        <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table-modern text-left min-w-full">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-8 py-6 text-[10px] font-black text-slate-500 uppercase tracking-widest" width="25%">Item Identity</th>
                            <th class="px-4 py-6 text-center text-[10px] font-black text-slate-500 uppercase tracking-widest" width="18%">Retail Price (₱)</th>
                            <th class="px-4 py-6 text-center text-[10px] font-black text-blue-500 uppercase tracking-widest" width="20%">½ Box Tier</th>
                            <th class="px-4 py-6 text-center text-[10px] font-black text-purple-500 uppercase tracking-widest" width="20%">Full Box Tier</th>
                            <th class="px-8 py-6 text-center text-[10px] font-black text-emerald-600 uppercase tracking-widest" width="17%">Received Qty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php while($p = $items->fetch_assoc()): ?>
                        <tr class="hover:bg-slate-50/30 transition-all">
                            <td class="px-8 py-8">
                                <input type="hidden" name="p_ids[]"        value="<?= $p['id'] ?>">
                                <input type="hidden" name="p_barcodes[]"  value="<?= htmlspecialchars($p['barcode']) ?>">
                                <input type="hidden" name="p_names[]"     value="<?= htmlspecialchars($p['name']) ?>">
                                <input type="hidden" name="batch_qtys[]"  value="<?= intval($p['total_qty']) ?>">
                                <input type="hidden" name="expiry_dates[]" value="<?= htmlspecialchars($p['expiry_date'] ?? '') ?>">
                                <p class="font-bold text-slate-800 text-lg leading-tight mb-1"><?= htmlspecialchars($p['name']) ?></p>
                                <code class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">#<?= $p['barcode'] ?></code>
                                <?php if (!empty($p['expiry_date'])): ?>
                                <p class="text-[10px] font-bold text-amber-500 mt-0.5">Exp: <?= date('M j, Y', strtotime($p['expiry_date'])) ?></p>
                                <?php endif; ?>
                            </td>

                            <!-- RETAIL PRICE -->
                            <td class="px-4 py-8">
                                <div class="p-3 bg-white rounded-2xl border border-emerald-200">
                                    <p class="text-[9px] font-black text-slate-400 ml-1 mb-1">Retail Price</p>
                                    <div class="flex items-center gap-1">
                                        <span class="text-sm font-black text-emerald-400">₱</span>
                                        <input type="number" step="0.01" name="prices[]" value="" required placeholder="0.00"
                                            class="w-full text-right font-bold text-emerald-700 outline-none text-sm bg-transparent">
                                    </div>
                                </div>
                            </td>

                            <!-- TIER 1 (½ Box) -->
                            <td class="px-4 py-8 bg-blue-50/10 border-x border-slate-50">
                                <div class="p-3 bg-white rounded-2xl border border-blue-100 space-y-2">
                                    <div>
                                        <p class="text-[9px] font-black text-slate-400 mb-1">Min Qty to qualify</p>
                                        <input type="number" name="t1_qtys[]" value="0" min="0"
                                            class="w-full text-center font-bold text-slate-600 outline-none text-sm bg-transparent border border-slate-100 rounded-lg py-1">
                                    </div>
                                    <div>
                                        <p class="text-[9px] font-black text-slate-400 mb-1">Bulk Price (₱)</p>
                                        <input type="number" step="0.01" name="t1_prices[]" value="" placeholder="0.00"
                                            class="w-full text-right font-bold text-blue-600 outline-none text-sm bg-transparent border border-slate-100 rounded-lg py-1 pr-2">
                                    </div>
                                </div>
                            </td>

                            <!-- TIER 2 (Full Box) -->
                            <td class="px-4 py-8 bg-purple-50/20">
                                <div class="p-3 bg-white rounded-2xl border border-purple-100 space-y-2">
                                    <div>
                                        <p class="text-[9px] font-black text-slate-400 mb-1">Min Qty to qualify</p>
                                        <input type="number" name="t2_qtys[]" value="0" min="0"
                                            class="w-full text-center font-bold text-slate-600 outline-none text-sm bg-transparent border border-slate-100 rounded-lg py-1">
                                    </div>
                                    <div>
                                        <p class="text-[9px] font-black text-slate-400 mb-1">Bulk Price (₱)</p>
                                        <input type="number" step="0.01" name="t2_prices[]" value="" placeholder="0.00"
                                            class="w-full text-right font-bold text-purple-600 outline-none text-sm bg-transparent border border-slate-100 rounded-lg py-1 pr-2">
                                    </div>
                                </div>
                            </td>

                            <!-- RECEIVED QTY (blank — receiver enters actual count) -->
                            <td class="px-8 py-8 text-center">
                                <div class="bg-slate-50 border-2 border-slate-200 rounded-3xl py-3 shadow-inner">
                                    <input type="number" name="final_qtys[]" value="" required min="0" placeholder="—"
                                        class="w-full bg-transparent text-center text-xl font-black text-slate-900 outline-none">
                                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mt-1">Count Received</p>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div class="p-12 bg-slate-900 flex flex-col items-center">
                <button type="submit" class="bg-emerald-500 text-white font-black px-56 py-7 rounded-[3rem] shadow-2xl hover:scale-105 active:scale-95 transition-all text-xl uppercase tracking-widest flex items-center gap-4">
                    VALIDATE &amp; OFFICIALIZE BATCH ✔
                </button>
                <p class="text-slate-500 text-[10px] mt-6 font-bold uppercase tracking-[0.2em]">Quantity mismatches will be automatically flagged for admin review.</p>
            </div>
        </div>
    </form>
</div>

<?php include '../layout_bottom.php'; ?>
