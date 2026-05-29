<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
require_role([ROLE_PRICE_CHECKER, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$active_tab = $_GET['tab'] ?? 'activity';
$success    = trim($_GET['success'] ?? '');
$error      = trim($_GET['error']   ?? '');

// ── Activity Records ──────────────────────────────────────────────────────────
$batches = $conn->query(
    "SELECT rb.*,
            u.username AS receiver_name,
            vu.username AS validator_name
     FROM receiving_batches rb
     LEFT JOIN users u  ON u.id  = rb.receiver_id
     LEFT JOIN users vu ON vu.id = rb.validator_id
     ORDER BY rb.created_at DESC
     LIMIT 100"
);

// ── Discrepancy Report — per-item amounts visible ─────────────────────────────
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$supplier_filter = trim($_GET['supplier'] ?? '');

$disc_sql = "SELECT rb.id AS batch_id, rb.supplier_name, rb.created_at AS batch_date,
                    rb.computed_subtotal, rb.tally_result,
                    ri.barcode, ri.description, ri.quantity, ri.base_price, ri.amount
             FROM receiving_batches rb
             JOIN receiving_items ri ON ri.batch_id = rb.id
             WHERE rb.tally_result = 'discrepancy'";
$disc_params = [];
$disc_types  = '';
if ($date_from) { $disc_sql .= " AND DATE(rb.created_at) >= ?"; $disc_types .= 's'; $disc_params[] = $date_from; }
if ($date_to)   { $disc_sql .= " AND DATE(rb.created_at) <= ?"; $disc_types .= 's'; $disc_params[] = $date_to; }
if ($supplier_filter) { $disc_sql .= " AND rb.supplier_name LIKE ?"; $disc_types .= 's'; $disc_params[] = '%' . $supplier_filter . '%'; }
$disc_sql .= " ORDER BY rb.id DESC, ri.id ASC LIMIT 500";

$disc_stmt = $conn->prepare($disc_sql);
if ($disc_types) {
    $disc_stmt->bind_param($disc_types, ...$disc_params);
}
$disc_stmt->execute();
$disc_items = $disc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Price Changes ─────────────────────────────────────────────────────────────
$price_changes = $conn->query(
    "SELECT pc.*, rb.supplier_name AS batch_supplier
     FROM pipeline_price_changes pc
     LEFT JOIN receiving_batches rb ON rb.id = pc.batch_id
     ORDER BY pc.created_at DESC LIMIT 100"
);

// Handle "Raise to Admin" POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['raise_price_change'])) {
    include '../../includes/csrf.php';
    csrf_verify('/project/staff/procurement/price_checker.php?tab=price_changes');

    $pc_id = intval($_POST['pc_id'] ?? 0);
    if ($pc_id > 0) {
        $pcr = $conn->prepare("SELECT * FROM pipeline_price_changes WHERE id = ? AND status = 'pending' LIMIT 1");
        $pcr->bind_param("i", $pc_id);
        $pcr->execute();
        $pc = $pcr->get_result()->fetch_assoc();
        if ($pc) {
            $msg = "Price change raised by {$username}: \"{$pc['description']}\" (barcode: {$pc['barcode']}) — ₱" . number_format($pc['old_price'], 2) . " → ₱" . number_format($pc['new_price'], 2) . " (Batch #{$pc['batch_id']}).";
            $notif = $conn->prepare("INSERT INTO notifications (recipient_role, type, batch_id, message) VALUES ('admin', 'price_change', ?, ?)");
            $notif->bind_param("is", $pc['batch_id'], $msg);
            $notif->execute();
        }
    }
    header("Location: price_checker.php?tab=price_changes&success=" . urlencode("Raised to Admin."));
    exit();
}

include '../layout_top.php';
?>

<div class="max-w-7xl mx-auto space-y-6">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Tab navigation -->
    <div class="flex gap-1 bg-slate-100 rounded-2xl p-1 w-fit">
        <?php
        $tabs = ['activity' => 'Activity Records', 'discrepancy' => 'Discrepancy Report', 'price_changes' => 'Price Changes'];
        foreach ($tabs as $key => $label):
        ?>
        <a href="?tab=<?= $key ?>"
           class="px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all <?= $active_tab === $key ? 'bg-white shadow text-slate-800' : 'text-slate-500 hover:text-slate-700' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($active_tab === 'activity'): ?>
    <!-- ── Tab 1: Activity Records ──────────────────────────────────────── -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-lg font-black text-slate-800 mb-6">All Batches</h3>
        <?php if (!$batches || $batches->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-10">No batches found.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Batch #</th>
                        <th>Supplier</th>
                        <th>Receiver</th>
                        <th>Validator</th>
                        <th>Tally</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $status_labels = [
                    'pending_request'        => ['Pending',     'bg-amber-100 text-amber-700'],
                    'pending_validation'     => ['In Review',   'bg-blue-100 text-blue-700'],
                    'validated_tally'        => ['Validated',   'bg-emerald-100 text-emerald-700'],
                    'validated_discrepancy'  => ['Discrepancy', 'bg-orange-100 text-orange-700'],
                    'on_hold'                => ['On Hold',     'bg-rose-100 text-rose-700'],
                    'completed'              => ['Completed',   'bg-green-100 text-green-700'],
                    'rejected'               => ['Rejected',    'bg-red-100 text-red-700'],
                ];
                while ($b = $batches->fetch_assoc()):
                    [$sl, $sb] = $status_labels[$b['status']] ?? ['—', 'bg-slate-100 text-slate-500'];
                ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $b['id'] ?></td>
                        <td class="font-bold"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($b['receiver_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($b['validator_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($b['tally_result'] === 'match'): ?>
                                <span class="text-emerald-600 font-black text-xs">Match</span>
                            <?php elseif ($b['tally_result'] === 'discrepancy'): ?>
                                <span class="text-rose-600 font-black text-xs">Discrepancy</span>
                            <?php else: ?>
                                <span class="text-slate-300 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="<?= $sb ?> text-[10px] font-black px-2 py-1 rounded-full uppercase"><?= $sl ?></span></td>
                        <td class="text-slate-400 text-xs"><?= date('M j, Y', strtotime($b['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($active_tab === 'discrepancy'): ?>
    <!-- ── Tab 2: Discrepancy Report ───────────────────────────────────── -->
    <div class="card-modern p-8 space-y-6">
        <h3 class="serif-title text-lg font-black text-slate-800">Discrepancy Report</h3>

        <!-- Filters -->
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="tab" value="discrepancy">
            <div>
                <label class="label-modern">From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="input-modern text-sm">
            </div>
            <div>
                <label class="label-modern">To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="input-modern text-sm">
            </div>
            <div>
                <label class="label-modern">Supplier</label>
                <input type="text" name="supplier" value="<?= htmlspecialchars($supplier_filter) ?>" placeholder="Filter by supplier" class="input-modern text-sm">
            </div>
            <button type="submit" class="btn-pos-primary px-6 py-2.5 text-xs font-black uppercase tracking-widest">Filter</button>
        </form>

        <?php if (empty($disc_items)): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-10">No discrepancy items found.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Supplier</th>
                        <th>Description</th>
                        <th>Barcode</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Base Price</th>
                        <th class="text-right">Amount</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($disc_items as $di): ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $di['batch_id'] ?></td>
                        <td class="text-xs"><?= htmlspecialchars($di['supplier_name'] ?? '—') ?></td>
                        <td class="font-bold"><?= htmlspecialchars($di['description']) ?></td>
                        <td class="font-mono text-xs"><?= htmlspecialchars($di['barcode'] ?? '—') ?></td>
                        <td class="text-right"><?= intval($di['quantity']) ?></td>
                        <td class="text-right"><?= $di['base_price'] !== null ? '₱' . number_format(floatval($di['base_price']), 2) : '—' ?></td>
                        <td class="text-right font-black text-slate-700"><?= $di['amount'] !== null ? '₱' . number_format(floatval($di['amount']), 2) : '—' ?></td>
                        <td class="text-slate-400 text-xs"><?= date('M j, Y', strtotime($di['batch_date'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($active_tab === 'price_changes'): ?>
    <!-- ── Tab 3: Price Changes ─────────────────────────────────────────── -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-lg font-black text-slate-800 mb-6">Pipeline Price Changes</h3>
        <?php if (!$price_changes || $price_changes->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-10">No price changes recorded.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Description</th>
                        <th>Barcode</th>
                        <th class="text-right">Old Price</th>
                        <th class="text-right">New Price</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($pc = $price_changes->fetch_assoc()):
                    $status_badge = match($pc['status']) {
                        'approved' => 'bg-emerald-100 text-emerald-700',
                        'rejected' => 'bg-red-100 text-red-700',
                        default    => 'bg-amber-100 text-amber-700',
                    };
                ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $pc['batch_id'] ?></td>
                        <td class="font-bold"><?= htmlspecialchars($pc['description'] ?? '—') ?></td>
                        <td class="font-mono text-xs"><?= htmlspecialchars($pc['barcode'] ?? '—') ?></td>
                        <td class="text-right">₱<?= number_format(floatval($pc['old_price']), 2) ?></td>
                        <td class="text-right font-black">₱<?= number_format(floatval($pc['new_price']), 2) ?></td>
                        <td><span class="<?= $status_badge ?> text-[10px] font-black px-2 py-1 rounded-full uppercase"><?= $pc['status'] ?></span></td>
                        <td class="text-slate-400 text-xs"><?= date('M j, Y', strtotime($pc['created_at'])) ?></td>
                        <td>
                            <?php if ($pc['status'] === 'pending'): ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="raise_price_change" value="1">
                                <input type="hidden" name="pc_id" value="<?= $pc['id'] ?>">
                                <button type="submit" class="bg-amber-100 hover:bg-amber-200 text-amber-800 text-[10px] font-black px-3 py-1.5 rounded-lg uppercase tracking-widest transition-all">
                                    Raise to Admin
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php include '../layout_bottom.php'; ?>
