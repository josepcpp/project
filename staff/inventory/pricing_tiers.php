<?php
/**
 * pricing_tiers.php — Manage tiered % pricing rules per product.
 * These supplement (not replace) the existing half-box/full-box fixed bulk prices.
 * A tier triggers when a customer buys ≥ min_qty of a product and applies a % discount
 * off the retail unit price. Tiers are evaluated highest min_qty first.
 *
 * Admin and above only.
 */
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$msg = '';

// ── POST HANDLERS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_tier') {
        $product_id  = intval($_POST['product_id']  ?? 0);
        $min_qty     = max(1, intval($_POST['min_qty'] ?? 1));
        $discount_pct = round(floatval($_POST['discount_pct'] ?? 0), 2);
        $label       = trim($_POST['label'] ?? '');
        $edit_id     = intval($_POST['edit_id'] ?? 0);

        if (!$product_id)                           $msg = "<p class='msg-error'>Select a product.</p>";
        elseif ($discount_pct <= 0 || $discount_pct > 99) $msg = "<p class='msg-error'>Discount must be between 0.01% and 99%.</p>";
        else {
            // GAP-15: block duplicate (product_id, min_qty) — only one tier per qty threshold per product
            $dup_q = $conn->prepare("SELECT id FROM pricing_tiers WHERE product_id = ? AND min_qty = ? AND id != ?");
            $dup_q->bind_param("iii", $product_id, $min_qty, $edit_id);
            $dup_q->execute();
            if ($dup_q->get_result()->fetch_assoc()) {
                $msg = "<p class='msg-error'>A tier for this product at qty ≥ {$min_qty} already exists. Edit that row instead.</p>";
            } else {
                $uid = $_SESSION['user_id'] ?? null;
                if ($edit_id > 0) {
                    $s = $conn->prepare("UPDATE pricing_tiers SET product_id=?, min_qty=?, discount_pct=?, label=? WHERE id=?");
                    $s->bind_param("iidsi", $product_id, $min_qty, $discount_pct, $label, $edit_id);
                } else {
                    $s = $conn->prepare("INSERT INTO pricing_tiers (product_id, min_qty, discount_pct, label, created_by) VALUES (?,?,?,?,?)");
                    $s->bind_param("iidsi", $product_id, $min_qty, $discount_pct, $label, $uid);
                }
                $s->execute();
                $msg = "<p class='msg-success'>Tier saved.</p>";
            }
        }
    }

    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        $s  = $conn->prepare("UPDATE pricing_tiers SET is_active = IF(is_active=1,0,1) WHERE id=?");
        $s->bind_param("i", $id); $s->execute();
        $msg = "<p class='msg-success'>Tier status updated.</p>";
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $s  = $conn->prepare("DELETE FROM pricing_tiers WHERE id=?");
        $s->bind_param("i", $id); $s->execute();
        $msg = "<p class='msg-success'>Tier removed.</p>";
    }
}

include '../layout_top.php';

// Data for form
$products_q   = $conn->query("SELECT id, name FROM products WHERE status = '" . PRODUCT_ACTIVE . "' ORDER BY name ASC");
$product_list = [];
while ($p = $products_q->fetch_assoc()) $product_list[] = $p;

// Existing tiers with product names
$tiers_q = $conn->query("
    SELECT pt.*, p.name AS product_name
    FROM pricing_tiers pt
    JOIN products p ON p.id = pt.product_id
    ORDER BY p.name ASC, pt.min_qty DESC
");

// Edit mode
$edit_row = null;
$edit_id  = intval($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $eq = $conn->prepare("SELECT * FROM pricing_tiers WHERE id=?");
    $eq->bind_param("i", $edit_id); $eq->execute();
    $edit_row = $eq->get_result()->fetch_assoc();
}
?>

<div class="max-w-5xl mx-auto pb-20 animate-in space-y-10">

    <?php if ($msg): ?><div><?= $msg ?></div><?php endif; ?>

    <!-- ── CREATE / EDIT TIER ──────────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 bg-emerald-100 rounded-2xl flex items-center justify-center text-emerald-600 shadow-sm">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800"><?= $edit_row ? 'Edit Tier' : 'Add Pricing Tier' ?></h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">% off retail price at quantity threshold</p>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="save_tier">
            <?php if ($edit_row): ?><input type="hidden" name="edit_id" value="<?= $edit_row['id'] ?>"><?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
                <div class="md:col-span-4">
                    <label class="label-modern ml-2">Product <span class="text-rose-400">*</span></label>
                    <select name="product_id" required class="input-modern cursor-pointer">
                        <option value="">— select product —</option>
                        <?php foreach ($product_list as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($edit_row['product_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Min Qty <span class="text-rose-400">*</span></label>
                    <input type="number" name="min_qty" min="1" required
                           value="<?= $edit_row['min_qty'] ?? 2 ?>"
                           placeholder="e.g. 10"
                           class="input-modern font-black text-slate-700">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Discount % <span class="text-rose-400">*</span></label>
                    <input type="number" name="discount_pct" step="0.01" min="0.01" max="99" required
                           value="<?= $edit_row['discount_pct'] ?? '' ?>"
                           placeholder="e.g. 5"
                           class="input-modern font-black text-emerald-600">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Label <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                    <input type="text" name="label"
                           value="<?= htmlspecialchars($edit_row['label'] ?? '') ?>"
                           placeholder="e.g. Bulk Deal"
                           class="input-modern">
                </div>
                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="btn-pos-primary w-full shadow-lg shadow-emerald-200">
                        <?= $edit_row ? 'UPDATE' : 'SAVE TIER' ?>
                    </button>
                    <?php if ($edit_row): ?>
                        <a href="pricing_tiers.php" class="btn-secondary px-4 py-3 rounded-2xl text-xs font-black uppercase">Cancel</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- ── HOW IT WORKS ───────────────────────────────────────────────────── -->
    <div class="bg-emerald-50 border border-emerald-100 rounded-3xl p-6 text-sm text-emerald-700 font-bold">
        💡 <strong>How tiers work:</strong>
        The highest matching tier wins. Example: Buy 12 units — tier at 10+ (5% off) applies, not the tier at 5+ (2% off).
        These tiers only activate when the product doesn't already qualify for a half-box or full-box bulk price.
        Configure box pricing in <a href="product_info.php" class="underline">Product Master</a>.
    </div>

    <!-- ── TIERS TABLE ────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 bg-slate-50 border-b border-slate-100">
            <h4 class="font-black text-slate-800 text-[11px] uppercase tracking-widest">Active Pricing Tiers</h4>
        </div>

        <table class="table-modern text-left w-full">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="px-8 py-5">Product</th>
                    <th class="px-4 py-5 text-center">Min Qty</th>
                    <th class="px-4 py-5 text-center">Discount</th>
                    <th class="px-4 py-5 text-center">Label</th>
                    <th class="px-4 py-5 text-center">Status</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if ($tiers_q && $tiers_q->num_rows > 0): ?>
                    <?php while ($t = $tiers_q->fetch_assoc()): ?>
                    <tr class="hover:bg-slate-50 transition-colors <?= !$t['is_active'] ? 'opacity-40' : '' ?>">
                        <td class="px-8 py-5">
                            <p class="font-bold text-slate-700"><?= htmlspecialchars($t['product_name']) ?></p>
                        </td>
                        <td class="px-4 py-5 text-center">
                            <span class="font-black text-slate-700 text-lg"><?= $t['min_qty'] ?>+</span>
                            <p class="text-[9px] text-slate-400 font-bold uppercase">units</p>
                        </td>
                        <td class="px-4 py-5 text-center">
                            <span class="text-xl font-black text-emerald-600"><?= number_format($t['discount_pct'], 1) ?>%</span>
                            <p class="text-[9px] text-slate-400 font-bold uppercase">off retail</p>
                        </td>
                        <td class="px-4 py-5 text-center">
                            <?php if ($t['label']): ?>
                                <span class="text-[9px] font-black text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full border border-emerald-100 uppercase"><?= htmlspecialchars($t['label']) ?></span>
                            <?php else: ?>
                                <span class="text-slate-200">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-5 text-center">
                            <span class="px-3 py-1.5 rounded-full text-[9px] font-black uppercase <?= $t['is_active'] ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
                                <?= $t['is_active'] ? 'ACTIVE' : 'OFF' ?>
                            </span>
                        </td>
                        <td class="px-8 py-5 text-right">
                            <div class="flex items-center justify-end gap-4">
                                <a href="?edit=<?= $t['id'] ?>" class="text-[9px] font-black text-slate-400 hover:text-blue-500 uppercase transition-colors">[ Edit ]</a>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="text-[9px] font-black text-slate-300 hover:text-amber-500 uppercase transition-colors">[ <?= $t['is_active'] ? 'Disable' : 'Enable' ?> ]</button>
                                </form>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button type="submit" onclick="return confirmAction('Remove this tier?', null, 'Remove Tier')"
                                            class="text-[9px] font-black text-slate-300 hover:text-rose-500 uppercase transition-colors">[ Remove ]</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="p-20 text-center text-slate-300 font-bold italic opacity-40 uppercase">No tiers defined yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
