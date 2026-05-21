<?php
include '../config/db.php';
include '../config/settings.php';
include 'layout_top.php';

if (empty($_SESSION['cart'])) {
    echo "<script>window.location.href='pos.php';</script>";
    exit();
}

$subtotal = 0;
foreach ($_SESSION['cart'] as $i) {
    // Math safety fallback: Sum of all items in cart
    $subtotal += $i['line_total'] ?? ($i['price'] * $i['qty']);
}
$tax_rate = TAX_RATE;

// Fetch all active promos/discounts (Plural table: discounts)
$all_discounts = [];
$disc_q = $conn->query("SELECT * FROM discounts WHERE is_active = 1");
if($disc_q) {
    while($d = $disc_q->fetch_assoc()) {
        $all_discounts[] = $d;
    }
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
            </div>

            <!-- ⚙️ TAX SWITCH (Feature 10) -->
            <div class="flex items-center justify-between bg-slate-50 p-6 rounded-[2rem] border border-slate-100 px-10">
                <div>
                    <span class="block text-slate-700 font-bold">Value Added Tax (12%)</span>
                    <span class="text-[10px] text-slate-400 font-black uppercase">Tax status affects the final total</span>
                </div>
                <label class="relative inline-flex items-center cursor-pointer group">
                    <input type="checkbox" id="tax-toggle" class="sr-only peer" checked onchange="updateTotals()">
                    <div class="w-14 h-7 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[4px] after:start-[4px] after:bg-white after:rounded-full after:h-5 after:w-6 after:transition-all peer-checked:bg-emerald-500 shadow-inner"></div>
                    <span id="tax-label" class="ml-3 text-[11px] font-black text-emerald-600 w-8">YES</span>
                </label>
            </div>

            <!-- 🏷️ PROMO & DISCOUNT SECTION -->
            <input type="hidden" name="discount_id" value="0">
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
const rawSubtotal = <?= (float)$subtotal ?>;
const taxRate = <?= (float)$tax_rate ?>;
const promoDatabase = <?= json_encode($all_discounts) ?>;
const PAY_CASH  = <?= json_encode(PAY_METHOD_CASH) ?>;
const PAY_GCASH = <?= json_encode(PAY_METHOD_GCASH) ?>;
const PAY_MAYA  = <?= json_encode(PAY_METHOD_MAYA) ?>;
const DISC_PCT  = <?= json_encode(DISCOUNT_PERCENTAGE) ?>;

let activeDiscount = { type: 'Fixed', value: 0 };
let promoValid = false;

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

    if (!code) {
        activeDiscount = { type: 'Fixed', value: 0 };
        promoValid = false;
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
            activeDiscount.type  = match.type;
            activeDiscount.value = parseFloat(match.value);
            promoValid = true;
            input.style.borderColor = '#10b981';
            statusIcon.classList.remove('hidden');
            const discLabel = match.type === DISC_PCT
                ? `${match.value}% off`
                : `₱${parseFloat(match.value).toFixed(2)} off`;
            feedback.textContent = `Code applied! ${discLabel}`;
            feedback.className = 'text-[11px] font-bold ml-2 text-emerald-600';
            feedback.classList.remove('hidden');
        }
    }
    updateTotals();
}

function updateTotals() {
    const taxToggle = document.getElementById('tax-toggle');
    const isTaxOn = taxToggle.checked;
    const totalDisplay = document.getElementById('display-total');
    const detailText = document.getElementById('tax-detail-text');
    const hiddenInput = document.getElementById('final-total-hidden');
    const cashInput = document.getElementById('cash-input');

    // 1. Calc Discount
    let discountAmt = (activeDiscount.type === DISC_PCT) ? (rawSubtotal * (activeDiscount.value / 100)) : activeDiscount.value;
    let runningTotal = Math.max(0, rawSubtotal - discountAmt);
    
    // 2. Calc Tax
    let calculatedTax = isTaxOn ? (runningTotal * taxRate) : 0;
    let finalTotal = runningTotal + calculatedTax;

    // 3. UI Update
    totalDisplay.innerText = "₱" + finalTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('tax-label').innerText = isTaxOn ? "YES" : "NO";
    
    let info = isTaxOn ? `Includes 12% Tax (₱${calculatedTax.toFixed(2)})` : "Non-VAT / Tax Exempt";
    if(discountAmt > 0) info = `<span class="text-amber-600">Saved ₱${discountAmt.toFixed(2)}</span> • ` + info;
    detailText.innerHTML = info;

    hiddenInput.value = finalTotal.toFixed(2);

    // Sync amount if digital
    if(document.querySelector('input[name="payment_mode"]:checked').value !== PAY_CASH) {
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

// Initial Run
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
        alert('Total amount cannot be negative.');
    }
});
</script>

<?php include 'layout_bottom.php'; ?>