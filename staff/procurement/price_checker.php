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

    // ── Flag a damaged inventory item → pending disposal + Admin bell ─────────
    // Reuses the existing product_disposals workflow: this only creates a PENDING
    // request; the Admin Disposal Queue (stock_management.php) approves it, and the
    // existing disposal_approve.php FIFO-deducts the stock. No stock is removed here.
    if (isset($_POST['flag_damage'])) {
        $pid    = intval($_POST['product_id'] ?? 0);
        $dqty   = intval($_POST['qty'] ?? 0);
        $dnotes = trim($_POST['notes'] ?? '');

        if ($pid < 1 || $dqty < 1) {
            header("Location: price_checker.php?tab=inventory&error=" . urlencode("Pick an item and a quantity of at least 1."));
            exit();
        }

        // Load this specific lot (one supplier's delivery) with its supplier + date.
        $pq = $conn->prepare(
            "SELECT p.id, p.name, p.barcode, p.quantity AS lot_qty,
                    COALESCE(s.name, rb.supplier_name)               AS supplier,
                    COALESCE(rb.inventory_pushed_at, rb.created_at)  AS delivered
             FROM products p
             LEFT JOIN suppliers s          ON s.id  = p.supplier_id
             LEFT JOIN receiving_batches rb ON rb.id = p.receiving_batch_id
             WHERE p.id = ? AND p.status = '" . PRODUCT_ACTIVE . "' LIMIT 1"
        );
        $pq->bind_param("i", $pid); $pq->execute();
        $prod = $pq->get_result()->fetch_assoc();
        if (!$prod) {
            header("Location: price_checker.php?tab=inventory&error=" . urlencode("Item not found or no longer active."));
            exit();
        }

        // Cap by THIS supplier's delivered/remaining quantity (the lot itself).
        $avail = intval($prod['lot_qty']);
        if ($dqty > $avail) {
            header("Location: price_checker.php?tab=inventory&error=" . urlencode("Quantity ($dqty) exceeds what this supplier has in stock ($avail)."));
            exit();
        }

        $reason_enum = 'Damaged';   // enum-safe; the free-text detail goes into notes
        $sup_txt     = $prod['supplier'] ?: 'unknown supplier';
        $del_txt     = $prod['delivered'] ? date('M j, Y', strtotime($prod['delivered'])) : 'unknown date';
        $notes_full  = "Flagged damaged on inventory check by @{$username}. From {$sup_txt}, delivered {$del_txt}."
                     . ($dnotes !== '' ? " Detail: {$dnotes}" : "");

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare(
                "INSERT INTO product_disposals
                    (product_id, product_name, barcode, qty, reason, notes, requested_by, requested_username, status)
                 VALUES (?,?,?,?,?,?,?,?, '" . DISPOSAL_PENDING . "')"
            );
            $ins->bind_param("ississis", $prod['id'], $prod['name'], $prod['barcode'], $dqty, $reason_enum, $notes_full, $user_id, $username);
            $ins->execute();

            // Bell notification for the Admin.
            $nmsg  = "Damage flag by @{$username}: {$dqty}× \"{$prod['name']}\" (#" . ($prod['barcode'] ?: '—') . ") "
                   . "from {$sup_txt}, delivered {$del_txt}"
                   . ($dnotes !== '' ? " — {$dnotes}" : "")
                   . ". Pending disposal review.";
            $notif = $conn->prepare("INSERT INTO notifications (recipient_role, type, message) VALUES ('admin', 'damage_flag', ?)");
            $notif->bind_param("s", $nmsg);
            $notif->execute();

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            header("Location: price_checker.php?tab=inventory&error=" . urlencode("Could not flag item: " . $e->getMessage()));
            exit();
        }

        header("Location: price_checker.php?tab=inventory&success=" . urlencode("Flagged {$dqty}× \"{$prod['name']}\" as damaged — sent to Admin for disposal approval."));
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

// ── Inventory Check — active stock with supplier + delivery date (for damage flags) ──
$inv_rows = [];
if ($active_tab === 'inventory') {
    $inv_search = trim($_GET['q'] ?? '');
    // One row PER LOT (per supplier delivery), so damage can be flagged against the
    // specific supplier's delivery with that delivery's quantity as the cap.
    $inv_sql = "SELECT p.id, p.name, p.barcode, p.quantity AS qty, p.expiry_date,
                       COALESCE(s.name, rb.supplier_name)               AS supplier,
                       COALESCE(rb.inventory_pushed_at, rb.created_at)  AS delivered
                FROM products p
                LEFT JOIN suppliers s          ON s.id  = p.supplier_id
                LEFT JOIN receiving_batches rb ON rb.id = p.receiving_batch_id
                WHERE p.status = '" . PRODUCT_ACTIVE . "' AND p.quantity > 0";
    $inv_params = []; $inv_types = '';
    if ($inv_search !== '') {
        $inv_sql .= " AND (p.name LIKE ? OR p.barcode LIKE ?)";
        $inv_types = 'ss'; $inv_like = '%' . $inv_search . '%';
        $inv_params = [$inv_like, $inv_like];
    }
    $inv_sql .= " ORDER BY p.name ASC, delivered DESC, p.id ASC";
    $inv_stmt = $conn->prepare($inv_sql);
    if ($inv_types) { $inv_stmt->bind_param($inv_types, ...$inv_params); }
    $inv_stmt->execute();
    $inv_rows = $inv_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

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
        $tabs = ['activity' => 'Activity Records', 'monitor' => 'Price Monitor', 'inventory' => 'Inventory Check'];
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

    <?php elseif ($active_tab === 'inventory'): ?>
    <!-- ── Inventory Check — flag damaged stock to Admin ───────────────────── -->
    <div class="card-modern overflow-hidden">
        <div class="p-6 border-b border-slate-50 flex items-center justify-between flex-wrap gap-3">
            <div>
                <h3 class="serif-title text-lg font-black text-slate-800">Inventory Check</h3>
                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest mt-0.5">Flag damaged stock — sent to Admin for disposal approval</p>
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="tab" value="inventory">
                <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" placeholder="Search item or barcode…"
                       class="input-modern text-sm w-56">
                <button type="submit" class="btn-pos-primary px-5 py-2.5 text-xs font-black uppercase tracking-widest">Search</button>
                <?php if (($_GET['q'] ?? '') !== ''): ?>
                <a href="?tab=inventory" class="text-xs font-bold text-slate-400 hover:text-slate-600 py-2">Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm text-left">
                <thead>
                    <tr>
                        <th class="px-6 py-4">Product</th>
                        <th class="px-4 py-4">Supplier</th>
                        <th class="px-4 py-4">Delivered</th>
                        <th class="px-4 py-4">Expiry</th>
                        <th class="px-4 py-4 text-center">Qty in Stock</th>
                        <th class="px-6 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($inv_rows)): ?>
                    <tr><td colspan="6" class="px-6 py-12 text-center text-slate-300 font-black italic text-sm">No active stock to show.</td></tr>
                    <?php else: foreach ($inv_rows as $iv):
                        $iv_qty   = intval($iv['qty']);
                        $iv_sup   = $iv['supplier'] ?: 'Unknown supplier';
                        $iv_deliv = $iv['delivered'] ? date('M j, Y', strtotime($iv['delivered'])) : '—';
                    ?>
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-6 py-4">
                            <p class="font-bold text-slate-800"><?= htmlspecialchars($iv['name']) ?></p>
                            <?php if (!empty($iv['barcode'])): ?>
                            <code class="text-[10px] text-slate-400 font-mono">#<?= htmlspecialchars($iv['barcode']) ?></code>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-slate-600 font-bold"><?= htmlspecialchars($iv_sup) ?></td>
                        <td class="px-4 py-4 text-slate-400 font-bold"><?= $iv_deliv ?></td>
                        <td class="px-4 py-4 text-slate-400 font-bold"><?= $iv['expiry_date'] ? date('M j, Y', strtotime($iv['expiry_date'])) : '—' ?></td>
                        <td class="px-4 py-4 text-center font-black text-slate-700"><?= number_format($iv_qty) ?></td>
                        <td class="px-6 py-4 text-right">
                            <button type="button"
                                onclick="openFlagModal(<?= intval($iv['id']) ?>, '<?= htmlspecialchars(addslashes($iv['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($iv_sup), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($iv_deliv), ENT_QUOTES) ?>', <?= $iv_qty ?>)"
                                class="bg-rose-50 hover:bg-rose-500 hover:text-white text-rose-600 border border-rose-100 font-black text-[10px] uppercase tracking-widest px-4 py-2 rounded-xl transition-all whitespace-nowrap">
                                Flag Damaged
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── Flag Damaged modal ──────────────────────────────────────────────────── -->
<div id="flag-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm hidden p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 animate-in">
        <h4 class="serif-title text-2xl font-black text-slate-800 mb-1">Flag Damaged Item</h4>
        <p id="flag-item-name" class="font-black text-slate-800 text-base leading-tight"></p>
        <p id="flag-detail" class="text-slate-400 text-xs font-bold mb-5"></p>
        <form method="POST" action="price_checker.php">
            <?= csrf_field() ?>
            <input type="hidden" name="flag_damage" value="1">
            <input type="hidden" name="product_id" id="flag-pid">
            <label class="label-modern text-xs">Quantity to dispose <span class="text-rose-500">*</span></label>
            <input type="number" name="qty" id="flag-qty" min="1" value="1" required class="input-modern w-full mb-1">
            <p id="flag-max" class="text-[10px] text-slate-400 font-bold mb-4"></p>
            <label class="label-modern text-xs">Reason / damage detail</label>
            <textarea name="notes" rows="3" placeholder="Describe the damage (e.g. crushed packaging, water-damaged, leaking)…"
                      class="input-modern w-full resize-none mb-5"></textarea>
            <div class="flex gap-3">
                <button type="button" onclick="closeFlagModal()" class="flex-1 border border-slate-200 text-slate-500 font-black text-[10px] uppercase tracking-widest py-3 rounded-2xl hover:bg-slate-50 transition-all">Cancel</button>
                <button type="submit" class="flex-1 bg-rose-600 hover:bg-rose-500 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-2xl shadow-lg transition-all active:scale-95">Send to Admin</button>
            </div>
        </form>
    </div>
</div>
<script>
let _flagMax = 0;
function openFlagModal(pid, name, supplier, delivered, maxQty) {
    _flagMax = maxQty;
    document.getElementById('flag-pid').value = pid;
    document.getElementById('flag-item-name').textContent = name;
    document.getElementById('flag-detail').textContent = 'From ' + supplier + ' · delivered ' + delivered;
    var q = document.getElementById('flag-qty');
    q.value = 1; q.max = maxQty;
    document.getElementById('flag-max').textContent = 'This supplier’s delivery has ' + maxQty + ' in stock';
    document.getElementById('flag-modal').classList.remove('hidden');
}
function closeFlagModal() { document.getElementById('flag-modal').classList.add('hidden'); }
document.getElementById('flag-modal')?.addEventListener('click', function (e) { if (e.target === this) closeFlagModal(); });
</script>

<?php include '../layout_bottom.php'; ?>
