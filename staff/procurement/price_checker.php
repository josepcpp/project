<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_PRICE_CHECKER, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$active_tab = $_GET['tab'] ?? 'activity';
$success    = trim($_GET['success'] ?? '');
$error      = trim($_GET['error']   ?? '');

/**
 * Load each delivered item's two most recent Validator-encoded base prices.
 * Grouped by barcode (fallback to description when barcode is blank).
 * Returns: [ ['barcode','description','delivered','recent'(|null),'last_date','deliveries'], ... ]
 */
function load_delivery_prices(mysqli $conn, string $search = ''): array {
    $sql = "SELECT ri.barcode, ri.description, ri.base_price,
                   COALESCE(rb.validated_at, rb.created_at) AS d_date, rb.id AS batch_id
            FROM receiving_items ri
            JOIN receiving_batches rb ON rb.id = ri.batch_id
            WHERE ri.base_price IS NOT NULL";
    $params = []; $types = '';
    if ($search !== '') {
        $sql .= " AND (ri.description LIKE ? OR ri.barcode LIKE ?)";
        $types .= 'ss'; $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY COALESCE(ri.barcode, ri.description) ASC, d_date DESC, rb.id DESC";
    $stmt = $conn->prepare($sql);
    if ($types) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();

    $groups = []; // key => deliveries (most recent first)
    while ($r = $res->fetch_assoc()) {
        $key = ($r['barcode'] !== null && $r['barcode'] !== '') ? 'b:' . $r['barcode'] : 'd:' . $r['description'];
        $groups[$key][] = $r;
    }

    $items = [];
    foreach ($groups as $list) {
        $delivered = $list[0];
        $recent    = $list[1] ?? null;
        $items[] = [
            'barcode'     => $delivered['barcode'],
            'description' => $delivered['description'],
            'delivered'   => floatval($delivered['base_price']),
            'recent'      => $recent ? floatval($recent['base_price']) : null,
            'last_date'   => $delivered['d_date'],
            'deliveries'  => count($list),
        ];
    }
    usort($items, fn($a, $b) => strcasecmp($a['description'], $b['description']));
    return $items;
}

// ── Active reporting: flag item(s) to Admin ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('price_checker.php?tab=monitor');

    // Single-item flag — reports the latest delivery's price movement
    if (isset($_POST['flag_item'])) {
        $bc = trim($_POST['barcode'] ?? '');
        if ($bc !== '') {
            $q = $conn->prepare(
                "SELECT ri.base_price, ri.description
                 FROM receiving_items ri
                 JOIN receiving_batches rb ON rb.id = ri.batch_id
                 WHERE ri.base_price IS NOT NULL AND ri.barcode = ?
                 ORDER BY COALESCE(rb.validated_at, rb.created_at) DESC, rb.id DESC
                 LIMIT 2"
            );
            $q->bind_param("s", $bc);
            $q->execute();
            $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            if ($rows) {
                $name      = $rows[0]['description'];
                $delivered = floatval($rows[0]['base_price']);
                $recent    = isset($rows[1]) ? floatval($rows[1]['base_price']) : null;
                if ($recent === null) {
                    $trend = "first recorded delivery";
                } else {
                    $delta = $delivered - $recent;
                    $pct   = $recent > 0 ? ($delta / $recent) * 100 : 0;
                    $dir   = $delta > 0 ? "▲ hike" : ($delta < 0 ? "▼ drop" : "no change");
                    $trend = "₱" . number_format($recent, 2) . " → ₱" . number_format($delivered, 2)
                           . " ($dir " . ($delta >= 0 ? '+' : '') . number_format($delta, 2)
                           . " / " . ($pct >= 0 ? '+' : '') . number_format($pct, 1) . "%)";
                }
                $msg = "Price Monitor flag by @{$username}: \"{$name}\" (barcode: {$bc}) — delivered price $trend. Review required.";
                $notif = $conn->prepare("INSERT INTO notifications (recipient_role, type, message) VALUES ('admin', 'price_change', ?)");
                $notif->bind_param("s", $msg);
                $notif->execute();
            }
        }
        header("Location: price_checker.php?tab=monitor&success=" . urlencode("Item flagged to Admin."));
        exit();
    }

    // Consolidated report — every item whose latest delivery is a price hike
    if (isset($_POST['send_hike_report'])) {
        $all     = load_delivery_prices($conn, '');
        $flagged = array_filter($all, fn($i) => $i['recent'] !== null && $i['delivered'] > $i['recent']);
        if (empty($flagged)) {
            header("Location: price_checker.php?tab=monitor&error=" . urlencode("No price hikes on the latest deliveries — nothing to report."));
            exit();
        }
        $lines = [];
        foreach ($flagged as $f) {
            $delta = $f['delivered'] - $f['recent'];
            $pct   = $f['recent'] > 0 ? ($delta / $f['recent']) * 100 : 0;
            $lines[] = "• {$f['description']} (" . ($f['barcode'] ?: '—') . "): ₱" . number_format($f['recent'], 2)
                     . " → ₱" . number_format($f['delivered'], 2) . " (+" . number_format($pct, 1) . "%)";
        }
        $msg = "Price Monitor hike report by @{$username} — " . count($flagged) . " item(s) with a higher latest delivery price:\n"
             . implode("\n", $lines);
        $notif = $conn->prepare("INSERT INTO notifications (recipient_role, type, message) VALUES ('admin', 'price_change', ?)");
        $notif->bind_param("s", $msg);
        $notif->execute();
        header("Location: price_checker.php?tab=monitor&success=" . urlencode("Hike report sent to Admin (" . count($flagged) . " item(s)).") );
        exit();
    }
}

// ── Activity Records — full Receiver + Validator detail per batch ──────────────
$batches = $conn->query(
    "SELECT rb.*,
            u.username  AS receiver_name,
            vu.username AS validator_name
     FROM receiving_batches rb
     LEFT JOIN users u  ON u.id  = rb.receiver_id
     LEFT JOIN users vu ON vu.id = rb.validator_id
     ORDER BY rb.created_at DESC
     LIMIT 100"
);

// Pre-load all items for the listed batches in one pass, grouped by batch_id
$items_by_batch = [];
if ($batches && $batches->num_rows > 0) {
    $ir = $conn->query(
        "SELECT ri.batch_id, ri.barcode, ri.description, ri.quantity, ri.damaged_qty,
                ri.expiry_date, ri.base_price, ri.amount, ri.match_flag
         FROM receiving_items ri
         JOIN receiving_batches rb ON rb.id = ri.batch_id
         WHERE rb.id IN (SELECT id FROM (SELECT id FROM receiving_batches ORDER BY created_at DESC LIMIT 100) t)
         ORDER BY ri.batch_id DESC, ri.id ASC"
    );
    if ($ir) {
        while ($row = $ir->fetch_assoc()) {
            $items_by_batch[$row['batch_id']][] = $row;
        }
    }
}

// ── Price Monitor — Validator-encoded delivery base-price trend ───────────────
$monitor_search = trim($_GET['q'] ?? '');
$monitor_items  = load_delivery_prices($conn, $monitor_search);

include '../layout_top.php';
?>

<div class="max-w-7xl mx-auto space-y-6">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold whitespace-pre-line"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Tab navigation -->
    <div class="flex gap-1 bg-slate-100 rounded-2xl p-1 w-fit">
        <?php
        $tabs = ['activity' => 'Activity Records', 'monitor' => 'Price Monitor'];
        foreach ($tabs as $key => $label):
        ?>
        <a href="?tab=<?= $key ?>"
           class="relative px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all <?= $active_tab === $key ? 'bg-white shadow text-slate-800' : 'text-slate-500 hover:text-slate-700' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($active_tab === 'activity'): ?>
    <!-- ── Tab 1: Activity Records (detailed) ───────────────────────────── -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-lg font-black text-slate-800 mb-1">Activity Records</h3>
        <p class="text-slate-400 text-xs font-bold mb-6">Full Receiver &amp; Validator trail per batch. Click a row to expand the item-level breakdown.</p>
        <?php if (!$batches || $batches->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-10">No batches found.</p>
        <?php else: ?>
        <?php
        $status_labels = [
            'pending_request'        => ['Pending',     'bg-amber-100 text-amber-700'],
            'pending_validation'     => ['In Review',   'bg-blue-100 text-blue-700'],
            'pending_reprice'        => ['Reprice',     'bg-fuchsia-100 text-fuchsia-700'],
            'validated_tally'        => ['Validated',   'bg-emerald-100 text-emerald-700'],
            'validated_discrepancy'  => ['Discrepancy', 'bg-orange-100 text-orange-700'],
            'on_hold'                => ['On Hold',     'bg-rose-100 text-rose-700'],
            'completed'              => ['Completed',   'bg-green-100 text-green-700'],
            'rejected'               => ['Rejected',    'bg-red-100 text-red-700'],
        ];
        ?>
        <div class="space-y-3">
        <?php while ($b = $batches->fetch_assoc()):
            [$sl, $sb] = $status_labels[$b['status']] ?? ['—', 'bg-slate-100 text-slate-500'];
            $rows = $items_by_batch[$b['id']] ?? [];
        ?>
            <details class="bg-white border border-slate-100 rounded-2xl overflow-hidden group">
                <summary class="cursor-pointer list-none px-5 py-4 flex flex-wrap items-center gap-4 hover:bg-slate-50 transition-all">
                    <span class="font-black text-slate-500">#<?= $b['id'] ?></span>
                    <span class="font-bold text-slate-800 flex-1 min-w-[140px]"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></span>
                    <span class="text-xs text-slate-500">Receiver: <span class="font-bold text-slate-700"><?= htmlspecialchars($b['receiver_name'] ?? '—') ?></span></span>
                    <span class="text-xs text-slate-500">Validator: <span class="font-bold text-slate-700"><?= htmlspecialchars($b['validator_name'] ?? '—') ?></span></span>
                    <?php if ($b['tally_result'] === 'match'): ?>
                        <span class="text-emerald-600 font-black text-xs">Match</span>
                    <?php elseif ($b['tally_result'] === 'discrepancy'): ?>
                        <span class="text-rose-600 font-black text-xs">Discrepancy</span>
                    <?php else: ?>
                        <span class="text-slate-300 text-xs">—</span>
                    <?php endif; ?>
                    <span class="<?= $sb ?> text-[10px] font-black px-2 py-1 rounded-full uppercase"><?= $sl ?></span>
                    <span class="text-slate-400 text-xs"><?= date('M j, Y', strtotime($b['created_at'])) ?></span>
                    <span class="text-slate-300 text-xs font-black group-open:rotate-90 transition-transform">▸</span>
                </summary>

                <div class="border-t border-slate-100 px-5 py-4 bg-slate-50/50 space-y-4">
                    <!-- Receiver / Validator meta -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div class="bg-white rounded-xl border border-slate-100 px-4 py-3">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Receiver</p>
                            <p class="font-bold text-slate-700"><?= htmlspecialchars($b['receiver_name'] ?? '—') ?></p>
                            <p class="text-slate-400">Encoded: <?= $b['created_at'] ? date('M j, Y g:i A', strtotime($b['created_at'])) : '—' ?></p>
                        </div>
                        <div class="bg-white rounded-xl border border-slate-100 px-4 py-3">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Validator</p>
                            <p class="font-bold text-slate-700"><?= htmlspecialchars($b['validator_name'] ?? '—') ?></p>
                            <p class="text-slate-400">Validated: <?= $b['validated_at'] ? date('M j, Y g:i A', strtotime($b['validated_at'])) : '—' ?></p>
                        </div>
                    </div>

                    <!-- Item-level breakdown -->
                    <?php if (empty($rows)): ?>
                        <p class="text-slate-400 text-xs font-bold text-center py-4">No items recorded for this batch.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="table-modern w-full text-sm">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Barcode</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-center">Damaged</th>
                                    <th>Expiry</th>
                                    <th class="text-right">Base Price</th>
                                    <th class="text-right">Amount</th>
                                    <th class="text-center">Matched</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $ri): ?>
                                <tr>
                                    <td class="font-bold text-slate-700"><?= htmlspecialchars($ri['description']) ?></td>
                                    <td class="font-mono text-xs text-slate-400"><?= htmlspecialchars($ri['barcode'] ?? '—') ?></td>
                                    <td class="text-center"><?= intval($ri['quantity']) ?></td>
                                    <td class="text-center <?= intval($ri['damaged_qty']) > 0 ? 'font-black text-rose-500' : 'text-slate-300' ?>"><?= intval($ri['damaged_qty']) ?></td>
                                    <td class="text-xs text-slate-500"><?= $ri['expiry_date'] ? date('M j, Y', strtotime($ri['expiry_date'])) : '—' ?></td>
                                    <td class="text-right"><?= $ri['base_price'] !== null ? '₱' . number_format(floatval($ri['base_price']), 2) : '—' ?></td>
                                    <td class="text-right font-black text-slate-700"><?= $ri['amount'] !== null ? '₱' . number_format(floatval($ri['amount']), 2) : '—' ?></td>
                                    <td class="text-center"><?= intval($ri['match_flag']) === 1 ? '<span class="text-emerald-500 font-black">✓</span>' : '<span class="text-slate-300">—</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($active_tab === 'monitor'): ?>
    <!-- ── Tab 2: Price Monitor ─────────────────────────────────────────── -->
    <div class="card-modern p-8 space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 class="serif-title text-lg font-black text-slate-800">Price Monitor</h3>
                <p class="text-slate-400 text-xs font-bold mt-1">Validator-encoded base price per item — previous delivery vs the latest. Flag price hikes to Admin.</p>
            </div>
            <form method="POST" onsubmit="return confirm('Send a report of every item whose latest delivery is a price hike to Admin?');">
                <?= csrf_field() ?>
                <input type="hidden" name="send_hike_report" value="1">
                <button type="submit" class="btn-pos-primary px-6 py-2.5 text-xs font-black uppercase tracking-widest whitespace-nowrap">
                    Send Hike Report to Admin
                </button>
            </form>
        </div>

        <!-- Search -->
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="tab" value="monitor">
            <div class="flex-1 min-w-[220px]">
                <label class="label-modern">Search</label>
                <input type="text" name="q" value="<?= htmlspecialchars($monitor_search) ?>" data-live="#monitorRows" placeholder="Type to filter by item name…" class="input-modern text-sm w-full">
            </div>
            <button type="submit" class="btn-pos-primary px-6 py-2.5 text-xs font-black uppercase tracking-widest">Search</button>
            <?php if ($monitor_search !== ''): ?>
            <a href="?tab=monitor" class="text-xs font-bold text-slate-400 hover:text-slate-600 py-3">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($monitor_items)): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-10">No delivered items with encoded prices yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Barcode</th>
                        <th class="text-right">Recent Price</th>
                        <th class="text-right">Delivered Price</th>
                        <th class="text-center">Trend</th>
                        <th>Last Delivered</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="monitorRows">
                <?php foreach ($monitor_items as $m):
                    $delivered = floatval($m['delivered']);
                    $recent    = $m['recent'];                       // null when only one delivery
                    $has_prev  = ($recent !== null);
                    $delta     = $has_prev ? $delivered - floatval($recent) : 0;
                    $pct       = ($has_prev && floatval($recent) > 0) ? ($delta / floatval($recent)) * 100 : 0;

                    if (!$has_prev) {
                        $row_cls = '';
                    } elseif ($delta > 0) {
                        $row_cls = 'bg-amber-50';                    // hike
                    } elseif ($delta < 0) {
                        $row_cls = 'bg-emerald-50/40';               // drop
                    } else {
                        $row_cls = '';
                    }
                ?>
                    <tr class="<?= $row_cls ?>">
                        <td class="live-name font-bold text-slate-700"><?= htmlspecialchars($m['description'] ?? '—') ?></td>
                        <td class="font-mono text-xs text-slate-400"><?= htmlspecialchars($m['barcode'] ?? '—') ?></td>
                        <td class="text-right text-slate-500"><?= $has_prev ? '₱' . number_format(floatval($recent), 2) : '—' ?></td>
                        <td class="text-right font-black text-slate-800">₱<?= number_format($delivered, 2) ?></td>
                        <td class="text-center">
                            <?php if (!$has_prev): ?>
                                <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-sky-100 text-sky-700 uppercase">New</span>
                            <?php elseif ($delta > 0): ?>
                                <span class="text-rose-600 font-black whitespace-nowrap">▲ +₱<?= number_format($delta, 2) ?> <span class="text-[10px]">(+<?= number_format($pct, 1) ?>%)</span></span>
                            <?php elseif ($delta < 0): ?>
                                <span class="text-emerald-600 font-black whitespace-nowrap">▼ −₱<?= number_format(abs($delta), 2) ?> <span class="text-[10px]">(<?= number_format($pct, 1) ?>%)</span></span>
                            <?php else: ?>
                                <span class="text-slate-400 font-black">– no change</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs text-slate-400"><?= $m['last_date'] ? date('M j, Y', strtotime($m['last_date'])) : '—' ?></td>
                        <td class="text-right">
                            <?php if ($m['barcode']): ?>
                            <form method="POST" onsubmit="return confirm('Flag &quot;<?= htmlspecialchars($m['description'], ENT_QUOTES) ?>&quot; to Admin for price review?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="flag_item" value="1">
                                <input type="hidden" name="barcode" value="<?= htmlspecialchars($m['barcode'], ENT_QUOTES) ?>">
                                <button type="submit" class="bg-slate-100 hover:bg-rose-100 hover:text-rose-700 text-slate-600 text-[10px] font-black px-3 py-1.5 rounded-lg uppercase tracking-widest transition-all whitespace-nowrap">
                                    Flag to Admin
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php include '../layout_bottom.php'; ?>
