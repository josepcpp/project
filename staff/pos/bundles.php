<?php
/**
 * bundles.php — Manage combo/bundle deals.
 * A bundle groups 2+ products with a fixed bundle price, shown as a single "add" button
 * in the POS. Savings vs. buying items individually are shown to the cashier.
 * Admin and above only.
 */
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$msg    = '';
$edit_bundle = null;
$edit_id     = intval($_GET['edit'] ?? 0);

// Maximum number of bundle deals that may exist at once. Change here to adjust.
$MAX_BUNDLES = 10;

// ── POST HANDLERS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_bundle') {
        $bid         = intval($_POST['bundle_id'] ?? 0);
        $name        = trim($_POST['name']         ?? '');
        $description = trim($_POST['description']  ?? '');
        $price       = round(floatval($_POST['bundle_price'] ?? 0), 2);
        $pids        = array_filter(array_map('intval', $_POST['product_ids']  ?? []), fn($v) => $v > 0);
        $qtys        = array_map('intval',               $_POST['product_qtys'] ?? []);

        if ($name === '')    { $msg = "<p class='msg-error'>Bundle name is required.</p>"; }
        elseif ($price <= 0) { $msg = "<p class='msg-error'>Bundle price must be greater than zero.</p>"; }
        elseif (count($pids) < 2) { $msg = "<p class='msg-error'>A bundle must include at least 2 products.</p>"; }
        elseif ($bid === 0 && intval($conn->query("SELECT COUNT(*) AS c FROM bundles")->fetch_assoc()['c'] ?? 0) >= $MAX_BUNDLES) {
            $msg = "<p class='msg-error'>Bundle limit reached (max {$MAX_BUNDLES}). Remove or disable an existing bundle before creating a new one.</p>";
        }
        else {
            $uid = $_SESSION['user_id'] ?? null;
            $conn->begin_transaction();
            try {
                if ($bid > 0) {
                    $s = $conn->prepare("UPDATE bundles SET name=?, description=?, bundle_price=? WHERE id=?");
                    $s->bind_param("ssdi", $name, $description, $price, $bid);
                    $s->execute();
                    $conn->prepare("DELETE FROM bundle_items WHERE bundle_id=?")->execute(); // fallback
                    $di = $conn->prepare("DELETE FROM bundle_items WHERE bundle_id=?");
                    $di->bind_param("i", $bid); $di->execute();
                } else {
                    $s = $conn->prepare("INSERT INTO bundles (name, description, bundle_price, created_by) VALUES (?,?,?,?)");
                    $s->bind_param("ssdi", $name, $description, $price, $uid);
                    $s->execute();
                    $bid = $conn->insert_id;
                }
                $is = $conn->prepare("INSERT INTO bundle_items (bundle_id, product_id, qty) VALUES (?,?,?)");
                foreach ($pids as $i => $pid) {
                    $qty = max(1, intval($qtys[$i] ?? 1));
                    $is->bind_param("iii", $bid, $pid, $qty);
                    $is->execute();
                }
                $conn->commit();
                $msg = "<p class='msg-success'>Bundle \"" . htmlspecialchars($name) . "\" saved.</p>";
                $edit_id = 0;
            } catch (Throwable $e) {
                $conn->rollback();
                $msg = "<p class='msg-error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    }

    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        $s  = $conn->prepare("UPDATE bundles SET is_active = IF(is_active=1,0,1) WHERE id=?");
        $s->bind_param("i", $id); $s->execute();
        $msg = "<p class='msg-success'>Bundle status updated.</p>";
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $conn->begin_transaction();
        try {
            $di = $conn->prepare("DELETE FROM bundle_items WHERE bundle_id=?");
            $di->bind_param("i", $id); $di->execute();
            $db = $conn->prepare("DELETE FROM bundles WHERE id=?");
            $db->bind_param("i", $id); $db->execute();
            $conn->commit();
            $msg = "<p class='msg-success'>Bundle removed.</p>";
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = "<p class='msg-error'>Delete failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

include '../layout_top.php';

// Load product list for the form picker
$products_q = $conn->query("SELECT id, name, price FROM products WHERE status='" . PRODUCT_ACTIVE . "' ORDER BY name ASC");
$product_list = [];
while ($p = $products_q->fetch_assoc()) $product_list[] = $p;

// Load edit target
if ($edit_id > 0) {
    $eq = $conn->prepare("SELECT * FROM bundles WHERE id=?");
    $eq->bind_param("i", $edit_id); $eq->execute();
    $edit_bundle = $eq->get_result()->fetch_assoc();
    if ($edit_bundle) {
        $eiq = $conn->prepare("SELECT bi.*, p.name AS product_name, p.price FROM bundle_items bi JOIN products p ON p.id=bi.product_id WHERE bi.bundle_id=?");
        $eiq->bind_param("i", $edit_id); $eiq->execute();
        $edit_bundle['items'] = $eiq->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Load all bundles with their items for the list
$bundles_q = $conn->query("SELECT b.*, GROUP_CONCAT(CONCAT(bi.qty,'× ',p.name) ORDER BY p.name SEPARATOR ', ') AS item_summary FROM bundles b LEFT JOIN bundle_items bi ON bi.bundle_id=b.id LEFT JOIN products p ON p.id=bi.product_id GROUP BY b.id ORDER BY b.id DESC");
$bundle_count = $bundles_q ? $bundles_q->num_rows : 0;
$at_limit     = $bundle_count >= $MAX_BUNDLES;
?>

<div class="max-w-5xl mx-auto pb-20 animate-in space-y-10">

    <!-- Promotions / Bundle Deals tabs -->
    <div class="flex gap-1 bg-slate-100 rounded-2xl p-1 w-fit">
        <a href="discount.php" class="px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all text-slate-500 hover:text-slate-700">Promotions</a>
        <a href="bundles.php" class="px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all bg-white shadow text-slate-800">Bundle Deals</a>
    </div>

    <?php if ($msg): ?><div><?= $msg ?></div><?php endif; ?>

    <?php if ($at_limit && !$edit_bundle): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-700 rounded-2xl px-5 py-4 text-sm font-bold">
        Bundle limit reached (<?= $bundle_count ?>/<?= $MAX_BUNDLES ?>). Remove or disable an existing bundle before creating a new one.
    </div>
    <?php endif; ?>

    <!-- ── CREATE / EDIT BUNDLE ───────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 bg-orange-100 rounded-2xl flex items-center justify-center text-orange-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800"><?= $edit_bundle ? 'Edit Bundle' : 'Create Bundle Deal' ?></h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">Group products at a single fixed price</p>
            </div>
        </div>

        <form method="POST" action="" id="bundleForm">
            <input type="hidden" name="action" value="save_bundle">
            <?php if ($edit_bundle): ?><input type="hidden" name="bundle_id" value="<?= $edit_bundle['id'] ?>"><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 mb-6">
                <div class="md:col-span-5">
                    <label class="label-modern ml-2">Bundle Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="name" required placeholder="e.g. Breakfast Combo"
                           value="<?= htmlspecialchars($edit_bundle['name'] ?? '') ?>"
                           class="input-modern">
                </div>
                <div class="md:col-span-4">
                    <label class="label-modern ml-2">Description <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                    <input type="text" name="description" placeholder="Shown on POS card"
                           value="<?= htmlspecialchars($edit_bundle['description'] ?? '') ?>"
                           class="input-modern">
                </div>
                <div class="md:col-span-3">
                    <label class="label-modern ml-2">Bundle Price (₱) <span class="text-rose-400">*</span></label>
                    <input type="number" name="bundle_price" step="0.01" min="0.01" required
                           id="bundlePriceInput"
                           value="<?= $edit_bundle['bundle_price'] ?? '' ?>"
                           placeholder="0.00"
                           onchange="updateSavings()"
                           class="input-modern font-black text-orange-600">
                </div>
            </div>

            <!-- Products picker -->
            <div class="border-t border-slate-100 pt-6">
                <div class="flex items-center justify-between mb-4">
                    <label class="label-modern">Bundle Components <span class="text-rose-400">*</span> <span class="text-slate-300 font-normal normal-case">(min. 2)</span></label>
                    <button type="button" onclick="addProductRow()"
                            class="text-[10px] font-black text-orange-600 hover:text-orange-700 uppercase tracking-widest border border-orange-200 rounded-xl px-3 py-1.5 hover:bg-orange-50 transition-colors">
                        + Add Product
                    </button>
                </div>

                <div id="product-rows" class="space-y-3">
                    <?php
                    $initial_items = $edit_bundle['items'] ?? [['product_id'=>'','qty'=>1],['product_id'=>'','qty'=>1]];
                    foreach ($initial_items as $idx => $ei):
                    ?>
                    <div class="flex gap-3 items-center product-row" id="row-<?= $idx ?>">
                        <select name="product_ids[]" onchange="updateSavings()" required
                                class="input-modern flex-1 cursor-pointer">
                            <option value="">— select product —</option>
                            <?php foreach ($product_list as $p): ?>
                                <option value="<?= $p['id'] ?>" data-price="<?= $p['price'] ?>"
                                    <?= ($ei['product_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> — ₱<?= number_format($p['price'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="flex items-center gap-2 shrink-0">
                            <label class="text-[10px] font-black text-slate-400 uppercase">Qty:</label>
                            <input type="number" name="product_qtys[]" min="1" value="<?= $ei['qty'] ?? 1 ?>"
                                   onchange="updateSavings()"
                                   class="w-20 border-2 border-slate-200 rounded-xl px-3 py-2.5 text-sm font-black text-slate-700 focus:outline-none focus:border-orange-400">
                        </div>
                        <button type="button" onclick="removeRow(this)"
                                class="w-8 h-8 flex items-center justify-center text-slate-300 hover:text-rose-400 transition-colors font-black text-lg shrink-0">✕</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Savings preview -->
                <div class="mt-5 flex items-center gap-4 bg-orange-50 rounded-2xl p-4 border border-orange-100">
                    <div class="text-sm font-bold text-slate-500">Individual total: <span id="individual-total" class="font-black text-slate-700">₱0.00</span></div>
                    <div class="text-sm font-bold text-orange-600">Bundle saves: <span id="savings-amt" class="font-black">₱0.00</span></div>
                    <div class="ml-auto text-[10px] font-black text-orange-500 uppercase" id="savings-pct"></div>
                </div>
            </div>

            <div class="flex gap-4 mt-8 pt-6 border-t border-slate-100">
                <?php if (!$edit_bundle && $at_limit): ?>
                    <button type="button" disabled class="btn-pos-primary px-10 opacity-50 cursor-not-allowed">LIMIT REACHED (<?= $MAX_BUNDLES ?>)</button>
                <?php else: ?>
                    <button type="submit" class="btn-pos-primary px-10 shadow-lg shadow-orange-200">
                        <?= $edit_bundle ? 'UPDATE BUNDLE' : 'CREATE BUNDLE' ?>
                    </button>
                <?php endif; ?>
                <?php if ($edit_bundle): ?>
                    <a href="bundles.php" class="btn-secondary px-6 py-3 rounded-2xl text-xs font-black uppercase tracking-widest">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ── BUNDLE LIST ────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 bg-slate-50 border-b border-slate-100">
            <h4 class="font-black text-slate-800 text-[11px] uppercase tracking-widest">Active Bundle Deals (<?= $bundle_count ?> / <?= $MAX_BUNDLES ?>)</h4>
        </div>

        <table class="table-modern text-left w-full">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="px-8 py-5">Bundle</th>
                    <th class="px-4 py-5">Components</th>
                    <th class="px-4 py-5 text-center">Price</th>
                    <th class="px-4 py-5 text-center">Status</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if ($bundles_q && $bundles_q->num_rows > 0): ?>
                    <?php while ($b = $bundles_q->fetch_assoc()): ?>
                    <tr class="hover:bg-slate-50 transition-colors <?= !$b['is_active'] ? 'opacity-40' : '' ?>">
                        <td class="px-8 py-5">
                            <p class="font-bold text-slate-700"><?= htmlspecialchars($b['name']) ?></p>
                            <?php if ($b['description']): ?><p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($b['description']) ?></p><?php endif; ?>
                        </td>
                        <td class="px-4 py-5 text-xs text-slate-500 max-w-[280px]">
                            <?= htmlspecialchars($b['item_summary'] ?? '—') ?>
                        </td>
                        <td class="px-4 py-5 text-center">
                            <span class="text-xl font-black text-orange-600">₱<?= number_format($b['bundle_price'], 2) ?></span>
                        </td>
                        <td class="px-4 py-5 text-center">
                            <span class="px-3 py-1.5 rounded-full text-[9px] font-black uppercase <?= $b['is_active'] ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
                                <?= $b['is_active'] ? 'ACTIVE' : 'OFF' ?>
                            </span>
                        </td>
                        <td class="px-8 py-5 text-right">
                            <div class="flex items-center justify-end gap-4">
                                <a href="?edit=<?= $b['id'] ?>" class="text-[9px] font-black text-slate-400 hover:text-blue-500 uppercase tracking-widest transition-colors">[ Edit ]</a>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="text-[9px] font-black text-slate-300 hover:text-amber-500 uppercase tracking-widest transition-colors">[ <?= $b['is_active'] ? 'Disable' : 'Enable' ?> ]</button>
                                </form>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                    <button type="submit" onclick="return confirmAction('Remove this bundle?', null, 'Remove Bundle')"
                                            class="text-[9px] font-black text-slate-300 hover:text-rose-500 uppercase tracking-widest transition-colors">[ Remove ]</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="p-20 text-center text-slate-300 font-bold italic opacity-40 uppercase">No bundles yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let rowIndex = <?= count($initial_items ?? [['',''],['','']]) ?>;

function addProductRow() {
    const idx = rowIndex++;
    const container = document.getElementById('product-rows');
    const div = document.createElement('div');
    div.className = 'flex gap-3 items-center product-row';
    div.id = 'row-' + idx;
    div.innerHTML = `
        <select name="product_ids[]" onchange="updateSavings()" required class="input-modern flex-1 cursor-pointer">
            <option value="">— select product —</option>
            <?php foreach ($product_list as $p): ?>
            <option value="<?= $p['id'] ?>" data-price="<?= $p['price'] ?>"><?= htmlspecialchars($p['name'], ENT_QUOTES) ?> — ₱<?= number_format($p['price'], 2) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="flex items-center gap-2 shrink-0">
            <label class="text-[10px] font-black text-slate-400 uppercase">Qty:</label>
            <input type="number" name="product_qtys[]" min="1" value="1" onchange="updateSavings()"
                   class="w-20 border-2 border-slate-200 rounded-xl px-3 py-2.5 text-sm font-black text-slate-700 focus:outline-none focus:border-orange-400">
        </div>
        <button type="button" onclick="removeRow(this)"
                class="w-8 h-8 flex items-center justify-center text-slate-300 hover:text-rose-400 transition-colors font-black text-lg shrink-0">✕</button>
    `;
    container.appendChild(div);
    updateSavings();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('.product-row');
    if (rows.length <= 2) { showFlash('A bundle needs at least 2 products.', 'error'); return; }
    btn.closest('.product-row').remove();
    updateSavings();
}

function updateSavings() {
    let individualTotal = 0;
    document.querySelectorAll('.product-row').forEach(row => {
        const sel = row.querySelector('select[name="product_ids[]"]');
        const qty = parseInt(row.querySelector('input[name="product_qtys[]"]')?.value || 1);
        if (sel && sel.value) {
            const opt = sel.options[sel.selectedIndex];
            const price = parseFloat(opt.dataset.price || 0);
            individualTotal += price * qty;
        }
    });
    const bundlePrice = parseFloat(document.getElementById('bundlePriceInput').value) || 0;
    const savings = Math.max(0, individualTotal - bundlePrice);
    const pct     = individualTotal > 0 ? ((savings / individualTotal) * 100).toFixed(1) : 0;

    document.getElementById('individual-total').textContent = '₱' + individualTotal.toFixed(2);
    document.getElementById('savings-amt').textContent      = '₱' + savings.toFixed(2);
    document.getElementById('savings-pct').textContent      = savings > 0 ? `${pct}% savings` : '';
}

updateSavings();
</script>

<?php include '../layout_bottom.php'; ?>
