<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_VALIDATOR, ROLE_PRICE_CHECKER, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

// Price Checker reprices reopened batches; everyone else validates. Back-link differs.
$home_page = ($role === ROLE_PRICE_CHECKER) ? 'price_checker.php?tab=reprice' : 'validate_batch.php';
$home_sep  = (strpos($home_page, '?') !== false) ? '&' : '?';

$batch_id = intval($_GET['batch_id'] ?? 0);
if (!$batch_id) {
    header("Location: {$home_page}{$home_sep}error=" . urlencode("No batch selected."));
    exit();
}

// Accept both validation (first pass) and reprice (reopened by admin) statuses.
// SECURITY: control_subtotal is NOT selected here — only on validate_process.php
$bq = $conn->prepare(
    "SELECT id, supplier_name, supplier_contact, status, computed_subtotal, tally_result
     FROM receiving_batches WHERE id = ? AND status IN ('pending_validation','pending_reprice') LIMIT 1"
);
$bq->bind_param("i", $batch_id);
$bq->execute();
$batch = $bq->get_result()->fetch_assoc();

if (!$batch) {
    header("Location: {$home_page}{$home_sep}error=" . urlencode("Batch not found or not awaiting price entry."));
    exit();
}
$is_reprice = ($batch['status'] === 'pending_reprice');

// During the first-pass blind validation, damaged_qty / damage_notes are NOT fetched.
// During a reprice (reopened by admin), the Price Checker is allowed full context:
// encoded qty, damaged qty/notes, and a suggested price that tallies the supplier receipt.
if ($is_reprice) {
    $iq = $conn->prepare(
        "SELECT id, barcode, description, quantity, expiry_date, base_price, damaged_qty, damage_notes
         FROM receiving_items WHERE batch_id = ? ORDER BY id ASC"
    );
} else {
    $iq = $conn->prepare(
        "SELECT id, barcode, description, quantity, expiry_date, base_price
         FROM receiving_items WHERE batch_id = ? ORDER BY id ASC"
    );
}
$iq->bind_param("i", $batch_id);
$iq->execute();
$items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Reprice-only: receipt target + suggested prices ───────────────────────────
$receipt_target = 0.0;
$suggested      = [];   // item_id => suggested unit price
if ($is_reprice) {
    // The supplier receipt amount is the target the computed subtotal must tally to.
    $cq = $conn->prepare("SELECT control_subtotal FROM receiving_batches WHERE id = ? LIMIT 1");
    $cq->bind_param("i", $batch_id);
    $cq->execute();
    $receipt_target = floatval($cq->get_result()->fetch_assoc()['control_subtotal'] ?? 0);

    // Prior computed subtotal from the existing base prices
    $prior = 0.0;
    $total_qty = 0;
    foreach ($items as $it) {
        $prior     += floatval($it['base_price'] ?? 0) * intval($it['quantity']);
        $total_qty += intval($it['quantity']);
    }

    foreach ($items as $it) {
        $qty = intval($it['quantity']);
        if ($qty <= 0) { $suggested[$it['id']] = 0.0; continue; }
        if ($prior > 0 && floatval($it['base_price'] ?? 0) > 0) {
            // Scale existing prices proportionally so they sum to the receipt target
            $suggested[$it['id']] = round(floatval($it['base_price']) * ($receipt_target / $prior), 2);
        } elseif ($total_qty > 0) {
            // No prior prices — spread the target evenly across all units
            $suggested[$it['id']] = round($receipt_target / $total_qty, 2);
        } else {
            $suggested[$it['id']] = 0.0;
        }
    }
}

$error = trim($_GET['error'] ?? '');

include '../layout_top.php';
?>

<div class="max-w-5xl mx-auto space-y-6">

    <!-- Header -->
    <div class="flex flex-wrap items-start gap-4">
        <a href="<?= htmlspecialchars($home_page) ?>" class="text-sm text-slate-500 font-bold hover:underline mt-1">&larr; Back</a>
        <div class="flex-1">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">
                Batch #<?= $batch_id ?>
                <?php if ($is_reprice): ?>
                <span class="ml-2 bg-rose-100 text-rose-600 px-2 py-0.5 rounded-full">Reopened for Repricing</span>
                <?php endif; ?>
            </p>
            <h3 class="serif-title text-xl font-black text-slate-800"><?= htmlspecialchars($batch['supplier_name'] ?? 'Unknown Supplier') ?></h3>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($is_reprice): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl px-5 py-4 text-sm font-bold flex flex-wrap items-center justify-between gap-4">
        <div>
            Re-enter prices so the computed subtotal tallies the supplier receipt.
            Encoded quantity and reported damage are shown for reference.
        </div>
        <div class="text-right">
            <p class="text-[10px] font-black uppercase tracking-widest text-rose-400">Supplier Receipt Total</p>
            <p class="text-2xl font-black text-rose-700">₱<?= number_format($receipt_target, 2) ?></p>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-sky-50 border border-sky-200 text-sky-800 rounded-2xl px-5 py-4 text-sm font-bold">
        Enter the base price for each item. Your computed subtotal will update live.
        The per-item amount is not displayed — only the running total is shown.
    </div>
    <?php endif; ?>

    <form method="POST" action="validate_process.php" id="validateForm">
        <?= csrf_field() ?>
        <input type="hidden" name="batch_id" value="<?= $batch_id ?>">

        <div class="card-modern p-6">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left">
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4">#</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4">Barcode</th>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4">Description</th>
                            <?php if ($is_reprice): ?>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4 text-center w-16">Qty</th>
                            <th class="pb-3 text-[10px] font-black text-rose-400 uppercase tracking-widest pr-4 text-center w-20">Damaged</th>
                            <?php endif; ?>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest pr-4">Expiry</th>
                            <?php if ($is_reprice): ?>
                            <th class="pb-3 text-[10px] font-black text-emerald-500 uppercase tracking-widest pr-4 text-right w-28">Suggested</th>
                            <?php endif; ?>
                            <th class="pb-3 text-[10px] font-black text-slate-400 uppercase tracking-widest w-36">Base Price <span class="text-rose-500">*</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $i => $item):
                        $sugg = $is_reprice ? ($suggested[$item['id']] ?? 0) : 0;
                    ?>
                        <tr class="border-t border-slate-50">
                            <td class="py-3 pr-4 text-slate-400 text-xs"><?= $i + 1 ?></td>
                            <td class="py-3 pr-4 font-mono text-xs text-slate-500"><?= htmlspecialchars($item['barcode'] ?? '—') ?></td>
                            <td class="py-3 pr-4 font-bold"><?= htmlspecialchars($item['description']) ?></td>
                            <?php if ($is_reprice): ?>
                            <td class="py-3 pr-4 text-center font-black text-slate-700"><?= intval($item['quantity']) ?></td>
                            <td class="py-3 pr-4 text-center">
                                <?php if (intval($item['damaged_qty'] ?? 0) > 0): ?>
                                <span class="font-black text-rose-500"><?= intval($item['damaged_qty']) ?></span>
                                <?php if (!empty($item['damage_notes'])): ?>
                                <p class="text-[9px] text-rose-400 font-bold max-w-[90px] truncate mx-auto" title="<?= htmlspecialchars($item['damage_notes']) ?>"><?= htmlspecialchars($item['damage_notes']) ?></p>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-slate-200 font-bold">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="py-3 pr-4 text-slate-400 text-xs"><?= $item['expiry_date'] ? date('M j, Y', strtotime($item['expiry_date'])) : '—' ?></td>
                            <?php if ($is_reprice): ?>
                            <td class="py-3 pr-4 text-right">
                                <span class="font-black text-emerald-600 text-sm">₱<?= number_format($sugg, 2) ?></span>
                            </td>
                            <?php endif; ?>
                            <td class="py-3">
                                <input type="hidden" name="items[<?= $i ?>][item_id]" value="<?= $item['id'] ?>">
                                <input type="hidden" name="items[<?= $i ?>][qty]" value="<?= intval($item['quantity']) ?>">
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">₱</span>
                                    <input type="number" name="items[<?= $i ?>][base_price]"
                                           required min="0.01" step="0.01"
                                           value="<?= $item['base_price'] !== null ? htmlspecialchars($item['base_price']) : '' ?>"
                                           class="input-modern pl-7 text-sm w-full price-input"
                                           data-qty="<?= intval($item['quantity']) ?>"
                                           <?php if ($is_reprice): ?>data-suggested="<?= $sugg ?>"<?php endif; ?>
                                           placeholder="0.00"
                                           oninput="recalcSubtotal()">
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Running subtotal -->
        <div class="card-modern p-6">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Your Computed Subtotal</p>
                    <p class="text-3xl font-black text-slate-800 mt-1" id="computed-subtotal">₱0.00</p>
                    <?php if ($is_reprice): ?>
                    <p class="text-[10px] font-bold mt-1" id="tally-status">Target: ₱<?= number_format($receipt_target, 2) ?></p>
                    <?php else: ?>
                    <p class="text-[10px] text-slate-400 mt-1">Sum of (base price × qty) for all items</p>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                    <?php if ($is_reprice): ?>
                    <button type="button" onclick="useSuggested()"
                            class="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 px-6 py-4 rounded-2xl text-sm font-black uppercase tracking-widest transition-all">
                        Use Suggested
                    </button>
                    <?php endif; ?>
                    <button type="submit" class="btn-pos-primary px-10 py-4 text-sm font-black uppercase tracking-widest">
                        <?= $is_reprice ? 'Submit Reprice' : 'Submit Validation' ?>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let _isDirty = false;

// Warn before navigating away if any price has been entered
window.addEventListener('beforeunload', function(e) {
    if (_isDirty) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Clear the warning when the form is actually submitted
document.getElementById('validateForm')?.addEventListener('submit', function() {
    _isDirty = false;
});

const RECEIPT_TARGET = <?= $is_reprice ? json_encode(round($receipt_target, 2)) : 'null' ?>;

function recalcSubtotal() {
    let total = 0;
    document.querySelectorAll('.price-input').forEach(input => {
        const price = parseFloat(input.value) || 0;
        const qty   = parseInt(input.dataset.qty) || 0;
        total += price * qty;
    });
    document.getElementById('computed-subtotal').textContent = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Reprice mode — show how close we are to the supplier receipt target
    const status = document.getElementById('tally-status');
    if (status && RECEIPT_TARGET !== null) {
        const diff = +(total - RECEIPT_TARGET).toFixed(2);
        const fmt  = v => '₱' + Math.abs(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (Math.abs(diff) <= 0.01) {
            status.textContent = '✓ Tallies with receipt (' + fmt(RECEIPT_TARGET) + ')';
            status.className = 'text-[10px] font-black mt-1 text-emerald-600';
        } else if (diff > 0) {
            status.textContent = fmt(diff) + ' over target of ' + fmt(RECEIPT_TARGET);
            status.className = 'text-[10px] font-black mt-1 text-amber-600';
        } else {
            status.textContent = fmt(diff) + ' under target of ' + fmt(RECEIPT_TARGET);
            status.className = 'text-[10px] font-black mt-1 text-rose-600';
        }
    }
    _isDirty = true;  // mark dirty as soon as any price changes
}

// Fill each price input with its suggested value, then recalc
function useSuggested() {
    document.querySelectorAll('.price-input').forEach(input => {
        const s = parseFloat(input.dataset.suggested);
        if (!isNaN(s) && s > 0) input.value = s.toFixed(2);
    });
    recalcSubtotal();
}

// Initialise if prices already filled (pre-filled = already dirty)
recalcSubtotal();
if (document.querySelectorAll('.price-input[value]').length > 0) _isDirty = true;
</script>

<?php include '../layout_bottom.php'; ?>
