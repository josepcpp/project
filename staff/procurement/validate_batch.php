<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_once '../../includes/batch_lock.php';
require_role([ROLE_VALIDATOR, ROLE_ADMIN, ROLE_SUPERADMIN]);

$success = trim($_GET['success'] ?? '');
$error   = trim($_GET['error']   ?? '');

// Load all pending_validation batches (working_active = someone is pricing it now)
$pq = $conn->query(
    "SELECT rb.*, COUNT(ri.id) AS item_count,
            u.username AS receiver_name,
            (rb.working_by IS NOT NULL AND rb.working_at >= (NOW() - INTERVAL " . BATCH_LOCK_TTL_MIN . " MINUTE)) AS working_active
     FROM receiving_batches rb
     LEFT JOIN receiving_items ri ON ri.batch_id = rb.id
     LEFT JOIN users u ON u.id = rb.receiver_id
     WHERE rb.status = 'pending_validation'
     GROUP BY rb.id
     ORDER BY rb.request_created_at ASC"
);

include '../layout_top.php';
?>

<div class="max-w-5xl mx-auto space-y-6">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">Batches Awaiting Price Validation</h3>

        <?php if (!$pq || $pq->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-10">No batches pending validation.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Supplier</th>
                        <th>Receiver</th>
                        <th>Items</th>
                        <th>Sent for Validation</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($b = $pq->fetch_assoc()): ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $b['id'] ?></td>
                        <td class="font-bold"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($b['receiver_name'] ?? $b['receiver_username'] ?? '—') ?></td>
                        <td class="text-center font-black"><?= intval($b['item_count']) ?></td>
                        <td class="text-slate-400 text-xs"><?= $b['request_created_at'] ? date('M j, Y g:i A', strtotime($b['request_created_at'])) : '—' ?></td>
                        <td>
                            <?php if (intval($b['working_active']) === 1): ?>
                            <a href="validate_items.php?batch_id=<?= $b['id'] ?>"
                               class="bg-amber-100 text-amber-700 border border-amber-200 text-xs font-black px-4 py-2 rounded-xl uppercase tracking-widest transition-all whitespace-nowrap inline-block"
                               title="Being priced by @<?= htmlspecialchars($b['working_username']) ?>">
                                ⏳ On-going · @<?= htmlspecialchars($b['working_username']) ?>
                            </a>
                            <?php else: ?>
                            <a href="validate_items.php?batch_id=<?= $b['id'] ?>"
                               class="bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-black px-4 py-2 rounded-xl uppercase tracking-widest transition-all whitespace-nowrap">
                                Validate Prices
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
