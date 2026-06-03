<?php
include '../../config/db.php';
include '../../config/settings.php';
include '../../includes/csrf.php';
include '../layout_top.php';

if (empty($_SESSION['cart'])) {
    echo "<script>window.location.href='/project/staff/pos/pos.php';</script>";
    exit();
}

$subtotal        = 0;
$cart_composition = []; // for scope-aware discount calculation in JS

foreach ($_SESSION['cart'] as $pid => $item) {
    $lt        = floatval($item['line_total'] ?? ($item['price'] * $item['qty']));
    $subtotal += $lt;

    // Fetch category and vat_exempt for this item
    $cat_q = $conn->prepare("SELECT category, vat_exempt FROM products WHERE id = ? LIMIT 1");
    $cat_q->bind_param("i", $pid);
    $cat_q->execute();
    $cat_row = $cat_q->get_result()->fetch_assoc();

    $cart_composition[] = [
        'product_id' => (int)$pid,
        'category'   => $cat_row['category']   ?? '',
        'vat_exempt' => (int)($cat_row['vat_exempt'] ?? 0),
        'line_total' => $lt,
    ];
}
$tax_rate = TAX_RATE;

// F-11 / F-14: Load rounding rule and tax display mode in one query
$rounding_rule    = 'none';
$tax_display_mode = 'exclusive';
$sys_q = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('price_rounding_rule','tax_display_mode')");
if ($sys_q) {
    while ($sr = $sys_q->fetch_assoc()) {
        if ($sr['setting_key'] === 'price_rounding_rule') $rounding_rule    = $sr['setting_value'] ?? 'none';
        if ($sr['setting_key'] === 'tax_display_mode')    $tax_display_mode = $sr['setting_value'] ?? 'exclusive';
    }
}

// F-13: Bundle discount total from session (for JS display)
$bundle_discount_total_php = 0.0;
$bundle_names_php = [];
foreach ($_SESSION['bundle_discounts'] ?? [] as $bd) {
    $bundle_discount_total_php += floatval($bd['amount']);
    $bundle_names_php[] = htmlspecialchars($bd['name']) . ($bd['qty'] > 1 ? ' ×'.$bd['qty'] : '');
}

// Fetch all active promos/discounts (Plural table: discounts)
$all_discounts = [];
$disc_q = $conn->query("SELECT * FROM discounts WHERE is_active = 1 ORDER BY priority DESC");
if($disc_q) {
    while($d = $disc_q->fetch_assoc()) {
        $all_discounts[] = $d;
    }
}

// F-06: Fetch active customer groups for group-pricing selector
$all_groups = [];
$grp_q = $conn->query("SELECT * FROM customer_groups WHERE is_active = 1 ORDER BY name ASC");
if ($grp_q) {
    while ($grp = $grp_q->fetch_assoc()) $all_groups[] = $grp;
}
?>

<div class="max-w-2xl mx-auto pt-10 pb-20 animate-in">
    <div class="card-modern shadow-2xl overflow-hidden border-none p-0">
        
        <!-- 🏢 HEADER -->
        <div class="bg-slate-900 px-10 py-8 flex justify-between items-center">
            <div>
                <h3 class="serif-title text-3xl font-bold text-white">Sale Finalization</h3>
                <p class="text-emerald-400 text-[10px] font-black uppercase tracking-[0.2em] mt-1">Review & Collect Payment</p>
            </div>
            <div class="bg-white/10 p-3 rounded-2xl text-white">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
            </div>
        </div>

        <form id="checkoutForm" method="POST" action="checkout_process.php" class="p-10 space-y-8 bg-white">
            <?= csrf_field() ?>

            <!-- 💰 DYNAMIC TOTAL DISPLAY -->
            <div class="text-center space-y-2 py-12 bg-emerald-50 rounded-[2.5rem] border border-emerald-100 relative">
                <p class="text-emerald-600 font-black uppercase tracking-[0.3em] text-[11px]">Total Payable Amount</p>
                <h1 id="display-total" class="text-6xl font-black text-slate-900 tracking-tighter">
                    ₱<?= number_format($subtotal, 2) ?>
                </h1>
                <div id="tax-detail-text" class="text-[10px] text-slate-400 font-bold uppercase mt-2">
                    Tax calculations pending...
                </div>
                <!-- Hidden inputs for backend processing -->
                <input type="hidden" name="total" id="final-total-hidden" value="<?= $subtotal ?>">
                <input type="hidden" name="customer_group_id" id="group-id-hidden" value="0">
                <!-- Rounding notice (shown when rule != none) -->
                <p id="rounding-note" class="text-[9px] text-slate-400 font-bold uppercase tracking-widest hidden mt-2"></p>
            </div>

            <!-- 👥 F-06: CUSTOMER GROUP SELECTOR -->
            <?php if (!empty($all_groups)): ?>
            <input type="hidden" name="customer_group_id" id="group-id-post" value="0">
            <div class="space-y-3 pt-2">
                <label class="label-modern ml-2">Customer Type <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-<?= min(4, count($all_groups) + 1) ?>">
                    <label class="cursor-pointer">
                        <input type="radio" name="_group_radio" value="0" checked class="hidden peer" onchange="selectGroup(0,0,'none',0,'')">
                        <div class="py-3 border-2 border-slate-100 rounded-2xl text-center font-black text-slate-400 text-[10px] uppercase tracking-widest peer-checked:border-slate-900 peer-checked:text-slate-900 peer-checked:bg-slate-50 transition-all">Regular</div>
                    </label>
                    <?php foreach ($all_groups as $grp): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="_group_radio" value="<?= $grp['id'] ?>" class="hidden peer"
                               onchange="selectGroup(<?= $grp['id'] ?>,'<?= htmlspecialchars($grp['discount_type']) ?>',<?= $grp['discount_value'] ?>,'<?= htmlspecialchars($grp['label']) ?>')">
                        <div class="py-3 border-2 border-slate-100 rounded-2xl text-center font-black text-blue-500 text-[10px] uppercase tracking-widest peer-checked:border-blue-500 peer-checked:bg-blue-50 transition-all">
                            <?= htmlspecialchars($grp['label'] ?: $grp['name']) ?>
                            <p class="text-[8px] font-black text-slate-400 mt-0.5">
                                <?= $grp['discount_type'] === 'Percentage' ? number_format($grp['discount_value'],0).'% off' : '₱'.number_format($grp['discount_value'],2).' off' ?>
                            </p>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p id="group-discount-line" class="text-[11px] font-bold text-blue-600 ml-2 hidden"></p>
            </div>
            <?php endif; ?>

            <!-- 🏷️ PROMO & DISCOUNT SECTION -->
            <input type="hidden" name="discount_id" id="discount_id_field" value="0">
            <input type="hidden" name="stacked_discount_ids" id="stacked_ids_field" value="">
            <div class="space-y-2 pt-4">
                <label class="label-modern ml-2">Promo Code</label>
                <div class="relative">
                    <input type="text" id="promo_input" name="promo_code" oninput="applyTypedPromo()" placeholder="TYPE CODE (leave blank to skip)"
                           class="input-modern bg-amber-50 border-amber-200 text-amber-700 uppercase focus:border-amber-500">
                    <div id="promo_status" class="absolute right-4 top-4 text-emerald-500 hidden">
                         <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                    </div>
                </div>
                <p id="promo_feedback" class="text-[11px] font-bold ml-2 hidden"></p>
            </div>

            <!-- 💳 PAYMENT METHOD (Grid Selection) -->
            <div class="space-y-4 pt-4 border-t border-slate-50">
                <label class="label-modern text-center mb-0">Select Payment Method</label>
                <div class="grid grid-cols-3 gap-3">
                    <?php
                    $methods = [
                        PAY_METHOD_CASH  => 'bg-emerald-50',
                        PAY_METHOD_GCASH => 'bg-blue-50',
                        PAY_METHOD_MAYA  => 'bg-green-50',
                    ];
                    foreach($methods as $method => $bg): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="payment_mode" value="<?= $method ?>" <?= $method==PAY_METHOD_CASH?'checked':'' ?>
                               class="hidden peer" onchange="toggleRefField('<?= ($method==PAY_METHOD_CASH) ? 'no' : 'yes' ?>')">
                        <div class="py-4 border-2 border-slate-100 rounded-2xl text-center font-black text-slate-400 text-xs uppercase tracking-widest peer-checked:border-slate-900 peer-checked:text-slate-900 peer-checked:<?= $bg ?> transition-all"><?= $method ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 📥 PAYMENT INPUTS -->
            <div class="space-y-4 py-6 bg-slate-50 rounded-[2rem] border border-slate-100">
                <div id="cash_input_container">
                    <label class="label-modern text-center mb-2">Tendered Amount</label>
                    <div class="relative max-w-xs mx-auto">
                        <span class="absolute left-6 top-1/2 -translate-y-1/2 text-2xl font-black text-slate-300">₱</span>
                        <input type="number" name="cash" id="cash-input" step="0.01" required 
                            class="w-full bg-white border-2 border-slate-200 rounded-[1.5rem] py-5 pl-12 pr-6 text-3xl font-black text-slate-800 focus:border-emerald-500 outline-none text-center shadow-sm">
                    </div>
                </div>

                <div id="ref_input_container" class="hidden px-10">
                    <label class="label-modern text-center mb-2 text-blue-500">Trace/Reference Number <span class="text-rose-500">*</span></label>
                    <input type="text" name="reference_no" id="reference_no" placeholder="TRANS-ID-XXXX"
                        oninput="document.getElementById('ref_error').classList.add('hidden');this.classList.remove('border-rose-400')"
                        class="input-modern bg-white text-center font-mono border-blue-100 focus:border-blue-400 uppercase">
                    <p id="ref_error" class="text-center text-rose-500 text-[11px] font-bold mt-2 hidden">Reference number is required for digital payments.</p>
                </div>
            </div>

            <!-- 🏁 FINAL SUBMISSION -->
            <button type="submit" class="btn-pos-primary w-full py-8 text-2xl shadow-xl shadow-emerald-200 uppercase tracking-widest">
                Confirm & Print Receipt
            </button>
        </form>
    </div>
</div>

<script>
const rawSubtotal    = <?= (float)$subtotal ?>;
const taxRate        = <?= (float)$tax_rate ?>;
const promoDatabase  = <?= json_encode($all_discounts) ?>;
const cartItems      = <?= json_encode($cart_composition) ?>; // for scope-aware discounts
const PAY_CASH  = <?= json_encode(PAY_METHOD_CASH) ?>;
const PAY_GCASH = <?= json_encode(PAY_METHOD_GCASH) ?>;
const PAY_MAYA  = <?= json_encode(PAY_METHOD_MAYA) ?>;
const DISC_PCT  = <?= json_encode(DISCOUNT_PERCENTAGE) ?>;
const ROUNDING_RULE        = <?= json_encode($rounding_rule) ?>;
const TAX_DISPLAY_MODE     = <?= json_encode($tax_display_mode) ?>; // F-14
const BUNDLE_DISCOUNT_AMT  = <?= (float)$bundle_discount_total_php ?>; // F-13
const BUNDLE_NAMES         = <?= json_encode(implode(', ', $bundle_names_php)) ?>;
// GAP-4: Rounding sync probe — PHP computed known inputs; JS must produce same outputs.
const ROUNDING_PROBE = <?= json_encode([
    'rule'     => $rounding_rule,
    'cases'    => array_map(function($v) use ($rounding_rule) {
        switch ($rounding_rule) {
            case 'nearest_25c':   return ['in' => $v, 'out' => round($v * 4) / 4];
            case 'nearest_50c':   return ['in' => $v, 'out' => round($v * 2) / 2];
            case 'nearest_peso':  return ['in' => $v, 'out' => round($v)];
            case 'nearest_5peso': return ['in' => $v, 'out' => round($v / 5) * 5];
            default:              return ['in' => $v, 'out' => $v];
        }
    }, [10.10, 10.25, 10.37, 10.50, 10.63, 10.75, 99.99]),
]) ?>;

// F-06: Active customer-group state
let activeGroup = { id: 0, type: 'Fixed', value: 0, label: '' };
let activeDiscount = { type: 'Fixed', value: 0, scope: 'store', target_product_id: 0, target_category: '' };
let promoValid = false;

// F-06: Called when cashier picks a customer group radio button
function selectGroup(id, type, value, label) {
    activeGroup = { id: parseInt(id)||0, type: type||'Fixed', value: parseFloat(value)||0, label: label||'' };
    document.getElementById('group-id-hidden').value = activeGroup.id;
    const post = document.getElementById('group-id-post');
    if (post) post.value = activeGroup.id;
    const line = document.getElementById('group-discount-line');
    if (activeGroup.id > 0 && line) {
        const disc = activeGroup.type === 'Percentage'
            ? `${activeGroup.value.toFixed(1)}% off subtotal`
            : `₱${activeGroup.value.toFixed(2)} flat off`;
        line.textContent = `${activeGroup.label} discount: ${disc}`;
        line.classList.remove('hidden');
    } else if (line) {
        line.classList.add('hidden');
    }
    updateTotals();
}

// F-11: Apply rounding rule to a total.
// GAP-20: Mirrors PHP applyRounding() in checkout_process.php — keep both in sync.
// When adding a new case here, add the identical case to the PHP function.
function applyRounding(total, rule) {
    switch (rule) {
        case 'nearest_25c':   return Math.round(total * 4) / 4;
        case 'nearest_50c':   return Math.round(total * 2) / 2;
        case 'nearest_peso':  return Math.round(total);
        case 'nearest_5peso': return Math.round(total / 5) * 5;
        default:              return total; // 'none'
    }
}

// GAP-4: Verify JS applyRounding() matches PHP at page load time.
(function() {
    const mismatch = ROUNDING_PROBE.cases.find(c => Math.abs(applyRounding(c.in, ROUNDING_PROBE.rule) - c.out) > 0.0001);
    if (mismatch) {
        console.error('[Rounding sync] JS/PHP mismatch on rule "' + ROUNDING_PROBE.rule + '": input ' + mismatch.in + ' → JS=' + applyRounding(mismatch.in, ROUNDING_PROBE.rule) + ', PHP=' + mismatch.out);
        showFlash('Warning: price rounding mismatch detected. Contact your system administrator.', 'error');
    }
})();

// F-09: Auto-resolve best applicable promo when no code is manually typed.
// Called on page load to surface the best deal automatically.
function autoResolvePromos() {
    const today = new Date().toISOString().slice(0, 10);
    const afterGroup = Math.max(0, rawSubtotal - (activeGroup.id > 0 && activeGroup.value > 0
        ? (activeGroup.type === 'Percentage' ? rawSubtotal * activeGroup.value / 100 : Math.min(activeGroup.value, rawSubtotal))
        : 0));

    // Collect all applicable (active, in-schedule, within usage) promos
    const applicable = promoDatabase.filter(p => {
        if (!parseInt(p.is_active)) return false;
        const limit = parseInt(p.usage_limit) || 0;
        const used  = parseInt(p.used_count)  || 0;
        if (limit > 0 && used >= limit) return false;
        if (p.start_date && today < p.start_date) return false;
        if (p.end_date   && today > p.end_date)   return false;
        return true;
    });
    if (!applicable.length) return;

    // Compute effective discount amount for each
    function calcAmt(p) {
        const discountable = getDiscountableSubtotal({
            type: p.type, value: parseFloat(p.value),
            scope: p.scope || 'store',
            target_product_id: parseInt(p.target_product_id) || 0,
            target_category: p.target_category || ''
        }, afterGroup);
        return p.type === DISC_PCT
            ? discountable * (parseFloat(p.value) / 100)
            : Math.min(parseFloat(p.value), discountable);
    }

    // Determine conflict_rule from the highest-priority applicable promo
    const sorted  = [...applicable].sort((a,b) => parseInt(b.priority||0) - parseInt(a.priority||0));
    const topRule = sorted[0]?.conflict_rule || 'best_for_customer';

    var discIdField     = document.getElementById('discount_id_field');
    var stackedField    = document.getElementById('stacked_ids_field');

    let chosen = null;
    if (topRule === 'priority_order') {
        chosen = sorted[0];
    } else if (topRule === 'stack') {
        // Stack: sum all applicable discounts client-side for display,
        // and pass all IDs to the server so it applies and tracks each one.
        const totalAmt = applicable.reduce((s, p) => s + calcAmt(p), 0);
        if (totalAmt > 0) {
            activeDiscount = { type: 'Fixed', value: Math.min(totalAmt, afterGroup), scope: 'store', target_product_id: 0, target_category: '' };
            promoValid = true;
            if (discIdField)  discIdField.value  = 0;
            if (stackedField) stackedField.value = applicable.map(function(p) { return p.id; }).join(',');
        }
        return;
    } else {
        // best_for_customer (default): pick the promo that saves the customer the most
        chosen = applicable.reduce((best, p) => calcAmt(p) > calcAmt(best) ? p : best, applicable[0]);
    }

    if (!chosen) return;
    activeDiscount = {
        type:              chosen.type,
        value:             parseFloat(chosen.value),
        scope:             chosen.scope || 'store',
        target_product_id: parseInt(chosen.target_product_id) || 0,
        target_category:   chosen.target_category || '',
    };
    promoValid = true;
    // Tell the server which discount was selected so it applies and tracks it.
    if (discIdField)  discIdField.value  = chosen.id;
    if (stackedField) stackedField.value = '';
}

// Returns the subtotal eligible for the active discount based on its scope
function getDiscountableSubtotal(discount, baseSubtotal) {
    if (discount.scope === 'product' && discount.target_product_id) {
        return cartItems.filter(i => i.product_id == discount.target_product_id)
                        .reduce((s, i) => s + i.line_total, 0);
    }
    if (discount.scope === 'category' && discount.target_category) {
        return cartItems.filter(i => i.category === discount.target_category)
                        .reduce((s, i) => s + i.line_total, 0);
    }
    return baseSubtotal; // store-wide: applied AFTER group discount
}

function applyTypedPromo() {
    const input      = document.getElementById('promo_input');
    const code       = input.value.trim().toUpperCase();
    const statusIcon = document.getElementById('promo_status');
    const feedback   = document.getElementById('promo_feedback');

    // Reset state
    input.style.borderColor = '';
    statusIcon.classList.add('hidden');
    feedback.classList.add('hidden');
    feedback.textContent = '';
    feedback.className = 'text-[11px] font-bold ml-2 hidden';

    var discIdField2  = document.getElementById('discount_id_field');
    var stackedField2 = document.getElementById('stacked_ids_field');

    if (!code) {
        activeDiscount = { type: 'Fixed', value: 0 };
        promoValid = false;
        if (discIdField2)  discIdField2.value  = 0;
        if (stackedField2) stackedField2.value = '';
        updateTotals();
        return;
    }

    const match = promoDatabase.find(p => p.promo_code === code && p.is_active == 1);

    if (!match) {
        activeDiscount = { type: 'Fixed', value: 0 };
        promoValid = false;
        input.style.borderColor = '#f87171';
        feedback.textContent = 'Promo code not found.';
        feedback.className = 'text-[11px] font-bold ml-2 text-rose-500';
        feedback.classList.remove('hidden');
    } else {
        const limit = parseInt(match.usage_limit) || 0;
        const used  = parseInt(match.used_count)  || 0;
        if (limit > 0 && used >= limit) {
            activeDiscount = { type: 'Fixed', value: 0 };
            promoValid = false;
            input.style.borderColor = '#f87171';
            feedback.textContent = `Code "${code}" has reached its usage limit (${used}/${limit} uses).`;
            feedback.className = 'text-[11px] font-bold ml-2 text-rose-500';
            feedback.classList.remove('hidden');
        } else {
            // Schedule check
            const today = new Date().toISOString().slice(0,10);
            if (match.start_date && today < match.start_date) {
                activeDiscount = { type: 'Fixed', value: 0, scope: 'store', target_product_id: 0, target_category: '' };
                promoValid = false;
                input.style.borderColor = '#f87171';
                feedback.textContent = `Code "${code}" hasn't started yet (starts ${match.start_date}).`;
                feedback.className = 'text-[11px] font-bold ml-2 text-rose-500';
                feedback.classList.remove('hidden');
                updateTotals(); return;
            }
            if (match.end_date && today > match.end_date) {
                activeDiscount = { type: 'Fixed', value: 0, scope: 'store', target_product_id: 0, target_category: '' };
                promoValid = false;
                input.style.borderColor = '#f87171';
                feedback.textContent = `Code "${code}" has expired.`;
                feedback.className = 'text-[11px] font-bold ml-2 text-rose-500';
                feedback.classList.remove('hidden');
                updateTotals(); return;
            }
            activeDiscount.type             = match.type;
            activeDiscount.value            = parseFloat(match.value);
            activeDiscount.scope            = match.scope || 'store';
            activeDiscount.target_product_id = parseInt(match.target_product_id) || 0;
            activeDiscount.target_category  = match.target_category || '';
            promoValid = true;
            // Sync to form so the server knows exactly which discount to apply.
            if (discIdField2)  discIdField2.value  = match.id;
            if (stackedField2) stackedField2.value = '';
            input.style.borderColor = '#10b981';
            statusIcon.classList.remove('hidden');
            const discLabel = match.type === DISC_PCT
                ? `${match.value}% off`
                : `₱${parseFloat(match.value).toFixed(2)} off`;
            const scopeLabel = activeDiscount.scope === 'category' ? ` on ${activeDiscount.target_category}`
                             : activeDiscount.scope === 'product'  ? ` on selected item` : '';
            feedback.textContent = `Code applied! ${discLabel}${scopeLabel}`;
            feedback.className = 'text-[11px] font-bold ml-2 text-emerald-600';
            feedback.classList.remove('hidden');
        }
    }
    updateTotals();
}

function updateTotals() {
    const totalDisplay = document.getElementById('display-total');
    const detailText   = document.getElementById('tax-detail-text');
    const hiddenInput  = document.getElementById('final-total-hidden');
    const cashInput    = document.getElementById('cash-input');
    let savings = [];

    // 1. F-06: Apply group discount first (on raw subtotal)
    let groupDiscountAmt = 0;
    if (activeGroup.id > 0 && activeGroup.value > 0) {
        groupDiscountAmt = (activeGroup.type === 'Percentage')
            ? rawSubtotal * (activeGroup.value / 100)
            : Math.min(activeGroup.value, rawSubtotal);
        savings.push(`<span class="text-blue-500">${activeGroup.label} −₱${groupDiscountAmt.toFixed(2)}</span>`);
    }
    let afterGroupSubtotal = Math.max(0, rawSubtotal - groupDiscountAmt);

    // 2. F-13: Apply bundle discount after group discount
    let afterBundleSubtotal = Math.max(0, afterGroupSubtotal - BUNDLE_DISCOUNT_AMT);
    if (BUNDLE_DISCOUNT_AMT > 0) savings.push(`<span class="text-orange-500">Bundle −₱${BUNDLE_DISCOUNT_AMT.toFixed(2)}</span>`);

    // 3. F-09 + Existing: Apply promo discount on post-bundle subtotal (scope-aware)
    const discountable = getDiscountableSubtotal(activeDiscount, afterBundleSubtotal);
    let discountAmt = (activeDiscount.type === DISC_PCT)
        ? (discountable * (activeDiscount.value / 100))
        : Math.min(activeDiscount.value, discountable);
    if (discountAmt > 0) savings.push(`<span class="text-amber-600">Promo −₱${discountAmt.toFixed(2)}</span>`);
    let runningTotal = Math.max(0, afterBundleSubtotal - discountAmt);

    // 4. Split into vatable / exempt portions using raw line-total ratios.
    // Discounts are proportionally distributed across both groups.
    let vatableRaw = 0, exemptRaw = 0;
    cartItems.forEach(function(item) {
        if (item.vat_exempt) exemptRaw  += item.line_total;
        else                 vatableRaw += item.line_total;
    });
    const totalRaw     = vatableRaw + exemptRaw;
    const vatableRatio = totalRaw > 0 ? vatableRaw / totalRaw : 1;
    const vatableNet   = runningTotal * vatableRatio;
    const exemptNet    = runningTotal - vatableNet;

    // 4b. F-14: Tax — exclusive (add on top) or inclusive (already embedded),
    //     applied only to the vatable portion. VAT is always on (12% fixed).
    let calculatedTax, preRoundTotal;
    if (TAX_DISPLAY_MODE === 'inclusive') {
        calculatedTax = vatableNet * (taxRate / (1 + taxRate));
        preRoundTotal = runningTotal; // inclusive: total unchanged
    } else {
        calculatedTax = vatableNet * taxRate;
        preRoundTotal = vatableNet + calculatedTax + exemptNet;
    }

    // 5. F-11: Price rounding
    let finalTotal = applyRounding(preRoundTotal, ROUNDING_RULE);
    const roundingNote = document.getElementById('rounding-note');
    if (roundingNote) {
        if (ROUNDING_RULE !== 'none' && Math.abs(finalTotal - preRoundTotal) > 0.001) {
            const diff = (finalTotal - preRoundTotal).toFixed(2);
            roundingNote.textContent = `Rounded ${diff >= 0 ? '+' : ''}${diff} (${ROUNDING_RULE.replace(/_/g,' ')})`;
            roundingNote.classList.remove('hidden');
        } else {
            roundingNote.classList.add('hidden');
        }
    }

    // 6. UI Update
    totalDisplay.innerText = '₱' + finalTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

    let taxInfo;
    if (exemptRaw > 0 && vatableRaw > 0) {
        taxInfo = `VAT (${(taxRate * 100).toFixed(0)}%) on ₱${vatableNet.toFixed(2)} = ₱${calculatedTax.toFixed(2)} · ₱${exemptNet.toFixed(2)} exempt`;
    } else if (exemptRaw > 0) {
        taxInfo = 'VAT Exempt';
    } else {
        taxInfo = `Includes ${(taxRate * 100).toFixed(0)}% Tax (₱${calculatedTax.toFixed(2)})`;
    }
    let info = savings.length ? savings.join(' • ') + ' • ' + taxInfo : taxInfo;
    detailText.innerHTML = info;

    hiddenInput.value = finalTotal.toFixed(2);

    // Sync amount if digital payment
    if (document.querySelector('input[name="payment_mode"]:checked').value !== PAY_CASH) {
        cashInput.value = finalTotal.toFixed(2);
    }
}

function toggleRefField(mode) {
    const refContainer = document.getElementById('ref_input_container');
    const cashInput = document.getElementById('cash-input');
    const isDigital = (mode === 'yes');

    if (isDigital) {
        refContainer.classList.remove('hidden');
        cashInput.readOnly = true;
        cashInput.value = document.getElementById('final-total-hidden').value;
    } else {
        refContainer.classList.add('hidden');
        cashInput.readOnly = false;
        cashInput.value = "";
        cashInput.focus();
    }
    updateTotals();
}

// Initial Run — F-09: auto-apply best promo before display
autoResolvePromos();
updateTotals();

document.getElementById('checkoutForm').addEventListener('submit', function(e) {
    // Block if promo code is typed but invalid (not found or limit exceeded)
    const promoField = document.getElementById('promo_input');
    if (promoField.value.trim() && !promoValid) {
        e.preventDefault();
        promoField.focus();
        return;
    }
    // Skip promo field if blank so server doesn't process an empty lookup
    if (!promoField.value.trim()) promoField.removeAttribute('name');

    // Require reference number for digital payment methods
    const method = document.querySelector('input[name="payment_mode"]:checked').value;
    if (method === PAY_GCASH || method === PAY_MAYA) {
        const refVal = document.getElementById('reference_no').value.trim();
        const refErr = document.getElementById('ref_error');
        const refInput = document.getElementById('reference_no');
        if (!refVal) {
            e.preventDefault();
            refErr.classList.remove('hidden');
            refInput.classList.add('border-rose-400');
            refInput.focus();
            return;
        } else {
            refErr.classList.add('hidden');
            refInput.classList.remove('border-rose-400');
        }
    }

    if (activeDiscount.type === DISC_PCT && activeDiscount.value > 100) {
        e.preventDefault();
        alert('Discount percentage cannot exceed 100%.');
        return;
    }
    const finalTotal = parseFloat(document.getElementById('final-total-hidden').value);
    if (finalTotal < 0) {
        e.preventDefault();
        showFlash('Total amount cannot be negative.', 'error');
        return;
    }

    // Reject insufficient cash tender (POS-5)
    const selectedMethod = document.querySelector('input[name="payment_mode"]:checked').value;
    if (selectedMethod === PAY_CASH) {
        const cashVal = parseFloat(document.getElementById('cash-input').value) || 0;
        if (cashVal < finalTotal) {
            e.preventDefault();
            const cashEl = document.getElementById('cash-input');
            cashEl.classList.add('!border-rose-400');
            cashEl.focus();
            setTimeout(() => cashEl.classList.remove('!border-rose-400'), 2500);
            showFlash('Insufficient payment. ₱' + cashVal.toFixed(2) + ' tendered for ₱' + finalTotal.toFixed(2) + ' total.', 'error');
            return;
        }
    }
});
</script>

<?php include '../layout_bottom.php'; ?>