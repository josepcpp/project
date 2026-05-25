<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$current_role = strtolower($_SESSION['role'] ?? '');
$is_admin     = in_array($current_role, ROLES_ADMIN_AND_UP);
$is_super     = $current_role === ROLE_SUPERADMIN;

// ── PENDING COUNTS ────────────────────────────────────────────────────────────
$pending_count        = 0;
$dr_pending_count     = 0;
$disposal_pending_count = 0;
if ($is_admin) {
    $pc = $conn->query("SELECT COUNT(*) AS c FROM refunds WHERE status = '" . REFUND_PENDING . "'");
    $pending_count = intval($pc->fetch_assoc()['c'] ?? 0);

    $dpc = $conn->query("SELECT COUNT(*) AS c FROM delivery_return_requests WHERE status = '" . DR_PENDING . "'");
    $dr_pending_count = intval($dpc->fetch_assoc()['c'] ?? 0);

    $dispc = $conn->query("SELECT COUNT(*) AS c FROM product_disposals WHERE status = '" . DISPOSAL_PENDING . "'");
    $disposal_pending_count = intval($dispc->fetch_assoc()['c'] ?? 0);
}

include '../layout_top.php';

$active_tab = $_GET['tab'] ?? 'sales';

// ── SALES REFUND QUEUE ────────────────────────────────────────────────────────
$queue_items = [];
if ($is_admin && $active_tab === 'queue') {
    $qstmt = $conn->prepare("
        SELECT r.id, r.sale_id, r.product_id, r.qty, r.disposition,
               r.amount_refunded, r.reason, r.status, r.override_note, r.reject_note,
               r.created_at,
               s.receipt_no,
               p.name AS product_name,
               u.username AS requested_by,
               u.full_name AS requested_by_name
        FROM refunds r
        JOIN sales s    ON r.sale_id    = s.id
        JOIN products p ON r.product_id = p.id
        LEFT JOIN users u ON r.requested_by = u.id
        WHERE r.status IN ('" . REFUND_PENDING . "', '" . REFUND_REJECTED . "')
        ORDER BY r.status ASC, r.created_at DESC
    ");
    $qstmt->execute();
    $queue_items = $qstmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── DELIVERY RETURN REQUESTS QUEUE ────────────────────────────────────────────
$dr_queue_items = [];
if ($is_admin && $active_tab === 'queue') {
    $drq = $conn->query("
        SELECT r.*
        FROM delivery_return_requests r
        ORDER BY FIELD(r.status,'pending','approved','rejected'), r.created_at DESC
        LIMIT 60
    ");
    while ($rrow = $drq->fetch_assoc()) {
        $ist = $conn->prepare("SELECT i.product_name, i.qty, i.reason, i.unit_price, i.notes FROM delivery_return_request_items i WHERE i.request_id = ?");
        $ist->bind_param("i", $rrow['id']); $ist->execute();
        $rrow['items'] = $ist->get_result()->fetch_all(MYSQLI_ASSOC);
        $dr_queue_items[] = $rrow;
    }
}

// ── DISPOSAL QUEUE (admin) ───────────────────────────────────────────────────
$disposal_queue = [];
if ($is_admin && $active_tab === 'queue') {
    $dq = $conn->query("SELECT d.*, u.full_name AS requester_fullname
        FROM product_disposals d
        LEFT JOIN users u ON d.requested_by = u.id
        WHERE d.status = '" . DISPOSAL_PENDING . "'
        ORDER BY d.created_at ASC");
    if ($dq) $disposal_queue = $dq->fetch_all(MYSQLI_ASSOC);
}

// ── DISPOSAL TAB DATA ────────────────────────────────────────────────────────
$disposal_product      = null;
$disposal_products     = []; // multiple batches when search returns more than one row
$disposal_history      = [];
$near_expiry_batches   = [];
$disposal_search       = trim($_GET['disposal_search'] ?? '');
$disposal_product_id   = intval($_GET['disposal_product_id'] ?? 0);
if ($active_tab === 'disposal') {
    if ($disposal_product_id > 0) {
        // Direct load by product_id — used by near-expiry button and batch selector
        $dp = $conn->prepare("SELECT * FROM products WHERE id = ? AND status = '" . PRODUCT_ACTIVE . "'");
        $dp->bind_param("i", $disposal_product_id); $dp->execute();
        $disposal_product = $dp->get_result()->fetch_assoc();
    } elseif ($disposal_search !== '') {
        $dsl = "%{$disposal_search}%";
        $ds  = $conn->prepare("SELECT * FROM products WHERE (name LIKE ? OR barcode LIKE ?) AND status = '" . PRODUCT_ACTIVE . "' ORDER BY expiry_date ASC, name ASC");
        $ds->bind_param("ss", $dsl, $dsl); $ds->execute();
        $disposal_products = $ds->get_result()->fetch_all(MYSQLI_ASSOC);
        // Single match — load directly, no picker needed
        if (count($disposal_products) === 1) {
            $disposal_product  = $disposal_products[0];
            $disposal_products = [];
        }
    }
    if ($is_admin) {
        $dh = $conn->query("SELECT * FROM product_disposals ORDER BY created_at DESC LIMIT 30");
    } else {
        $dh = $conn->prepare("SELECT * FROM product_disposals WHERE requested_by = ? ORDER BY created_at DESC LIMIT 30");
        $dh_uid = intval($_SESSION['user_id']);
        $dh->bind_param("i", $dh_uid); $dh->execute();
        $dh = $dh->get_result();
    }
    if ($dh) $disposal_history = $dh->fetch_all(MYSQLI_ASSOC);

    // Near-expiry products from supplier batches (≤7 days or already expired)
    $ne_q = $conn->query("
        SELECT p.id, p.name, p.barcode, p.quantity, p.expiry_date,
               s.id AS supplier_id, s.name AS supplier_name, s.invoice_number
        FROM products p
        JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.status = '" . PRODUCT_ACTIVE . "'
          AND p.expiry_date IS NOT NULL
          AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL " . DEFAULT_EXPIRY_WARNING_DAYS . " DAY)
        ORDER BY p.expiry_date ASC, s.name ASC
        LIMIT 50
    ");
    if ($ne_q) {
        while ($ne_row = $ne_q->fetch_assoc()) {
            $key = intval($ne_row['supplier_id']);
            if (!isset($near_expiry_batches[$key])) {
                $near_expiry_batches[$key] = [
                    'supplier_name'  => $ne_row['supplier_name'],
                    'invoice_number' => $ne_row['invoice_number'],
                    'items'          => [],
                ];
            }
            $near_expiry_batches[$key]['items'][] = $ne_row;
        }
    }
}

// ── RECENT SALES ──────────────────────────────────────────────────────────────
$recent_sales = [];
if ($active_tab === 'sales') {
    $rs_q = $conn->query("SELECT id, receipt_no, total, payment_mode, created_at FROM sales ORDER BY created_at DESC LIMIT 12");
    if ($rs_q) while ($r = $rs_q->fetch_assoc()) $recent_sales[] = $r;
}

// ── SALES REFUND SEARCH ───────────────────────────────────────────────────────
$sale_data     = null;
$items         = null;
$receipt_query = isset($_GET['receipt_no']) ? trim(str_replace('#', '', $_GET['receipt_no'])) : null;

if ($active_tab === 'sales' && $receipt_query) {
    $stmt = $conn->prepare("SELECT * FROM sales WHERE receipt_no = ?");
    $stmt->bind_param("s", $receipt_query); $stmt->execute();
    $sale_data = $stmt->get_result()->fetch_assoc();

    if ($sale_data) {
        $sale_id    = $sale_data['id'];
        $stmt_items = $conn->prepare("
            SELECT si.*, p.name, p.barcode,
                   COALESCE((SELECT SUM(r.qty) FROM refunds r
                             WHERE r.sale_id = si.sale_id
                               AND r.product_id = si.product_id
                               AND r.status != '" . REFUND_REJECTED . "'), 0) AS total_refunded
            FROM sales_items si
            JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = ?
        ");
        $stmt_items->bind_param("i", $sale_id); $stmt_items->execute();
        $items = $stmt_items->get_result();
    }
}

// ── DELIVERY RETURN SEARCH ────────────────────────────────────────────────────
$supplier_data         = null;
$delivery_items        = [];
$existing_dr_requests  = [];
$invoice_query         = isset($_GET['invoice_no']) ? trim($_GET['invoice_no']) : null;
$delivery_batches      = [];
$selected_batch        = null;
$batch_id_param        = intval($_GET['batch_id'] ?? 0);

if ($active_tab === 'delivery') {
    // Batch receipt list — completed batches only
    $bq = $conn->query("
        SELECT pb.id, pb.supplier_name, pb.invoice, pb.staff_username,
               pb.officialized_at, pb.status, pb.item_count, pb.discrepancy_count
        FROM procurement_batches pb
        WHERE pb.status IN ('" . BATCH_COMPLETE_CLEAN . "','" . BATCH_COMPLETE_ERRORS . "')
        ORDER BY pb.officialized_at DESC
        LIMIT 60
    ");
    if ($bq) $delivery_batches = $bq->fetch_all(MYSQLI_ASSOC);

    // Load items via batch selection
    if ($batch_id_param > 0) {
        $bstmt = $conn->prepare("SELECT pb.*, s.id AS sup_id, s.name AS sup_name, s.invoice_number AS sup_invoice, s.amount AS sup_amount
            FROM procurement_batches pb LEFT JOIN suppliers s ON pb.supplier_id = s.id
            WHERE pb.id = ? AND pb.status IN ('" . BATCH_COMPLETE_CLEAN . "','" . BATCH_COMPLETE_ERRORS . "') LIMIT 1");
        $bstmt->bind_param("i", $batch_id_param); $bstmt->execute();
        $selected_batch = $bstmt->get_result()->fetch_assoc();
        if ($selected_batch && $selected_batch['sup_id']) {
            $supplier_data = [
                'id'             => $selected_batch['sup_id'],
                'name'           => $selected_batch['sup_name'],
                'invoice_number' => $selected_batch['sup_invoice'],
                'amount'         => $selected_batch['sup_amount'],
            ];
            $invoice_query = $selected_batch['sup_invoice'] ?? $selected_batch['invoice'];
        }
    }

    // Load items via invoice search
    if (!$supplier_data && $invoice_query) {
        $sup_stmt = $conn->prepare("SELECT * FROM suppliers WHERE invoice_number = ? LIMIT 1");
        $sup_stmt->bind_param("s", $invoice_query); $sup_stmt->execute();
        $supplier_data = $sup_stmt->get_result()->fetch_assoc();
    }

    // Fetch delivery items + existing requests once supplier is known
    if ($supplier_data) {
        $sup_id   = intval($supplier_data['id']);
        $del_stmt = $conn->prepare("
            SELECT p.id, p.name, p.barcode, p.price, p.quantity,
                   COALESCE((SELECT SUM(i.qty) FROM delivery_return_request_items i
                             JOIN delivery_return_requests r ON i.request_id = r.id
                             WHERE i.product_id = p.id AND r.supplier_id = ?
                               AND r.status != '" . DR_REJECTED . "'), 0) AS total_returned
            FROM products p
            WHERE p.supplier_id = ? AND p.status = '" . PRODUCT_ACTIVE . "'
            ORDER BY p.name ASC
        ");
        $del_stmt->bind_param("ii", $sup_id, $sup_id); $del_stmt->execute();
        $delivery_items = $del_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $edr2 = $conn->prepare("SELECT id, status, ticket_no, requested_username, created_at FROM delivery_return_requests WHERE invoice_no = ? ORDER BY created_at DESC LIMIT 5");
        $inv_key = $supplier_data['invoice_number'];
        $edr2->bind_param("s", $inv_key); $edr2->execute();
        $existing_dr_requests = $edr2->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<div class="max-w-7xl mx-auto space-y-8 animate-in pb-20">

    <!-- ── TABS ──────────────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-2 flex gap-2 flex-wrap">
        <a href="refund_management.php?tab=sales"
           class="flex-1 py-4 rounded-[1.5rem] text-center font-black text-sm uppercase tracking-widest transition-all min-w-[120px]
                  <?= $active_tab === 'sales' ? 'bg-slate-900 text-white shadow-lg' : 'text-slate-400 hover:bg-slate-50' ?>">
            Customer Returns
        </a>

        <?php if ($is_admin):
            $combined_pending = $pending_count + $dr_pending_count + $disposal_pending_count;
        ?>
        <a href="refund_management.php?tab=queue"
           class="relative flex-1 py-4 rounded-[1.5rem] text-center font-black text-sm uppercase tracking-widest transition-all min-w-[120px]
                  <?= $active_tab === 'queue' ? 'bg-amber-500 text-white shadow-lg shadow-amber-100' : 'text-slate-400 hover:bg-slate-50' ?>">
            Approval Queue
            <?php if ($combined_pending > 0): ?>
                <span class="absolute -top-1 -right-1 bg-rose-500 text-white text-[10px] font-black w-5 h-5 rounded-full flex items-center justify-center shadow"><?= $combined_pending ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <a href="refund_management.php?tab=delivery"
           class="flex-1 py-4 rounded-[1.5rem] text-center font-black text-sm uppercase tracking-widest transition-all min-w-[120px]
                  <?= $active_tab === 'delivery' ? 'bg-rose-500 text-white shadow-lg shadow-rose-100' : 'text-slate-400 hover:bg-slate-50' ?>">
            Delivery Returns
        </a>

        <a href="refund_management.php?tab=disposal"
           class="flex-1 py-4 rounded-[1.5rem] text-center font-black text-sm uppercase tracking-widest transition-all min-w-[120px]
                  <?= $active_tab === 'disposal' ? 'bg-orange-500 text-white shadow-lg shadow-orange-100' : 'text-slate-400 hover:bg-slate-50' ?>">
            Expired Items
        </a>
    </div>

    <?php if ($active_tab === 'sales'): ?>
    <!-- ════════════════════════════════════════════════════════════════
         TAB — CUSTOMER RETURNS
    ════════════════════════════════════════════════════════════════ -->

    <div class="flex gap-5 items-start">

        <!-- Recent Sales Panel -->
        <div class="w-[270px] flex-shrink-0 flex flex-col gap-3">
            <div class="bg-white rounded-[1.5rem] border border-slate-100 shadow-sm p-4">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">Recent Sales</p>
                <div class="flex flex-col gap-1.5 overflow-y-auto" style="max-height: 68vh;">
                    <?php if (empty($recent_sales)): ?>
                        <p class="text-slate-300 text-xs font-bold text-center py-6">No sales yet.</p>
                    <?php else: foreach ($recent_sales as $rs): ?>
                    <?php $rs_active = ($receipt_query === $rs['receipt_no']); ?>
                    <a href="refund_management.php?tab=sales&receipt_no=<?= urlencode($rs['receipt_no']) ?>"
                       class="flex flex-col gap-0.5 px-3 py-2.5 rounded-xl transition-all cursor-pointer
                              <?= $rs_active ? 'bg-slate-900 text-white' : 'hover:bg-slate-50 border border-transparent hover:border-slate-100' ?>">
                        <span class="font-black text-[11px] <?= $rs_active ? 'text-emerald-400' : 'text-slate-700' ?> truncate">
                            #<?= htmlspecialchars($rs['receipt_no']) ?>
                        </span>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] font-bold <?= $rs_active ? 'text-slate-300' : 'text-slate-400' ?>">
                                <?= date('M j · g:i A', strtotime($rs['created_at'])) ?>
                            </span>
                            <span class="text-[10px] font-black <?= $rs_active ? 'text-white' : 'text-emerald-600' ?> flex-shrink-0">
                                ₱<?= number_format($rs['total'], 2) ?>
                            </span>
                        </div>
                        <?php if (!empty($rs['payment_mode'])): ?>
                        <span class="text-[9px] <?= $rs_active ? 'text-slate-400' : 'text-slate-300' ?> font-bold uppercase tracking-wide">
                            <?= htmlspecialchars($rs['payment_mode']) ?>
                        </span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Search + Results -->
        <div class="flex-1 min-w-0 flex flex-col gap-5">
            <div class="card-modern shadow-xl">
                <h3 class="serif-title text-2xl font-bold mb-6">Refund Auditor</h3>
                <form method="GET" action="refund_management.php" class="flex gap-4">
                    <input type="hidden" name="tab" value="sales">
                    <input type="text" name="receipt_no" placeholder="Enter Receipt Number (e.g. RCPT-20250512-A3F2)..." required
                           class="input-modern flex-1 shadow-sm uppercase font-bold"
                           value="<?= htmlspecialchars($receipt_query ?? '') ?>">
                    <button type="submit" class="btn-pos-primary px-10">Search Record</button>
                </form>
            </div>

            <?php if ($receipt_query && !$sale_data): ?>
                <div class="card-modern text-center py-16">
                    <p class="text-slate-400 font-black uppercase text-sm">No receipt found for "<?= htmlspecialchars($receipt_query) ?>"</p>
                </div>
            <?php elseif (!$receipt_query): ?>
                <div class="card-modern text-center py-16">
                    <p class="text-slate-300 font-black text-sm uppercase tracking-widest">Select a receipt or search above</p>
                </div>
            <?php endif; ?>

            <?php if ($sale_data): ?>
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden animate-in">
                <div class="p-8 bg-slate-900 text-white flex justify-between items-center">
                    <div>
                        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-widest">Receipt Verified</p>
                        <h4 class="text-2xl font-bold">#<?= htmlspecialchars($sale_data['receipt_no']) ?></h4>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-slate-500 font-bold uppercase mb-1">Receipt Total</p>
                        <h4 class="text-2xl font-black text-emerald-400">₱<?= number_format($sale_data['total'], 2) ?></h4>
                    </div>
                </div>
                <table class="table-modern text-left min-w-full">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-10 py-6">Item Description</th>
                            <th class="px-6 py-6 text-center">Remaining Qty</th>
                            <th class="px-10 py-6 text-right">Refund Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php
                        $count = 0;
                        while ($item = $items->fetch_assoc()):
                            $rem = $item['qty'] - $item['total_refunded'];
                            if ($rem <= 0) continue;
                            $count++;
                        ?>
                        <tr class="hover:bg-slate-50 transition-all">
                            <td class="px-10 py-6">
                                <p class="font-bold text-slate-800 text-lg"><?= htmlspecialchars($item['name']) ?></p>
                                <code class="text-[10px] text-slate-400">Barcode: #<?= htmlspecialchars($item['barcode']) ?></code>
                            </td>
                            <td class="px-6 py-6 text-center font-black text-slate-700 text-2xl"><?= $rem ?></td>
                            <td class="px-10 py-6 text-right">
                                <form id="ref_form_<?= $item['product_id'] ?>" class="bg-slate-50 p-4 rounded-3xl border border-slate-100 flex flex-col gap-3 max-w-sm ml-auto">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="sale_id"    value="<?= $sale_data['id'] ?>">
                                    <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                    <input type="hidden" name="receipt_no" value="<?= htmlspecialchars($sale_data['receipt_no']) ?>">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <p class="text-[9px] font-black text-slate-400 uppercase">Return Qty</p>
                                            <input type="number" name="qty" value="1" min="1" max="<?= $rem ?>"
                                                   class="input-modern bg-white text-center font-black h-10 mt-1">
                                        </div>
                                        <div>
                                            <p class="text-[9px] font-black text-slate-400 uppercase">State</p>
                                            <select name="disposition" class="input-modern bg-white h-10 mt-1 text-xs font-bold">
                                                <option value="restock">Good — Restock</option>
                                                <option value="damaged">Damaged — Dispose</option>
                                            </select>
                                        </div>
                                    </div>
                                    <?php if ($is_admin): ?>
                                    <button type="button" id="btn_<?= $item['product_id'] ?>"
                                            onclick="triggerRefundAction(<?= $item['product_id'] ?>, false)"
                                            class="bg-rose-500 text-white w-full py-3 rounded-2xl font-black text-[10px] uppercase shadow-lg shadow-rose-100 hover:bg-rose-600 transition-all">
                                        Process Reversal
                                    </button>
                                    <?php else: ?>
                                    <button type="button" id="btn_<?= $item['product_id'] ?>"
                                            onclick="triggerRefundAction(<?= $item['product_id'] ?>, true)"
                                            class="bg-amber-500 text-white w-full py-3 rounded-2xl font-black text-[10px] uppercase shadow-lg shadow-amber-100 hover:bg-amber-600 transition-all">
                                        Submit Request
                                    </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($count === 0): ?>
                            <tr><td colspan="3" class="p-20 text-center text-slate-300 font-bold italic opacity-30">Fully Refunded.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($active_tab === 'queue' && $is_admin): ?>
    <!-- ════════════════════════════════════════════════════════════════
         TAB — SALES REFUND QUEUE
    ════════════════════════════════════════════════════════════════ -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-amber-500 text-white rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800">Refund Approval Queue</h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">
                    Review staff-submitted refund requests
                    <?php if ($is_super): ?>&nbsp;&middot;&nbsp;<span class="text-rose-500">Super Admin: Override available on rejected entries</span><?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <?php if (empty($queue_items)): ?>
        <div class="card-modern text-center py-20">
            <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            </div>
            <p class="text-slate-400 font-black uppercase text-sm">No pending requests</p>
        </div>
    <?php else: ?>
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden animate-in">
        <table class="table-modern text-left min-w-full">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="px-8 py-5">Receipt / Product</th>
                    <th class="px-6 py-5 text-center">Qty</th>
                    <th class="px-6 py-5 text-center">Est. Amount</th>
                    <th class="px-6 py-5 text-center">Disposition</th>
                    <th class="px-6 py-5 text-center">Requested By</th>
                    <th class="px-6 py-5 text-center">Date</th>
                    <th class="px-6 py-5 text-center">Status</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($queue_items as $qi):
                    $is_pending  = $qi['status'] === REFUND_PENDING;
                    $is_rejected = $qi['status'] === REFUND_REJECTED;
                    $req_name    = $qi['requested_by_name'] ?: ($qi['requested_by'] ?? '—');
                ?>
                <tr class="hover:bg-slate-50 transition-all <?= $is_rejected ? 'opacity-70' : '' ?>">
                    <td class="px-8 py-5">
                        <p class="font-bold text-slate-800"><?= htmlspecialchars($qi['product_name']) ?></p>
                        <code class="text-[10px] text-slate-400 font-mono">#<?= htmlspecialchars($qi['receipt_no']) ?></code>
                        <?php $q_note = $qi['reject_note'] ?? $qi['override_note'] ?? ''; if ($is_rejected && $q_note): ?>
                            <p class="text-[10px] text-rose-400 mt-1 italic">Reject reason: <?= htmlspecialchars($q_note) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-5 text-center font-black text-slate-700 text-xl"><?= intval($qi['qty']) ?></td>
                    <td class="px-6 py-5 text-center"><span class="font-black text-emerald-600">₱<?= number_format($qi['amount_refunded'], 2) ?></span></td>
                    <td class="px-6 py-5 text-center">
                        <?php if ($qi['disposition'] === DISP_RESTOCK): ?>
                            <span class="bg-emerald-50 text-emerald-600 text-[10px] font-black px-3 py-1 rounded-full border border-emerald-100">Restock</span>
                        <?php else: ?>
                            <span class="bg-slate-100 text-slate-500 text-[10px] font-black px-3 py-1 rounded-full">Dispose</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-5 text-center"><p class="font-bold text-slate-700 text-sm"><?= htmlspecialchars($req_name) ?></p></td>
                    <td class="px-6 py-5 text-center">
                        <p class="text-slate-400 text-xs font-bold"><?= date('M j, Y', strtotime($qi['created_at'])) ?></p>
                        <p class="text-slate-300 text-[10px]"><?= date('g:i A', strtotime($qi['created_at'])) ?></p>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <?php if ($is_pending): ?>
                            <span class="bg-amber-50 text-amber-600 text-[10px] font-black px-3 py-1 rounded-full border border-amber-100 uppercase">Pending</span>
                        <?php else: ?>
                            <span class="bg-rose-50 text-rose-500 text-[10px] font-black px-3 py-1 rounded-full border border-rose-100 uppercase">Rejected</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="flex gap-2 justify-end">
                            <?php if ($is_pending): ?>
                                <form method="POST" action="refund_approve.php" class="inline">
                                    <input type="hidden" name="action"    value="approve">
                                    <input type="hidden" name="refund_id" value="<?= $qi['id'] ?>">
                                    <button type="submit"
                                            onclick="confirmForm(event, this.closest('form'), 'Refund of ₱<?= number_format($qi['amount_refunded'], 2) ?> for <?= htmlspecialchars(addslashes($qi['product_name'])) ?> will be approved.', 'Approve Refund?'); return false;"
                                            class="bg-emerald-500 text-white px-4 py-2 rounded-xl font-black text-[10px] uppercase hover:bg-emerald-600 transition-all shadow-sm">
                                        Approve
                                    </button>
                                </form>
                                <button type="button"
                                        onclick="openRejectModal(<?= $qi['id'] ?>, '<?= htmlspecialchars(addslashes($qi['product_name'])) ?>')"
                                        class="bg-rose-500 text-white px-4 py-2 rounded-xl font-black text-[10px] uppercase hover:bg-rose-600 transition-all shadow-sm">
                                    Reject
                                </button>
                            <?php elseif ($is_rejected && $is_super): ?>
                                <button type="button"
                                        onclick="openOverrideModal(<?= $qi['id'] ?>, '<?= htmlspecialchars(addslashes($qi['product_name'])) ?>')"
                                        class="bg-amber-500 text-white px-4 py-2 rounded-xl font-black text-[10px] uppercase hover:bg-amber-600 transition-all shadow-sm">
                                    Override
                                </button>
                            <?php else: ?>
                                <span class="text-slate-200 text-xs font-bold">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── DELIVERY RETURN REQUESTS (appended to queue tab) ──────────────── -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-rose-600 text-white rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800">Delivery Return Requests</h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">
                    Review staff-submitted delivery return requests · Accepted requests generate a formal return ticket
                </p>
            </div>
            <?php if ($dr_pending_count > 0): ?>
            <span class="ml-auto bg-rose-100 text-rose-700 font-black text-xs px-4 py-2 rounded-full flex-shrink-0"><?= $dr_pending_count ?> pending</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($dr_queue_items)): ?>
        <div class="card-modern text-center py-20">
            <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <p class="text-slate-400 font-black uppercase text-sm">No delivery return requests</p>
            <p class="text-slate-300 text-xs mt-1">Staff can submit requests from the Delivery Returns tab.</p>
        </div>
    <?php else: ?>
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden animate-in">
        <table class="table-modern text-left min-w-full">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="px-8 py-5">Invoice / Supplier</th>
                    <th class="px-6 py-5 text-center">Items</th>
                    <th class="px-6 py-5">Purpose</th>
                    <th class="px-6 py-5 text-center">Submitted By</th>
                    <th class="px-6 py-5 text-center">Date</th>
                    <th class="px-6 py-5 text-center">Status</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dr_queue_items as $dr):
                    $total_qty   = array_sum(array_column($dr['items'], 'qty'));
                    $total_value = array_sum(array_map(fn($i) => $i['qty'] * $i['unit_price'], $dr['items']));
                    $st_cls = match($dr['status']) {
                        DR_APPROVED => 'bg-emerald-50 text-emerald-600 border-emerald-100',
                        DR_REJECTED => 'bg-rose-50 text-rose-500 border-rose-100',
                        default     => 'bg-amber-50 text-amber-600 border-amber-100',
                    };
                ?>
                <!-- Summary row — clickable -->
                <tr class="border-t border-slate-50 hover:bg-rose-50/20 cursor-pointer transition-all <?= $dr['status'] === DR_REJECTED ? 'opacity-60' : '' ?>"
                    onclick="toggleDRRow(<?= $dr['id'] ?>)">
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-2">
                            <svg id="dr_chevron_<?= $dr['id'] ?>" class="w-4 h-4 text-slate-300 flex-shrink-0 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                            </svg>
                            <div>
                                <p class="font-bold text-slate-800"><?= htmlspecialchars($dr['supplier_name'] ?? '—') ?></p>
                                <code class="text-[11px] font-mono text-slate-400"><?= htmlspecialchars($dr['invoice_no']) ?></code>
                                <?php if ($dr['ticket_no']): ?>
                                    <p class="text-[10px] font-black text-emerald-600 mt-0.5"><?= htmlspecialchars($dr['ticket_no']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <span class="font-black text-slate-700 text-lg"><?= count($dr['items']) ?></span>
                        <p class="text-[9px] text-slate-400 font-bold"><?= $total_qty ?> units total</p>
                    </td>
                    <td class="px-6 py-5 max-w-[200px]">
                        <p class="text-sm text-slate-600 font-medium line-clamp-2"><?= htmlspecialchars($dr['purpose'] ?? '—') ?></p>
                        <?php if ($dr['status'] === DR_REJECTED && $dr['reject_reason']): ?>
                            <p class="text-[10px] text-rose-400 mt-1 italic">Rejected: <?= htmlspecialchars($dr['reject_reason']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <p class="font-bold text-slate-700 text-sm"><?= htmlspecialchars($dr['requested_username'] ?? '—') ?></p>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <p class="text-slate-400 text-xs font-bold"><?= date('M j, Y', strtotime($dr['created_at'])) ?></p>
                        <p class="text-slate-300 text-[10px]"><?= date('g:i A', strtotime($dr['created_at'])) ?></p>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <span class="text-[10px] font-black px-3 py-1 rounded-full border uppercase <?= $st_cls ?>"><?= ucfirst($dr['status']) ?></span>
                    </td>
                    <td class="px-8 py-5 text-right" onclick="event.stopPropagation()">
                        <div class="flex gap-2 justify-end">
                            <?php if ($dr['status'] === DR_PENDING): ?>
                                <button onclick="openDRReview(<?= $dr['id'] ?>)"
                                    class="bg-rose-600 text-white px-4 py-2 rounded-xl font-black text-[10px] uppercase hover:bg-rose-700 transition-all shadow-sm">Review</button>
                            <?php elseif ($dr['status'] === DR_APPROVED): ?>
                                <a href="../procurement/delivery_return_ticket.php?id=<?= $dr['id'] ?>"
                                   class="bg-emerald-500 text-white px-4 py-2 rounded-xl font-black text-[10px] uppercase hover:bg-emerald-600 transition-all shadow-sm">View Ticket</a>
                            <?php else: ?>
                                <span class="text-slate-200 text-xs font-bold">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- Expanded detail row — hidden by default -->
                <tr id="dr_expand_<?= $dr['id'] ?>" class="hidden">
                    <td colspan="7" class="px-0 py-0 bg-slate-50/40 border-t border-slate-100">
                        <div class="px-8 py-3">
                            <!-- Compact meta strip -->
                            <div class="flex items-center gap-6 mb-2 flex-wrap">
                                <span class="text-[10px] font-bold text-slate-500 italic"><?= htmlspecialchars($dr['purpose'] ?? '—') ?></span>
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest"><?= $total_qty ?> units · <span class="text-rose-500">₱<?= number_format($total_value, 2) ?></span> est. value · Deduct: <span class="<?= $dr['deduct_pay'] ? 'text-emerald-500' : 'text-slate-300' ?>"><?= $dr['deduct_pay'] ? 'Yes' : 'No' ?></span></span>
                            </div>
                            <!-- Compact items table -->
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-slate-100">
                                        <th class="pb-1.5 text-left text-[9px] font-black text-slate-300 uppercase tracking-widest">Product</th>
                                        <th class="pb-1.5 text-center text-[9px] font-black text-slate-300 uppercase tracking-widest w-12">Qty</th>
                                        <th class="pb-1.5 text-center text-[9px] font-black text-slate-300 uppercase tracking-widest w-24">Unit Price</th>
                                        <th class="pb-1.5 text-center text-[9px] font-black text-slate-300 uppercase tracking-widest w-24">Subtotal</th>
                                        <th class="pb-1.5 text-center text-[9px] font-black text-slate-300 uppercase tracking-widest w-28">Reason</th>
                                        <th class="pb-1.5 text-left text-[9px] font-black text-slate-300 uppercase tracking-widest">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                <?php foreach ($dr['items'] as $di):
                                    $di_reason_cls = match($di['reason']) {
                                        'Damaged'       => 'bg-amber-50 text-amber-600 border-amber-100',
                                        'Wrong Item'    => 'bg-purple-50 text-purple-600 border-purple-100',
                                        'Overdelivery'  => 'bg-blue-50 text-blue-600 border-blue-100',
                                        'Expired'       => 'bg-orange-50 text-orange-600 border-orange-100',
                                        'Quality Issue' => 'bg-rose-50 text-rose-500 border-rose-100',
                                        default         => 'bg-slate-100 text-slate-500 border-slate-200',
                                    };
                                ?>
                                <tr class="hover:bg-white/60 transition-all">
                                    <td class="py-2 pr-3 font-bold text-slate-700 text-xs"><?= htmlspecialchars($di['product_name']) ?></td>
                                    <td class="py-2 text-center font-black text-slate-700 text-sm"><?= intval($di['qty']) ?></td>
                                    <td class="py-2 text-center text-slate-400 text-xs font-bold">₱<?= number_format($di['unit_price'], 2) ?></td>
                                    <td class="py-2 text-center font-black text-rose-500 text-xs">₱<?= number_format($di['qty'] * $di['unit_price'], 2) ?></td>
                                    <td class="py-2 text-center">
                                        <span class="text-[9px] font-black px-2 py-0.5 rounded-full border <?= $di_reason_cls ?> uppercase whitespace-nowrap"><?= htmlspecialchars($di['reason']) ?></span>
                                    </td>
                                    <td class="py-2 text-slate-400 text-xs italic"><?= htmlspecialchars($di['notes'] ?? '') ?: '—' ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── DISPOSAL REQUESTS (appended to queue tab) ────────────────── -->
    <div class="card-modern shadow-xl">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-orange-500 text-white rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800">Expired / Damaged Item Disposals</h3>
                <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">
                    Staff-submitted disposal requests — approve to write off from inventory
                </p>
            </div>
            <?php if ($disposal_pending_count > 0): ?>
            <span class="ml-auto bg-orange-100 text-orange-700 font-black text-xs px-4 py-2 rounded-full flex-shrink-0"><?= $disposal_pending_count ?> pending</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($disposal_queue)): ?>
        <div class="card-modern text-center py-16">
            <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-slate-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <p class="text-slate-400 font-black uppercase text-sm">No pending disposal requests</p>
        </div>
    <?php else: ?>
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden animate-in">
        <table class="table-modern text-left min-w-full">
            <thead>
                <tr class="bg-orange-50/60">
                    <th class="px-8 py-5">Product</th>
                    <th class="px-6 py-5 text-center">Qty</th>
                    <th class="px-6 py-5 text-center">Reason</th>
                    <th class="px-6 py-5 text-center">Expiry Date</th>
                    <th class="px-6 py-5 text-center">Submitted By</th>
                    <th class="px-6 py-5 text-center">Date</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($disposal_queue as $dsp): ?>
                <tr class="hover:bg-orange-50/20 transition-all">
                    <td class="px-8 py-5">
                        <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($dsp['product_name']) ?></p>
                        <code class="text-xs text-slate-400 font-mono">#<?= htmlspecialchars($dsp['barcode']) ?></code>
                        <?php if ($dsp['notes']): ?>
                            <p class="text-xs text-slate-500 mt-1 italic"><?= htmlspecialchars($dsp['notes']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-5 text-center font-black text-slate-700 text-xl"><?= intval($dsp['qty']) ?></td>
                    <td class="px-6 py-5 text-center">
                        <?php
                        $reason_cls = match($dsp['reason']) {
                            'Expired'      => 'bg-orange-50 text-orange-600 border-orange-100',
                            'Contaminated' => 'bg-rose-50 text-rose-600 border-rose-100',
                            'Damaged'      => 'bg-amber-50 text-amber-600 border-amber-100',
                            'Spoiled'      => 'bg-red-50 text-red-600 border-red-100',
                            default        => 'bg-slate-100 text-slate-500 border-slate-200',
                        };
                        ?>
                        <span class="text-xs font-black px-4 py-1.5 rounded-full border <?= $reason_cls ?> uppercase tracking-wide whitespace-nowrap"><?= htmlspecialchars($dsp['reason'] ?: '—') ?></span>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <?php if ($dsp['expiry_date']): ?>
                            <span class="font-bold text-rose-500 text-sm"><?= date('M j, Y', strtotime($dsp['expiry_date'])) ?></span>
                        <?php else: ?>
                            <span class="text-slate-200 font-bold">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <p class="font-bold text-slate-700 text-sm"><?= htmlspecialchars($dsp['requester_fullname'] ?: $dsp['requested_username'] ?? '—') ?></p>
                    </td>
                    <td class="px-6 py-5 text-center">
                        <p class="text-slate-400 text-xs font-bold"><?= date('M j, Y', strtotime($dsp['created_at'])) ?></p>
                        <p class="text-slate-300 text-[10px]"><?= date('g:i A', strtotime($dsp['created_at'])) ?></p>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="flex gap-2 justify-end">
                            <form method="POST" action="../inventory/disposal_approve.php" class="inline">
                                <input type="hidden" name="action"      value="approve">
                                <input type="hidden" name="disposal_id" value="<?= $dsp['id'] ?>">
                                <button type="button"
                                        onclick="confirmDisposalApprove(this, <?= intval($dsp['qty']) ?>, '<?= htmlspecialchars(addslashes($dsp['product_name']), ENT_QUOTES) ?>')"
                                        class="bg-emerald-500 text-white px-4 py-2 rounded-xl font-black text-[10px] uppercase hover:bg-emerald-600 transition-all shadow-sm">
                                    Approve
                                </button>
                            </form>
                            <button type="button"
                                    onclick="openDisposalRejectModal(<?= $dsp['id'] ?>, '<?= htmlspecialchars(addslashes($dsp['product_name'])) ?>')"
                                    class="bg-rose-500 text-white px-4 py-2 rounded-xl font-black text-[10px] uppercase hover:bg-rose-600 transition-all shadow-sm">
                                Reject
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php elseif ($active_tab === 'disposal'): ?>
    <!-- ════════════════════════════════════════════════════════════════
         TAB — EXPIRED ITEMS DISPOSAL
    ════════════════════════════════════════════════════════════════ -->

    <?php if (!empty($_GET['success'])): ?>
    <div class="bg-emerald-500 text-white px-8 py-4 rounded-2xl font-black text-sm text-center shadow-lg animate-in">
        <?= htmlspecialchars($_GET['success']) ?>
    </div>
    <?php elseif (!empty($_GET['error'])): ?>
    <div class="bg-rose-500 text-white px-8 py-4 rounded-2xl font-black text-sm text-center shadow-lg animate-in">
        <?= htmlspecialchars($_GET['error']) ?>
    </div>
    <?php endif; ?>

    <div class="flex gap-5 items-start">

        <!-- Left: Disposal History -->
        <div class="w-[270px] flex-shrink-0 flex flex-col gap-3">
            <div class="bg-white rounded-[1.5rem] border border-slate-100 shadow-sm p-4">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">
                    <?= $is_admin ? 'All Disposal Requests' : 'My Requests' ?>
                </p>
                <div class="flex flex-col gap-1.5 overflow-y-auto" style="max-height:68vh;">
                    <?php if (empty($disposal_history)): ?>
                        <p class="text-slate-300 text-xs font-bold text-center py-6">No disposals yet.</p>
                    <?php else: foreach ($disposal_history as $dh_row):
                        $dh_cls = match($dh_row['status']) {
                            DISPOSAL_APPROVED => 'text-emerald-500',
                            DISPOSAL_REJECTED => 'text-rose-400',
                            default           => 'text-amber-500',
                        };
                    ?>
                    <div class="flex flex-col gap-0.5 px-3 py-2.5 rounded-xl border border-slate-100 bg-slate-50/50">
                        <span class="font-black text-[11px] text-slate-700 truncate"><?= htmlspecialchars($dh_row['product_name']) ?></span>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[10px] font-bold text-slate-400"><?= date('M j · g:i A', strtotime($dh_row['created_at'])) ?></span>
                            <span class="text-[10px] font-black <?= $dh_cls ?>"><?= ucfirst($dh_row['status']) ?></span>
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="text-[9px] font-bold text-slate-400"><?= intval($dh_row['qty']) ?> pcs · <?= htmlspecialchars($dh_row['reason']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Search + Form -->
        <div class="flex-1 min-w-0 flex flex-col gap-5">

            <?php if (!empty($near_expiry_batches)): ?>
            <!-- ── Near-Expiry from Supplier Batches ── -->
            <div class="bg-white rounded-[2rem] border-2 border-orange-200 shadow-xl overflow-hidden">
                <div class="px-7 py-5 bg-orange-50 border-b border-orange-100 flex items-center gap-4">
                    <div class="w-10 h-10 bg-orange-500 rounded-2xl flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-black text-orange-900 text-sm uppercase tracking-widest">Near-Expiry from Supplier Batches</h4>
                        <p class="text-orange-600 text-[10px] font-bold mt-0.5">
                            <?= array_sum(array_map(fn($b) => count($b['items']), $near_expiry_batches)) ?> item<?= array_sum(array_map(fn($b) => count($b['items']), $near_expiry_batches)) > 1 ? 's' : '' ?> across <?= count($near_expiry_batches) ?> supplier batch<?= count($near_expiry_batches) > 1 ? 'es' : '' ?> need attention.
                        </p>
                    </div>
                </div>

                <?php foreach ($near_expiry_batches as $batch): ?>
                <div class="border-b border-orange-50 last:border-b-0">
                    <!-- Batch header -->
                    <div class="px-7 py-3 bg-orange-50/40 flex items-center gap-3">
                        <svg class="w-3.5 h-3.5 text-orange-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        <span class="font-black text-orange-800 text-[11px] uppercase tracking-widest"><?= htmlspecialchars($batch['supplier_name']) ?></span>
                        <span class="text-[10px] font-bold text-orange-400 font-mono">· <?= htmlspecialchars($batch['invoice_number']) ?></span>
                        <span class="ml-auto text-[9px] font-black bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full uppercase tracking-wider"><?= count($batch['items']) ?> item<?= count($batch['items']) > 1 ? 's' : '' ?></span>
                    </div>

                    <!-- Items in this batch -->
                    <?php foreach ($batch['items'] as $ni):
                        $ni_days = (int)ceil((strtotime($ni['expiry_date']) - strtotime('today')) / 86400);
                        if ($ni_days < 0)       { $ni_badge = 'Expired ' . abs($ni_days) . 'd ago'; $ni_badge_cls = 'bg-rose-500 text-white'; $ni_date_cls = 'text-rose-600'; }
                        elseif ($ni_days === 0)  { $ni_badge = 'Expires Today'; $ni_badge_cls = 'bg-rose-500 text-white'; $ni_date_cls = 'text-rose-600'; }
                        else                     { $ni_badge = "In {$ni_days}d"; $ni_badge_cls = 'bg-amber-100 text-amber-800'; $ni_date_cls = 'text-amber-600'; }
                    ?>
                    <div class="px-7 py-4 flex items-center gap-4 hover:bg-orange-50/30 transition-all">
                        <div class="flex-1 min-w-0">
                            <p class="font-black text-slate-800 text-sm leading-tight truncate"><?= htmlspecialchars($ni['name']) ?></p>
                            <div class="flex items-center gap-3 mt-1 flex-wrap">
                                <code class="text-[10px] text-slate-400 font-bold">#<?= htmlspecialchars($ni['barcode']) ?></code>
                                <span class="text-[10px] font-bold <?= $ni_date_cls ?>"><?= date('M j, Y', strtotime($ni['expiry_date'])) ?></span>
                                <span class="text-[10px] font-bold text-slate-400"><?= number_format($ni['quantity']) ?> units in stock</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <span class="text-[9px] font-black px-3 py-1.5 rounded-xl <?= $ni_badge_cls ?> uppercase tracking-widest whitespace-nowrap"><?= $ni_badge ?></span>
                            <button type="button"
                                    onclick="quickDispose(<?= intval($ni['id']) ?>)"
                                    class="text-[9px] font-black text-orange-600 border border-orange-200 bg-white px-3 py-1.5 rounded-xl hover:bg-orange-500 hover:text-white hover:border-orange-500 transition-all uppercase tracking-widest whitespace-nowrap">
                                Log Disposal
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card-modern shadow-xl">
                <div class="flex items-center gap-4 mb-6">
                    <div class="w-12 h-12 bg-orange-500 text-white rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </div>
                    <div>
                        <h3 class="serif-title text-2xl font-bold">Disposal Log</h3>
                        <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mt-0.5">Search product to log expired or damaged items</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <input type="text" id="disposalSearchInput" placeholder="Product name or barcode..."
                           class="input-modern flex-1 shadow-sm font-bold"
                           value="<?= htmlspecialchars($disposal_search) ?>"
                           onkeydown="if(event.key==='Enter') doDisposalSearch()">
                    <button type="button" onclick="doDisposalSearch()"
                            class="bg-orange-500 text-white font-black px-10 rounded-[1.25rem] hover:bg-orange-600 transition-all shadow-lg shadow-orange-100">
                        Search
                    </button>
                </div>
            </div>

            <?php if (($disposal_search !== '' || $disposal_product_id > 0) && !$disposal_product && empty($disposal_products)): ?>
            <div class="card-modern text-center py-16">
                <p class="text-slate-400 font-black uppercase text-sm">No active product found<?= $disposal_search !== '' ? ' for "' . htmlspecialchars($disposal_search) . '"' : '' ?></p>
            </div>
            <?php elseif (!empty($disposal_products)): ?>
            <!-- Multiple batches — let user pick the exact lot -->
            <div class="bg-white rounded-[3rem] border-2 border-amber-200 shadow-2xl overflow-hidden animate-in">
                <div class="px-8 py-5 bg-amber-50 border-b border-amber-100 flex items-center gap-3">
                    <svg class="w-5 h-5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <p class="font-black text-amber-800 text-sm uppercase tracking-widest">Multiple Batches Found</p>
                        <p class="text-amber-600 text-[10px] font-bold mt-0.5">Select the specific batch you want to dispose</p>
                    </div>
                </div>
                <?php foreach ($disposal_products as $dp_row):
                    $dp_days = $dp_row['expiry_date'] ? (int)ceil((strtotime($dp_row['expiry_date']) - strtotime('today')) / 86400) : null;
                    $dp_exp_cls = ($dp_days !== null && $dp_days <= 0) ? 'text-rose-500' : 'text-amber-500';
                    $dp_exp_lbl = $dp_days === null ? '' : ($dp_days <= 0 ? ' (EXPIRED)' : " (in {$dp_days}d)");
                ?>
                <div class="px-8 py-4 border-b border-slate-50 last:border-b-0 flex items-center gap-4 hover:bg-orange-50/20 transition-all">
                    <div class="flex-1 min-w-0">
                        <p class="font-bold text-slate-800 text-sm leading-tight"><?= htmlspecialchars($dp_row['name']) ?></p>
                        <div class="flex flex-wrap items-center gap-3 mt-1">
                            <code class="text-[10px] text-slate-400 font-bold">#<?= htmlspecialchars($dp_row['barcode']) ?></code>
                            <?php if ($dp_row['expiry_date']): ?>
                                <span class="text-[10px] font-black <?= $dp_exp_cls ?>">Exp: <?= date('M j, Y', strtotime($dp_row['expiry_date'])) ?><?= $dp_exp_lbl ?></span>
                            <?php else: ?>
                                <span class="text-[10px] text-slate-300 font-bold">No expiry date</span>
                            <?php endif; ?>
                            <span class="text-[10px] text-slate-400 font-bold"><?= number_format($dp_row['quantity']) ?> units</span>
                        </div>
                    </div>
                    <a href="refund_management.php?tab=disposal&disposal_product_id=<?= intval($dp_row['id']) ?>"
                       class="text-[9px] font-black text-orange-600 border border-orange-200 bg-white px-4 py-2 rounded-xl hover:bg-orange-500 hover:text-white hover:border-orange-500 transition-all uppercase tracking-widest whitespace-nowrap flex-shrink-0">
                        Select Batch
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif (!$disposal_product && $disposal_search === '' && !$disposal_product_id): ?>
            <div class="card-modern text-center py-16">
                <p class="text-slate-300 font-black text-sm uppercase tracking-widest">Search for a product above to log a disposal</p>
            </div>
            <?php endif; ?>

            <?php if ($disposal_product): ?>
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden animate-in">
                <div class="p-8 bg-orange-500 text-white flex justify-between items-center">
                    <div>
                        <p class="text-[10px] font-black text-orange-200 uppercase tracking-widest mb-1">Product Found</p>
                        <h4 class="text-2xl font-bold"><?= htmlspecialchars($disposal_product['name']) ?></h4>
                        <code class="text-orange-200 font-mono text-sm">#<?= htmlspecialchars($disposal_product['barcode']) ?></code>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-orange-200 font-bold uppercase mb-1">Current Stock</p>
                        <h4 class="text-4xl font-black"><?= number_format($disposal_product['quantity']) ?></h4>
                        <p class="text-orange-200 text-[10px] font-bold">units available</p>
                    </div>
                </div>

                <form method="POST" action="../inventory/disposal_process.php" class="p-10 space-y-6">
                    <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="product_id" value="<?= intval($disposal_product['id']) ?>">

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="label-modern ml-1 mb-2 block">Qty to Dispose <span class="text-rose-500">*</span></label>
                            <input type="number" name="qty" required min="1" max="<?= intval($disposal_product['quantity']) ?>"
                                   class="input-modern text-center font-black text-2xl"
                                   placeholder="0">
                        </div>
                        <div>
                            <label class="label-modern ml-1 mb-2 block">Reason <span class="text-rose-500">*</span></label>
                            <select name="reason" class="input-modern font-bold">
                                <option value="Expired">Expired</option>
                                <option value="Contaminated">Contaminated</option>
                                <option value="Damaged">Damaged</option>
                                <option value="Spoiled">Spoiled</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="label-modern ml-1 mb-2 block">Expiry Date <span class="text-slate-400 font-normal text-[10px]">(optional — if printed on batch)</span></label>
                        <input type="date" name="expiry_date" class="input-modern font-bold">
                    </div>

                    <div>
                        <label class="label-modern ml-1 mb-2 block">Notes <span class="text-slate-400 font-normal text-[10px]">(optional)</span></label>
                        <textarea name="notes" rows="2" placeholder="Additional details about this disposal..."
                                  class="input-modern w-full resize-none"></textarea>
                    </div>

                    <div class="flex items-center gap-4 pt-2">
                        <?php if ($is_admin): ?>
                        <button type="button"
                                onclick="confirmDisposalSubmit(this)"
                                class="flex-1 bg-orange-500 hover:bg-orange-600 text-white font-black py-5 rounded-[1.5rem] text-sm uppercase tracking-widest shadow-xl shadow-orange-100 transition-all">
                            Apply Disposal Now
                        </button>
                        <?php else: ?>
                        <button type="submit"
                                class="flex-1 bg-orange-500 hover:bg-orange-600 text-white font-black py-5 rounded-[1.5rem] text-sm uppercase tracking-widest shadow-xl shadow-orange-100 transition-all">
                            Submit Disposal Request
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if (!$is_admin): ?>
                    <p class="text-[10px] text-slate-400 font-bold text-center">
                        Your request will be reviewed by an admin before inventory is adjusted.
                    </p>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php else: ?>
    <!-- ════════════════════════════════════════════════════════════════
         TAB — DELIVERY RETURNS
    ════════════════════════════════════════════════════════════════ -->

    <div class="flex gap-5 items-start">

        <!-- ── Left: Batch Receipt List ─────────────────────────────── -->
        <div class="w-[270px] flex-shrink-0 flex flex-col gap-3">
            <div class="bg-white rounded-[1.5rem] border border-slate-100 shadow-sm p-4">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">Delivery Receipts</p>
                <div class="flex flex-col gap-1.5 overflow-y-auto" style="max-height:72vh;">
                    <?php if (empty($delivery_batches)): ?>
                        <p class="text-slate-300 text-xs font-bold text-center py-6">No completed batches yet.</p>
                    <?php else: foreach ($delivery_batches as $db):
                        $db_active = ($batch_id_param === intval($db['id']));
                        $db_clean  = $db['status'] === 'complete_clean';
                    ?>
                    <a href="refund_management.php?tab=delivery&batch_id=<?= $db['id'] ?>"
                       class="flex flex-col gap-0.5 px-3 py-2.5 rounded-xl transition-all cursor-pointer
                              <?= $db_active ? 'bg-rose-500 text-white' : 'hover:bg-slate-50 border border-transparent hover:border-slate-100' ?>">
                        <span class="font-black text-[11px] <?= $db_active ? 'text-white' : 'text-slate-700' ?> truncate">
                            <?= htmlspecialchars($db['supplier_name'] ?? '—') ?>
                        </span>
                        <div class="flex items-center justify-between gap-2">
                            <code class="text-[10px] font-bold <?= $db_active ? 'text-rose-100' : 'text-slate-400' ?> truncate">
                                <?= htmlspecialchars($db['invoice'] ?? '—') ?>
                            </code>
                            <span class="text-[8px] font-black px-1.5 py-0.5 rounded-full flex-shrink-0
                                         <?= $db_active ? 'bg-white/20 text-white' : ($db_clean ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600') ?>">
                                <?= $db_clean ? 'CLEAN' : 'ERRORS' ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-0.5">
                            <span class="text-[9px] font-bold <?= $db_active ? 'text-rose-100' : 'text-slate-300' ?>">
                                <?= $db['officialized_at'] ? date('M j, Y', strtotime($db['officialized_at'])) : '—' ?>
                            </span>
                            <span class="text-[9px] font-bold <?= $db_active ? 'text-rose-100' : 'text-slate-400' ?>">
                                <?= intval($db['item_count']) ?> item<?= $db['item_count'] != 1 ? 's' : '' ?>
                            </span>
                        </div>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Right: Search + Form ──────────────────────────────────── -->
        <div class="flex-1 min-w-0 flex flex-col gap-5">

            <!-- Search bar -->
            <div class="card-modern shadow-xl">
                <div class="flex items-center gap-4 mb-5">
                    <div class="w-12 h-12 bg-rose-500 text-white rounded-2xl flex items-center justify-center shadow-lg flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                    </div>
                    <div>
                        <h3 class="serif-title text-2xl font-bold text-slate-800">Delivery Return</h3>
                        <p class="text-slate-400 text-xs font-bold uppercase tracking-widest">Select a batch on the left or search by invoice number</p>
                    </div>
                </div>
                <div class="flex gap-4">
                    <input type="text" id="drInvoiceInput"
                           placeholder="Enter Invoice Number (e.g. DEL-20250512-A3F2)..."
                           class="input-modern flex-1 shadow-sm font-bold font-mono uppercase"
                           value="<?= htmlspecialchars(!$batch_id_param ? ($invoice_query ?? '') : '') ?>"
                           onkeydown="if(event.key==='Enter') doDRInvoiceSearch()">
                    <button type="button" onclick="doDRInvoiceSearch()"
                            class="bg-rose-500 text-white font-black px-10 rounded-[1.25rem] hover:bg-rose-600 transition-all shadow-lg shadow-rose-100">
                        Find Shipment
                    </button>
                </div>
            </div>

            <?php if (!$supplier_data && !$batch_id_param && $invoice_query): ?>
            <div class="card-modern text-center py-16">
                <p class="text-slate-400 font-black uppercase text-sm">No shipment found for "<?= htmlspecialchars($invoice_query) ?>"</p>
                <p class="text-slate-300 text-xs mt-2">Check the invoice number in Supply Vouchers.</p>
            </div>
            <?php elseif (!$supplier_data && !$batch_id_param): ?>
            <div class="card-modern text-center py-16">
                <p class="text-slate-300 font-black text-sm uppercase tracking-widest">Select a delivery receipt or enter an invoice number</p>
            </div>
            <?php endif; ?>

            <?php if ($supplier_data): ?>

            <!-- Existing requests notice -->
            <?php if (!empty($existing_dr_requests)): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-[2rem] p-6 flex flex-col gap-3">
                <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest">Existing Return Requests for this Invoice</p>
                <div class="flex flex-wrap gap-3">
                <?php foreach ($existing_dr_requests as $edr): ?>
                    <div class="flex items-center gap-3 bg-white rounded-xl px-4 py-2 border border-amber-100 shadow-sm">
                        <div>
                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-full <?= $edr['status'] === DR_APPROVED ? 'bg-emerald-50 text-emerald-600' : ($edr['status'] === DR_REJECTED ? 'bg-rose-50 text-rose-500' : 'bg-amber-50 text-amber-600') ?>">
                                <?= ucfirst($edr['status']) ?>
                            </span>
                            <?php if ($edr['ticket_no']): ?>
                                <span class="ml-2 text-[10px] font-black text-emerald-600"><?= htmlspecialchars($edr['ticket_no']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="text-[10px] text-slate-400 font-bold">by <?= htmlspecialchars($edr['requested_username'] ?? '?') ?> · <?= date('M j', strtotime($edr['created_at'])) ?></span>
                        <?php if ($edr['status'] === DR_APPROVED): ?>
                            <a href="../procurement/delivery_return_ticket.php?id=<?= $edr['id'] ?>" class="text-[10px] font-black text-emerald-500 hover:underline">View Ticket →</a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Return Request Form -->
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden animate-in">
                <div class="p-8 bg-rose-500 text-white flex justify-between items-center">
                    <div>
                        <p class="text-[10px] font-black text-rose-200 uppercase tracking-widest mb-1">
                            <?= $selected_batch ? 'Batch Receipt' : 'Shipment Verified' ?>
                        </p>
                        <h4 class="text-2xl font-bold"><?= htmlspecialchars($supplier_data['name']) ?></h4>
                        <code class="text-rose-200 font-mono text-sm"><?= htmlspecialchars($supplier_data['invoice_number']) ?></code>
                        <?php if ($selected_batch): ?>
                        <p class="text-rose-200 text-[10px] font-bold mt-1 uppercase">
                            Received <?= $selected_batch['officialized_at'] ? date('M j, Y', strtotime($selected_batch['officialized_at'])) : '—' ?>
                            · by <?= htmlspecialchars($selected_batch['staff_username'] ?? '—') ?>
                            · <?= intval($selected_batch['item_count']) ?> items
                            <?php if ($selected_batch['discrepancy_count'] > 0): ?>
                            · <span class="text-amber-200"><?= $selected_batch['discrepancy_count'] ?> discrepanc<?= $selected_batch['discrepancy_count'] != 1 ? 'ies' : 'y' ?></span>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-rose-200 font-bold uppercase mb-1">Invoice Value</p>
                        <h4 class="text-2xl font-black">₱<?= number_format($supplier_data['amount'], 2) ?></h4>
                    </div>
                </div>

                <form id="dr_request_form">
                    <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="supplier_id"   value="<?= $supplier_data['id'] ?>">
                    <input type="hidden" name="supplier_name" value="<?= htmlspecialchars($supplier_data['name']) ?>">
                    <input type="hidden" name="invoice_no"    value="<?= htmlspecialchars($supplier_data['invoice_number']) ?>">

                    <table class="table-modern text-left min-w-full">
                        <thead>
                            <tr class="bg-slate-50/50">
                                <th class="px-6 py-5 w-8">
                                    <input type="checkbox" id="check_all" onchange="toggleAll(this)" class="w-4 h-4 accent-rose-500 rounded cursor-pointer">
                                </th>
                                <th class="px-4 py-5">Product</th>
                                <th class="px-4 py-5 text-center">In Stock</th>
                                <th class="px-4 py-5 text-center">Returned</th>
                                <th class="px-4 py-5 text-center w-28">Return Qty</th>
                                <th class="px-6 py-5">Reason</th>
                                <th class="px-4 py-5">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if (empty($delivery_items)): ?>
                                <tr><td colspan="7" class="p-16 text-center text-slate-300 font-bold italic opacity-30">No active items found in this shipment.</td></tr>
                            <?php else: foreach ($delivery_items as $di): ?>
                            <tr class="hover:bg-slate-50 transition-all" id="item_row_<?= $di['id'] ?>">
                                <td class="px-6 py-4">
                                    <input type="checkbox" name="include[]" value="<?= $di['id'] ?>"
                                           class="item-check w-4 h-4 accent-rose-500 rounded cursor-pointer"
                                           onchange="toggleRow(this, <?= $di['id'] ?>)">
                                </td>
                                <td class="px-4 py-4">
                                    <p class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($di['name']) ?></p>
                                    <code class="text-xs text-slate-400">#<?= htmlspecialchars($di['barcode']) ?> · ₱<?= number_format($di['price'], 2) ?>/unit</code>
                                    <input type="hidden" name="item_name[<?= $di['id'] ?>]"  value="<?= htmlspecialchars($di['name']) ?>">
                                    <input type="hidden" name="item_price[<?= $di['id'] ?>]" value="<?= $di['price'] ?>">
                                </td>
                                <td class="px-4 py-4 text-center font-black text-slate-700 text-xl"><?= number_format($di['quantity']) ?></td>
                                <td class="px-4 py-4 text-center">
                                    <?php if ($di['total_returned'] > 0): ?>
                                        <span class="bg-rose-50 text-rose-500 font-black text-sm px-3 py-1 rounded-full border border-rose-100"><?= $di['total_returned'] ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-200 font-bold">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <input type="number" name="qty[<?= $di['id'] ?>]" value="1" min="1" max="<?= $di['quantity'] ?>"
                                           id="qty_<?= $di['id'] ?>"
                                           class="item-qty w-20 bg-slate-50 border border-slate-200 rounded-xl px-2 py-2 text-center font-black text-sm outline-none focus:border-rose-300 disabled:opacity-40"
                                           disabled>
                                </td>
                                <td class="px-4 py-4">
                                    <select name="reason[<?= $di['id'] ?>]" id="reason_<?= $di['id'] ?>"
                                            class="item-reason w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold outline-none focus:border-rose-300 disabled:opacity-40"
                                            disabled>
                                        <option value="Damaged">Damaged</option>
                                        <option value="Wrong Item">Wrong Item</option>
                                        <option value="Overdelivery">Overdelivery</option>
                                        <option value="Expired">Expired</option>
                                        <option value="Quality Issue">Quality Issue</option>
                                    </select>
                                </td>
                                <td class="px-4 py-4">
                                    <input type="text" name="notes[<?= $di['id'] ?>]" id="notes_<?= $di['id'] ?>"
                                           placeholder="Optional detail..."
                                           class="item-notes w-full bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold outline-none focus:border-rose-300 disabled:opacity-40"
                                           disabled>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>

                    <!-- Request footer -->
                    <?php if (!empty($delivery_items)): ?>
                    <div class="p-8 bg-slate-50/50 border-t border-slate-100 flex flex-col gap-5">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Overall Purpose of Return <span class="text-rose-500">*</span></label>
                            <textarea name="purpose" id="dr_purpose" rows="3" required
                                class="input-modern w-full resize-none"
                                placeholder="Summarise why these items are being returned (e.g. items arrived damaged, incorrect items delivered, overshipment)..."></textarea>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-5">
                            <label class="flex items-center gap-3 bg-white p-4 rounded-2xl border border-slate-200 cursor-pointer hover:border-rose-200 transition-all w-full sm:w-auto">
                                <input type="checkbox" name="deduct_pay" value="1" checked class="w-4 h-4 accent-rose-500 rounded flex-shrink-0">
                                <span class="text-[11px] font-black text-slate-600 uppercase leading-tight">Deduct return value from unpaid supplier balance</span>
                            </label>
                            <button type="button" onclick="submitDRRequest()"
                                class="bg-rose-600 hover:bg-rose-700 text-white font-black px-10 py-4 rounded-2xl text-sm uppercase tracking-widest shadow-xl shadow-rose-100 transition-all active:scale-95 flex-shrink-0">
                                Submit Return Request
                            </button>
                        </div>
                        <p class="text-[10px] text-slate-400 font-bold text-center">
                            Your request will be reviewed by an admin. A return ticket will be generated upon approval.
                        </p>
                    </div>
                    <?php endif; ?>
                </form>
            </div><!-- /return form card -->

            <?php endif; // $supplier_data ?>
        </div><!-- /right column -->
    </div><!-- /flex row -->

    <?php endif; // delivery tab ?>

</div>

<!-- ── DISPOSAL REJECT MODAL ──────────────────────────────────────────────── -->
<?php if ($is_admin): ?>
<div id="disposalRejectModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 animate-in">
        <h3 class="text-xl font-black text-slate-800 mb-1">Reject Disposal Request</h3>
        <p id="disposalRejectLabel" class="text-slate-400 text-sm mb-6"></p>
        <form id="disposalRejectForm" method="POST" action="../inventory/disposal_approve.php">
            <input type="hidden" name="action"      value="reject">
            <input type="hidden" name="disposal_id" id="disposalRejectId" value="">
            <div class="mb-5">
                <label class="block text-slate-700 font-bold mb-2 text-sm">Rejection Reason <span class="text-rose-500">*</span></label>
                <textarea name="reject_reason" rows="3" required placeholder="State the reason..."
                          class="input-modern w-full resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeDisposalRejectModal()"
                        class="flex-1 bg-slate-100 text-slate-600 font-black py-4 rounded-2xl hover:bg-slate-200 transition-all text-sm uppercase">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 bg-rose-500 text-white font-black py-4 rounded-2xl hover:bg-rose-600 transition-all shadow-lg text-sm uppercase">
                    Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── SALES REFUND REJECT MODAL ──────────────────────────────────────────── -->
<div id="rejectModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 animate-in">
        <h3 class="text-xl font-black text-slate-800 mb-1">Reject Refund Request</h3>
        <p id="rejectModalLabel" class="text-slate-400 text-sm mb-6"></p>
        <form id="rejectForm" method="POST" action="refund_approve.php">
            <input type="hidden" name="action"    value="reject">
            <input type="hidden" name="refund_id" id="rejectRefundId" value="">
            <div class="mb-5">
                <label class="block text-slate-700 font-bold mb-2 text-sm">Rejection Reason <span class="text-rose-500">*</span></label>
                <textarea name="note" id="rejectNote" rows="3" required placeholder="State the reason..."
                          class="input-modern w-full resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeRejectModal()" class="flex-1 bg-slate-100 text-slate-600 font-black py-4 rounded-2xl hover:bg-slate-200 transition-all text-sm uppercase">Cancel</button>
                <button type="submit" class="flex-1 bg-rose-500 text-white font-black py-4 rounded-2xl hover:bg-rose-600 transition-all shadow-lg text-sm uppercase">Reject Request</button>
            </div>
        </form>
    </div>
</div>

<!-- ── SUPERADMIN OVERRIDE MODAL ─────────────────────────────────────────── -->
<?php if ($is_super): ?>
<div id="overrideModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-md p-8 animate-in">
        <div class="flex items-center gap-3 mb-1">
            <span class="bg-rose-100 text-rose-500 text-xs font-black px-3 py-1 rounded-full uppercase">★ Super Admin</span>
            <h3 class="text-xl font-black text-slate-800">Override Rejection</h3>
        </div>
        <p id="overrideModalLabel" class="text-slate-400 text-sm mb-6"></p>
        <form id="overrideForm" method="POST" action="refund_approve.php">
            <input type="hidden" name="action"    value="override">
            <input type="hidden" name="refund_id" id="overrideRefundId" value="">
            <div class="mb-5">
                <label class="block text-slate-700 font-bold mb-2 text-sm">Override Note <span class="text-slate-400 font-normal">(optional)</span></label>
                <textarea name="note" id="overrideNote" rows="3" placeholder="Add a note for this override..."
                          class="input-modern w-full resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeOverrideModal()" class="flex-1 bg-slate-100 text-slate-600 font-black py-4 rounded-2xl hover:bg-slate-200 transition-all text-sm uppercase">Cancel</button>
                <button type="submit" class="flex-1 bg-amber-500 text-white font-black py-4 rounded-2xl hover:bg-amber-600 transition-all shadow-lg text-sm uppercase">Override & Approve</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── DELIVERY RETURN REVIEW MODAL (admin) ──────────────────────────────── -->
<?php if ($is_admin): ?>
<div id="drReviewModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-[2.5rem] shadow-2xl w-full max-w-2xl mx-4 animate-in flex flex-col" style="max-height:90vh;">
        <div class="p-8 bg-rose-600 text-white rounded-t-[2.5rem] flex-shrink-0">
            <p class="text-[10px] font-black text-rose-200 uppercase tracking-widest mb-1">Delivery Return Request</p>
            <h4 id="dr-modal-title" class="text-2xl font-black"></h4>
            <p id="dr-modal-supplier" class="text-rose-200 text-sm font-bold mt-1"></p>
        </div>

        <div class="flex-1 overflow-y-auto p-8 space-y-6">
            <!-- Purpose -->
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Purpose of Return</p>
                <p id="dr-modal-purpose" class="text-slate-700 font-medium bg-slate-50 rounded-2xl p-4 text-sm leading-relaxed"></p>
            </div>

            <!-- Items table -->
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3">Items to Return</p>
                <div class="bg-slate-50 rounded-2xl overflow-hidden border border-slate-100">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200">
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase">Product</th>
                                <th class="px-4 py-3 text-[10px] font-black text-slate-400 uppercase text-center">Qty</th>
                                <th class="px-4 py-3 text-[10px] font-black text-slate-400 uppercase">Reason</th>
                                <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody id="dr-modal-items" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>

            <!-- Submitted by + deduct info -->
            <div class="flex gap-4 flex-wrap">
                <div class="bg-slate-50 rounded-2xl p-4 flex-1 min-w-[140px]">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Submitted By</p>
                    <p id="dr-modal-submitter" class="font-bold text-slate-700 text-sm"></p>
                    <p id="dr-modal-date" class="text-slate-400 text-[10px] mt-0.5"></p>
                </div>
                <div id="dr-modal-deduct-box" class="bg-rose-50 rounded-2xl p-4 flex-1 min-w-[140px] border border-rose-100">
                    <p class="text-[9px] font-black text-rose-400 uppercase tracking-widest mb-1">Deduct from Balance</p>
                    <p id="dr-modal-deduct" class="font-bold text-rose-600 text-sm"></p>
                </div>
            </div>
        </div>

        <!-- Action footer -->
        <div class="p-6 border-t border-slate-100 flex-shrink-0" id="dr-modal-pending-actions">
            <div id="dr-modal-reject-area" class="mb-4 hidden">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-2">Rejection Reason <span class="text-rose-500">*</span></label>
                <textarea id="dr-modal-reject-reason" rows="2" class="input-modern w-full resize-none text-sm" placeholder="Why is this request being rejected?"></textarea>
            </div>
            <div class="flex gap-3">
                <button onclick="closeDRReview()" class="flex-1 bg-slate-100 text-slate-600 font-black py-3.5 rounded-2xl hover:bg-slate-200 transition-all text-xs uppercase">Cancel</button>
                <button id="dr-modal-reject-btn" onclick="toggleDRRejectArea()"
                    class="flex-1 border-2 border-rose-200 text-rose-500 font-black py-3.5 rounded-2xl hover:bg-rose-50 transition-all text-xs uppercase">
                    Reject
                </button>
                <button id="dr-modal-approve-btn" onclick="confirmDRAction('approve')"
                    class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white font-black py-3.5 rounded-2xl shadow-lg transition-all text-xs uppercase active:scale-95">
                    Approve &amp; Generate Ticket
                </button>
            </div>
        </div>
    </div>
</div>

<form id="dr-action-form" method="POST" action="../procurement/delivery_return_approve.php" class="hidden">
    <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <input type="hidden" name="request_id"    id="da-req-id">
    <input type="hidden" name="action"        id="da-action">
    <input type="hidden" name="reject_reason" id="da-reason">
</form>
<?php endif; ?>

<script>
// ── Sales refund handlers ─────────────────────────────────────────────────────
function triggerRefundAction(pid, isRequest) {
    const btn  = document.getElementById('btn_' + pid);
    const form = document.getElementById('ref_form_' + pid);
    const msg  = isRequest
        ? 'The refund will be processed once an admin reviews the request.'
        : 'This will recalculate the entire receipt including tax and bulk rules.';
    customConfirm(msg, isRequest ? 'Submit for Approval?' : 'Process Refund?').then(ok => {
        if (!ok) return;
        btn.disabled = true; btn.textContent = isRequest ? 'Submitting...' : 'Processing...'; btn.style.opacity = '0.6';
        const fd = new FormData(form);
        if (typeof navigate === 'function') navigate('refund_process.php', fd);
        else { form.method = 'POST'; form.action = 'refund_process.php'; form.submit(); }
    });
}

function openRejectModal(refundId, productName) {
    document.getElementById('rejectRefundId').value = refundId;
    document.getElementById('rejectModalLabel').textContent = 'Refund request for: ' + productName;
    document.getElementById('rejectNote').value = '';
    const m = document.getElementById('rejectModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeRejectModal() {
    const m = document.getElementById('rejectModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

<?php if ($is_super): ?>
function openOverrideModal(refundId, productName) {
    document.getElementById('overrideRefundId').value = refundId;
    document.getElementById('overrideModalLabel').textContent = 'Overriding rejected refund for: ' + productName;
    document.getElementById('overrideNote').value = '';
    const m = document.getElementById('overrideModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeOverrideModal() {
    const m = document.getElementById('overrideModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
<?php endif; ?>

document.querySelectorAll('#rejectModal, #overrideModal').forEach(modal => {
    modal.addEventListener('click', e => { if (e.target === modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); } });
});

function doDisposalSearch() {
    var q = document.getElementById('disposalSearchInput').value.trim();
    var url = 'refund_management.php?tab=disposal' + (q ? '&disposal_search=' + encodeURIComponent(q) : '');
    window.history.pushState({}, '', url);
    navigate(url);
}

function quickDispose(productId) {
    var url = 'refund_management.php?tab=disposal&disposal_product_id=' + encodeURIComponent(productId);
    window.history.pushState({}, '', url);
    navigate(url);
}

<?php if ($is_admin): ?>
function confirmDisposalSubmit(btn) {
    customConfirm('This will immediately deduct the disposal qty from inventory. Proceed?', 'Apply Disposal').then(ok => {
        if (ok) btn.closest('form').submit();
    });
}

function confirmDisposalApprove(btn, qty, name) {
    customConfirm('Approve disposal of ' + qty + ' pcs of "' + name + '"? This will deduct from inventory.', 'Approve Disposal').then(ok => {
        if (ok) btn.closest('form').submit();
    });
}

function openDisposalRejectModal(disposalId, productName) {
    document.getElementById('disposalRejectId').value = disposalId;
    document.getElementById('disposalRejectLabel').textContent = 'Disposal request for: ' + productName;
    const m = document.getElementById('disposalRejectModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeDisposalRejectModal() {
    const m = document.getElementById('disposalRejectModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
var _drm = document.getElementById('disposalRejectModal');
if (_drm) _drm.addEventListener('click', function(e) {
    if (e.target === this) closeDisposalRejectModal();
});
<?php endif; ?>

// ── Delivery return queue — collapsible rows ─────────────────────────────────
function toggleDRRow(id) {
    const row     = document.getElementById('dr_expand_' + id);
    const chevron = document.getElementById('dr_chevron_' + id);
    if (!row) return;
    const open = row.classList.toggle('hidden');
    if (chevron) chevron.style.transform = open ? '' : 'rotate(90deg)';
}

// ── Delivery return — invoice search ─────────────────────────────────────────
function doDRInvoiceSearch() {
    var v = document.getElementById('drInvoiceInput').value.trim();
    if (!v) return;
    var url = 'refund_management.php?tab=delivery&invoice_no=' + encodeURIComponent(v);
    window.history.pushState({}, '', url);
    navigate(url);
}

// ── Delivery return multi-item form ───────────────────────────────────────────
function toggleAll(master) {
    document.querySelectorAll('.item-check').forEach(cb => {
        cb.checked = master.checked;
        toggleRow(cb, parseInt(cb.value));
    });
}

function toggleRow(cb, id) {
    const qty   = document.getElementById('qty_'   + id);
    const reason = document.getElementById('reason_' + id);
    const notes = document.getElementById('notes_' + id);
    if (qty)    qty.disabled    = !cb.checked;
    if (reason) reason.disabled = !cb.checked;
    if (notes)  notes.disabled  = !cb.checked;
    const row = document.getElementById('item_row_' + id);
    if (row) row.classList.toggle('bg-rose-50/30', cb.checked);
}

function submitDRRequest() {
    const form    = document.getElementById('dr_request_form');
    const checked = form.querySelectorAll('input[name="include[]"]:checked');
    if (checked.length === 0) {
        customConfirm('Please select at least one item to return.', 'No Items Selected');
        return;
    }
    const purpose = document.getElementById('dr_purpose').value.trim();
    if (!purpose) {
        customConfirm('Please describe the purpose of this return.', 'Purpose Required');
        return;
    }
    const totalQty = Array.from(checked).reduce((s, cb) => {
        return s + parseInt(document.getElementById('qty_' + cb.value).value || 0);
    }, 0);
    customConfirm(
        `Return request for ${checked.length} item(s) (${totalQty} units total) will be submitted for admin review. A ticket will be generated upon approval.`,
        'Submit Return Request?'
    ).then(ok => {
        if (!ok) return;
        const fd = new FormData(form);
        if (typeof navigate === 'function') navigate('../procurement/delivery_return_request.php', fd);
        else { form.method = 'POST'; form.action = '../procurement/delivery_return_request.php'; form.submit(); }
    });
}

// ── Delivery return review modal (admin) ──────────────────────────────────────
<?php if ($is_admin): ?>
const DR_DATA = <?= json_encode($dr_queue_items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let _activeReqId = null;
let _rejectAreaVisible = false;

function openDRReview(id) {
    const req = DR_DATA.find(r => r.id == id);
    if (!req) return;
    _activeReqId = id;
    _rejectAreaVisible = false;

    document.getElementById('dr-modal-title').textContent    = 'Invoice: ' + req.invoice_no;
    document.getElementById('dr-modal-supplier').textContent = req.supplier_name || '—';
    document.getElementById('dr-modal-purpose').textContent  = req.purpose || '—';
    document.getElementById('dr-modal-submitter').textContent = req.requested_username || '—';
    document.getElementById('dr-modal-date').textContent      = req.created_at ? req.created_at.substring(0, 16).replace('T', ' ') : '';
    document.getElementById('dr-modal-deduct').textContent    = req.deduct_pay == 1 ? 'Yes — deduct from unpaid balance' : 'No';

    const tbody = document.getElementById('dr-modal-items');
    tbody.innerHTML = (req.items || []).map(item => {
        const val = (parseFloat(item.unit_price || 0) * parseInt(item.qty || 0)).toFixed(2);
        return '<tr>' +
            '<td class="px-5 py-3 font-bold text-slate-700">' + esc(item.product_name || '—') + '</td>' +
            '<td class="px-4 py-3 text-center font-black text-slate-800">' + item.qty + '</td>' +
            '<td class="px-4 py-3 text-slate-500 text-xs font-bold">' + esc(item.reason || '—') + '</td>' +
            '<td class="px-5 py-3 text-right font-black text-slate-800">₱' + val + '</td>' +
            '</tr>';
    }).join('') || '<tr><td colspan="4" class="px-5 py-4 text-slate-300 text-center font-bold">No items</td></tr>';

    document.getElementById('dr-modal-reject-area').classList.add('hidden');
    document.getElementById('dr-modal-reject-reason').value = '';
    document.getElementById('dr-modal-reject-btn').textContent = 'Reject';

    const m = document.getElementById('drReviewModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}

function closeDRReview() {
    const m = document.getElementById('drReviewModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

function toggleDRRejectArea() {
    _rejectAreaVisible = !_rejectAreaVisible;
    document.getElementById('dr-modal-reject-area').classList.toggle('hidden', !_rejectAreaVisible);
    const rejectBtn  = document.getElementById('dr-modal-reject-btn');
    const approveBtn = document.getElementById('dr-modal-approve-btn');
    if (_rejectAreaVisible) {
        rejectBtn.textContent = 'Back';
        approveBtn.textContent = 'Confirm Reject';
        approveBtn.className = approveBtn.className.replace('bg-emerald-600 hover:bg-emerald-500', 'bg-rose-600 hover:bg-rose-500');
        approveBtn.onclick = () => confirmDRAction('reject');
    } else {
        rejectBtn.textContent = 'Reject';
        approveBtn.textContent = 'Approve & Generate Ticket';
        approveBtn.className = approveBtn.className.replace('bg-rose-600 hover:bg-rose-500', 'bg-emerald-600 hover:bg-emerald-500');
        approveBtn.onclick = () => confirmDRAction('approve');
    }
}

async function confirmDRAction(action) {
    if (action === 'reject') {
        const reason = document.getElementById('dr-modal-reject-reason').value.trim();
        if (!reason) { await customConfirm('Please provide a rejection reason.', 'Reason Required'); return; }
        const ok = await customConfirm('This request will be rejected and the staff member will be notified.', 'Reject Return Request?');
        if (!ok) return;
        document.getElementById('da-req-id').value  = _activeReqId;
        document.getElementById('da-action').value  = 'reject';
        document.getElementById('da-reason').value  = reason;
    } else {
        const ok = await customConfirm('This will approve the return request and generate a formal return ticket. The stock will be deducted immediately.', 'Approve & Generate Ticket?');
        if (!ok) return;
        document.getElementById('da-req-id').value  = _activeReqId;
        document.getElementById('da-action').value  = 'approve';
        document.getElementById('da-reason').value  = '';
    }
    const form = document.getElementById('dr-action-form');
    if (typeof navigate === 'function') {
        navigate('../procurement/delivery_return_approve.php', new FormData(form));
    } else {
        form.classList.remove('hidden'); form.submit();
    }
    closeDRReview();
}

document.getElementById('drReviewModal').addEventListener('click', e => {
    if (e.target === document.getElementById('drReviewModal')) closeDRReview();
});
<?php endif; ?>

function esc(str) { const d = document.createElement('div'); d.textContent = str; return d.innerHTML; }
</script>

<?php include '../layout_bottom.php'; ?>
