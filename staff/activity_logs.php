<?php
include '../config/db.php';
include '../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id     = $_SESSION['user_id'] ?? null;
$current_tab = $_GET['tab'] ?? 'all';
$start_date  = $_GET['start_date'] ?? '';
$end_date    = $_GET['end_date']   ?? '';

include 'layout_top.php';
?>

<style>
    .price-history-dropdown {
        background: #f8fafc; border-radius: 12px; padding: 10px; margin-top: 10px;
        border: 1px solid #e2e8f0; display: none;
    }
    .history-btn { font-size: 10px; color: #64748b; font-weight: 800; }
    .history-btn:hover { color: #00a651; }
</style>

<div class="max-w-7xl mx-auto space-y-6 animate-in pb-20">

    <!-- ── HEADER & DATE FILTER ───────────────────────────────────────────────── -->
    <div class="bg-white p-6 rounded-[2.5rem] border border-slate-100 shadow-xl flex flex-col md:flex-row justify-between items-center gap-4">
        <div class="text-left">
            <h3 class="serif-title text-2xl font-black text-slate-800">Activity Center</h3>
            <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">Business Audit Logs</p>
        </div>

        <form method="GET" action="activity_logs.php" class="flex gap-2 items-center">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($current_tab) ?>">
            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="input-modern h-10 text-xs px-4 bg-slate-50 w-36">
            <span class="text-slate-300 font-bold">to</span>
            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="input-modern h-10 text-xs px-4 bg-slate-50 w-36">
            <button type="submit" class="bg-slate-900 text-white px-5 h-10 rounded-xl font-black text-[10px] uppercase transition-all">Go</button>
        </form>
    </div>

    <!-- ── TAB NAVIGATION ────────────────────────────────────────────────────── -->
    <div class="flex gap-2 overflow-x-auto no-scrollbar">
        <?php
        $tabs = ['all' => 'All', 'sales' => 'Sales', 'deliveries' => 'Shipments', 'prices' => 'Prices', 'refunds' => 'Refunds'];
        foreach ($tabs as $key => $label):
            $isActive = ($current_tab === $key);
        ?>
            <a href="activity_logs.php?tab=<?= $key ?>"
               class="text-[11px] px-5 py-2.5 rounded-full font-black uppercase tracking-widest transition-all
               <?= $isActive ? 'bg-emerald-500 text-white shadow-md' : 'bg-white text-slate-400 border border-slate-100' ?>">
               <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- ── LOG FEED ───────────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-2xl overflow-hidden">
        <div class="divide-y divide-slate-50">
            <?php if ($current_tab === 'prices'): ?>

                <!-- Prices tab: grouped by product with expandable history rows -->
                <?php
                $p_res = $conn->query("
                    SELECT p.id, p.name, p.barcode
                      FROM products p
                      INNER JOIN price_history ph ON p.id = ph.product_id
                     GROUP BY p.id
                     ORDER BY p.name ASC
                ");
                if ($p_res && $p_res->num_rows > 0):
                    while ($prod = $p_res->fetch_assoc()): ?>
                    <div class="p-6 hover:bg-slate-50 transition-all">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-purple-100 text-purple-600 rounded-xl flex items-center justify-center font-bold">P</div>
                                <div>
                                    <h5 class="font-bold text-slate-700 text-sm"><?= htmlspecialchars($prod['name']) ?></h5>
                                    <code class="text-[9px] text-slate-400 uppercase"><?= htmlspecialchars($prod['barcode']) ?></code>
                                </div>
                            </div>
                            <button onclick="togglePriceHistory(<?= intval($prod['id']) ?>)" class="history-btn uppercase tracking-widest">
                                [ View History ]
                            </button>
                        </div>

                        <div id="hist_<?= intval($prod['id']) ?>" class="price-history-dropdown">
                            <?php
                            $hq = $conn->prepare("SELECT * FROM price_history WHERE product_id = ? ORDER BY id DESC");
                            $hq->bind_param("i", $prod['id']); $hq->execute();
                            $h_res = $hq->get_result();
                            while ($h = $h_res->fetch_assoc()): ?>
                                <div class="flex justify-between items-center py-2 border-b border-slate-200/50 last:border-0">
                                    <span class="text-slate-400 text-[10px] font-black uppercase">
                                        <?= date('d-m-Y', strtotime($h['change_date'])) ?>
                                    </span>
                                    <div class="text-[11px] font-bold">
                                        <span class="text-slate-300">₱<?= number_format($h['old_price'], 2) ?></span>
                                        <span class="text-slate-400 mx-2">→</span>
                                        <span class="text-emerald-600 font-black">₱<?= number_format($h['new_price'], 2) ?></span>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    <?php endwhile;
                else: ?>
                    <p class="p-10 text-center text-slate-300 font-bold uppercase text-xs">No price history.</p>
                <?php endif; ?>

            <?php else: ?>

                <!-- All other tabs: chronological activity log list -->
                <?php
                // Map tab keys to log_type constants
                $tab_map = [
                    'sales'      => LOG_SALES,
                    'deliveries' => LOG_DELIVERIES,
                    'refunds'    => LOG_DISPOSAL,
                ];

                $params      = [];
                $param_types = '';
                $where       = ' WHERE 1=1';

                if (isset($tab_map[$current_tab])) {
                    $where        .= ' AND log_type = ?';
                    $params[]      = $tab_map[$current_tab];
                    $param_types  .= 's';
                }

                // Date bounds validated as YYYY-MM-DD to prevent injection
                if ($start_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
                    $where        .= ' AND DATE(created_at) >= ?';
                    $params[]      = $start_date;
                    $param_types  .= 's';
                }
                if ($end_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                    $where        .= ' AND DATE(created_at) <= ?';
                    $params[]      = $end_date;
                    $param_types  .= 's';
                }

                $sql  = "SELECT * FROM activity_logs" . $where . " ORDER BY id DESC";
                $stmt = $conn->prepare($sql);
                if (!empty($params)) {
                    $stmt->bind_param($param_types, ...$params);
                }
                $stmt->execute();
                $logs = $stmt->get_result();

                if ($logs && $logs->num_rows > 0):
                    while ($l = $logs->fetch_assoc()): ?>
                    <div class="p-6 hover:bg-slate-50 transition-all flex items-center gap-6 group">
                        <div class="w-24 flex-shrink-0 text-[10px] font-black text-slate-300 uppercase leading-tight">
                            <?= date("d-m-Y", strtotime($l['created_at'])) ?><br>
                            <span class="text-slate-200"><?= date("h:i A", strtotime($l['created_at'])) ?></span>
                        </div>
                        <div class="flex-1">
                            <span class="text-[8px] font-black uppercase px-2 py-0.5 rounded bg-slate-100 text-slate-500 mb-1 inline-block"><?= htmlspecialchars($l['log_type']) ?></span>
                            <p class="text-slate-600 font-bold text-sm leading-snug">"<?= htmlspecialchars($l['message']) ?>"</p>
                        </div>
                    </div>
                    <?php endwhile;
                else: ?>
                    <p class="p-10 text-center text-slate-300 font-bold uppercase text-xs">Category Empty</p>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function togglePriceHistory(prodId) {
    const el = document.getElementById('hist_' + prodId);
    el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}
</script>

<?php include 'layout_bottom.php'; ?>
