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

// Available vouchers — admin-created, no receiver assigned yet
$vouchers_q = $conn->query(
    "SELECT id, supplier_name, supplier_contact, created_at
     FROM receiving_batches
     WHERE status = 'pending_request' AND receiver_id IS NULL
     ORDER BY created_at ASC"
);

// My encoding history
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
    'pending_request'       => ['Encoding',    'bg-amber-100 text-amber-700'],
    'pending_validation'    => ['In Review',   'bg-blue-100 text-blue-700'],
    'pending_inventory'     => ['Queued',      'bg-sky-100 text-sky-700'],
    'validated_tally'       => ['Validated',   'bg-emerald-100 text-emerald-700'],
    'validated_discrepancy' => ['Discrepancy', 'bg-orange-100 text-orange-700'],
    'on_hold'               => ['On Hold',     'bg-rose-100 text-rose-700'],
    'completed'             => ['Completed',   'bg-green-100 text-green-700'],
    'rejected'              => ['Rejected',    'bg-red-100 text-red-700'],
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

    <!-- Available Vouchers -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-1">Available Vouchers</h3>
        <p class="text-slate-400 text-sm font-bold mb-6">Select a voucher created by Admin to begin encoding received items.</p>

        <?php if (!$vouchers_q || $vouchers_q->num_rows === 0): ?>
            <div class="text-center py-10 text-slate-400">
                <p class="font-black text-sm">No vouchers available.</p>
                <p class="text-xs font-bold mt-1">Ask an Admin to create a voucher for the incoming delivery.</p>
            </div>
        <?php else: ?>
        <div class="space-y-3">
            <?php while ($v = $vouchers_q->fetch_assoc()): ?>
            <div class="flex items-center justify-between gap-4 bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4">
                <div class="min-w-0">
                    <p class="font-black text-slate-800 text-sm">#<?= $v['id'] ?> — <?= htmlspecialchars($v['supplier_name']) ?></p>
                    <?php if ($v['supplier_contact']): ?>
                    <p class="text-xs text-slate-400 font-bold mt-0.5"><?= htmlspecialchars($v['supplier_contact']) ?></p>
                    <?php endif; ?>
                    <p class="text-[10px] text-slate-300 font-bold mt-0.5">Created <?= date('M j, Y g:i A', strtotime($v['created_at'])) ?></p>
                </div>
                <a href="receive_items.php?batch_id=<?= $v['id'] ?>"
                   class="btn-pos-primary text-xs font-black px-5 py-2.5 rounded-xl uppercase tracking-widest whitespace-nowrap flex-shrink-0">
                    Encode Items
                </a>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- My Batch History -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">My Batch History</h3>
        <?php if ($batches->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-8">No batches yet.</p>
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
