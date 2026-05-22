<?php
include '../config/db.php';
include '../includes/admin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id   = $_SESSION['user_id']   ?? null;
$user_role = $_SESSION['role']       ?? '';
$uname     = $_SESSION['username']   ?? 'Unknown';
$msg       = '';

// ── Helper: log a price_update_logs entry ────────────────────────────────────
function _log_price_action(mysqli $conn, int $req_id, string $action, int $actor_id, string $actor_uname, ?float $old_price = null, ?float $new_price = null, ?string $note = null): void {
    $l = $conn->prepare("INSERT INTO price_update_logs (request_id, action, actor_id, actor_username, old_price, new_price, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($l) { $l->bind_param("isiidds", $req_id, $action, $actor_id, $actor_uname, $old_price, $new_price, $note); $l->execute(); }
}

// ── Helper: log an activity_logs entry ───────────────────────────────────────
function _log_activity(mysqli $conn, int $user_id, int $item_id, string $message, ?string $old_val = null, ?string $new_val = null): void {
    $a = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message, old_value, new_value) VALUES (?, '" . LOG_PRICES . "', ?, ?, ?, ?)");
    if ($a) { $a->bind_param("iisss", $user_id, $item_id, $message, $old_val, $new_val); $a->execute(); }
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Manual global price update (existing feature) ─────────────────────────
    if ($action === 'update_unit_price') {
        $pid       = intval($_POST['p_id']);
        $item_name = trim($_POST['item_name'] ?? 'Product');
        $t1_qty    = max(0, intval($_POST['t1_qty']    ?? 0));
        $t1_price  = max(0, floatval($_POST['t1_price'] ?? 0));
        $t2_qty    = max(0, intval($_POST['t2_qty']    ?? 0));
        $t2_price  = max(0, floatval($_POST['t2_price'] ?? 0));

        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("UPDATE products SET bulk_qty_half = ?, price_half_box = ?, bulk_qty_full = ?, price_full_box = ?, tiers_locked = 0 WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND status = '" . PRODUCT_ACTIVE . "'");
            $upd->bind_param("idids", $t1_qty, $t1_price, $t2_qty, $t2_price, $item_name);
            $upd->execute();

            $tier_note = '';
            if ($t1_qty > 0) $tier_note .= " T1: {$t1_qty}pcs @ ₱" . number_format($t1_price, 2);
            if ($t2_qty > 0) $tier_note .= " | T2: {$t2_qty}pcs @ ₱" . number_format($t2_price, 2);
            $log_msg = "TIER UPDATE: {$item_name} —{$tier_note}";
            _log_activity($conn, $user_id, $pid, $log_msg);

            $conn->commit();
            $msg = "<div class='bg-emerald-500 text-white p-4 rounded-2xl mb-6 font-bold animate-in text-center shadow-lg'>Prices updated and synced across all active batches.</div>";
        } catch (\Throwable $e) {
            $conn->rollback();
            $msg = "<div class='bg-rose-500 text-white p-4 rounded-2xl mb-6 font-bold shadow-lg'>Transaction Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    // ── Step 1 approval (single reviewer, self-approval allowed) ─────────────
    if ($action === 'price_step1') {
        $req_id = intval($_POST['req_id']);
        $rq = $conn->prepare("SELECT * FROM price_update_requests WHERE id = ? AND status = '" . PRICE_REQ_PENDING . "' LIMIT 1");
        $rq->bind_param("i", $req_id); $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        if ($req) {
            $upd = $conn->prepare("UPDATE price_update_requests SET status='" . PRICE_REQ_APPROVED . "', step1_by=?, step1_username=?, step1_at=NOW() WHERE id=?");
            $upd->bind_param("isi", $user_id, $uname, $req_id); $upd->execute();
            _log_price_action($conn, $req_id, 'step1_approved', $user_id, $uname);
            _log_activity($conn, $user_id, $req['product_id'], "PRICE REQUEST #{$req_id}: Approved by {$uname} for {$req['product_name']}. Ready to apply.");
            $msg = "<div class='bg-emerald-500 text-white p-4 rounded-2xl mb-6 font-bold animate-in text-center shadow-lg'>Approved. Price is ready to be applied.</div>";
        }
    }

    // ── Defer price apply until product goes out of stock ────────────────────
    if ($action === 'price_defer') {
        $req_id = intval($_POST['req_id']);
        $rq = $conn->prepare("SELECT * FROM price_update_requests WHERE id = ? AND status = '" . PRICE_REQ_APPROVED . "' LIMIT 1");
        $rq->bind_param("i", $req_id); $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        if ($req) {
            $upd = $conn->prepare("UPDATE price_update_requests SET status='" . PRICE_REQ_DEFERRED . "' WHERE id=?");
            $upd->bind_param("i", $req_id); $upd->execute();
            _log_price_action($conn, $req_id, 'deferred', $user_id, $uname, null, null, 'Scheduled: will auto-apply when stock reaches zero');
            _log_activity($conn, $user_id, $req['product_id'], "PRICE REQUEST #{$req_id}: Deferred by {$uname} — auto-applies when {$req['product_name']} stock reaches zero.");
            $msg = "<div class='bg-blue-500 text-white p-4 rounded-2xl mb-6 font-bold animate-in text-center shadow-lg'>Scheduled. Price will apply automatically when stock runs out.</div>";
        }
    }

    // ── Cancel a deferred request (revert to approved) ────────────────────────
    if ($action === 'price_cancel_defer') {
        $req_id = intval($_POST['req_id']);
        $rq = $conn->prepare("SELECT * FROM price_update_requests WHERE id = ? AND status = '" . PRICE_REQ_DEFERRED . "' LIMIT 1");
        $rq->bind_param("i", $req_id); $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        if ($req) {
            $upd = $conn->prepare("UPDATE price_update_requests SET status='" . PRICE_REQ_APPROVED . "' WHERE id=?");
            $upd->bind_param("i", $req_id); $upd->execute();
            _log_price_action($conn, $req_id, 'cancelled', $user_id, $uname, null, null, 'Deferred apply cancelled — returned to approved');
            $msg = "<div class='bg-slate-500 text-white p-4 rounded-2xl mb-6 font-bold animate-in text-center shadow-lg'>Schedule cancelled. You can apply the price manually now.</div>";
        }
    }

    // ── Apply approved price ──────────────────────────────────────────────────
    if ($action === 'price_apply') {
        $req_id = intval($_POST['req_id']);
        $rq = $conn->prepare("SELECT * FROM price_update_requests WHERE id = ? AND status = '" . PRICE_REQ_APPROVED . "' LIMIT 1");
        $rq->bind_param("i", $req_id); $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        if ($req) {
            $conn->begin_transaction();
            try {
                $ph = $conn->prepare("INSERT INTO price_history (product_id, old_price, new_price) VALUES (?, ?, ?)");
                $ph->bind_param("idd", $req['product_id'], $req['current_price'], $req['proposed_price']); $ph->execute();

                $upd_prod = $conn->prepare("UPDATE products SET price = ?, tiers_locked = 1 WHERE barcode = ? AND status IN ('" . PRODUCT_ACTIVE . "','" . PRODUCT_ARCHIVED . "')");
                $upd_prod->bind_param("ds", $req['proposed_price'], $req['barcode']); $upd_prod->execute();

                $upd_req = $conn->prepare("UPDATE price_update_requests SET status='" . PRICE_REQ_APPLIED . "', applied_by=?, applied_username=?, applied_at=NOW() WHERE id=?");
                $upd_req->bind_param("isi", $user_id, $uname, $req_id); $upd_req->execute();

                // Cascade: any other queued requests for this barcode now have a new current_price baseline
                $upd_cur = $conn->prepare("UPDATE price_update_requests SET current_price = ? WHERE barcode = ? AND id != ? AND status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')");
                $upd_cur->bind_param("dsi", $req['proposed_price'], $req['barcode'], $req_id);
                $upd_cur->execute();

                $conn->commit();
                _log_price_action($conn, $req_id, 'applied', $user_id, $uname, $req['current_price'], $req['proposed_price']);
                _log_activity($conn, $user_id, $req['product_id'],
                    "PRICE APPLIED: {$req['product_name']} ₱" . number_format($req['current_price'], 2) . " → ₱" . number_format($req['proposed_price'], 2) . " (Request #{$req_id})",
                    number_format($req['current_price'], 2), number_format($req['proposed_price'], 2)
                );
                $msg = "<div class='bg-emerald-600 text-white p-4 rounded-2xl mb-6 font-bold animate-in text-center shadow-lg'>Price applied successfully. POS updated.</div>";
            } catch (\Throwable $e) {
                $conn->rollback();
                $msg = "<div class='bg-rose-500 text-white p-4 rounded-2xl mb-6 font-bold shadow-lg'>Apply Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

    // ── Reject request ────────────────────────────────────────────────────────
    if ($action === 'price_reject') {
        $req_id = intval($_POST['req_id']);
        $reason = trim($_POST['reject_reason'] ?? '');
        $rq = $conn->prepare("SELECT * FROM price_update_requests WHERE id = ? AND status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "') LIMIT 1");
        $rq->bind_param("i", $req_id); $rq->execute();
        $req = $rq->get_result()->fetch_assoc();
        if ($req) {
            $upd = $conn->prepare("UPDATE price_update_requests SET status='" . PRICE_REQ_REJECTED . "', rejected_by=?, rejected_username=?, rejected_at=NOW(), reject_reason=? WHERE id=?");
            $upd->bind_param("issi", $user_id, $uname, $reason, $req_id); $upd->execute();
            $log_action = ($req['status'] === PRICE_REQ_PENDING) ? 'step1_rejected' : 'step2_rejected';
            _log_price_action($conn, $req_id, $log_action, $user_id, $uname, null, null, $reason);
            _log_activity($conn, $user_id, $req['product_id'], "PRICE REQUEST #{$req_id} rejected by {$uname}. Reason: {$reason}");
            $msg = "<div class='bg-rose-500 text-white p-4 rounded-2xl mb-6 font-bold animate-in text-center shadow-lg'>Request rejected and logged.</div>";
        }
    }
}

include 'layout_top.php';

// ── DATA QUERIES ──────────────────────────────────────────────────────────────
$sup_filter = $_GET['sup_id'] ?? '';

// Pending / active price update requests
$pending_requests = $conn->query(
    "SELECT r.*, u.username AS submitter_name
     FROM price_update_requests r
     LEFT JOIN users u ON r.submitted_by = u.id
     WHERE r.status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')
     ORDER BY r.created_at ASC"
);

// Recent closed requests (last 10 applied or rejected)
$closed_requests = $conn->query(
    "SELECT r.* FROM price_update_requests r
     WHERE r.status IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')
     ORDER BY COALESCE(r.applied_at, r.rejected_at) DESC LIMIT 10"
);

// Master price table
$sql = "SELECT
            MIN(p.id) as id,
            p.name AS product_name,
            MIN(p.barcode) as barcode,
            MAX(p.category) as category,
            MAX(p.price) as price,
            MAX(p.bulk_qty_half)   as bulk_qty_half,
            MAX(p.price_half_box)  as price_half_box,
            MAX(p.bulk_qty_full)   as bulk_qty_full,
            MAX(p.price_full_box)  as price_full_box,
            GROUP_CONCAT(DISTINCT CONCAT(s.name, '||', s.supplier_code) SEPARATOR ';;') as supplier_list,
            COUNT(DISTINCT s.id) as supplier_count,
            MAX(p.tiers_locked) as tiers_locked
        FROM products p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.status = '" . PRODUCT_ACTIVE . "'
        AND p.id NOT IN (
            SELECT product_id FROM quantity_alerts
            WHERE status IN ('" . ALERT_PENDING . "','" . ALERT_RECOUNTING . "','" . ALERT_SUBMITTED . "') AND product_id IS NOT NULL
            UNION
            SELECT p2.id FROM products p2
            JOIN quantity_alerts qa ON qa.barcode = p2.barcode
            WHERE qa.status IN ('" . ALERT_PENDING . "','" . ALERT_RECOUNTING . "','" . ALERT_SUBMITTED . "') AND qa.product_id IS NULL
        )";
if (!empty($sup_filter)) $sql .= " AND p.supplier_id = " . intval($sup_filter);
$sql .= " GROUP BY LOWER(TRIM(p.name)) ORDER BY p.name ASC";
$result = $conn->query($sql);

$suppliers_list = $conn->query("SELECT id, name, supplier_code FROM suppliers WHERE id IN (SELECT DISTINCT supplier_id FROM products WHERE status = '" . PRODUCT_ACTIVE . "') ORDER BY name ASC");

$pending_rows = [];
while ($r = $pending_requests->fetch_assoc()) $pending_rows[] = $r;

$closed_rows = [];
while ($r = $closed_requests->fetch_assoc()) $closed_rows[] = $r;

// Keyed by barcode — each barcode maps to an ordered array of all pending requests
$pending_by_barcode = [];
foreach ($pending_rows as $pr) {
    $pending_by_barcode[$pr['barcode']][] = $pr;
}
?>

<style>
.sup-preview-wrapper { position: relative; display: inline-block; cursor: pointer; }
.sup-dropdown {
    position: absolute; top: 100%; left: 0; min-width: 220px;
    background: white; border: 1px solid #e2e8f0; border-radius: 1rem;
    box-shadow: 0 15px 30px -10px rgba(0,0,0,0.15);
    z-index: 100; padding: 12px; margin-top: 8px;
    visibility: hidden; opacity: 0; transform: translateY(10px);
    transition: all 0.2s ease;
}
.sup-preview-wrapper:hover .sup-dropdown { visibility: visible; opacity: 1; transform: translateY(0); }
.sup-item-list { font-size: 11px; font-weight: 600; color: #64748b; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
.sup-item-list:last-child { border-bottom: none; }

/* Step tracker */
.step-track { display: flex; align-items: center; gap: 0; }
.step-node {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 900; flex-shrink: 0;
}
.step-node.done   { background: #10b981; color: white; }
.step-node.active { background: #f59e0b; color: white; }
.step-node.idle   { background: #f1f5f9; color: #94a3b8; }
.step-line { flex: 1; height: 2px; min-width: 18px; }
.step-line.done  { background: #10b981; }
.step-line.idle  { background: #e2e8f0; }
</style>

<div class="max-w-7xl mx-auto space-y-10 animate-in pb-20">

    <?= $msg ?>

    <!-- ── PENDING PRICE SUMMARY BANNER ────────────────────────────────────── -->
    <?php if (!empty($pending_rows)): ?>
    <div class="flex items-center gap-4 bg-amber-50 border-2 border-amber-200 rounded-[2rem] px-8 py-5">
        <div class="w-9 h-9 bg-amber-500 rounded-xl flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <div class="flex-1">
            <p class="font-black text-amber-800 text-sm"><?= count($pending_rows) ?> price update<?= count($pending_rows) > 1 ? 's' : '' ?> pending approval — see highlighted rows below</p>
            <p class="text-amber-600 text-xs font-bold mt-0.5">Detected from delivery — two-step approval required before price goes live</p>
        </div>
        <span class="bg-amber-500 text-white font-black text-sm px-4 py-1.5 rounded-full flex-shrink-0"><?= count($pending_rows) ?> pending</span>
    </div>
    <?php endif; ?>

    <!-- ── FILTER PANEL ──────────────────────────────────────────────────────── -->
    <div class="bg-white p-8 rounded-[3rem] border border-slate-100 shadow-xl flex flex-col md:flex-row justify-between items-center gap-6">
        <div>
            <h3 class="serif-title text-3xl font-black text-slate-800">Global Pricing Tool</h3>
            <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-1">Updates apply immediately to POS and inventory</p>
        </div>
        <select onchange="navigate('price_maintenance.php?sup_id=' + this.value)" class="input-modern md:w-80 h-[58px] bg-slate-50 font-bold text-slate-600">
            <option value="">-- All Suppliers --</option>
            <?php
            $suppliers_list->data_seek(0);
            while ($s = $suppliers_list->fetch_assoc()):
            ?>
                <option value="<?= $s['id'] ?>" <?= ($sup_filter == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?> (#<?= $s['supplier_code'] ?>)</option>
            <?php endwhile; ?>
        </select>
    </div>

    <!-- ── MASTER PRICE TABLE ────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-modern text-left min-w-full">
                <thead>
                    <tr class="bg-slate-50/50">
                        <th class="px-8 py-6" width="28%">Product Description</th>
                        <th class="px-6 py-6 text-center" width="13%">Retail Price</th>
                        <th class="px-4 py-6 text-center" width="24%">Bulk Tiers</th>
                        <th class="px-6 py-6" width="20%">Source History</th>
                        <th class="px-8 py-6 text-right" width="15%">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($r = $result->fetch_assoc()):
                            $suppliers_raw  = explode(';;', $r['supplier_list'] ?? '');
                            $primary_sup    = explode('||', $suppliers_raw[0]);
                            $reqs           = $pending_by_barcode[$r['barcode']] ?? [];
                            $first_req      = $reqs[0] ?? null;
                            $has_pending    = !empty($reqs);
                            $tiers_locked   = intval($r['tiers_locked']) === 1;
                        ?>
                        <tr class="hover:bg-slate-50/30 transition-all <?= $tiers_locked ? 'bg-rose-50/40' : ($has_pending ? 'bg-amber-50/40' : '') ?>">
                            <td class="px-8 py-5">
                                <p class="font-bold text-slate-800 text-base leading-tight"><?= htmlspecialchars($r['product_name']) ?></p>
                                <div class="mt-1.5 flex items-center gap-1.5 flex-wrap">
                                    <code class="text-[10px] font-bold text-slate-400 bg-slate-50 px-2 py-0.5 rounded border">ID: #<?= $r['barcode'] ?></code>
                                    <span class="text-[10px] font-black text-blue-500 uppercase bg-blue-50/50 px-2 py-0.5 rounded"><?= htmlspecialchars($r['category']) ?></span>
                                    <?php if ($tiers_locked): ?>
                                        <span class="text-[9px] font-black text-rose-600 bg-rose-100 px-2 py-0.5 rounded-full border border-rose-200 uppercase animate-pulse">⚠ Tiers Need Review</span>
                                    <?php elseif ($has_pending): ?>
                                        <span class="text-[9px] font-black text-amber-600 bg-amber-100 px-2 py-0.5 rounded-full border border-amber-200 uppercase"><?= count($reqs) > 1 ? count($reqs) . ' Price Updates Pending' : 'Price Update Pending' ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td class="px-6 py-5 text-center">
                                <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest mb-1">Current</p>
                                <p class="text-xl font-black <?= $has_pending ? 'text-amber-500' : 'text-emerald-600' ?>">₱<?= number_format($r['price'], 2) ?></p>
                                <?php if ($first_req): ?>
                                    <p class="text-[8px] font-black text-slate-300 mt-0.5">→ <span class="text-rose-500">₱<?= number_format($first_req['proposed_price'], 2) ?></span><?= count($reqs) > 1 ? ' <span class="text-slate-300">+' . (count($reqs)-1) . ' more</span>' : '' ?></p>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-4 <?= $tiers_locked ? 'bg-rose-50/60' : '' ?>">
                                <?php if ($tiers_locked): ?>
                                    <p class="text-[8px] font-black text-rose-500 uppercase tracking-widest mb-2">Update tiers to unlock POS</p>
                                <?php endif; ?>
                                <form id="p_form_<?= $r['id'] ?>" class="flex flex-col gap-2" style="min-width:270px">
                                    <!-- Tiers side-by-side -->
                                    <div class="grid grid-cols-2 gap-2">
                                        <div class="bg-blue-50/50 border border-blue-100 rounded-xl p-2">
                                            <p class="text-[7px] font-black text-blue-400 uppercase mb-1.5">T1 — Half Box</p>
                                            <div class="flex gap-1">
                                                <input type="number" name="t1_qty" min="0" value="<?= intval($r['bulk_qty_half']) ?>"
                                                    placeholder="Qty"
                                                    class="w-full bg-white border border-blue-100 rounded-lg px-2 py-1.5 text-center font-black text-slate-700 text-xs outline-none">
                                                <input type="number" name="t1_price" step="0.01" min="0" value="<?= number_format($r['price_half_box'], 2, '.', '') ?>"
                                                    placeholder="₱"
                                                    class="w-full bg-white border border-blue-100 rounded-lg px-2 py-1.5 text-right font-black text-blue-600 text-xs outline-none">
                                            </div>
                                        </div>
                                        <div class="bg-purple-50/50 border border-purple-100 rounded-xl p-2">
                                            <p class="text-[7px] font-black text-purple-400 uppercase mb-1.5">T2 — Full Box</p>
                                            <div class="flex gap-1">
                                                <input type="number" name="t2_qty" min="0" value="<?= intval($r['bulk_qty_full']) ?>"
                                                    placeholder="Qty"
                                                    class="w-full bg-white border border-purple-100 rounded-lg px-2 py-1.5 text-center font-black text-slate-700 text-xs outline-none">
                                                <input type="number" name="t2_price" step="0.01" min="0" value="<?= number_format($r['price_full_box'], 2, '.', '') ?>"
                                                    placeholder="₱"
                                                    class="w-full bg-white border border-purple-100 rounded-lg px-2 py-1.5 text-right font-black text-purple-600 text-xs outline-none">
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </td>

                            <td class="px-6 py-5">
                                <div class="sup-preview-wrapper">
                                    <p class="font-bold text-slate-700 text-sm leading-tight"><?= htmlspecialchars($primary_sup[0] ?? '—') ?></p>
                                    <span class="text-[10px] font-black text-blue-500 bg-blue-50 px-2 py-0.5 rounded-lg border border-blue-100 uppercase mt-1.5 inline-block">ID: #<?= $primary_sup[1] ?? '?' ?></span>
                                    <?php if ($r['supplier_count'] > 1): ?>
                                        <div class="inline-block ml-1.5 px-2 py-0.5 bg-slate-100 text-slate-500 rounded text-[9px] font-bold">+<?= $r['supplier_count'] - 1 ?> more</div>
                                        <div class="sup-dropdown shadow-2xl">
                                            <p class="text-[9px] font-black text-slate-300 uppercase tracking-widest mb-2">Past Source Suppliers:</p>
                                            <?php foreach ($suppliers_raw as $s_item): $s_data = explode('||', $s_item); ?>
                                                <div class="sup-item-list"><?= htmlspecialchars($s_data[0]) ?> <span class="text-blue-400 ml-1">#<?= $s_data[1] ?></span></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td class="px-8 py-5 text-right">
                                <button onclick="saveGlobalPrice(<?= $r['id'] ?>, '<?= addslashes($r['product_name']) ?>')"
                                    class="<?= $tiers_locked ? 'bg-rose-600 hover:bg-rose-500 animate-pulse' : 'bg-slate-900 hover:bg-emerald-600' ?> text-white px-6 py-2.5 rounded-2xl font-black text-[10px] uppercase tracking-widest transition-all shadow-md active:scale-95">
                                    <?= $tiers_locked ? 'Update Tiers ↑' : 'Apply Changes' ?>
                                </button>
                            </td>
                        </tr>

                        <?php foreach ($reqs as $req_idx => $req):
                            $step        = $req['status'];
                            $s1_done     = in_array($step, [PRICE_REQ_STEP1_APPROVED, PRICE_REQ_APPROVED, PRICE_REQ_DEFERRED]);
                            $can_step1   = $step === PRICE_REQ_PENDING;
                            $can_apply   = in_array($step, PRICE_REQ_APPLY_STATUSES);
                            $is_deferred = $step === PRICE_REQ_DEFERRED;
                            $can_reject  = in_array($step, [PRICE_REQ_PENDING, PRICE_REQ_STEP1_APPROVED, PRICE_REQ_APPROVED, PRICE_REQ_DEFERRED]);
                            $pct_change = $req['current_price'] > 0
                                ? round((($req['proposed_price'] - $req['current_price']) / $req['current_price']) * 100, 1)
                                : 0;
                            $is_increase = $pct_change >= 0;
                        ?>
                        <tr class="border-l-4 border-amber-400 bg-amber-50/60">
                            <td colspan="5" class="px-8 py-4 border-t-2 border-b-2 border-amber-300">
                                <div class="flex flex-wrap lg:flex-nowrap gap-4 items-center">

                                    <!-- Submitted info -->
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[8px] font-black text-amber-500 uppercase tracking-widest mb-1.5">Price Update Request — Pending Approval</p>
                                        <p class="font-bold text-slate-800 text-sm leading-tight mb-0.5"><?= htmlspecialchars($req['product_name']) ?></p>
                                        <code class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded inline-block mb-1.5">#<?= htmlspecialchars($req['barcode']) ?></code>
                                        <div class="flex flex-wrap gap-1.5">
                                            <?php if ($req['supplier_name']): ?>
                                                <span class="text-[10px] font-black text-blue-500 bg-blue-50 px-2 py-0.5 rounded-lg border border-blue-100 uppercase"><?= htmlspecialchars($req['supplier_name']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($req['invoice']): ?>
                                                <span class="text-[10px] font-black text-slate-400 bg-white px-2 py-0.5 rounded-lg border uppercase">INV: <?= htmlspecialchars($req['invoice']) ?></span>
                                            <?php endif; ?>
                                            <span class="text-[10px] font-black text-slate-300 uppercase"><?= date('M d, Y', strtotime($req['created_at'])) ?></span>
                                        </div>
                                        <p class="text-[10px] text-slate-400 mt-1">Submitted by <span class="font-black text-slate-600"><?= htmlspecialchars($req['submitted_username'] ?? '—') ?></span></p>
                                    </div>

                                    <!-- Price comparison -->
                                    <div class="flex items-center gap-3 shrink-0">
                                        <div class="text-center">
                                            <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest mb-0.5">Current</p>
                                            <p class="text-lg font-black text-slate-500">₱<?= number_format($req['current_price'], 2) ?></p>
                                        </div>
                                        <div class="flex flex-col items-center gap-0.5">
                                            <svg class="w-3.5 h-3.5 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                            <span class="text-[9px] font-black px-1.5 py-0.5 rounded-full <?= $is_increase ? 'text-rose-600 bg-rose-50' : 'text-emerald-600 bg-emerald-50' ?>"><?= ($is_increase ? '+' : '') . $pct_change ?>%</span>
                                        </div>
                                        <div class="text-center">
                                            <p class="text-[8px] font-black text-amber-400 uppercase tracking-widest mb-0.5">Proposed</p>
                                            <p class="text-lg font-black <?= $is_increase ? 'text-rose-600' : 'text-emerald-600' ?>">₱<?= number_format($req['proposed_price'], 2) ?></p>
                                        </div>
                                    </div>

                                    <!-- Step tracker -->
                                    <div class="shrink-0 text-center">
                                        <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest mb-1.5">Approval Progress</p>
                                        <div class="step-track">
                                            <div class="step-node done" title="Submitted">S</div>
                                            <div class="step-line <?= $s1_done ? 'done' : 'idle' ?>"></div>
                                            <div class="step-node <?= $s1_done ? 'done' : ($step === 'pending' ? 'active' : 'idle') ?>" title="Reviewer">1</div>
                                            <div class="step-line <?= $s1_done ? 'done' : 'idle' ?>"></div>
                                            <div class="step-node <?= $is_deferred ? 'active' : ($can_apply && !$is_deferred ? 'active' : 'idle') ?>" title="Apply">
                                                <?= $is_deferred ? '⏱' : 'A' ?>
                                            </div>
                                        </div>
                                        <?php if ($s1_done): ?><p class="text-[8px] text-emerald-600 font-bold mt-1">By: <?= htmlspecialchars($req['step1_username'] ?? '?') ?></p><?php endif; ?>
                                    </div>

                                    <!-- Action buttons -->
                                    <div class="flex flex-col gap-2 shrink-0 min-w-[148px]">
                                        <?php if ($is_deferred): ?>
                                            <div class="flex items-center gap-1.5 bg-blue-50 border border-blue-200 rounded-xl px-3 py-2">
                                                <span class="text-blue-500 text-sm">⏱</span>
                                                <span class="text-[9px] font-black text-blue-600 uppercase">Applies on stockout</span>
                                            </div>
                                            <button onclick="applyPrice(<?= $req['id'] ?>, '<?= addslashes($req['product_name']) ?>', '<?= number_format($req['proposed_price'], 2) ?>')"
                                                class="bg-emerald-600 hover:bg-emerald-500 text-white font-black text-[10px] uppercase tracking-widest px-4 py-2 rounded-xl transition-all shadow-md active:scale-95">
                                                Apply Now
                                            </button>
                                            <button onclick="cancelDefer(<?= $req['id'] ?>)"
                                                class="border border-slate-200 text-slate-500 hover:bg-slate-50 font-black text-[10px] uppercase tracking-widest px-4 py-2 rounded-xl transition-all active:scale-95">
                                                Cancel Schedule
                                            </button>
                                        <?php elseif ($can_apply): ?>
                                            <button onclick="applyPrice(<?= $req['id'] ?>, '<?= addslashes($req['product_name']) ?>', '<?= number_format($req['proposed_price'], 2) ?>')"
                                                class="bg-emerald-600 hover:bg-emerald-500 text-white font-black text-[10px] uppercase tracking-widest px-4 py-2 rounded-xl transition-all shadow-md active:scale-95">
                                                Apply Now
                                            </button>
                                            <button onclick="doDefer(<?= $req['id'] ?>, '<?= addslashes($req['product_name']) ?>')"
                                                class="bg-blue-500 hover:bg-blue-400 text-white font-black text-[10px] uppercase tracking-widest px-4 py-2 rounded-xl transition-all shadow-md active:scale-95">
                                                Apply on Stockout
                                            </button>
                                        <?php elseif ($can_step1): ?>
                                            <button onclick="doStep(<?= $req['id'] ?>, 'price_step1', 'Approve price update for <?= addslashes($req['product_name']) ?>?')"
                                                class="bg-amber-500 hover:bg-amber-400 text-white font-black text-[10px] uppercase tracking-widest px-4 py-2.5 rounded-xl transition-all shadow-md active:scale-95">
                                                Approve
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($can_reject && !$is_deferred): ?>
                                            <button onclick="openRejectModal(<?= $req['id'] ?>, '<?= addslashes($req['product_name']) ?>')"
                                                class="border border-rose-200 text-rose-500 hover:bg-rose-50 font-black text-[10px] uppercase tracking-widest px-4 py-2 rounded-xl transition-all active:scale-95">
                                                Reject
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── RECENT PRICE CHANGE HISTORY ──────────────────────────────────────── -->
    <?php if (!empty($closed_rows)): ?>
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="px-10 py-7 border-b border-slate-50">
            <h3 class="serif-title text-xl font-black text-slate-700">Recent Price Change History</h3>
            <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mt-1">Last 10 resolved requests</p>
        </div>
        <div class="divide-y divide-slate-50">
        <?php foreach ($closed_rows as $cr):
            $is_applied  = $cr['status'] === PRICE_REQ_APPLIED;
            $resolved_at = $is_applied ? $cr['applied_at'] : $cr['rejected_at'];
            $resolved_by = $is_applied ? $cr['applied_username'] : $cr['rejected_username'];
        ?>
        <div class="px-10 py-5 flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex-1">
                <p class="font-bold text-slate-700"><?= htmlspecialchars($cr['product_name']) ?></p>
                <p class="text-[10px] text-slate-400 font-bold mt-1">
                    <?= htmlspecialchars($cr['supplier_name'] ?? '—') ?> · INV: <?= htmlspecialchars($cr['invoice'] ?? '—') ?> · <?= date('M d, Y', strtotime($cr['created_at'])) ?>
                </p>
                <?php if (!$is_applied && $cr['reject_reason']): ?>
                    <p class="text-[10px] text-rose-500 font-bold mt-1">Reason: <?= htmlspecialchars($cr['reject_reason']) ?></p>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-4 shrink-0">
                <span class="text-slate-400 font-black text-sm line-through">₱<?= number_format($cr['current_price'], 2) ?></span>
                <span class="font-black text-lg <?= $is_applied ? 'text-emerald-600' : 'text-slate-300' ?>">₱<?= number_format($cr['proposed_price'], 2) ?></span>
                <span class="px-3 py-1.5 rounded-full font-black text-[9px] uppercase tracking-widest <?= $is_applied ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-rose-50 text-rose-500 border border-rose-200' ?>">
                    <?= $is_applied ? 'Applied' : 'Rejected' ?>
                </span>
            </div>
            <div class="text-right shrink-0">
                <p class="text-[10px] font-black text-slate-400"><?= htmlspecialchars($resolved_by ?? '—') ?></p>
                <p class="text-[9px] text-slate-300"><?= $resolved_at ? date('M d, Y · g:i A', strtotime($resolved_at)) : '—' ?></p>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── REJECTION MODAL ───────────────────────────────────────────────────────── -->
<div id="reject-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm hidden">
    <div class="bg-white rounded-[2.5rem] shadow-2xl p-10 w-full max-w-md mx-4 animate-in">
        <h4 class="serif-title text-2xl font-black text-slate-800 mb-2">Reject Price Update</h4>
        <p id="reject-modal-product" class="text-slate-400 text-sm font-bold mb-6"></p>
        <form id="reject-form">
            <input type="hidden" name="action" value="price_reject">
            <input type="hidden" name="req_id" id="reject-req-id">
            <div class="mb-6">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Reason for Rejection</label>
                <textarea name="reject_reason" id="reject-reason-text" rows="4"
                    class="input-modern w-full resize-none"
                    placeholder="Explain why this price change is being rejected..."></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeRejectModal()" class="flex-1 border border-slate-200 text-slate-500 font-black text-[10px] uppercase tracking-widest py-3 rounded-2xl hover:bg-slate-50 transition-all">Cancel</button>
                <button type="button" onclick="submitReject()" class="flex-1 bg-rose-600 hover:bg-rose-500 text-white font-black text-[10px] uppercase tracking-widest py-3 rounded-2xl transition-all shadow-lg active:scale-95">Confirm Reject</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Global price manual update ────────────────────────────────────────────────
async function saveGlobalPrice(pid, itemName) {
    const form = document.getElementById('p_form_' + pid);
    if (!await customConfirm('Tier pricing will be updated across all active batches.', "Update tiers for '" + itemName + "'?")) return;

    const fd = new FormData();
    fd.append('action',    'update_unit_price');
    fd.append('p_id',      pid);
    fd.append('item_name', itemName);
    fd.append('t1_qty',    form.querySelector('[name="t1_qty"]').value);
    fd.append('t1_price',  form.querySelector('[name="t1_price"]').value);
    fd.append('t2_qty',    form.querySelector('[name="t2_qty"]').value);
    fd.append('t2_price',  form.querySelector('[name="t2_price"]').value);

    if (typeof navigate === 'function') {
        navigate('price_maintenance.php', fd);
    } else {
        window.location.reload();
    }
}

// ── Step 1 / Step 2 approval ──────────────────────────────────────────────────
async function doStep(reqId, action, confirmMsg) {
    if (!await customConfirm(confirmMsg, 'Confirm Approval')) return;
    const fd = new FormData();
    fd.append('action', action);
    fd.append('req_id', reqId);
    if (typeof navigate === 'function') navigate('price_maintenance.php', fd);
    else window.location.reload();
}

// ── Apply final price ─────────────────────────────────────────────────────────
async function applyPrice(reqId, name, price) {
    if (!await customConfirm(
        'This will immediately update the live selling price to ₱' + price + ' for all POS transactions.',
        "Apply price for '" + name + "'?"
    )) return;
    const fd = new FormData();
    fd.append('action', 'price_apply');
    fd.append('req_id', reqId);
    if (typeof navigate === 'function') navigate('price_maintenance.php', fd);
    else window.location.reload();
}

// ── Defer price apply until stockout ─────────────────────────────────────────
async function doDefer(reqId, name) {
    if (!await customConfirm(
        'The new price will be held and applied automatically when this product runs out of stock.',
        "Schedule deferred apply for '" + name + "'?"
    )) return;
    const fd = new FormData();
    fd.append('action', 'price_defer');
    fd.append('req_id', reqId);
    if (typeof navigate === 'function') navigate('price_maintenance.php', fd);
    else window.location.reload();
}

// ── Cancel a deferred schedule (revert to approved) ──────────────────────────
async function cancelDefer(reqId) {
    if (!await customConfirm(
        'The deferred schedule will be cancelled. You can still apply the price manually.',
        'Cancel Schedule?'
    )) return;
    const fd = new FormData();
    fd.append('action', 'price_cancel_defer');
    fd.append('req_id', reqId);
    if (typeof navigate === 'function') navigate('price_maintenance.php', fd);
    else window.location.reload();
}

// ── Rejection modal ───────────────────────────────────────────────────────────
function openRejectModal(reqId, name) {
    document.getElementById('reject-req-id').value  = reqId;
    document.getElementById('reject-modal-product').textContent = name;
    document.getElementById('reject-reason-text').value = '';
    document.getElementById('reject-modal').classList.remove('hidden');
}
function closeRejectModal() {
    document.getElementById('reject-modal').classList.add('hidden');
}
async function submitReject() {
    const reason = document.getElementById('reject-reason-text').value.trim();
    if (!reason) { alert('Please provide a reason for rejection.'); return; }
    const fd = new FormData(document.getElementById('reject-form'));
    if (typeof navigate === 'function') navigate('price_maintenance.php', fd);
    else window.location.reload();
    closeRejectModal();
}
document.getElementById('reject-modal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});
</script>

<?php include 'layout_bottom.php'; ?>
