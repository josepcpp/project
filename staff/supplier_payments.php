<?php
include '../includes/auth_check.php';
include '../config/db.php';

// ── DATA ──────────────────────────────────────────────────────────────────────
$suppliers = $conn->query("SELECT id, name FROM suppliers ORDER BY name ASC");
$payments  = $conn->query("
    SELECT sp.*, s.name AS supplier
      FROM supplier_payments sp
      JOIN suppliers s ON s.id = sp.supplier_id
     ORDER BY sp.id DESC
");

include 'layout_top.php';
?>

<div class="max-w-5xl mx-auto pb-20 animate-in space-y-8">

    <!-- ── ADD PAYMENT FORM ──────────────────────────────────────────────────── -->
    <div class="card-modern">
        <h3 class="serif-title text-2xl font-bold text-slate-800 mb-6">Record Supplier Payment</h3>
        <form method="POST" action="supplier_payments_process.php" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="label-modern">Supplier</label>
                <select name="supplier_id" required class="input-modern">
                    <?php while ($s = $suppliers->fetch_assoc()): ?>
                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div>
                <label class="label-modern">Invoice Number</label>
                <input type="text" name="invoice_no" placeholder="Invoice #" required class="input-modern">
            </div>
            <div>
                <label class="label-modern">Amount (₱)</label>
                <input type="number" name="amount" step="0.01" placeholder="0.00" required class="input-modern">
            </div>
            <div>
                <label class="label-modern">Status</label>
                <select name="status" class="input-modern">
                    <option value="<?= SUP_PAY_UNPAID ?>">Unpaid</option>
                    <option value="<?= SUP_PAY_PAID ?>">Paid</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="btn-pos-primary w-full">Save Payment</button>
            </div>
        </form>
    </div>

    <!-- ── PAYMENTS TABLE ────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 bg-slate-50 border-b border-slate-100">
            <h4 class="font-black text-slate-400 text-xs uppercase tracking-widest">Supplier Payment Ledger</h4>
        </div>
        <table class="table-modern w-full text-left">
            <thead>
                <tr>
                    <th class="px-8 py-5">Invoice</th>
                    <th class="px-6 py-5">Supplier</th>
                    <th class="px-6 py-5 text-right">Amount</th>
                    <th class="px-6 py-5 text-center">Status</th>
                    <th class="px-8 py-5 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php while ($p = $payments->fetch_assoc()): ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-8 py-4 font-mono font-bold text-blue-600"><?= htmlspecialchars($p['invoice_no']) ?></td>
                    <td class="px-6 py-4 font-bold text-slate-700"><?= htmlspecialchars($p['supplier']) ?></td>
                    <td class="px-6 py-4 text-right font-black text-slate-800">₱<?= number_format($p['amount'], 2) ?></td>
                    <td class="px-6 py-4 text-center">
                        <?php if ($p['status'] === SUP_PAY_PAID): ?>
                            <span class="px-3 py-1 bg-emerald-50 text-emerald-600 border border-emerald-100 rounded-full text-[9px] font-black uppercase">Paid</span>
                        <?php else: ?>
                            <span class="px-3 py-1 bg-amber-50 text-amber-600 border border-amber-100 rounded-full text-[9px] font-black uppercase">Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-8 py-4 text-right">
                        <form method="POST" action="supplier_payments_process.php" class="inline">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button name="toggle" class="text-[10px] font-black text-slate-300 hover:text-emerald-500 transition-colors uppercase tracking-widest">Toggle Status</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'layout_bottom.php'; ?>
