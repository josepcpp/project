<?php
include '../config/db.php';
include 'layout_top.php';

// ── DATA QUERIES ──────────────────────────────────────────────────────────────
$filter     = $_GET['filter'] ?? 'all';
$deliveries = null;
$vouchers   = null;

if ($filter !== 'vouchers') {
    $where = match($filter) {
        'pending'  => "WHERE d.status='" . DEL_PENDING . "'",
        'verified' => "WHERE d.status='" . DEL_VERIFIED . "'",
        default    => "",
    };
    $deliveries = $conn->query("
        SELECT d.*, s.name AS supplier
          FROM deliveries d
          JOIN suppliers s ON s.id = d.supplier_id
        {$where}
         ORDER BY d.id DESC
    ");
}

if ($filter === 'vouchers') {
    $vouchers = $conn->query("
        SELECT sp.*, s.name AS supplier_name, s.supplier_code
          FROM supplier_payments sp
          JOIN suppliers s ON s.id = sp.supplier_id
         ORDER BY sp.id DESC
    ");
}
?>

<div class="max-w-7xl mx-auto space-y-6 animate-in pb-20">

    <!-- ── TABS + ACTION ──────────────────────────────────────────────────────── -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm">
        <div class="flex gap-2 flex-wrap">
            <?php
            $tabs = [
                'all'      => 'All',
                'pending'  => 'Pending',
                'verified' => 'Verified',
                'vouchers' => 'Supply Vouchers',
            ];
            foreach ($tabs as $key => $label):
                $active = $filter === $key;
            ?>
            <a href="deliveries.php?filter=<?= $key ?>"
               class="px-6 py-2 rounded-xl text-sm font-bold transition-all
                      <?= $active
                          ? ($key === 'vouchers' ? 'bg-blue-600 text-white shadow-lg shadow-blue-100' : 'bg-emerald-500 text-white shadow-lg shadow-emerald-100')
                          : 'bg-slate-50 text-slate-500 hover:bg-slate-100' ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($filter === 'vouchers'): ?>
        <a href="suppliers.php"
           class="bg-slate-900 text-white px-8 py-3 rounded-xl font-black hover:bg-slate-700 transition-all shadow-xl shadow-slate-200 text-sm flex items-center gap-2 flex-shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
            </svg>
            New Delivery Arrival
        </a>
        <?php endif; ?>
    </div>

    <!-- ── DELIVERY LIST (All / Pending / Verified) ───────────────────────────── -->
    <?php if ($filter !== 'vouchers'): ?>
    <div class="bg-white rounded-[2.5rem] border border-slate-50 shadow-sm overflow-hidden">
        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-slate-50 bg-slate-50/30">
                    <th class="px-10 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest">Date Received</th>
                    <th class="px-6 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest">Supplier</th>
                    <th class="px-6 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest">Status</th>
                    <th class="px-10 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if ($deliveries && $deliveries->num_rows > 0): while ($d = $deliveries->fetch_assoc()): ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-10 py-6 font-bold text-slate-700"><?= date("M d, Y", strtotime($d['delivery_date'])) ?></td>
                    <td class="px-6 py-6 text-slate-600 font-medium"><?= htmlspecialchars($d['supplier']) ?></td>
                    <td class="px-6 py-6">
                        <?php if ($d['status'] === DEL_VERIFIED): ?>
                            <span class="px-4 py-1.5 bg-emerald-50 text-emerald-600 rounded-full text-[9px] font-black uppercase tracking-widest border border-emerald-100">Verified</span>
                        <?php else: ?>
                            <span class="px-4 py-1.5 bg-amber-50 text-amber-600 rounded-full text-[9px] font-black uppercase tracking-widest border border-amber-100">Pending Review</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-10 py-6 text-right">
                        <a href="delivery_view.php?id=<?= $d['id'] ?>" class="text-emerald-600 font-black text-xs hover:underline uppercase tracking-tighter">View Items &amp; Verify</a>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="4" class="p-16 text-center text-slate-300 font-black italic">No deliveries found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── SUPPLY VOUCHERS TABLE ──────────────────────────────────────────────── -->
    <?php if ($filter === 'vouchers'): ?>
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 bg-slate-50/50 border-b border-slate-100">
            <h4 class="font-black text-slate-800 text-[11px] uppercase tracking-widest">Supplier Voucher Ledger</h4>
            <p class="text-slate-400 text-[10px] font-bold mt-0.5">Invoice records from suppliers — register a new delivery arrival above</p>
        </div>
        <table class="w-full text-left">
            <thead>
                <tr class="bg-slate-50/30 border-b border-slate-100">
                    <th class="px-10 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest">Invoice #</th>
                    <th class="px-6 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest">Supplier</th>
                    <th class="px-6 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest text-center">Amount</th>
                    <th class="px-6 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest text-center">Payment</th>
                    <th class="px-10 py-6 text-[10px] font-black text-slate-300 uppercase tracking-widest text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if ($vouchers && $vouchers->num_rows > 0): while ($v = $vouchers->fetch_assoc()): ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-10 py-6 font-mono font-bold text-slate-400 text-sm"><?= htmlspecialchars($v['invoice_no']) ?></td>
                    <td class="px-6 py-6">
                        <p class="font-bold text-slate-700"><?= htmlspecialchars($v['supplier_name']) ?></p>
                        <span class="text-[9px] font-black text-slate-300">CODE: #<?= htmlspecialchars($v['supplier_code']) ?></span>
                    </td>
                    <td class="px-6 py-6 text-center font-black text-slate-800 text-lg tracking-tight">
                        ₱<?= number_format($v['amount'], 2) ?>
                    </td>
                    <td class="px-6 py-6 text-center">
                        <?php if ($v['status'] === SUP_PAY_PAID): ?>
                            <span class="px-4 py-1.5 bg-emerald-50 text-emerald-600 border border-emerald-100 rounded-lg text-[9px] font-black uppercase">Paid</span>
                        <?php else: ?>
                            <span class="px-4 py-1.5 bg-amber-50 text-amber-600 border border-amber-100 rounded-lg text-[9px] font-black uppercase">Unsettled</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-10 py-6 text-right">
                        <a href="payments.php" class="text-blue-500 font-black text-xs hover:underline uppercase tracking-tighter">
                            View Payment →
                        </a>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="5" class="p-16 text-center text-slate-300 font-black italic">No supply vouchers recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<?php include 'layout_bottom.php'; ?>
