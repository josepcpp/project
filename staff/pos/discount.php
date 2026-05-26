<?php
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$msg = '';

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_discount') {
        $name               = trim($_POST['name']);
        $promo_code         = strtoupper(trim($_POST['promo_code']));
        $type               = $_POST['type'];
        $val                = floatval($_POST['value']);
        $max_uses           = max(0, intval($_POST['max_uses'] ?? 0));
        $start_date         = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $end_date           = !empty($_POST['end_date'])   ? $_POST['end_date']   : null;
        $scope              = in_array($_POST['scope'] ?? '', ['store','product','category']) ? $_POST['scope'] : 'store';
        $target_product_id  = ($scope === 'product')  ? intval($_POST['target_product_id'] ?? 0) : null;
        $target_category    = ($scope === 'category') ? trim($_POST['target_category'] ?? '') : null;
        // F-09: conflict resolution fields
        $priority           = intval($_POST['priority']      ?? 0);
        $conflict_rule      = in_array($_POST['conflict_rule'] ?? '', ['best_for_customer','priority_order','stack']) ? $_POST['conflict_rule'] : 'best_for_customer';

        $stmt = $conn->prepare("INSERT INTO discounts (name, promo_code, type, value, is_active, usage_limit, start_date, end_date, scope, target_product_id, target_category, priority, conflict_rule) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssdisssissis", $name, $promo_code, $type, $val, $max_uses, $start_date, $end_date, $scope, $target_product_id, $target_category, $priority, $conflict_rule);

        if ($stmt->execute()) {
            $limit_note  = $max_uses > 0 ? " (limit: {$max_uses} uses)" : " (unlimited uses)";
            $scope_note  = $scope !== 'store' ? ", {$scope}-specific" : "";
            $sched_note  = ($start_date || $end_date) ? ", scheduled" : "";
            $msg = "<div class='bg-emerald-500 text-white p-4 rounded-2xl mb-6 font-bold animate-in shadow-lg text-center'>Promo '{$promo_code}' created{$limit_note}{$scope_note}{$sched_note}.</div>";
        } else {
            $msg = "<div class='bg-rose-500 text-white p-4 rounded-2xl mb-6 font-bold shadow-lg text-center'>Error: " . htmlspecialchars($conn->error) . "</div>";
        }
    }

    if ($action === 'toggle_status') {
        $id = intval($_POST['id']);
        $stmt = $conn->prepare("UPDATE discounts SET is_active = IF(is_active=1, 0, 1) WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $msg = "<div class='bg-blue-500 text-white p-4 rounded-2xl mb-6 font-bold animate-in shadow-lg text-center'>Promotion status toggled.</div>";
    }
}

include '../layout_top.php';

// ── DATA ──────────────────────────────────────────────────────────────────────
$discounts   = $conn->query("SELECT * FROM discounts ORDER BY id DESC");
$categories  = $conn->query("SELECT DISTINCT category FROM products WHERE status = '" . PRODUCT_ACTIVE . "' ORDER BY category");
$cat_list    = [];
while ($c = $categories->fetch_assoc()) $cat_list[] = $c['category'];
$products_q  = $conn->query("SELECT id, name FROM products WHERE status = '" . PRODUCT_ACTIVE . "' ORDER BY name");
$product_list = [];
while ($p = $products_q->fetch_assoc()) $product_list[] = $p;
$today = date('Y-m-d');
?>

<div class="max-w-7xl mx-auto pb-20 animate-in space-y-10">
    <?= $msg ?>

    <!-- ── CREATE NEW PROMO ────────────────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 bg-amber-100 rounded-2xl flex items-center justify-center text-amber-600 shadow-sm">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" /></svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800">Create Promotion</h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">Setup discounts for your POS</p>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="save_discount">

            <!-- Row 1: Core fields -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end mb-5">
                <div class="md:col-span-3">
                    <label class="label-modern ml-2">Promo Name (Display)</label>
                    <input type="text" name="name" required placeholder="e.g. Grand Opening" class="input-modern">
                </div>
                <div class="md:col-span-3">
                    <label class="label-modern ml-2 text-blue-500">Promo Code (Keyword)</label>
                    <input type="text" name="promo_code" required placeholder="e.g. WELCOME2026" class="input-modern uppercase font-black tracking-widest">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Type</label>
                    <select name="type" required class="input-modern cursor-pointer">
                        <option value="Percentage">Percentage %</option>
                        <option value="Fixed">Fixed Amount ₱</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Discount Value</label>
                    <input type="number" step="0.01" name="value" required placeholder="0.00" class="input-modern font-black text-emerald-600">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Usage Limit <span class="text-slate-300 font-normal normal-case">(0 = unlimited)</span></label>
                    <input type="number" name="max_uses" min="0" value="0" placeholder="0" class="input-modern font-black text-slate-700">
                </div>
            </div>

            <!-- Row 2: Schedule + Scope -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end pt-5 border-t border-slate-100">
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Start Date <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                    <input type="date" name="start_date" class="input-modern text-slate-600">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">End Date <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                    <input type="date" name="end_date" class="input-modern text-slate-600">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Applies To</label>
                    <select name="scope" id="promo-scope" onchange="toggleScopeTarget(this.value)" class="input-modern cursor-pointer">
                        <option value="store">Entire Cart (Store-wide)</option>
                        <option value="category">Specific Category</option>
                        <option value="product">Specific Product</option>
                    </select>
                </div>
                <div class="md:col-span-3" id="scope-category-wrap" style="display:none">
                    <label class="label-modern ml-2 text-violet-500">Target Category</label>
                    <select name="target_category" class="input-modern cursor-pointer">
                        <option value="">— pick category —</option>
                        <?php foreach ($cat_list as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-3" id="scope-product-wrap" style="display:none">
                    <label class="label-modern ml-2 text-violet-500">Target Product</label>
                    <select name="target_product_id" class="input-modern cursor-pointer">
                        <option value="">— pick product —</option>
                        <?php foreach ($product_list as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- F-09: Priority & conflict rule -->
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Priority <span class="text-slate-300 font-normal normal-case">(higher = preferred)</span></label>
                    <input type="number" name="priority" min="0" value="0" placeholder="0"
                           class="input-modern font-black text-slate-700">
                </div>
                <div class="md:col-span-3">
                    <label class="label-modern ml-2">Conflict Rule</label>
                    <select name="conflict_rule" class="input-modern cursor-pointer" title="What happens when multiple promos apply">
                        <option value="best_for_customer">Best for Customer (highest discount wins)</option>
                        <option value="priority_order">Priority Order (use highest priority)</option>
                        <option value="stack">Stack All (add all applicable discounts)</option>
                    </select>
                </div>
                <div class="md:col-span-2 md:col-start-11">
                    <button type="submit" class="btn-pos-primary w-full shadow-lg shadow-amber-200">ADD PROMO</button>
                </div>
            </div>
        </form>
    </div>

    <!-- ── PROMO LIST TABLE ────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden mb-20">
        <div class="p-6 bg-slate-50 border-b border-slate-100">
            <h4 class="font-black text-slate-800 text-[11px] uppercase tracking-widest">Store Promotions</h4>
        </div>

        <div class="overflow-x-auto">
            <table class="table-modern text-left min-w-full">
                <thead>
                    <tr class="bg-slate-50/50">
                        <th class="px-8 py-6">Promotion Details</th>
                        <th class="px-4 py-6 text-center">Applies To</th>
                        <th class="px-4 py-6 text-center">Rate</th>
                        <th class="px-4 py-6 text-center">Schedule</th>
                        <th class="px-4 py-6 text-center">Usage</th>
                        <th class="px-4 py-6 text-center">Status</th>
                        <th class="px-8 py-6 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if ($discounts->num_rows > 0): ?>
                        <?php while ($d = $discounts->fetch_assoc()):
                            $now         = date('Y-m-d');
                            $has_sched   = !empty($d['start_date']) || !empty($d['end_date']);
                            $not_started = !empty($d['start_date']) && $d['start_date'] > $now;
                            $expired     = !empty($d['end_date'])   && $d['end_date']   < $now;
                            $sched_state = $not_started ? 'upcoming' : ($expired ? 'expired' : ($has_sched ? 'active' : 'always'));
                        ?>
                        <tr class="hover:bg-slate-50 transition-colors <?= $expired ? 'opacity-50' : '' ?>">
                            <td class="px-8 py-6">
                                <p class="font-bold text-slate-700 text-base"><?= htmlspecialchars($d['name']) ?></p>
                                <code class="text-[10px] font-black text-blue-500 bg-blue-50 px-2 py-0.5 rounded border border-blue-100 uppercase tracking-widest">Code: <?= htmlspecialchars($d['promo_code'] ?: 'N/A') ?></code>
                            </td>
                            <td class="px-4 py-6 text-center">
                                <?php if ($d['scope'] === 'category'): ?>
                                    <span class="bg-violet-50 text-violet-600 text-[10px] font-black px-3 py-1 rounded-full border border-violet-100 uppercase">Category</span>
                                    <p class="text-[10px] font-bold text-slate-500 mt-1"><?= htmlspecialchars($d['target_category'] ?? '—') ?></p>
                                <?php elseif ($d['scope'] === 'product'): ?>
                                    <span class="bg-blue-50 text-blue-600 text-[10px] font-black px-3 py-1 rounded-full border border-blue-100 uppercase">Product</span>
                                    <?php
                                    $pn = '—';
                                    if ($d['target_product_id']) {
                                        $pnq = $conn->prepare("SELECT name FROM products WHERE id = ?");
                                        $pnq->bind_param("i", $d['target_product_id']); $pnq->execute();
                                        $pn = $pnq->get_result()->fetch_assoc()['name'] ?? '—';
                                    }
                                    ?>
                                    <p class="text-[10px] font-bold text-slate-500 mt-1 max-w-[120px] truncate"><?= htmlspecialchars($pn) ?></p>
                                <?php else: ?>
                                    <span class="bg-emerald-50 text-emerald-600 text-[10px] font-black px-3 py-1 rounded-full border border-emerald-100 uppercase">Store-wide</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-6 text-center">
                                <span class="text-xl font-black text-emerald-600">
                                    <?= $d['type'] == 'Percentage' ? number_format($d['value']).'%' : '₱'.number_format($d['value'], 2) ?>
                                </span>
                            </td>
                            <td class="px-4 py-6 text-center">
                                <?php if ($sched_state === 'always'): ?>
                                    <span class="text-slate-300 text-[10px] font-bold">No limit</span>
                                <?php elseif ($sched_state === 'upcoming'): ?>
                                    <span class="bg-amber-50 text-amber-600 text-[9px] font-black px-2 py-1 rounded-full border border-amber-100">Starts <?= date('M d', strtotime($d['start_date'])) ?></span>
                                <?php elseif ($sched_state === 'expired'): ?>
                                    <span class="bg-slate-100 text-slate-400 text-[9px] font-black px-2 py-1 rounded-full">Ended <?= date('M d', strtotime($d['end_date'])) ?></span>
                                <?php else: ?>
                                    <?php if ($d['start_date']): ?><p class="text-[9px] text-slate-500 font-bold">From <?= date('M d, Y', strtotime($d['start_date'])) ?></p><?php endif; ?>
                                    <?php if ($d['end_date']): ?><p class="text-[9px] text-slate-500 font-bold">Until <?= date('M d, Y', strtotime($d['end_date'])) ?></p><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-6 text-center">
                                <?php
                                $limit = intval($d['usage_limit']);
                                $used  = intval($d['used_count']);
                                $maxed = ($limit > 0 && $used >= $limit);
                                ?>
                                <?php if ($limit > 0): ?>
                                    <span class="font-black text-sm <?= $maxed ? 'text-red-500' : 'text-slate-700' ?>"><?= $used ?> / <?= $limit ?></span>
                                    <div class="w-20 mx-auto bg-slate-100 rounded-full h-1.5 mt-1">
                                        <div class="<?= $maxed ? 'bg-red-400' : 'bg-emerald-500' ?> h-1.5 rounded-full" style="width:<?= min(100, round($used / $limit * 100)) ?>%"></div>
                                    </div>
                                    <?php if ($maxed): ?><p class="text-[9px] font-black text-red-400 uppercase mt-1">Limit Reached</p><?php endif; ?>
                                <?php else: ?>
                                    <span class="text-slate-300 font-bold text-xs uppercase">Unlimited</span>
                                    <p class="text-[9px] text-slate-300 font-bold"><?= $used ?> used</p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-6 text-center">
                                <?php
                                $status_label = $d['is_active'] ? 'ACTIVE' : 'INACTIVE';
                                $status_class = $d['is_active'] ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400';
                                if ($expired)     { $status_label = 'EXPIRED'; $status_class = 'bg-rose-50 text-rose-400'; }
                                if ($not_started) { $status_label = 'UPCOMING'; $status_class = 'bg-amber-50 text-amber-500'; }
                                ?>
                                <span class="px-3 py-1.5 rounded-full text-[9px] font-black uppercase <?= $status_class ?>">
                                    <?= $status_label ?>
                                </span>
                            </td>
                            <td class="px-8 py-6 text-right">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="text-[9px] font-black text-slate-300 hover:text-emerald-500 transition-colors uppercase tracking-widest">
                                        [ Switch Status ]
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="p-20 text-center text-slate-300 font-bold italic opacity-40 uppercase">No promotions created yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function toggleScopeTarget(scope) {
    document.getElementById('scope-category-wrap').style.display = scope === 'category' ? '' : 'none';
    document.getElementById('scope-product-wrap').style.display  = scope === 'product'  ? '' : 'none';
}
</script>

<?php include '../layout_bottom.php'; ?>
