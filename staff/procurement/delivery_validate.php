<?php
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$msg          = '';
$total_queued = 0;

// ── VALIDATE ALL ITEMS IN QUEUE ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_all'])) {
    $items = $conn->query("SELECT * FROM delivery_queue WHERE status = '" . DEL_PENDING . "'");

    if ($items && $items->num_rows > 0) {
        $total_processed = 0;

        while ($row = $items->fetch_assoc()) {
            $pid  = intval($row['product_id']);
            $qty  = intval($row['qty']);
            $cost = floatval($row['cost']);

            // Preserve existing selling price — only update cost and quantity.
            // If selling price is 0 (new product), seed it from the delivery cost.
            $update = $conn->prepare("
                UPDATE products
                   SET last_buy_cost = cost_price,
                       cost_price    = ?,
                       price         = CASE WHEN COALESCE(price, 0) = 0 THEN ? ELSE price END,
                       quantity      = COALESCE(quantity, 0) + ?
                 WHERE id = ?
            ");
            $update->bind_param("ddii", $cost, $cost, $qty, $pid);
            $update->execute();
            $total_processed++;
        }

        $conn->query("UPDATE delivery_queue SET status = '" . DEL_VALIDATED . "' WHERE status = '" . DEL_PENDING . "'");

        $log_msg = "Stock Validation Complete: {$total_processed} items added to Inventory.";
        $log_ins = $conn->prepare("INSERT INTO admin_notifications (type, message) VALUES ('DELIVERY', ?)");
        if ($log_ins) { $log_ins->bind_param("s", $log_msg); $log_ins->execute(); }

        $msg = "<div class='bg-emerald-500 text-white p-6 rounded-[2rem] mb-10 shadow-xl font-black text-center text-xl animate-bounce'>INVENTORY UPDATED: {$total_processed} ITEMS ADDED!</div>";
    }
}

// ── LOAD PENDING QUEUE ────────────────────────────────────────────────────────
$queue = $conn->query("
    SELECT q.*, p.name AS product_name, p.barcode
      FROM delivery_queue q
      JOIN products p ON q.product_id = p.id
     WHERE q.status = '" . DEL_PENDING . "'
     ORDER BY q.id DESC
");
$total_queued = $queue ? $queue->num_rows : 0;

include '../layout_top.php';
?>

<div class="max-w-5xl mx-auto space-y-10 pb-20">
    <?php if (!empty($msg)) echo $msg; ?>

    <!-- HEADER CARD -->
    <div class="bg-emerald-600 p-10 rounded-[3.5rem] shadow-2xl shadow-emerald-100 flex flex-col md:flex-row justify-between items-center gap-8 text-white relative overflow-hidden">
        <div class="relative z-10">
            <h2 class="serif-title text-4xl font-black tracking-tight leading-none mb-2">Stock Validation</h2>
            <p class="text-emerald-100 font-bold text-sm uppercase tracking-widest opacity-80 italic">Review batch before adding to official inventory</p>
        </div>

        <?php if ($total_queued > 0): ?>
            <form method="POST" class="relative z-10">
                <button type="submit" name="validate_all"
                    onclick="confirmForm(event, this.closest('form'), 'These items will be officially added to inventory.', 'Validate Batch?'); return false;"
                    class="bg-amber-400 text-emerald-900 px-12 py-5 rounded-[2rem] font-black shadow-xl hover:bg-white hover:scale-105 transition-all text-lg uppercase tracking-tighter">
                    Validate &amp; Add to Inventory
                </button>
            </form>
        <?php else: ?>
            <div class="bg-emerald-700/50 px-8 py-4 rounded-full font-bold italic text-emerald-200 border border-emerald-500/30">The queue is currently empty</div>
        <?php endif; ?>

        <div class="absolute -right-10 -bottom-10 w-40 h-40 bg-white/10 rounded-full"></div>
    </div>

    <!-- PENDING ITEMS LIST -->
    <div class="grid grid-cols-1 gap-4">
        <?php if ($total_queued > 0): ?>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] ml-8 mb-2">Items Awaiting Approval (<?= $total_queued ?>)</p>
            <?php while ($q = $queue->fetch_assoc()): ?>
                <div class="bg-white p-8 rounded-[2.5rem] border border-slate-100 flex justify-between items-center shadow-sm hover:shadow-md transition-all group">
                    <div class="flex items-center gap-6">
                        <div class="w-14 h-14 bg-slate-50 rounded-2xl flex items-center justify-center text-slate-300 group-hover:bg-emerald-50 group-hover:text-emerald-500 transition-colors text-xl font-bold italic">
                            <?= substr($q['product_name'], 0, 1) ?>
                        </div>
                        <div>
                            <h4 class="font-black text-slate-800 text-xl leading-tight"><?= htmlspecialchars($q['product_name']) ?></h4>
                            <div class="flex items-center gap-3 mt-1">
                                <span class="text-[10px] font-bold text-emerald-500 uppercase tracking-widest">Pending Verification</span>
                                <span class="text-[10px] font-mono text-slate-300">ID: <?= htmlspecialchars($q['barcode']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-black text-emerald-600 tracking-tighter">+<?= number_format($q['qty']) ?> <span class="text-xs uppercase tracking-normal">units</span></p>
                        <p class="text-[10px] font-bold text-slate-300 uppercase tracking-widest mt-1">Cost: ₱<?= number_format($q['cost'], 2) ?></p>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="bg-white border border-slate-100 rounded-[3rem] p-24 text-center shadow-sm">
                <div class="w-20 h-20 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-6 text-slate-200">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <p class="text-slate-300 font-black text-xl italic tracking-tight uppercase">Validation Queue Empty</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
