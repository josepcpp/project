<?php
/**
 * supplier_payments.php — Supplier Payment Verification (Admin / Superadmin).
 *
 * Shows what to pay each supplier: receipt subtotal (Admin voucher) minus the
 * value of damaged goods (approved damage tickets) = net payable. The Receiver
 * and Validator trail is available behind a collapsible per batch.
 */
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$active_tab = $_GET['tab'] ?? 'unpaid';
$success    = trim($_GET['success'] ?? '');
$error      = trim($_GET['error']   ?? '');

// ── Record a payment ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    csrf_verify('supplier_payments.php');

    $batch_id  = intval($_POST['batch_id'] ?? 0);
    // The paid amount is NOT taken from the form — it is derived server-side from the
    // Admin's voucher receipt: Net = control_subtotal − approved damage − discount.
    // Discount may be a flat peso amount or a percentage of the receipt subtotal.
    $disc_mode  = (($_POST['discount_mode'] ?? 'amount') === 'percent') ? 'percent' : 'amount';
    $disc_input = max(0, round(floatval($_POST['discount_value'] ?? 0), 4));
    $reference = trim($_POST['payment_reference'] ?? '') ?: null;
    $method    = trim($_POST['payment_method'] ?? '') ?: null;
    $notes     = trim($_POST['notes'] ?? '') ?: null;

    if ($batch_id < 1) {
        header("Location: supplier_payments.php?error=" . urlencode("A valid batch is required."));
        exit();
    }

    $conn->begin_transaction();
    try {
        // Lock the batch; must be validated and not already paid
        $bq = $conn->prepare(
            "SELECT rb.id, rb.control_subtotal, rb.validated_at,
                    (SELECT COALESCE(SUM(total_deduction),0) FROM delivery_damage_tickets
                     WHERE batch_id = rb.id AND status = 'approved') AS deduction,
                    sp.id AS payment_id
             FROM receiving_batches rb
             LEFT JOIN procurement_payments sp ON sp.batch_id = rb.id
             WHERE rb.id = ? LIMIT 1 FOR UPDATE"
        );
        $bq->bind_param("i", $batch_id);
        $bq->execute();
        $b = $bq->get_result()->fetch_assoc();

        if (!$b)                          throw new Exception("Batch not found.");
        if ($b['validated_at'] === null)  throw new Exception("Batch has not been validated yet.");
        if ($b['payment_id'] !== null)    throw new Exception("This batch has already been paid.");

        $receipt   = round(floatval($b['control_subtotal']), 2);
        $deduction = round(floatval($b['deduction']), 2);
        // Resolve percentage to a peso amount against the receipt subtotal.
        $discount  = $disc_mode === 'percent'
            ? round($receipt * $disc_input / 100, 2)
            : round($disc_input, 2);
        if ($discount < 0) $discount = 0;
        // Discount can't exceed what's left after damage (would make net negative).
        if ($discount > $receipt - $deduction) throw new Exception("Discount can't exceed the receipt minus damage deduction.");
        $net       = round($receipt - $deduction - $discount, 2);
        if ($net <= 0) throw new Exception("Net payable must be greater than zero.");

        $ins = $conn->prepare(
            "INSERT INTO procurement_payments
                (batch_id, receipt_subtotal, damage_deduction, supplier_discount, net_amount,
                 payment_reference, payment_method, notes, status,
                 verified_by, verified_by_username, verified_at)
             VALUES (?,?,?,?,?,?,?,?,'paid',?,?,NOW())"
        );
        $ins->bind_param("iddddsssis",
            $batch_id, $receipt, $deduction, $discount, $net,
            $reference, $method, $notes, $user_id, $username);
        $ins->execute();

        $al = $conn->prepare(
            "INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action, reason)
             VALUES (?,?,?,?,'supplier_paid',?)"
        );
        $disc_label = $disc_mode === 'percent' ? rtrim(rtrim(number_format($disc_input, 2), '0'), '.') . "% (₱" . number_format($discount, 2) . ")" : "₱" . number_format($discount, 2);
        $reason = "Paid ₱" . number_format($net, 2) . " (net of ₱" . number_format($deduction, 2) . " damage"
                . ($discount > 0 ? " and $disc_label discount" : "") . ")"
                . ($reference ? " · Ref: $reference" : '');
        $al->bind_param("iisss", $batch_id, $user_id, $username, $role, $reason);
        $al->execute();

        $conn->commit();
        header("Location: supplier_payments.php?tab=paid&success=" . urlencode("Payment of ₱" . number_format($net, 2) . " recorded for Batch #$batch_id."));
        exit();
    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: supplier_payments.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// ── Load validated batches with their payable figures ─────────────────────────
$search   = trim($_GET['q'] ?? '');
$where    = "WHERE rb.validated_at IS NOT NULL";
$s_params = [];
$s_types  = '';
if ($search !== '') {
    $where   .= " AND (rb.supplier_name LIKE ? OR CAST(rb.id AS CHAR) LIKE ?)";
    $s_types .= 'ss';
    $like     = '%' . $search . '%';
    $s_params[] = $like; $s_params[] = $like;
}

$sql = "SELECT rb.id, rb.supplier_name, rb.supplier_contact, rb.control_subtotal,
               rb.computed_subtotal, rb.tally_result, rb.status, rb.validated_at, rb.created_at,
               u.username  AS receiver_name,
               vu.username AS validator_name,
               COALESCE(dd.deduction, 0)        AS damage_deduction,
               COALESCE(dd.pending_tickets, 0)  AS pending_tickets,
               sp.id AS payment_id, sp.net_amount AS paid_amount, sp.payment_reference,
               sp.payment_method, sp.verified_by_username, sp.verified_at, sp.notes AS payment_notes,
               COALESCE(sp.supplier_discount, 0) AS supplier_discount
        FROM receiving_batches rb
        LEFT JOIN users u  ON u.id  = rb.receiver_id
        LEFT JOIN users vu ON vu.id = rb.validator_id
        LEFT JOIN (
            SELECT batch_id,
                   SUM(CASE WHEN status='approved' THEN total_deduction ELSE 0 END) AS deduction,
                   SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END)               AS pending_tickets
            FROM delivery_damage_tickets GROUP BY batch_id
        ) dd ON dd.batch_id = rb.id
        LEFT JOIN procurement_payments sp ON sp.batch_id = rb.id
        $where
        ORDER BY rb.validated_at DESC
        LIMIT 200";
$stmt = $conn->prepare($sql);
if ($s_types) { $stmt->bind_param($s_types, ...$s_params); }
$stmt->execute();
$batches = $stmt->get_result();

$unpaid = [];
$paid   = [];
$batch_ids = [];
while ($b = $batches->fetch_assoc()) {
    $batch_ids[] = $b['id'];
    if ($b['payment_id'] !== null) $paid[] = $b;
    else                           $unpaid[] = $b;
}

// Pre-load items for all listed batches (for the collapsible detail)
$items_by_batch = [];
if (!empty($batch_ids)) {
    $in = implode(',', array_map('intval', $batch_ids));
    $ir = $conn->query(
        "SELECT batch_id, barcode, description, quantity, damaged_qty, damage_notes,
                expiry_date, base_price, amount
         FROM receiving_items WHERE batch_id IN ($in) ORDER BY batch_id, id ASC"
    );
    if ($ir) while ($row = $ir->fetch_assoc()) $items_by_batch[$row['batch_id']][] = $row;
}

include '../layout_top.php';

/** Render one batch card (used by both tabs). */
function render_payment_card(array $b, array $items, bool $is_paid): void
{
    $receipt   = floatval($b['control_subtotal']);
    $deduction = floatval($b['damage_deduction']);
    $net       = round($receipt - $deduction, 2);
    $damaged   = array_filter($items, fn($i) => intval($i['damaged_qty']) > 0);
    $pending   = intval($b['pending_tickets']) > 0;
    ?>
    <div class="card-modern overflow-hidden">
        <div class="p-6 flex flex-wrap items-start gap-5 justify-between">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <p class="font-black text-slate-800">Voucher #<?= $b['id'] ?></p>
                    <span class="font-bold text-slate-600"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></span>
                    <?php if ($is_paid): ?>
                        <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 uppercase">Paid</span>
                    <?php elseif ($pending): ?>
                        <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 uppercase">Damage Ticket Pending</span>
                    <?php endif; ?>
                </div>
                <?php if ($b['supplier_contact']): ?>
                <p class="text-xs text-slate-400 font-bold"><?= htmlspecialchars($b['supplier_contact']) ?></p>
                <?php endif; ?>
                <p class="text-[10px] text-slate-300 font-bold mt-0.5">
                    Validated <?= $b['validated_at'] ? date('M j, Y', strtotime($b['validated_at'])) : '—' ?>
                    · Receiver @<?= htmlspecialchars($b['receiver_name'] ?? '—') ?>
                    · Validator @<?= htmlspecialchars($b['validator_name'] ?? '—') ?>
                </p>
            </div>

            <!-- Money summary -->
            <div class="flex items-center gap-5 shrink-0">
                <div class="text-right">
                    <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest">Receipt</p>
                    <p class="font-black text-slate-500">₱<?= number_format($receipt, 2) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest">Damage</p>
                    <p class="font-black <?= $deduction > 0 ? 'text-rose-500' : 'text-slate-300' ?>">−₱<?= number_format($deduction, 2) ?></p>
                </div>
                <div class="text-right border-l border-slate-100 pl-5">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest"><?= $is_paid ? 'Paid' : 'Net Payable' ?></p>
                    <p id="netpay-<?= $b['id'] ?>" class="text-2xl font-black text-emerald-600">₱<?= number_format($is_paid ? floatval($b['paid_amount']) : $net, 2) ?></p>
                </div>
                <button type="button" onclick="openDetail(<?= $b['id'] ?>)"
                    class="bg-blue-50 hover:bg-blue-100 text-blue-600 border border-blue-100 font-black text-[10px] uppercase tracking-widest px-4 py-2.5 rounded-xl transition-all whitespace-nowrap">
                    View Details
                </button>
            </div>
        </div>

        <!-- Detail content — rendered into the shared modal via "View Details" -->
        <div id="vpdetail-<?= $b['id'] ?>" class="hidden" data-title="Voucher #<?= $b['id'] ?> — <?= htmlspecialchars($b['supplier_name'] ?? '', ENT_QUOTES) ?>">
            <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest mb-4">
                Receiver @<?= htmlspecialchars($b['receiver_name'] ?? '—') ?>
                · Validator @<?= htmlspecialchars($b['validator_name'] ?? '—') ?>
                · Validated <?= $b['validated_at'] ? date('M j, Y', strtotime($b['validated_at'])) : '—' ?>
            </p>
            <?php if (empty($items)): ?>
                <p class="text-slate-400 text-xs font-bold text-center py-4">No items recorded.</p>
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
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td class="font-bold text-slate-700"><?= htmlspecialchars($it['description']) ?></td>
                            <td class="font-mono text-xs text-slate-400"><?= htmlspecialchars($it['barcode'] ?? '—') ?></td>
                            <td class="text-center"><?= intval($it['quantity']) ?></td>
                            <td class="text-center <?= intval($it['damaged_qty']) > 0 ? 'font-black text-rose-500' : 'text-slate-300' ?>"><?= intval($it['damaged_qty']) ?></td>
                            <td class="text-xs text-slate-500"><?= $it['expiry_date'] ? date('M j, Y', strtotime($it['expiry_date'])) : '—' ?></td>
                            <td class="text-right"><?= $it['base_price'] !== null ? '₱' . number_format(floatval($it['base_price']), 2) : '—' ?></td>
                            <td class="text-right font-black text-slate-700"><?= $it['amount'] !== null ? '₱' . number_format(floatval($it['amount']), 2) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200">
                            <td colspan="6" class="pt-2 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Computed Subtotal (Validator)</td>
                            <td class="pt-2 text-right font-black text-slate-600">₱<?= number_format(floatval($b['computed_subtotal']), 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (!empty($damaged)): ?>
            <div class="bg-rose-50/60 border border-rose-100 rounded-2xl p-4 mt-4">
                <p class="text-[10px] font-black text-rose-500 uppercase tracking-widest mb-3">Damaged Items — Deducted from Receipt</p>
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-rose-100/60">
                    <?php foreach ($damaged as $di):
                        $d_val = floatval($di['base_price'] ?? 0) * intval($di['damaged_qty']);
                    ?>
                        <tr>
                            <td class="py-1.5 font-bold text-slate-700"><?= htmlspecialchars($di['description']) ?></td>
                            <td class="py-1.5 text-slate-400 italic"><?= htmlspecialchars($di['damage_notes'] ?? '—') ?></td>
                            <td class="py-1.5 text-center font-black text-rose-500"><?= intval($di['damaged_qty']) ?> ×</td>
                            <td class="py-1.5 text-right text-slate-500">₱<?= number_format(floatval($di['base_price'] ?? 0), 2) ?></td>
                            <td class="py-1.5 text-right font-black text-rose-600">−₱<?= number_format($d_val, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($pending): ?>
                    <p class="text-[10px] text-amber-600 font-black mt-3 uppercase tracking-wide">⚠ A damage ticket is still pending review — this deduction is not yet final.</p>
                <?php else: ?>
                    <p class="text-[10px] text-rose-500 font-black mt-3 text-right">Approved deduction applied: −₱<?= number_format($deduction, 2) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Action / payment record -->
        <?php if ($is_paid): ?>
        <div class="border-t border-slate-100 px-6 py-3 flex flex-wrap items-center gap-4 text-xs">
            <span class="font-black text-slate-400 uppercase tracking-widest">Payment</span>
            <?php if ($b['payment_method']): ?><span class="font-bold text-slate-600"><?= htmlspecialchars($b['payment_method']) ?></span><?php endif; ?>
            <?php if (floatval($b['supplier_discount'] ?? 0) > 0): ?><span class="font-bold text-amber-600">Discount: −₱<?= number_format(floatval($b['supplier_discount']), 2) ?></span><?php endif; ?>
            <?php if ($b['payment_reference']): ?><span class="text-slate-400">Ref: <?= htmlspecialchars($b['payment_reference']) ?></span><?php endif; ?>
            <span class="text-slate-400">by @<?= htmlspecialchars($b['verified_by_username'] ?? '—') ?> · <?= $b['verified_at'] ? date('M j, Y g:i A', strtotime($b['verified_at'])) : '—' ?></span>
            <?php if ($b['payment_notes']): ?><span class="text-slate-400 italic ml-auto"><?= htmlspecialchars($b['payment_notes']) ?></span><?php endif; ?>
        </div>
        <?php else: ?>
        <form method="POST"
              data-receipt="<?= number_format($receipt, 2, '.', '') ?>"
              data-damage="<?= number_format($deduction, 2, '.', '') ?>"
              data-batch="<?= $b['id'] ?>"
              class="border-t border-slate-100 px-6 py-4 flex flex-wrap items-end gap-3"
              onsubmit="return confirm('Record this payment to <?= htmlspecialchars($b['supplier_name'] ?? 'supplier', ENT_QUOTES) ?>?');">
            <?= csrf_field() ?>
            <input type="hidden" name="record_payment" value="1">
            <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
            <div>
                <label class="label-modern text-xs">Supplier Discount</label>
                <div class="flex items-center gap-1">
                    <button type="button" onclick="toggleDiscMode(this)"
                        class="disc-toggle w-9 h-[42px] rounded-xl bg-slate-100 hover:bg-amber-100 text-slate-600 font-black text-sm flex-shrink-0 transition-all" title="Switch peso / percent">₱</button>
                    <input type="number" name="discount_value" step="0.01" min="0" value="0"
                        oninput="recalcNet(this.closest('form'))"
                        class="input-modern text-sm w-24 text-right font-black text-amber-600">
                    <input type="hidden" name="discount_mode" value="amount">
                </div>
            </div>
            <div>
                <label class="label-modern text-xs">Amount Paid <span class="text-slate-300 normal-case font-normal">(auto)</span></label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-black text-sm">₱</span>
                    <input type="text" name="amount" readonly tabindex="-1" value="<?= number_format($net, 2, '.', '') ?>"
                        title="Receipt − Damage − Discount (derived from the voucher)"
                        class="input-modern text-sm w-32 pl-7 text-right font-black text-emerald-600 bg-slate-50 cursor-not-allowed">
                </div>
            </div>
            <div>
                <label class="label-modern text-xs">Method</label>
                <select name="payment_method" class="input-modern text-sm">
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Cheque">Cheque</option>
                    <option value="GCash">GCash</option>
                </select>
            </div>
            <div class="flex-1 min-w-[140px]">
                <label class="label-modern text-xs">Reference No.</label>
                <input type="text" name="payment_reference" class="input-modern text-sm w-full" placeholder="OR / txn no. (optional)">
            </div>
            <div class="flex-1 min-w-[140px]">
                <label class="label-modern text-xs">Notes</label>
                <input type="text" name="notes" class="input-modern text-sm w-full" placeholder="Optional">
            </div>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-500 text-white font-black text-xs px-6 py-3 rounded-xl uppercase tracking-widest transition-all whitespace-nowrap shadow-md active:scale-95">
                Record Payment
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php
}
?>

<div class="max-w-5xl mx-auto space-y-6">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Search -->
    <form method="GET" class="card-modern p-4">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
        <div class="flex items-center gap-3">
            <div class="relative flex-1">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                    </svg>
                </span>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" autofocus
                    placeholder="Search by supplier name or voucher #…"
                    class="input-modern text-base w-full pl-12 py-3.5 font-bold">
            </div>
            <button type="submit" class="btn-pos-primary px-8 py-3.5 text-sm font-black uppercase tracking-widest whitespace-nowrap">Search</button>
            <?php if ($search !== ''): ?>
            <a href="?tab=<?= htmlspecialchars($active_tab) ?>"
               class="px-5 py-3.5 rounded-2xl border border-slate-200 text-xs font-black uppercase tracking-widest text-slate-500 hover:bg-slate-50 transition-all whitespace-nowrap">Clear</a>
            <?php endif; ?>
        </div>
        <?php if ($search !== ''): ?>
        <p class="text-xs font-bold text-slate-400 mt-3 ml-1">
            Showing results for <span class="text-slate-700">"<?= htmlspecialchars($search) ?>"</span>
        </p>
        <?php endif; ?>
    </form>

    <!-- Tabs -->
    <div class="flex gap-1 bg-slate-100 rounded-2xl p-1 w-fit">
        <?php foreach (['unpaid' => 'Unpaid', 'paid' => 'Paid'] as $key => $label):
            $count = $key === 'unpaid' ? count($unpaid) : count($paid);
        ?>
        <a href="?tab=<?= $key ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
           class="px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all <?= $active_tab === $key ? 'bg-white shadow text-slate-800' : 'text-slate-500 hover:text-slate-700' ?>">
            <?= $label ?> <span class="ml-1 <?= $key === 'unpaid' && $count > 0 ? 'text-rose-500' : 'text-slate-400' ?>">(<?= $count ?>)</span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php $list = $active_tab === 'paid' ? $paid : $unpaid; ?>
    <?php if (empty($list)): ?>
        <div class="card-modern p-12 text-center">
            <p class="text-slate-400 font-black text-sm">
                <?php if ($search !== ''): ?>
                    No <?= $active_tab === 'paid' ? 'paid' : 'unpaid' ?> results for "<?= htmlspecialchars($search) ?>".
                <?php else: ?>
                    <?= $active_tab === 'paid' ? 'No supplier payments recorded yet.' : 'No validated batches awaiting payment.' ?>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="space-y-4">
        <?php foreach ($list as $b): ?>
            <?php render_payment_card($b, $items_by_batch[$b['id']] ?? [], $active_tab === 'paid'); ?>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ── Detail Viewer Modal ────────────────────────────────────────────────── -->
<div id="detail-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm hidden p-4">
    <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col animate-in">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h4 id="detail-modal-title" class="serif-title text-lg font-black text-slate-800">Details</h4>
            <button type="button" onclick="closeDetail()" class="text-slate-400 hover:text-slate-700 text-3xl font-black leading-none">&times;</button>
        </div>
        <div id="detail-modal-body" class="px-6 py-5 overflow-y-auto"></div>
    </div>
</div>

<script>
// Live net-payable: Receipt − Damage − Supplier Discount (flat ₱ or % of receipt).
// Updates the Amount Paid field and the Net Payable figure as the discount is typed.
function recalcNet(form) {
    if (!form) return;
    var receipt = parseFloat(form.dataset.receipt) || 0;
    var damage  = parseFloat(form.dataset.damage)  || 0;
    var mode    = form.querySelector('[name="discount_mode"]').value;
    var val     = parseFloat(form.querySelector('[name="discount_value"]').value) || 0;
    if (val < 0) val = 0;
    var disc = (mode === 'percent') ? (receipt * val / 100) : val;
    disc = Math.min(disc, Math.max(0, receipt - damage));      // never push net below 0
    var net = Math.max(0, receipt - damage - disc);
    var amt = form.querySelector('[name="amount"]');
    if (amt) amt.value = net.toFixed(2);
    var disp = document.getElementById('netpay-' + form.dataset.batch);
    if (disp) disp.textContent = '₱' + net.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
// Toggle the discount input between flat peso (₱) and percentage (%).
function toggleDiscMode(btn) {
    var form  = btn.closest('form');
    var modeEl = form.querySelector('[name="discount_mode"]');
    var input  = form.querySelector('[name="discount_value"]');
    if (modeEl.value === 'amount') {
        modeEl.value = 'percent'; btn.textContent = '%';
        if (input) { input.max = '100'; input.placeholder = '0–100'; }
    } else {
        modeEl.value = 'amount'; btn.textContent = '₱';
        if (input) { input.removeAttribute('max'); input.placeholder = '0.00'; }
    }
    recalcNet(form);
}
function openDetail(id) {
    const src = document.getElementById('vpdetail-' + id);
    if (!src) return;
    document.getElementById('detail-modal-title').textContent = src.dataset.title || 'Details';
    document.getElementById('detail-modal-body').innerHTML = src.innerHTML;
    document.getElementById('detail-modal').classList.remove('hidden');
}
function closeDetail() {
    document.getElementById('detail-modal').classList.add('hidden');
    document.getElementById('detail-modal-body').innerHTML = '';
}
document.getElementById('detail-modal').addEventListener('click', function (e) {
    if (e.target === this) closeDetail();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDetail();
});
</script>

<?php include '../layout_bottom.php'; ?>
