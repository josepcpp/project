<?php
include '../../config/db.php';
include '../../includes/admin_only.php';
include '../layout_top.php';

$current_role = strtolower($_SESSION['role'] ?? '');
$is_super     = $current_role === ROLE_SUPERADMIN;

// ── Sales Refund Queue ────────────────────────────────────────────────────────
$sr_q = $conn->query("
    SELECT r.id, r.qty, r.disposition, r.amount_refunded, r.override_note, r.reject_note,
           r.status, r.created_at,
           s.receipt_no, p.name AS product_name,
           u.username AS requested_by
    FROM refunds r
    JOIN sales s    ON r.sale_id    = s.id
    JOIN products p ON r.product_id = p.id
    LEFT JOIN users u ON r.requested_by = u.id
    WHERE r.status IN ('" . REFUND_PENDING . "','" . REFUND_REJECTED . "')
    ORDER BY FIELD(r.status,'" . REFUND_PENDING . "','" . REFUND_REJECTED . "'), r.created_at DESC
");
$sr_items   = $sr_q ? $sr_q->fetch_all(MYSQLI_ASSOC) : [];
$sr_pending = count(array_filter($sr_items, fn($r) => $r['status'] === REFUND_PENDING));

// ── Delivery Return Requests ──────────────────────────────────────────────────
$dr_q = $conn->query("
    SELECT * FROM delivery_return_requests
    ORDER BY FIELD(status,'" . DR_PENDING . "','" . DR_APPROVED . "','" . DR_REJECTED . "'), created_at DESC
    LIMIT 60
");
$dr_items   = [];
if ($dr_q) while ($row = $dr_q->fetch_assoc()) {
    $ist = $conn->prepare("SELECT product_name, qty, reason, unit_price FROM delivery_return_request_items WHERE request_id = ?");
    $ist->bind_param("i", $row['id']); $ist->execute();
    $row['items'] = $ist->get_result()->fetch_all(MYSQLI_ASSOC);
    $dr_items[] = $row;
}
$dr_pending = count(array_filter($dr_items, fn($r) => $r['status'] === DR_PENDING));
?>

<div class="max-w-7xl mx-auto space-y-8 pb-20 animate-in">

    <!-- ── Summary stat row ──────────────────────────────────────────────────── -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div class="bg-white rounded-[2rem] border <?= $sr_pending > 0 ? 'border-amber-200 bg-amber-50/20' : 'border-slate-100' ?> shadow-md p-6 flex items-center gap-5">
            <div class="w-12 h-12 <?= $sr_pending > 0 ? 'bg-amber-500' : 'bg-slate-100' ?> rounded-2xl flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 <?= $sr_pending > 0 ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
            </div>
            <div class="flex-1">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Sales Refunds</p>
                <p class="font-black text-2xl <?= $sr_pending > 0 ? 'text-amber-600' : 'text-slate-800' ?>"><?= $sr_pending ?> <span class="text-sm font-bold text-slate-300">pending</span></p>
                <p class="text-[10px] text-slate-400 font-bold"><?= count($sr_items) ?> total in queue</p>
            </div>
        </div>
        <div class="bg-white rounded-[2rem] border <?= $dr_pending > 0 ? 'border-rose-200 bg-rose-50/20' : 'border-slate-100' ?> shadow-md p-6 flex items-center gap-5">
            <div class="w-12 h-12 <?= $dr_pending > 0 ? 'bg-rose-600' : 'bg-slate-100' ?> rounded-2xl flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 <?= $dr_pending > 0 ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
            </div>
            <div class="flex-1">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Delivery Returns</p>
                <p class="font-black text-2xl <?= $dr_pending > 0 ? 'text-rose-600' : 'text-slate-800' ?>"><?= $dr_pending ?> <span class="text-sm font-bold text-slate-300">pending</span></p>
                <p class="text-[10px] text-slate-400 font-bold"><?= count($dr_items) ?> total in queue</p>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         SALES REFUND QUEUE
    ════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden">
        <div class="px-8 py-6 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-11 h-11 bg-amber-500 text-white rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                </div>
                <div>
                    <h3 class="serif-title text-xl font-bold text-slate-800">Sales Refund Queue</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">
                        Customer return requests submitted by staff
                        <?php if ($is_super): ?>&nbsp;·&nbsp;<span class="text-rose-500">Super Admin override available on rejected entries</span><?php endif; ?>
                    </p>
                </div>
            </div>
            <?php if ($sr_pending > 0): ?>
            <span class="bg-amber-100 text-amber-700 font-black text-xs px-4 py-2 rounded-full"><?= $sr_pending ?> pending</span>
            <?php endif; ?>
        </div>

        <?php if (empty($sr_items)): ?>
        <div class="py-20 text-center">
            <div class="w-14 h-14 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            </div>
            <p class="text-slate-400 font-black uppercase text-sm tracking-widest">No pending refund requests</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-100">
                        <th class="px-8 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest">Product / Receipt</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Qty</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Amount</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">State</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest">Submitted</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                        <th class="px-8 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php foreach ($sr_items as $sr):
                        $is_pending  = $sr['status'] === REFUND_PENDING;
                        $is_rejected = $sr['status'] === REFUND_REJECTED;
                    ?>
                    <tr class="hover:bg-slate-50/50 transition-all <?= $is_rejected ? 'opacity-60' : '' ?>">
                        <td class="px-8 py-5">
                            <p class="font-bold text-slate-800"><?= htmlspecialchars($sr['product_name']) ?></p>
                            <code class="text-[10px] font-mono text-slate-400">#<?= htmlspecialchars($sr['receipt_no']) ?></code>
                            <?php $note_text = $sr['reject_note'] ?? $sr['override_note'] ?? ''; if ($is_rejected && $note_text): ?>
                                <p class="text-[10px] text-rose-400 mt-1 italic">Reject reason: <?= htmlspecialchars($note_text) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-5 text-center font-black text-slate-700 text-xl"><?= intval($sr['qty']) ?></td>
                        <td class="px-5 py-5 text-center font-black text-emerald-600">₱<?= number_format($sr['amount_refunded'], 2) ?></td>
                        <td class="px-5 py-5 text-center">
                            <?php if ($sr['disposition'] === 'restock'): ?>
                                <span class="bg-emerald-50 text-emerald-600 text-[9px] font-black px-3 py-1 rounded-full border border-emerald-100">Restock</span>
                            <?php else: ?>
                                <span class="bg-slate-100 text-slate-500 text-[9px] font-black px-3 py-1 rounded-full">Dispose</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-5">
                            <p class="font-bold text-slate-700 text-sm"><?= $sr['requested_by'] ? '@' . htmlspecialchars($sr['requested_by']) : '—' ?></p>
                            <p class="text-[9px] text-slate-300 font-bold"><?= date('M j, h:i A', strtotime($sr['created_at'])) ?></p>
                        </td>
                        <td class="px-5 py-5 text-center">
                            <?php if ($is_pending): ?>
                                <span class="bg-amber-50 text-amber-600 text-[9px] font-black px-3 py-1 rounded-full border border-amber-100 uppercase">Pending</span>
                            <?php else: ?>
                                <span class="bg-rose-50 text-rose-500 text-[9px] font-black px-3 py-1 rounded-full border border-rose-100 uppercase">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-8 py-5 text-right">
                            <div class="flex gap-2 justify-end">
                                <?php if ($is_pending): ?>
                                    <form id="srf_<?= $sr['id'] ?>" method="POST" action="refund_approve.php" class="hidden">
                                        <input type="hidden" name="action"    value="approve">
                                        <input type="hidden" name="refund_id" value="<?= $sr['id'] ?>">
                                    </form>
                                    <button onclick="srApprove(<?= $sr['id'] ?>, '<?= htmlspecialchars(addslashes($sr['product_name'])) ?>', '<?= number_format($sr['amount_refunded'], 2) ?>')"
                                        class="bg-emerald-500 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase hover:bg-emerald-600 transition-all shadow-sm">Approve</button>
                                    <button onclick="srOpenReject(<?= $sr['id'] ?>, '<?= htmlspecialchars(addslashes($sr['product_name'])) ?>')"
                                        class="bg-rose-500 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase hover:bg-rose-600 transition-all shadow-sm">Reject</button>
                                <?php elseif ($is_rejected && $is_super): ?>
                                    <button onclick="srOverride(<?= $sr['id'] ?>, '<?= htmlspecialchars(addslashes($sr['product_name'])) ?>')"
                                        class="bg-amber-500 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase hover:bg-amber-600 transition-all shadow-sm">Override</button>
                                <?php else: ?>
                                    <span class="text-slate-200 text-xs font-bold">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         DELIVERY RETURN REQUESTS
    ════════════════════════════════════════════════════════════════ -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden">
        <div class="px-8 py-6 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-11 h-11 bg-rose-600 text-white rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                </div>
                <div>
                    <h3 class="serif-title text-xl font-bold text-slate-800">Delivery Return Requests</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">Accepted requests generate a formal return ticket</p>
                </div>
            </div>
            <?php if ($dr_pending > 0): ?>
            <span class="bg-rose-100 text-rose-700 font-black text-xs px-4 py-2 rounded-full"><?= $dr_pending ?> pending</span>
            <?php endif; ?>
        </div>

        <?php if (empty($dr_items)): ?>
        <div class="py-20 text-center">
            <div class="w-14 h-14 bg-slate-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <p class="text-slate-400 font-black uppercase text-sm tracking-widest">No delivery return requests</p>
            <p class="text-slate-300 text-xs mt-1">Staff submit requests from the Delivery Returns tab.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50/50 border-b border-slate-100">
                        <th class="px-8 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest">Invoice / Supplier</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Items</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest">Purpose</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Submitted By</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Date</th>
                        <th class="px-5 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                        <th class="px-8 py-5 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php foreach ($dr_items as $dr):
                        $total_qty = array_sum(array_column($dr['items'], 'qty'));
                    ?>
                    <tr class="hover:bg-slate-50/50 transition-all <?= $dr['status'] === DR_REJECTED ? 'opacity-60' : '' ?>">
                        <td class="px-8 py-5">
                            <p class="font-bold text-slate-800"><?= htmlspecialchars($dr['supplier_name'] ?? '—') ?></p>
                            <code class="text-[10px] font-mono text-slate-400"><?= htmlspecialchars($dr['invoice_no']) ?></code>
                            <?php if ($dr['ticket_no']): ?>
                                <p class="text-[10px] font-black text-emerald-600 mt-1"><?= htmlspecialchars($dr['ticket_no']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-5 text-center">
                            <span class="font-black text-slate-700 text-lg"><?= count($dr['items']) ?></span>
                            <p class="text-[9px] text-slate-400 font-bold"><?= $total_qty ?> units total</p>
                        </td>
                        <td class="px-5 py-5 max-w-[200px]">
                            <p class="text-sm text-slate-600 font-medium line-clamp-2"><?= htmlspecialchars($dr['purpose'] ?? '—') ?></p>
                            <?php if ($dr['status'] === DR_REJECTED && $dr['reject_reason']): ?>
                                <p class="text-[9px] text-rose-400 mt-1 italic">Rejected: <?= htmlspecialchars($dr['reject_reason']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-5 text-center">
                            <p class="font-bold text-slate-700 text-sm"><?= htmlspecialchars($dr['requested_username'] ?? '—') ?></p>
                        </td>
                        <td class="px-5 py-5 text-center">
                            <p class="text-slate-400 text-xs font-bold"><?= date('M j, Y', strtotime($dr['created_at'])) ?></p>
                            <p class="text-slate-300 text-[10px]"><?= date('g:i A', strtotime($dr['created_at'])) ?></p>
                        </td>
                        <td class="px-5 py-5 text-center">
                            <?php if ($dr['status'] === DR_PENDING): ?>
                                <span class="bg-amber-50 text-amber-600 text-[9px] font-black px-3 py-1 rounded-full border border-amber-100 uppercase">Pending</span>
                            <?php elseif ($dr['status'] === DR_APPROVED): ?>
                                <span class="bg-emerald-50 text-emerald-600 text-[9px] font-black px-3 py-1 rounded-full border border-emerald-100 uppercase">Approved</span>
                            <?php else: ?>
                                <span class="bg-rose-50 text-rose-500 text-[9px] font-black px-3 py-1 rounded-full border border-rose-100 uppercase">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-8 py-5 text-right">
                            <?php if ($dr['status'] === DR_PENDING): ?>
                                <button onclick="openDRReview(<?= $dr['id'] ?>)"
                                    class="bg-rose-600 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase hover:bg-rose-700 transition-all shadow-sm">Review</button>
                            <?php elseif ($dr['status'] === DR_APPROVED): ?>
                                <a href="../procurement/delivery_return_ticket.php?id=<?= $dr['id'] ?>"
                                   class="bg-emerald-500 text-white px-4 py-2 rounded-xl font-black text-[9px] uppercase hover:bg-emerald-600 transition-all shadow-sm inline-block">View Ticket</a>
                            <?php else: ?>
                                <span class="text-slate-200 text-xs font-bold">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ── Sales refund reject modal ──────────────────────────────────────────── -->
<div id="srRejectModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[200] hidden items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 animate-in">
        <h3 class="text-xl font-black text-slate-800 mb-1">Reject Refund Request</h3>
        <p id="srRejectLabel" class="text-slate-400 text-sm mb-6"></p>
        <form id="srRejectForm" method="POST" action="refund_approve.php">
            <input type="hidden" name="action"    value="reject">
            <input type="hidden" name="refund_id" id="srRejectId">
            <div class="mb-5">
                <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Rejection Reason <span class="text-rose-500">*</span></label>
                <textarea name="note" id="srRejectNote" rows="3" required placeholder="State the reason..."
                          class="input-modern w-full resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeSrReject()" class="flex-1 bg-slate-100 text-slate-600 font-black py-4 rounded-2xl hover:bg-slate-200 transition-all text-sm uppercase">Cancel</button>
                <button type="submit" class="flex-1 bg-rose-500 text-white font-black py-4 rounded-2xl hover:bg-rose-600 transition-all shadow-lg text-sm uppercase">Reject Request</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Superadmin override modal ──────────────────────────────────────────── -->
<?php if ($is_super): ?>
<div id="srOverrideModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-[200] hidden items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 animate-in">
        <div class="flex items-center gap-3 mb-1">
            <span class="bg-rose-100 text-rose-500 text-xs font-black px-3 py-1 rounded-full uppercase">★ Super Admin</span>
            <h3 class="text-xl font-black text-slate-800">Override Rejection</h3>
        </div>
        <p id="srOverrideLabel" class="text-slate-400 text-sm mb-6"></p>
        <form id="srOverrideForm" method="POST" action="refund_approve.php">
            <input type="hidden" name="action"    value="override">
            <input type="hidden" name="refund_id" id="srOverrideId">
            <div class="mb-5">
                <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2">Override Note <span class="text-slate-400 font-normal">(optional)</span></label>
                <textarea name="note" id="srOverrideNote" rows="3" placeholder="Add a note for this override..."
                          class="input-modern w-full resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeSrOverride()" class="flex-1 bg-slate-100 text-slate-600 font-black py-4 rounded-2xl hover:bg-slate-200 transition-all text-sm uppercase">Cancel</button>
                <button type="submit" class="flex-1 bg-amber-500 text-white font-black py-4 rounded-2xl hover:bg-amber-600 transition-all shadow-lg text-sm uppercase">Override & Approve</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── Delivery return review modal ──────────────────────────────────────── -->
<div id="drReviewModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[200] hidden items-center justify-center p-4">
    <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-2xl mx-4 animate-in flex flex-col" style="max-height:90vh;">
        <div class="p-8 bg-rose-600 text-white rounded-t-[2.5rem] flex-shrink-0">
            <p class="text-[10px] font-black text-rose-200 uppercase tracking-widest mb-1">Delivery Return Request</p>
            <h4 id="dr-modal-title" class="text-2xl font-black"></h4>
            <p id="dr-modal-supplier" class="text-rose-200 text-sm font-bold mt-1"></p>
        </div>
        <div class="flex-1 overflow-y-auto p-8 space-y-6">
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Purpose of Return</p>
                <p id="dr-modal-purpose" class="text-slate-700 font-medium bg-slate-50 rounded-2xl p-4 text-sm leading-relaxed"></p>
            </div>
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">Items to Return</p>
                <div class="bg-slate-50 rounded-2xl overflow-hidden border border-slate-100">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200">
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase">Product</th>
                                <th class="px-4 py-3 text-[10px] font-black text-slate-400 uppercase text-center">Qty</th>
                                <th class="px-4 py-3 text-[10px] font-black text-slate-400 uppercase">Reason</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody id="dr-modal-items" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
            <div class="flex gap-4 flex-wrap">
                <div class="bg-slate-50 rounded-2xl p-4 flex-1 min-w-[140px]">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Submitted By</p>
                    <p id="dr-modal-submitter" class="font-bold text-slate-700 text-sm"></p>
                    <p id="dr-modal-date" class="text-slate-400 text-[10px] mt-0.5"></p>
                </div>
                <div class="bg-rose-50 rounded-2xl p-4 flex-1 min-w-[140px] border border-rose-100">
                    <p class="text-[9px] font-black text-rose-400 uppercase tracking-widest mb-1">Deduct from Balance</p>
                    <p id="dr-modal-deduct" class="font-bold text-rose-600 text-sm"></p>
                </div>
            </div>
        </div>
        <div class="p-6 border-t border-slate-100 flex-shrink-0">
            <div id="dr-modal-reject-area" class="mb-4 hidden">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Rejection Reason <span class="text-rose-500">*</span></label>
                <textarea id="dr-modal-reject-reason" rows="2" class="input-modern w-full resize-none text-sm" placeholder="Why is this request being rejected?"></textarea>
            </div>
            <div class="flex gap-3">
                <button onclick="closeDRReview()" class="flex-1 bg-slate-100 text-slate-600 font-black py-3.5 rounded-2xl hover:bg-slate-200 transition-all text-xs uppercase">Cancel</button>
                <button id="dr-modal-reject-btn" onclick="toggleDRRejectArea()"
                    class="flex-1 border-2 border-rose-200 text-rose-500 font-black py-3.5 rounded-2xl hover:bg-rose-50 transition-all text-xs uppercase">Reject</button>
                <button id="dr-modal-approve-btn" onclick="confirmDRAction('approve')"
                    class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white font-black py-3.5 rounded-2xl shadow-lg transition-all text-xs uppercase active:scale-95">Approve &amp; Generate Ticket</button>
            </div>
        </div>
    </div>
</div>

<form id="dr-action-form" method="POST" action="../procurement/delivery_return_approve.php" class="hidden">
    <input type="hidden" name="request_id"    id="da-req-id">
    <input type="hidden" name="action"        id="da-action">
    <input type="hidden" name="reject_reason" id="da-reason">
</form>

<script>
// ── Sales refund actions ──────────────────────────────────────────────────────
async function srApprove(id, name, amount) {
    const ok = await customConfirm(`Refund of ₱${amount} for "${name}" will be approved.`, 'Approve Refund?');
    if (!ok) return;
    navigate('refund_approve.php', new FormData(document.getElementById('srf_' + id)));
}

function srOpenReject(id, name) {
    document.getElementById('srRejectId').value      = id;
    document.getElementById('srRejectLabel').textContent = 'Refund request for: ' + name;
    document.getElementById('srRejectNote').value    = '';
    const m = document.getElementById('srRejectModal');
    m.classList.remove('hidden'); m.classList.add('flex');
    setTimeout(() => document.getElementById('srRejectNote').focus(), 80);
}
function closeSrReject() {
    const m = document.getElementById('srRejectModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

<?php if ($is_super): ?>
function srOverride(id, name) {
    document.getElementById('srOverrideId').value      = id;
    document.getElementById('srOverrideLabel').textContent = 'Overriding rejected refund for: ' + name;
    document.getElementById('srOverrideNote').value   = '';
    const m = document.getElementById('srOverrideModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeSrOverride() {
    const m = document.getElementById('srOverrideModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
<?php endif; ?>

// ── DR review modal ───────────────────────────────────────────────────────────
const DR_DATA = <?= json_encode($dr_items) ?>;
let _activeReqId = null;
let _rejectAreaVisible = false;

function openDRReview(id) {
    const req = DR_DATA.find(r => r.id == id);
    if (!req) return;
    _activeReqId = id;
    _rejectAreaVisible = false;

    document.getElementById('dr-modal-title').textContent     = 'Invoice: ' + req.invoice_no;
    document.getElementById('dr-modal-supplier').textContent  = req.supplier_name || '—';
    document.getElementById('dr-modal-purpose').textContent   = req.purpose || '—';
    document.getElementById('dr-modal-submitter').textContent = req.requested_username || '—';
    document.getElementById('dr-modal-date').textContent      = req.created_at ? req.created_at.substring(0, 16).replace('T', ' ') : '';
    document.getElementById('dr-modal-deduct').textContent    = req.deduct_pay == 1 ? 'Yes — deduct from unpaid balance' : 'No';

    const tbody = document.getElementById('dr-modal-items');
    tbody.innerHTML = (req.items || []).map(item => {
        const val = (parseFloat(item.unit_price || 0) * parseInt(item.qty || 0)).toFixed(2);
        return '<tr>' +
            '<td class="px-5 py-3 font-bold text-slate-700">' + esc(item.product_name || '—') + '</td>' +
            '<td class="px-4 py-3 text-center font-black text-slate-800">' + item.qty + '</td>' +
            '<td class="px-4 py-3 text-slate-500 text-xs font-bold">' + esc(item.reason || '—') + '</td>' +
            '<td class="px-5 py-3 text-right font-black text-slate-800">₱' + val + '</td></tr>';
    }).join('') || '<tr><td colspan="4" class="px-5 py-4 text-slate-300 text-center font-bold">No items</td></tr>';

    document.getElementById('dr-modal-reject-area').classList.add('hidden');
    document.getElementById('dr-modal-reject-reason').value = '';
    document.getElementById('dr-modal-reject-btn').textContent = 'Reject';
    const approveBtn = document.getElementById('dr-modal-approve-btn');
    approveBtn.textContent = 'Approve & Generate Ticket';
    approveBtn.className = approveBtn.className.replace('bg-rose-600 hover:bg-rose-500', 'bg-emerald-600 hover:bg-emerald-500');
    approveBtn.onclick = () => confirmDRAction('approve');

    const m = document.getElementById('drReviewModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}

function closeDRReview() {
    const m = document.getElementById('drReviewModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

function toggleDRRejectArea() {
    _rejectAreaVisible = !_rejectAreaVisible;
    document.getElementById('dr-modal-reject-area').classList.toggle('hidden', !_rejectAreaVisible);
    const rejectBtn  = document.getElementById('dr-modal-reject-btn');
    const approveBtn = document.getElementById('dr-modal-approve-btn');
    if (_rejectAreaVisible) {
        rejectBtn.textContent  = 'Back';
        approveBtn.textContent = 'Confirm Reject';
        approveBtn.className   = approveBtn.className.replace('bg-emerald-600 hover:bg-emerald-500', 'bg-rose-600 hover:bg-rose-500');
        approveBtn.onclick     = () => confirmDRAction('reject');
    } else {
        rejectBtn.textContent  = 'Reject';
        approveBtn.textContent = 'Approve & Generate Ticket';
        approveBtn.className   = approveBtn.className.replace('bg-rose-600 hover:bg-rose-500', 'bg-emerald-600 hover:bg-emerald-500');
        approveBtn.onclick     = () => confirmDRAction('approve');
    }
}

async function confirmDRAction(action) {
    if (action === 'reject') {
        const reason = document.getElementById('dr-modal-reject-reason').value.trim();
        if (!reason) { alert('Please provide a rejection reason.'); return; }
        const ok = await customConfirm('This request will be rejected.', 'Reject Return Request?');
        if (!ok) return;
        document.getElementById('da-req-id').value = _activeReqId;
        document.getElementById('da-action').value = 'reject';
        document.getElementById('da-reason').value = reason;
    } else {
        const ok = await customConfirm('This will approve the return and generate a formal ticket. Stock will be deducted immediately.', 'Approve & Generate Ticket?');
        if (!ok) return;
        document.getElementById('da-req-id').value = _activeReqId;
        document.getElementById('da-action').value = 'approve';
        document.getElementById('da-reason').value = '';
    }
    navigate('../procurement/delivery_return_approve.php', new FormData(document.getElementById('dr-action-form')));
    closeDRReview();
}

function esc(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

// Close modals on backdrop click / ESC
['srRejectModal', 'srOverrideModal', 'drReviewModal'].forEach(id => {
    const m = document.getElementById(id);
    if (!m) return;
    m.addEventListener('click', e => { if (e.target === m) { m.classList.add('hidden'); m.classList.remove('flex'); } });
});
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    ['srRejectModal', 'srOverrideModal', 'drReviewModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m) { m.classList.add('hidden'); m.classList.remove('flex'); }
    });
});
</script>

<?php include '../layout_bottom.php'; ?>
