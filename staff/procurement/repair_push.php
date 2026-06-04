<?php
/**
 * repair_push.php — One-time recovery tool (Admin / Superadmin only).
 *
 * Finds receiving_items from a completed batch whose products were never
 * inserted into the products table (caused by the bind_param bug in
 * push_inventory.php when category column was added). Creates them as
 * draft lots so Admin can set a selling price and release to POS.
 *
 * Usage: /project/staff/procurement/repair_push.php?batch_id=X
 * Confirm: /project/staff/procurement/repair_push.php?batch_id=X&confirm=1
 */
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);
require_once 'push_inventory.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$batch_id = intval($_GET['batch_id'] ?? 0);
include '../layout_top.php';
?>
<div class="max-w-3xl mx-auto space-y-6">
<div class="card-modern p-8">
<h3 class="serif-title text-xl font-black text-slate-800 mb-2">Batch Inventory Recovery</h3>
<p class="text-slate-400 text-sm font-bold mb-6">Recovers products that failed to insert during inventory push.</p>

<?php if (!$batch_id): ?>
    <p class="text-rose-500 font-bold text-sm">No batch_id provided. Add <code>?batch_id=X</code> to the URL.</p>
<?php else:

    // Load batch — must be completed
    $bq = $conn->prepare("SELECT id, supplier_name, status FROM receiving_batches WHERE id = ? LIMIT 1");
    $bq->bind_param("i", $batch_id);
    $bq->execute();
    $batch = $bq->get_result()->fetch_assoc();

    if (!$batch):
?>
    <p class="text-rose-500 font-bold text-sm">Batch #<?= $batch_id ?> not found.</p>
<?php elseif (!in_array($batch['status'], ['completed', 'validated_tally'])): ?>
    <p class="text-amber-600 font-bold text-sm">Batch #<?= $batch_id ?> has status <strong><?= htmlspecialchars($batch['status']) ?></strong> — only <strong>validated_tally</strong> or <strong>completed</strong> batches can be repaired here.</p>
<?php else:

    // ── validated_tally: push_inventory never completed — run it now ─────────────
    if ($batch['status'] === 'validated_tally') {
        if (($_GET['confirm'] ?? '') !== '1'): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4 mb-4">
            <p class="text-amber-700 font-black text-sm">Batch #<?= $batch_id ?> (<?= htmlspecialchars($batch['supplier_name']) ?>) was validated but inventory push never completed.</p>
            <p class="text-amber-600 text-sm mt-1">Clicking confirm will run the inventory push now — products will be created and the batch will be marked completed.</p>
        </div>
        <a href="repair_push.php?batch_id=<?= $batch_id ?>&confirm=1"
           class="inline-block bg-emerald-600 hover:bg-emerald-500 text-white font-black text-sm px-6 py-3 rounded-2xl transition-all shadow-md">
            Run inventory push for Batch #<?= $batch_id ?> →
        </a>
        <?php else:
            try {
                push_inventory($batch_id, $user_id, $username, $role, $conn);
                ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold">
                    ✓ Inventory push completed for Batch #<?= $batch_id ?>. Go to <a href="../inventory/price_maintenance.php" class="underline">Master Price Table → Pending Price Update</a> to set selling prices for any new items.
                </div>
                <?php
            } catch (Throwable $e) { ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl px-5 py-4 text-sm font-bold">
                    Error: <?= htmlspecialchars($e->getMessage()) ?>
                </div>
                <?php
            }
        endif;
        include '../layout_bottom.php';
        exit();
    }

    // ── completed: find items that were silently skipped ─────────────────────────
    // Load items for this batch
    $iq = $conn->prepare("SELECT * FROM receiving_items WHERE batch_id = ? ORDER BY id ASC");
    $iq->bind_param("i", $batch_id);
    $iq->execute();
    $items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

    // Find items whose product was never created (no matching barcode in products)
    $missing = [];
    foreach ($items as $item) {
        $barcode     = $item['barcode']     ?: null;
        $box_barcode = $item['box_barcode'] ?: null;
        if (!$barcode && !$box_barcode) continue;

        $cq = $conn->prepare(
            "SELECT id FROM products
             WHERE (? IS NOT NULL AND barcode = ?) OR (? IS NOT NULL AND box_barcode = ?)
             LIMIT 1"
        );
        $cq->bind_param("ssss", $barcode, $barcode, $box_barcode, $box_barcode);
        $cq->execute();
        if ($cq->get_result()->num_rows === 0) {
            $missing[] = $item;
        }
    }

    if (empty($missing)):
?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold">
        ✓ All items from Batch #<?= $batch_id ?> (<?= htmlspecialchars($batch['supplier_name']) ?>) are already in the products table. No recovery needed.
    </div>
<?php elseif (($_GET['confirm'] ?? '') !== '1'): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4 mb-4">
        <p class="text-amber-700 font-black text-sm mb-3">Found <?= count($missing) ?> item(s) from Batch #<?= $batch_id ?> missing from products:</p>
        <div class="divide-y divide-amber-100">
        <?php foreach ($missing as $m): ?>
        <div class="py-2 flex items-center gap-3">
            <p class="font-bold text-slate-700 flex-1"><?= htmlspecialchars($m['description']) ?></p>
            <code class="text-[10px] text-slate-400 bg-slate-50 px-2 py-0.5 rounded border"><?= htmlspecialchars($m['barcode'] ?? '—') ?></code>
            <span class="text-sm font-black text-slate-500">×<?= intval($m['quantity']) ?></span>
            <span class="text-sm font-black text-slate-400">@ ₱<?= number_format(floatval($m['base_price'] ?? 0), 2) ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <a href="repair_push.php?batch_id=<?= $batch_id ?>&confirm=1"
       class="inline-block bg-emerald-600 hover:bg-emerald-500 text-white font-black text-sm px-6 py-3 rounded-2xl transition-all shadow-md">
        Recover these <?= count($missing) ?> item(s) as draft products →
    </a>
<?php else:
    // Run recovery
    $recovered = 0;
    $errors    = [];
    foreach ($missing as $item) {
        if ($item['base_price'] === null) {
            $errors[] = $item['description'] . ' — no base price set, skipped.';
            continue;
        }
        try {
            $barcode     = $item['barcode']     ?: null;
            $box_barcode = $item['box_barcode'] ?: null;
            $box_units   = max(1, intval($item['box_units'] ?? 1));
            $cost        = floatval($item['base_price']);
            $qty         = intval($item['quantity']);
            $expiry      = $item['expiry_date'] ?: null;
            $desc        = $item['description'];
            $category    = $item['category']    ?: null;

            _insert_draft_lot($conn, $desc, $barcode, $cost, $qty, $expiry, $batch_id, 'new', $box_barcode, $box_units, $category);
            _notify_admin($conn, $batch_id,
                "Recovered: \"$desc\" (barcode: " . ($barcode ?: $box_barcode ?: 'none') . ") — $qty unit(s) added to inventory. Set a selling price to release to POS."
            );
            $recovered++;
        } catch (Throwable $e) {
            $errors[] = $item['description'] . ' — ' . $e->getMessage();
        }
    }
?>
    <?php if ($recovered > 0): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold mb-4">
        ✓ Recovered <?= $recovered ?> item(s) as draft products. Go to <a href="../inventory/price_maintenance.php" class="underline">Master Price Table → Pending Price Update</a> to set selling prices.
    </div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl px-5 py-4 text-sm font-bold">
        <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php endif; endif; endif; ?>

</div>
</div>
<?php include '../layout_bottom.php'; ?>
