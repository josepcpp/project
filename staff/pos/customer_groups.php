<?php
/**
 * customer_groups.php — Manage customer pricing groups (Employee, Wholesale, VIP, etc.)
 * Admin and above only. Groups are selectable at checkout to apply a blanket discount
 * on top of the cart subtotal, independent from promotional codes.
 */
include '../../config/db.php';
include '../../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$msg = '';

// ── POST HANDLERS ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_group') {
        $name           = trim($_POST['name'] ?? '');
        $label          = strtoupper(trim($_POST['label'] ?? ''));
        $discount_type  = in_array($_POST['discount_type'] ?? '', ['Percentage','Fixed']) ? $_POST['discount_type'] : 'Percentage';
        $discount_value = max(0, floatval($_POST['discount_value'] ?? 0));

        if ($discount_type === 'Percentage' && $discount_value > 100)
            $msg = "<p class='msg-error'>Percentage discount cannot exceed 100%.</p>";
        elseif ($name === '')
            $msg = "<p class='msg-error'>Group name is required.</p>";
        else {
            $id = intval($_POST['edit_id'] ?? 0);
            if ($id > 0) {
                $s = $conn->prepare("UPDATE customer_groups SET name=?, label=?, discount_type=?, discount_value=? WHERE id=?");
                $s->bind_param("sssdi", $name, $label, $discount_type, $discount_value, $id);
            } else {
                $uid = $_SESSION['user_id'] ?? null;
                $s   = $conn->prepare("INSERT INTO customer_groups (name, label, discount_type, discount_value, created_by) VALUES (?,?,?,?,?)");
                $s->bind_param("sssdi", $name, $label, $discount_type, $discount_value, $uid);
            }
            $s->execute();
            $msg = "<p class='msg-success'>Customer group saved.</p>";
        }
    }

    if ($action === 'toggle') {
        $id = intval($_POST['id'] ?? 0);
        $conn->prepare("UPDATE customer_groups SET is_active = IF(is_active=1,0,1) WHERE id=?")->execute() ||
        ($s = $conn->prepare("UPDATE customer_groups SET is_active = IF(is_active=1,0,1) WHERE id=?")) && $s->bind_param("i",$id) && $s->execute();
        $s = $conn->prepare("UPDATE customer_groups SET is_active = IF(is_active=1,0,1) WHERE id=?");
        $s->bind_param("i", $id); $s->execute();
        $msg = "<p class='msg-success'>Group status updated.</p>";
    }

    if ($action === 'delete') {
        // GAP-16: soft-delete — preserve FK integrity of past sales that reference this group.
        // Deactivate and rename so it's hidden from POS but historical records stay intact.
        $id = intval($_POST['id'] ?? 0);
        $s  = $conn->prepare("UPDATE customer_groups SET is_active = 0, name = CONCAT('[Deleted] ', name) WHERE id = ? AND name NOT LIKE '[Deleted]%'");
        $s->bind_param("i", $id); $s->execute();
        $msg = "<p class='msg-success'>Group removed.</p>";
    }
}

include '../layout_top.php';

$groups   = $conn->query("SELECT * FROM customer_groups ORDER BY id ASC");
$edit_row = null;
$edit_id  = intval($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $eq = $conn->prepare("SELECT * FROM customer_groups WHERE id=?");
    $eq->bind_param("i", $edit_id); $eq->execute();
    $edit_row = $eq->get_result()->fetch_assoc();
}
?>

<div class="max-w-5xl mx-auto pb-20 animate-in space-y-10">

    <?php if ($msg): ?><div class="px-2"><?= $msg ?></div><?php endif; ?>

    <!-- ── CREATE / EDIT GROUP ─────────────────────────────────────────────── -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 bg-blue-100 rounded-2xl flex items-center justify-center text-blue-600 shadow-sm">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800"><?= $edit_row ? 'Edit Group' : 'Create Customer Group' ?></h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">Applied at checkout before promo codes</p>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="action" value="save_group">
            <?php if ($edit_row): ?>
                <input type="hidden" name="edit_id" value="<?= $edit_row['id'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">
                <div class="md:col-span-4">
                    <label class="label-modern ml-2">Group Name <span class="text-rose-400">*</span></label>
                    <input type="text" name="name" required placeholder="e.g. Senior Citizen"
                           value="<?= htmlspecialchars($edit_row['name'] ?? '') ?>"
                           class="input-modern">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Badge Label</label>
                    <input type="text" name="label" placeholder="e.g. SENIOR" maxlength="20"
                           value="<?= htmlspecialchars($edit_row['label'] ?? '') ?>"
                           class="input-modern uppercase font-black tracking-widest text-blue-600">
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Discount Type</label>
                    <select name="discount_type" class="input-modern cursor-pointer">
                        <option value="Percentage" <?= ($edit_row['discount_type'] ?? '') === 'Percentage' ? 'selected' : '' ?>>Percentage %</option>
                        <option value="Fixed"      <?= ($edit_row['discount_type'] ?? '') === 'Fixed'      ? 'selected' : '' ?>>Fixed Amount ₱</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="label-modern ml-2">Value</label>
                    <input type="number" name="discount_value" step="0.01" min="0" required
                           value="<?= $edit_row['discount_value'] ?? '0' ?>"
                           placeholder="0.00" class="input-modern font-black text-emerald-600">
                </div>
                <div class="md:col-span-2 flex gap-2">
                    <button type="submit" class="btn-pos-primary w-full shadow-lg shadow-blue-200">
                        <?= $edit_row ? 'UPDATE' : 'ADD GROUP' ?>
                    </button>
                    <?php if ($edit_row): ?>
                        <a href="customer_groups.php" class="btn-secondary px-4 py-3 rounded-2xl text-xs font-black uppercase tracking-widest">Cancel</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <!-- ── GROUPS TABLE ────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
            <h4 class="font-black text-slate-800 text-[11px] uppercase tracking-widest">Customer Groups</h4>
            <p class="text-[10px] text-slate-400 font-bold uppercase">Applied before promo discounts at checkout</p>
        </div>

        <table class="table-modern text-left w-full">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="px-8 py-5">Group</th>
                    <th class="px-4 py-5 text-center">Discount</th>
                    <th class="px-4 py-5 text-center">Status</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if ($groups->num_rows > 0): ?>
                    <?php while ($g = $groups->fetch_assoc()): ?>
                    <tr class="hover:bg-slate-50 transition-colors <?= !$g['is_active'] ? 'opacity-50' : '' ?>">
                        <td class="px-8 py-5">
                            <p class="font-bold text-slate-700"><?= htmlspecialchars($g['name']) ?></p>
                            <?php if ($g['label']): ?>
                                <span class="text-[9px] font-black text-blue-500 bg-blue-50 px-2 py-0.5 rounded border border-blue-100 uppercase tracking-widest"><?= htmlspecialchars($g['label']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-5 text-center">
                            <span class="text-xl font-black text-emerald-600">
                                <?= $g['discount_type'] === 'Percentage' ? number_format($g['discount_value'],1).'%' : '₱'.number_format($g['discount_value'],2) ?>
                            </span>
                            <p class="text-[9px] text-slate-400 font-bold uppercase"><?= $g['discount_type'] === 'Percentage' ? 'off subtotal' : 'flat off' ?></p>
                        </td>
                        <td class="px-4 py-5 text-center">
                            <span class="px-3 py-1.5 rounded-full text-[9px] font-black uppercase <?= $g['is_active'] ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' ?>">
                                <?= $g['is_active'] ? 'ACTIVE' : 'INACTIVE' ?>
                            </span>
                        </td>
                        <td class="px-8 py-5 text-right">
                            <div class="flex items-center justify-end gap-4">
                                <a href="?edit=<?= $g['id'] ?>" class="text-[9px] font-black text-slate-400 hover:text-blue-500 uppercase tracking-widest transition-colors">[ Edit ]</a>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                    <button type="submit" class="text-[9px] font-black text-slate-300 hover:text-amber-500 uppercase tracking-widest transition-colors">
                                        [ <?= $g['is_active'] ? 'Disable' : 'Enable' ?> ]
                                    </button>
                                </form>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                    <button type="submit" onclick="return confirmAction('Remove this group?', null, 'Remove Group')"
                                            class="text-[9px] font-black text-slate-300 hover:text-rose-500 uppercase tracking-widest transition-colors">
                                        [ Remove ]
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4" class="p-20 text-center text-slate-300 font-bold italic opacity-40 uppercase">No groups yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="bg-blue-50 border border-blue-100 rounded-3xl p-6 text-sm text-blue-700 font-bold">
        💡 <strong>How it works:</strong> When a cashier selects a customer group at checkout, the group's discount applies to the cart subtotal first. Then any promo code discount is applied to the remaining amount. Group discounts <em>do not stack</em> with each other — only one group per transaction.
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
