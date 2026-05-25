<?php 
include '../layout_top.php';
$r = $_SESSION['receipt'] ?? null;

if (!$r) {
    echo "<script>window.location.href='pos.php';</script>";
    exit();
}
?>

<div class="max-w-md mx-auto pt-10 pb-20">
    <!-- Success Icon -->
    <div class="flex justify-center mb-8">
        <div class="w-20 h-20 bg-emerald-100 text-emerald-500 rounded-full flex items-center justify-center shadow-lg shadow-emerald-50 border-4 border-white">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
            </svg>
        </div>
    </div>

    <!-- The Receipt Paper Card -->
    <div class="bg-white rounded-3xl shadow-2xl border border-slate-100 overflow-hidden relative p-10 text-center" id="printableReceipt">
        
        <!-- Store Header -->
        <h2 class="serif-title text-2xl font-black text-slate-800">Cynthia Bersabe</h2>
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] mb-8">Grocery Store & POS System</p>
        
        <div class="space-y-1 mb-8">
            <p class="text-[10px] font-bold text-slate-400">RECEIPT NUMBER</p>
            <p class="font-mono font-bold text-slate-700"><?= $r['no'] ?></p>
            <p class="text-[10px] text-slate-300 font-medium"><?= $r['date'] ?></p>
        </div>

        <hr class="border-dashed border-slate-200 mb-8">

        <!-- Values -->
        <div class="space-y-6">
            <div class="flex justify-between items-center">
                <span class="text-slate-400 font-bold uppercase text-[10px] tracking-widest">Total Paid</span>
                <span class="text-2xl font-black text-slate-800 tracking-tighter">₱<?= number_format($r['total'], 2) ?></span>
            </div>
            <div class="flex justify-between items-center text-slate-500 font-medium">
                <span class="text-[10px] uppercase tracking-widest">Cash Received</span>
                <span class="text-sm font-bold">₱<?= number_format($r['cash'], 2) ?></span>
            </div>
            <div class="flex justify-between items-center py-4 bg-emerald-50 px-4 rounded-2xl border border-emerald-100">
                <span class="text-emerald-600 font-black uppercase text-[10px] tracking-widest">Change</span>
                <span class="text-2xl font-black text-emerald-700 tracking-tighter">₱<?= number_format($r['change'], 2) ?></span>
            </div>
        </div>

        <div class="mt-12">
            <p class="serif-title text-lg font-bold text-slate-800 italic">Thank you for shopping!</p>
            <p class="text-[10px] text-slate-300 mt-1">Please keep this for your records.</p>
        </div>

        <!-- Scalloped edge visual (Optional CSS trick) -->
        <div class="absolute bottom-0 left-0 right-0 h-2 bg-[url('https://www.transparenttextures.com/patterns/white-diamond.png')] opacity-10"></div>
    </div>

    <!-- Actions -->
    <div class="mt-10 grid grid-cols-2 gap-4 no-print">
        <button onclick="window.print()" class="bg-white border-2 border-slate-200 text-slate-700 font-black py-4 rounded-2xl hover:bg-slate-50 transition-all flex items-center justify-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2-2v4h10z" />
            </svg>
            Print
        </button>
        <a href="pos.php" class="bg-emerald-500 hover:bg-emerald-600 text-white font-black py-4 rounded-2xl shadow-lg shadow-emerald-100 transition-all flex items-center justify-center gap-2">
            Next Sale
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6" />
            </svg>
        </a>
    </div>
</div>

<style>
@media print {
    .no-print, aside, header, #loading-bar { display: none !important; }
    main { margin-left: 0 !important; padding: 0 !important; }
    body { background: white !important; }
    #printableReceipt { box-shadow: none !important; border: 1px solid #eee !important; margin: 0 auto; }
}
</style>

<?php include '../layout_bottom.php'; ?>