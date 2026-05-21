<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$id = intval($_GET['id'] ?? 0);
if (!$id) { die("Invalid ticket ID."); }

$rq = $conn->prepare("SELECT * FROM delivery_return_requests WHERE id = ? AND status = '" . DR_APPROVED . "' LIMIT 1");
$rq->bind_param("i", $id); $rq->execute();
$req = $rq->get_result()->fetch_assoc();

if (!$req) {
    header("Location: refund_management.php?tab=dr_queue");
    exit();
}

$its = $conn->prepare("SELECT * FROM delivery_return_request_items WHERE request_id = ? ORDER BY id ASC");
$its->bind_param("i", $id); $its->execute();
$items = $its->get_result()->fetch_all(MYSQLI_ASSOC);

$grand_total = array_sum(array_map(fn($i) => floatval($i['unit_price']) * intval($i['qty']), $items));

include 'layout_top.php';
?>

<style>
@media print {
    body { background: white !important; }
    .no-print { display: none !important; }
    .print-only { display: block !important; }
    #page-content { padding: 0 !important; }
    .ticket-wrapper { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
}
.print-only { display: none; }
</style>

<div class="max-w-3xl mx-auto pb-20 animate-in">

    <!-- Action bar -->
    <div class="flex items-center justify-between mb-6 no-print">
        <button onclick="navigate('refund_management.php?tab=dr_queue')"
            class="flex items-center gap-2 text-slate-400 hover:text-slate-700 font-black text-sm uppercase transition-all">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
            Back to Queue
        </button>
        <div class="flex gap-3">
            <button onclick="window.print()"
                class="flex items-center gap-2 bg-slate-900 text-white font-black px-6 py-3 rounded-2xl text-xs uppercase hover:bg-slate-700 transition-all shadow-lg">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print Ticket
            </button>
        </div>
    </div>

    <!-- Ticket card -->
    <div class="ticket-wrapper bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden">

        <!-- Header -->
        <div class="bg-slate-900 text-white p-10">
            <div class="flex justify-between items-start gap-6">
                <div>
                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-[0.2em] mb-2">Cynthia Bersabe Grocery</p>
                    <h2 class="serif-title text-3xl font-black text-white leading-none">Delivery Return Ticket</h2>
                    <p class="text-slate-400 font-bold text-sm mt-2">Official supplier return authorization</p>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest mb-1">Ticket No.</p>
                    <p class="font-mono font-black text-emerald-400 text-xl"><?= htmlspecialchars($req['ticket_no']) ?></p>
                    <p class="text-slate-500 text-xs font-bold mt-2"><?= date('F d, Y', strtotime($req['reviewed_at'])) ?></p>
                    <p class="text-slate-600 text-xs font-bold"><?= date('g:i A', strtotime($req['reviewed_at'])) ?></p>
                </div>
            </div>
        </div>

        <!-- Info grid -->
        <div class="grid grid-cols-2 divide-x divide-y divide-slate-50 border-b border-slate-100">
            <div class="p-7">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Supplier</p>
                <p class="font-black text-slate-800 text-lg leading-snug"><?= htmlspecialchars($req['supplier_name'] ?? '—') ?></p>
            </div>
            <div class="p-7">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Original Invoice</p>
                <p class="font-mono font-black text-slate-800 text-lg"><?= htmlspecialchars($req['invoice_no']) ?></p>
            </div>
            <div class="p-7">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Requested By</p>
                <p class="font-bold text-slate-700"><?= htmlspecialchars($req['requested_username'] ?? '—') ?></p>
                <p class="text-slate-400 text-xs font-bold mt-0.5"><?= date('M d, Y · g:i A', strtotime($req['created_at'])) ?></p>
            </div>
            <div class="p-7">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Approved By</p>
                <p class="font-bold text-slate-700"><?= htmlspecialchars($req['reviewed_username'] ?? '—') ?></p>
                <p class="text-slate-400 text-xs font-bold mt-0.5"><?= date('M d, Y · g:i A', strtotime($req['reviewed_at'])) ?></p>
            </div>
        </div>

        <!-- Purpose -->
        <div class="px-10 py-7 border-b border-slate-100 bg-slate-50/40">
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Purpose of Return</p>
            <p class="text-slate-700 font-medium text-sm leading-relaxed"><?= htmlspecialchars($req['purpose'] ?? '—') ?></p>
        </div>

        <!-- Items table -->
        <div class="px-10 py-8">
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-5">Items Being Returned</p>
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b-2 border-slate-100">
                        <th class="pb-4 text-[10px] font-black text-slate-400 uppercase tracking-widest w-1/2">Product</th>
                        <th class="pb-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Qty</th>
                        <th class="pb-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Reason</th>
                        <th class="pb-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Unit Price</th>
                        <th class="pb-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Total Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php foreach ($items as $item):
                        $line_val = floatval($item['unit_price']) * intval($item['qty']);
                    ?>
                    <tr>
                        <td class="py-4">
                            <p class="font-bold text-slate-800"><?= htmlspecialchars($item['product_name'] ?? '—') ?></p>
                        </td>
                        <td class="py-4 text-center font-black text-slate-800 text-lg"><?= intval($item['qty']) ?></td>
                        <td class="py-4">
                            <span class="bg-slate-100 text-slate-600 text-[10px] font-black px-3 py-1 rounded-full uppercase">
                                <?= htmlspecialchars($item['reason'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="py-4 text-right text-slate-500 font-bold">₱<?= number_format($item['unit_price'], 2) ?></td>
                        <td class="py-4 text-right font-black text-slate-800">₱<?= number_format($line_val, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-900">
                        <td colspan="3" class="pt-5 pb-2 font-black text-slate-500 text-xs uppercase tracking-widest">
                            <?= count($items) ?> item type(s) · <?= array_sum(array_column($items, 'qty')) ?> units total
                        </td>
                        <td class="pt-5 pb-2 text-right text-[10px] font-black text-slate-400 uppercase">Total Return Value</td>
                        <td class="pt-5 pb-2 text-right font-black text-slate-900 text-xl">₱<?= number_format($grand_total, 2) ?></td>
                    </tr>
                    <?php if ($req['deduct_pay']): ?>
                    <tr>
                        <td colspan="4" class="py-1 text-right text-[10px] font-black text-rose-500 uppercase">Deducted from Unpaid Balance</td>
                        <td class="py-1 text-right font-black text-rose-500">₱<?= number_format($grand_total, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>

        <!-- Signature area -->
        <div class="mx-10 mb-10 grid grid-cols-3 gap-6 border-t border-slate-100 pt-8">
            <div class="text-center">
                <div class="h-12 border-b-2 border-slate-300 mb-2"></div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Prepared By</p>
                <p class="text-[11px] font-bold text-slate-600 mt-1"><?= htmlspecialchars($req['requested_username'] ?? '') ?></p>
            </div>
            <div class="text-center">
                <div class="h-12 border-b-2 border-slate-300 mb-2"></div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Approved By</p>
                <p class="text-[11px] font-bold text-slate-600 mt-1"><?= htmlspecialchars($req['reviewed_username'] ?? '') ?></p>
            </div>
            <div class="text-center">
                <div class="h-12 border-b-2 border-slate-300 mb-2"></div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Received By (Supplier)</p>
                <p class="text-[11px] font-bold text-slate-300 mt-1">Signature over printed name</p>
            </div>
        </div>

        <!-- Footer stamp -->
        <div class="bg-slate-50 border-t border-slate-100 px-10 py-5 flex justify-between items-center">
            <p class="text-[9px] text-slate-400 font-bold">This ticket was system-generated and verified. Ticket: <?= htmlspecialchars($req['ticket_no']) ?></p>
            <span class="text-[9px] font-black text-emerald-500 bg-emerald-50 px-3 py-1 rounded-full border border-emerald-100 uppercase tracking-widest">Approved</span>
        </div>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>
