<?php
/**
 * exchange_receipt.php — Confirmation screen after a successful exchange.
 */
include '../../config/db.php';
include '../layout_top.php';

if (empty($_SESSION['exchange_done'])) {
    header("Location: ../sales/returns_exchange.php");
    exit();
}

$ex = $_SESSION['exchange_done'];
unset($_SESSION['exchange_done']);

$delta_type   = $ex['delta_type'];
$delta_amount = floatval($ex['delta_amount']);
?>

<div class="max-w-lg mx-auto pt-16 pb-20 animate-in text-center">
    <div class="card-modern shadow-2xl p-12 space-y-6">
        <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto">
            <svg class="w-10 h-10 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
        </div>

        <div>
            <h2 class="serif-title text-3xl font-bold text-slate-800">Exchange Complete</h2>
            <p class="text-slate-400 text-sm font-bold mt-2">Items have been swapped successfully.</p>
        </div>

        <div class="bg-slate-50 rounded-3xl p-6 text-left space-y-3">
            <div class="flex justify-between text-sm">
                <span class="text-slate-400 font-bold">Exchange No.</span>
                <span class="font-black text-slate-700"><?= htmlspecialchars($ex['exchange_no']) ?></span>
            </div>
            <div class="flex justify-between text-sm">
                <span class="text-slate-400 font-bold">Original Receipt</span>
                <span class="font-black text-slate-700"><?= htmlspecialchars($ex['receipt_no']) ?></span>
            </div>
            <div class="flex justify-between text-sm border-t border-slate-200 pt-3">
                <span class="text-slate-400 font-bold">Delta</span>
                <?php if ($delta_type === 'none' || $delta_amount == 0): ?>
                    <span class="font-black text-emerald-600">Even — No Payment</span>
                <?php elseif ($delta_type === 'collect'): ?>
                    <span class="font-black text-amber-600">Collected ₱<?= number_format($delta_amount, 2) ?></span>
                <?php else: ?>
                    <span class="font-black text-blue-600">Refunded ₱<?= number_format($delta_amount, 2) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex gap-4 pt-4">
            <a href="exchange.php" class="flex-1 btn-secondary py-4 text-sm font-black uppercase tracking-widest rounded-2xl text-center">New Exchange</a>
            <a href="pos.php" class="flex-1 btn-pos-primary py-4 text-sm uppercase tracking-widest rounded-2xl text-center">Back to POS</a>
        </div>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
