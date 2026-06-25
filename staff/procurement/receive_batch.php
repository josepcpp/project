<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_once '../../includes/batch_lock.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$success = trim($_GET['success'] ?? '');
$error   = trim($_GET['error']   ?? '');
$warning = trim($_GET['warning'] ?? '');
$info    = trim($_GET['info']    ?? '');

// Available vouchers — admin-created, no receiver assigned yet.
// working_active flags a voucher someone is currently encoding (soft lock).
$vouchers_q = $conn->query(
    "SELECT id, supplier_name, supplier_contact, created_at,
            working_username, working_role,
            (working_by IS NOT NULL AND working_at >= (NOW() - INTERVAL " . BATCH_LOCK_TTL_MIN . " MINUTE)) AS working_active
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

// Collect history rows into an array so we can iterate twice (table + hidden templates)
$items_by_batch = [];
$batch_rows_raw = [];
while ($row = $batches->fetch_assoc()) $batch_rows_raw[] = $row;

if (!empty($batch_rows_raw)) {
    $ids = implode(',', array_map('intval', array_column($batch_rows_raw, 'id')));
    $ir  = $conn->query(
        "SELECT batch_id, barcode, description, quantity, damaged_qty, damage_notes, expiry_date, base_price
         FROM receiving_items WHERE batch_id IN ($ids) ORDER BY batch_id, id ASC"
    );
    if ($ir) while ($row = $ir->fetch_assoc()) $items_by_batch[$row['batch_id']][] = $row;
}

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

<div class="max-w-7xl mx-auto space-y-6">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($warning): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($warning) ?></div>
    <?php endif; ?>
    <?php if ($info): ?>
    <div class="bg-blue-50 border border-blue-200 text-blue-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($info) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-10 gap-3 items-start">

        <!-- LEFT — Available Vouchers -->
        <div class="card-modern p-7 lg:col-span-4">
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
                <div class="bg-slate-50 border border-slate-200 rounded-2xl px-5 py-4 space-y-3">
                    <div class="min-w-0">
                        <p class="font-black text-slate-800 text-sm">#<?= $v['id'] ?> — <?= htmlspecialchars($v['supplier_name']) ?></p>
                        <?php if ($v['supplier_contact']): ?>
                        <p class="text-xs text-slate-400 font-bold mt-0.5"><?= htmlspecialchars($v['supplier_contact']) ?></p>
                        <?php endif; ?>
                        <p class="text-[10px] text-slate-300 font-bold mt-0.5">Created <?= date('M j, Y g:i A', strtotime($v['created_at'])) ?></p>
                    </div>
                    <?php if (intval($v['working_active']) === 1): ?>
                    <div class="w-full text-center text-[10px] font-black px-5 py-2.5 rounded-xl uppercase tracking-widest bg-amber-100 text-amber-700 border border-amber-200">
                        ⏳ On-going · @<?= htmlspecialchars($v['working_username']) ?>
                    </div>
                    <a href="receive_items.php?batch_id=<?= $v['id'] ?>" class="block text-center text-[10px] font-bold text-slate-400 hover:text-slate-600 mt-1">View status →</a>
                    <?php else: ?>
                    <a href="receive_items.php?batch_id=<?= $v['id'] ?>"
                       class="btn-pos-primary w-full text-center text-xs font-black px-5 py-2.5 rounded-xl uppercase tracking-widest block">
                        Encode Items
                    </a>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT — My Batch History -->
        <div class="card-modern p-7 lg:col-span-6">
            <h3 class="serif-title text-xl font-black text-slate-800 mb-6">My Batch History</h3>
            <?php if (empty($batch_rows_raw)): ?>
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
                    <?php foreach ($batch_rows_raw as $b):
                        [$label, $badge] = $status_labels[$b['status']] ?? ['Unknown', 'bg-slate-100 text-slate-500'];
                    ?>
                        <tr>
                            <td class="font-black text-slate-500">#<?= $b['id'] ?></td>
                            <td class="font-bold"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
                            <td class="text-center"><?= intval($b['item_count']) ?></td>
                            <td><span class="<?= $badge ?> inline-block whitespace-nowrap text-[10px] font-black px-2.5 py-1 rounded-full uppercase tracking-wider"><?= $label ?></span></td>
                            <td class="text-slate-400"><?= date('M j, Y', strtotime($b['created_at'])) ?></td>
                            <td class="whitespace-nowrap">
                                <?php if ($b['status'] === 'pending_request'): ?>
                                <a href="receive_items.php?batch_id=<?= $b['id'] ?>" class="text-emerald-600 font-bold hover:underline text-xs">
                                    <?= intval($b['item_count']) > 0 ? 'Continue' : 'Encode' ?>
                                </a>
                                <?php else: ?>
                                <button type="button" onclick="openBatchDetail(<?= $b['id'] ?>)"
                                    class="text-blue-500 font-bold hover:underline text-xs">View</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- ── Hidden detail templates (one per batch) ───────────────────────────── -->
<?php foreach ($batch_rows_raw as $b):
    [$label, $badge] = $status_labels[$b['status']] ?? ['Unknown', 'bg-slate-100 text-slate-500'];
    $items = $items_by_batch[$b['id']] ?? [];
?>
<div id="bdetail-<?= $b['id'] ?>" class="hidden"
     data-title="Voucher #<?= $b['id'] ?> — <?= htmlspecialchars($b['supplier_name'] ?? '') ?>">
    <!-- Meta row -->
    <div class="flex flex-wrap gap-4 mb-5 text-xs font-bold text-slate-500">
        <span>Supplier: <span class="text-slate-800"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></span></span>
        <?php if ($b['supplier_contact']): ?>
        <span>Contact: <span class="text-slate-800"><?= htmlspecialchars($b['supplier_contact']) ?></span></span>
        <?php endif; ?>
        <span>Status: <span class="<?= $badge ?> px-2 py-0.5 rounded-full text-[10px] uppercase tracking-wider"><?= $label ?></span></span>
        <span>Date: <span class="text-slate-800"><?= date('M j, Y g:i A', strtotime($b['created_at'])) ?></span></span>
    </div>
    <!-- Items table -->
    <?php if (empty($items)): ?>
        <p class="text-slate-400 text-sm font-bold text-center py-6">No items encoded yet.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="table-modern w-full text-sm">
            <thead>
                <tr>
                    <th>Barcode</th>
                    <th>Description</th>
                    <th class="text-center">Qty</th>
                    <th class="text-center">Damaged</th>
                    <th>Expiry</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr class="<?= intval($it['damaged_qty']) > 0 ? 'bg-rose-50' : '' ?>">
                    <td class="text-slate-400 text-xs font-mono"><?= htmlspecialchars($it['barcode'] ?? '—') ?></td>
                    <td class="font-bold"><?= htmlspecialchars($it['description']) ?></td>
                    <td class="text-center font-black"><?= intval($it['quantity']) ?></td>
                    <td class="text-center">
                        <?php if (intval($it['damaged_qty']) > 0): ?>
                            <span class="text-rose-600 font-black"><?= intval($it['damaged_qty']) ?></span>
                            <?php if ($it['damage_notes']): ?>
                            <span class="block text-[10px] text-rose-400 font-bold"><?= htmlspecialchars($it['damage_notes']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-slate-300">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-slate-400 text-xs"><?= $it['expiry_date'] ? date('M j, Y', strtotime($it['expiry_date'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- ── Shared batch detail modal ─────────────────────────────────────────── -->
<div id="batch-detail-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm hidden">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-2xl mx-4 flex flex-col" style="max-height:85vh">
        <div class="flex items-center justify-between px-7 pt-7 pb-4 border-b border-slate-100 flex-shrink-0">
            <h4 id="bdm-title" class="serif-title text-lg font-black text-slate-800"></h4>
            <button type="button" onclick="closeBatchDetail()"
                class="text-slate-400 hover:text-slate-600 transition-colors p-1 rounded-xl hover:bg-slate-100">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="bdm-body" class="px-7 py-5 overflow-y-auto flex-1"></div>
    </div>
</div>

<script>
function openBatchDetail(id) {
    const src = document.getElementById('bdetail-' + id);
    if (!src) return;
    document.getElementById('bdm-title').textContent = src.dataset.title;
    document.getElementById('bdm-body').innerHTML = src.innerHTML;
    document.getElementById('batch-detail-modal').classList.remove('hidden');
}
function closeBatchDetail() {
    document.getElementById('batch-detail-modal').classList.add('hidden');
    document.getElementById('bdm-body').innerHTML = '';
}
document.getElementById('batch-detail-modal').addEventListener('click', function(e) {
    if (e.target === this) closeBatchDetail();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeBatchDetail();
});
</script>

<?php include '../layout_bottom.php'; ?>
