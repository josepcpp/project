<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$success = trim($_GET['success'] ?? '');
$error   = trim($_GET['error']   ?? '');

// Load this receiver's batch history
$history_q = $conn->prepare(
    "SELECT rb.*, COUNT(ri.id) AS item_count
     FROM receiving_batches rb
     LEFT JOIN receiving_items ri ON ri.batch_id = rb.id
     WHERE rb.receiver_id = ?
     GROUP BY rb.id
     ORDER BY rb.created_at DESC
     LIMIT 50"
);
$history_q->bind_param("i", $user_id);
$history_q->execute();
$batches = $history_q->get_result();

$status_labels = [
    'pending_request'        => ['Pending',     'bg-amber-100 text-amber-700'],
    'pending_validation'     => ['In Review',   'bg-blue-100 text-blue-700'],
    'pending_inventory'      => ['Queued',       'bg-sky-100 text-sky-700'],
    'validated_tally'        => ['Validated',   'bg-emerald-100 text-emerald-700'],
    'validated_discrepancy'  => ['Discrepancy', 'bg-orange-100 text-orange-700'],
    'on_hold'                => ['On Hold',     'bg-rose-100 text-rose-700'],
    'completed'              => ['Completed',   'bg-green-100 text-green-700'],
    'rejected'               => ['Rejected',    'bg-red-100 text-red-700'],
];

include '../layout_top.php';
?>

<div class="max-w-3xl mx-auto space-y-8">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- New Batch Form -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">Start New Receiving Batch</h3>
        <form method="POST" action="receive_process.php" class="space-y-5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_batch">
            <div>
                <label class="label-modern">Supplier Name <span class="text-rose-500">*</span></label>
                <input type="text" name="supplier_name" required maxlength="255"
                       class="input-modern" placeholder="e.g. ABC Trading Co.">
            </div>
            <div>
                <label class="label-modern">Supplier Contact</label>
                <input type="text" name="supplier_contact" maxlength="255"
                       class="input-modern" placeholder="Phone / email (optional)">
            </div>
            <button type="submit" class="btn-pos-primary w-full py-3 text-sm font-black uppercase tracking-widest">
                Create Batch &amp; Encode Items
            </button>
        </form>
    </div>

    <!-- Batch History -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">My Batch History</h3>
        <?php if ($batches->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-8">No batches yet. Create one above.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Supplier</th>
                        <th>Items</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($b = $batches->fetch_assoc()):
                    [$label, $badge] = $status_labels[$b['status']] ?? ['Unknown', 'bg-slate-100 text-slate-500'];
                ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $b['id'] ?></td>
                        <td class="font-bold"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
                        <td class="text-center"><?= intval($b['item_count']) ?></td>
                        <td><span class="<?= $badge ?> text-[10px] font-black px-2 py-1 rounded-full uppercase tracking-wider"><?= $label ?></span></td>
                        <td class="text-slate-400"><?= date('M j, Y', strtotime($b['created_at'])) ?></td>
                        <td>
                            <?php if ($b['status'] === 'pending_request'): ?>
                            <a href="receive_items.php?batch_id=<?= $b['id'] ?>" class="text-emerald-600 font-bold hover:underline text-xs">
                                <?= $b['item_count'] > 0 ? 'Continue Encoding' : 'Encode Items' ?>
                            </a>
                            <?php else: ?>
                            <a href="receive_items.php?batch_id=<?= $b['id'] ?>&readonly=1" class="text-slate-400 font-bold hover:underline text-xs">View</a>
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
