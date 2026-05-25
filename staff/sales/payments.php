<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;

include '../layout_top.php';

if (($role ?? '') === ROLE_STAFF && !in_array(basename(__FILE__), $staff_procurement_steps ?? [])) {
    header("Location: ../dashboard.php");
    exit();
}

// Fetch all payments joined with any active (pending) approval record
$payments = $conn->query("
    SELECT p.*, s.name AS supplier_name, s.supplier_code,
           pa.id              AS approval_id,
           pa.status          AS approval_status,
           pa.requested_by    AS ap_req_by,
           pa.requested_by_username AS ap_req_user,
           pa.step1_username  AS ap_step1_user,
           pa.step1_at        AS ap_step1_at
    FROM supplier_payments p
    JOIN suppliers s ON s.id = p.supplier_id
    LEFT JOIN payment_approvals pa
           ON pa.payment_id = p.id
          AND pa.status IN ('" . APPROVAL_PENDING_STEP1 . "','" . APPROVAL_PENDING_STEP2 . "')
    ORDER BY FIELD(p.status,'" . SUP_PAY_UNPAID . "','" . SUP_PAY_PAID . "'), p.id DESC
");

// Count pending approvals
$pending_count = 0;
$rows = [];
while ($r = $payments->fetch_assoc()) {
    $rows[] = $r;
    if ($r['approval_status']) $pending_count++;
}

// Pending delivery validations
$del_q = $conn->query("
    SELECT d.id, d.delivery_date, s.name AS supplier
    FROM deliveries d
    JOIN suppliers s ON s.id = d.supplier_id
    WHERE d.status = '" . DEL_PENDING . "'
    ORDER BY d.delivery_date ASC
");
$pending_del_rows  = $del_q ? $del_q->fetch_all(MYSQLI_ASSOC) : [];
$pending_del_count = count($pending_del_rows);
?>

<div class="max-w-7xl mx-auto space-y-6 animate-in pb-20">

    <!-- ── HEADER ─────────────────────────────────────────────────────────── -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-4">
        <div>
            <h2 class="serif-title text-2xl font-black text-slate-800">Outgoing Payments</h2>
            <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mt-0.5">Supplier settlement tracker</p>
        </div>
        <div class="bg-white px-5 py-2 rounded-2xl border border-slate-100 shadow-sm flex items-center gap-6">
            <div class="text-center">
                <span class="text-[8px] font-black text-slate-300 uppercase block">Total Invoices</span>
                <span class="text-base font-black text-slate-700"><?= count($rows) ?></span>
            </div>
            <?php if ($pending_count > 0): ?>
            <div class="text-center">
                <span class="text-[8px] font-black text-amber-400 uppercase block">Awaiting Approval</span>
                <span class="text-base font-black text-amber-500"><?= $pending_count ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── PENDING DELIVERIES BANNER ────────────────────────────────────── -->
    <?php if ($pending_del_count > 0): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-[1.75rem] overflow-hidden shadow-sm shadow-amber-100">

        <!-- Header row — always visible -->
        <button onclick="togglePendingDeliveries()"
                class="w-full flex items-center justify-between px-7 py-5 hover:bg-amber-100/50 transition-colors text-left">
            <div class="flex items-center gap-4">
                <div class="w-9 h-9 bg-amber-500 rounded-xl flex items-center justify-center flex-shrink-0 shadow shadow-amber-200">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="font-black text-amber-800 text-sm">
                        <?= $pending_del_count ?> Deliver<?= $pending_del_count === 1 ? 'y' : 'ies' ?> Pending Validation
                    </p>
                    <p class="text-amber-600 text-[10px] font-bold mt-0.5">Verify received items before processing supplier payments</p>
                </div>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <span class="bg-amber-500 text-white text-[10px] font-black px-3 py-1 rounded-full shadow shadow-amber-200">
                    <?= $pending_del_count ?> unverified
                </span>
                <svg id="del-chevron" class="w-4 h-4 text-amber-500 transition-transform duration-200"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
        </button>

        <!-- Expandable delivery list -->
        <div id="pending-del-list" class="hidden border-t border-amber-200">
            <?php foreach ($pending_del_rows as $pd): ?>
            <div class="flex items-center justify-between px-7 py-4 border-b border-amber-100/70 last:border-0 hover:bg-amber-100/30 transition-colors">
                <div class="flex items-center gap-4">
                    <div class="w-2 h-2 bg-amber-400 rounded-full flex-shrink-0"></div>
                    <div>
                        <p class="font-bold text-amber-900 text-sm"><?= htmlspecialchars($pd['supplier']) ?></p>
                        <p class="text-amber-600 text-[10px] font-bold">
                            Received <?= date('M d, Y', strtotime($pd['delivery_date'])) ?>
                        </p>
                    </div>
                </div>
                <a href="../procurement/delivery_view.php?id=<?= $pd['id'] ?>"
                   class="text-[10px] font-black text-amber-700 bg-amber-100 hover:bg-amber-200 border border-amber-300 px-4 py-2 rounded-xl uppercase tracking-widest transition-all flex-shrink-0">
                    View &amp; Verify →
                </a>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
    <?php else: ?>
    <div class="bg-emerald-50 border border-emerald-100 rounded-[1.75rem] px-7 py-4 flex items-center gap-3">
        <div class="w-7 h-7 bg-emerald-500 rounded-lg flex items-center justify-center flex-shrink-0">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <p class="text-emerald-700 font-black text-sm">All deliveries validated</p>
        <span class="text-emerald-400 text-[10px] font-bold">— safe to process supplier payments</span>
    </div>
    <?php endif; ?>

    <!-- ── APPROVAL PIPELINE LEGEND (admin/superadmin) ───────────────────── -->
    <?php if (in_array($role ?? '', ROLES_PAYMENT_APPROVERS)): ?>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm px-6 py-4 flex flex-wrap items-center gap-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">
        <span class="text-slate-500">Approval Flow:</span>
        <span class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-slate-300 inline-block"></span>Request
        </span>
        <span class="text-slate-200">→</span>
        <span class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-blue-400 inline-block"></span>Step 1 — Admin Review
        </span>
        <span class="text-slate-200">→</span>
        <span class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-violet-400 inline-block"></span>Step 2 — Final (Superadmin)
        </span>
        <span class="text-slate-200">→</span>
        <span class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>PAID
        </span>
    </div>
    <?php endif; ?>

    <!-- ── LEDGER TABLE ───────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden mb-20">
        <div class="p-6 bg-slate-50/50 border-b border-slate-100">
            <h4 class="font-black text-slate-800 text-[11px] uppercase tracking-widest">Recent Shipments Ledger</h4>
        </div>

        <div class="overflow-x-auto">
            <table class="table-modern text-left min-w-full">
                <thead>
                    <tr class="bg-slate-50/30">
                        <th class="px-8 py-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Voucher ID</th>
                        <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Supplier</th>
                        <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount Due</th>
                        <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                        <th class="px-6 py-5 text-center text-[10px] font-black text-slate-400 uppercase tracking-widest">Approval</th>
                        <th class="px-8 py-5 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $p):
                        $ap_status  = $p['approval_status'] ?? null; // pending_step1 | pending_step2 | null
                        $is_paid    = ($p['status'] === SUP_PAY_PAID);
                        $is_my_req  = ($p['ap_req_by'] == $user_id);
                    ?>
                    <tr class="hover:bg-slate-50 transition-all group">

                        <!-- Voucher ID -->
                        <td class="px-8 py-6 font-mono font-bold text-slate-400 text-sm">
                            <?= htmlspecialchars($p['invoice_no']) ?>
                        </td>

                        <!-- Supplier -->
                        <td class="px-6 py-6 text-center">
                            <p class="font-bold text-slate-700 text-base"><?= htmlspecialchars($p['supplier_name']) ?></p>
                            <span class="text-[9px] font-black text-slate-300">CODE: #<?= htmlspecialchars($p['supplier_code']) ?></span>
                        </td>

                        <!-- Amount -->
                        <td class="px-6 py-6 text-center font-black text-slate-800 text-lg tracking-tight">
                            ₱<?= number_format($p['amount'], 2) ?>
                        </td>

                        <!-- Payment Status badge -->
                        <td class="px-6 py-6 text-center">
                            <?php if ($is_paid): ?>
                                <span class="px-4 py-1.5 bg-emerald-50 text-emerald-600 border border-emerald-100 rounded-lg text-[9px] font-black uppercase">PAID</span>
                            <?php else: ?>
                                <span class="px-4 py-1.5 bg-amber-50 text-amber-600 border border-amber-100 rounded-lg text-[9px] font-black uppercase">UNSETTLED</span>
                            <?php endif; ?>
                        </td>

                        <!-- Approval Pipeline column -->
                        <td class="px-6 py-6 text-center">
                            <?php if ($is_paid && !$ap_status): ?>
                                <span class="text-[9px] font-black text-emerald-500 uppercase">Verified</span>
                            <?php elseif ($ap_status === APPROVAL_PENDING_STEP1): ?>
                                <div class="inline-flex flex-col items-center gap-0.5">
                                    <span class="px-3 py-1 bg-blue-50 text-blue-600 border border-blue-100 rounded-lg text-[9px] font-black uppercase animate-pulse">Step 1 Pending</span>
                                    <span class="text-[8px] text-slate-300 font-bold">by <?= htmlspecialchars($p['ap_req_user'] ?? '—') ?></span>
                                </div>
                            <?php elseif ($ap_status === APPROVAL_PENDING_STEP2): ?>
                                <div class="inline-flex flex-col items-center gap-0.5">
                                    <span class="px-3 py-1 bg-violet-50 text-violet-600 border border-violet-100 rounded-lg text-[9px] font-black uppercase animate-pulse">Step 2 Pending</span>
                                    <span class="text-[8px] text-slate-300 font-bold">Step 1 by <?= htmlspecialchars($p['ap_step1_user'] ?? '—') ?></span>
                                </div>
                            <?php else: ?>
                                <span class="text-[9px] font-black text-slate-300 uppercase">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Action buttons -->
                        <td class="px-8 py-6 text-right">
                            <div class="flex items-center justify-end gap-2 flex-wrap">

                            <?php if ($is_paid): ?>
                                <!-- PAID: only admin/superadmin can revert -->
                                <?php if (in_array($role ?? '', ROLES_PAYMENT_APPROVERS)): ?>
                                <form method="POST" action="payments_approve.php">
                                    <input type="hidden" name="action" value="revert_payment">
                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                    <button type="submit"
                                        onclick="confirmForm(event, this.closest('form'), 'Revert this payment to UNPAID? A security flag will be logged.', 'Revert Payment?'); return false;"
                                        class="px-3 py-1.5 bg-white border border-rose-200 rounded-xl text-[9px] font-black text-rose-400 hover:bg-rose-50 hover:border-rose-400 transition-all uppercase">
                                        Revert
                                    </button>
                                </form>
                                <?php endif; ?>

                            <?php elseif (!$ap_status): ?>
                                <!-- UNPAID, no pending request → anyone can request -->
                                <form method="POST" action="payments_approve.php">
                                    <input type="hidden" name="action" value="request_payment">
                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                    <button type="submit"
                                        onclick="confirmForm(event, this.closest('form'), 'Submit this payment for approval? It will go through a two-step review before being marked PAID.', 'Request Payment?'); return false;"
                                        class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-[9px] font-black text-slate-500 hover:text-blue-600 hover:border-blue-400 transition-all uppercase">
                                        Request Payment
                                    </button>
                                </form>

                            <?php elseif ($ap_status === APPROVAL_PENDING_STEP1): ?>
                                <?php if (in_array($role ?? '', ROLES_PAYMENT_APPROVERS) && (!$is_my_req || ($role ?? '') === ROLE_SUPERADMIN)): ?>
                                <!-- Admin/SA can approve step 1 (not their own request, unless SA) -->
                                <form method="POST" action="payments_approve.php">
                                    <input type="hidden" name="action" value="approve_step1">
                                    <input type="hidden" name="approval_id" value="<?= $p['approval_id'] ?>">
                                    <button type="submit"
                                        onclick="confirmForm(event, this.closest('form'), 'Approve Step 1 for Invoice <?= htmlspecialchars($p['invoice_no'], ENT_QUOTES) ?>? This sends it to final (superadmin) review.', 'Approve Step 1?'); return false;"
                                        class="px-4 py-2 bg-blue-600 border border-blue-600 rounded-xl text-[9px] font-black text-white hover:bg-blue-700 transition-all uppercase">
                                        Approve ①
                                    </button>
                                </form>
                                <?php elseif ($is_my_req && $role !== 'superadmin'): ?>
                                <span class="text-[9px] font-black text-slate-300 uppercase px-3 py-2">Your Request</span>
                                <?php endif; ?>

                                <?php if (in_array($role ?? '', ROLES_PAYMENT_APPROVERS)): ?>
                                <form method="POST" action="payments_approve.php">
                                    <input type="hidden" name="action" value="deny_payment">
                                    <input type="hidden" name="approval_id" value="<?= $p['approval_id'] ?>">
                                    <button type="submit"
                                        onclick="confirmForm(event, this.closest('form'), 'Deny this payment approval request?', 'Deny Request?'); return false;"
                                        class="px-3 py-1.5 bg-white border border-rose-200 rounded-xl text-[9px] font-black text-rose-400 hover:bg-rose-50 hover:border-rose-400 transition-all uppercase">
                                        Deny
                                    </button>
                                </form>
                                <?php endif; ?>

                            <?php elseif ($ap_status === APPROVAL_PENDING_STEP2): ?>
                                <?php if (($role ?? '') === ROLE_SUPERADMIN): ?>
                                <!-- Only superadmin can give final approval -->
                                <form method="POST" action="payments_approve.php">
                                    <input type="hidden" name="action" value="approve_step2">
                                    <input type="hidden" name="approval_id" value="<?= $p['approval_id'] ?>">
                                    <button type="submit"
                                        onclick="confirmForm(event, this.closest('form'), 'Give final approval for Invoice <?= htmlspecialchars($p['invoice_no'], ENT_QUOTES) ?>? This will mark the payment as PAID.', 'Final Approval?'); return false;"
                                        class="px-4 py-2 bg-violet-600 border border-violet-600 rounded-xl text-[9px] font-black text-white hover:bg-violet-700 transition-all uppercase">
                                        Final Approve ②
                                    </button>
                                </form>
                                <form method="POST" action="payments_approve.php">
                                    <input type="hidden" name="action" value="deny_payment">
                                    <input type="hidden" name="approval_id" value="<?= $p['approval_id'] ?>">
                                    <button type="submit"
                                        onclick="confirmForm(event, this.closest('form'), 'Deny this payment at Step 2?', 'Deny Final Step?'); return false;"
                                        class="px-3 py-1.5 bg-white border border-rose-200 rounded-xl text-[9px] font-black text-rose-400 hover:bg-rose-50 hover:border-rose-400 transition-all uppercase">
                                        Deny
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="text-[9px] font-black text-violet-400 uppercase px-3 py-2">Awaiting Superadmin</span>
                                <?php endif; ?>

                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="p-20 text-center text-slate-300 font-bold italic opacity-30">No records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function togglePendingDeliveries() {
    const list     = document.getElementById('pending-del-list');
    const chevron  = document.getElementById('del-chevron');
    const expanded = !list.classList.contains('hidden');
    list.classList.toggle('hidden', expanded);
    chevron.style.transform = expanded ? '' : 'rotate(180deg)';
}
</script>

<?php include '../layout_bottom.php'; ?>
