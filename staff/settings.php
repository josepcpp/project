<?php
include '../config/db.php';
include '../includes/superadmin_only.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$success = '';
$error   = '';

// ── SAVE ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowed = [
        'low_stock_threshold'       => 'int',
        'tax_display_mode'          => 'string',
        'price_rounding_rule'       => 'string',
        'damage_ticket_expiry_days' => 'int',
    ];

    try {
        $upsert = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );

        foreach ($allowed as $key => $type) {
            if (!isset($_POST[$key])) continue;
            $val = $type === 'int'
                ? strval(max(0, intval($_POST[$key])))
                : trim($_POST[$key]);
            $upsert->bind_param("ss", $key, $val);
            $upsert->execute();
        }

        $success = "Settings saved successfully.";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── LOAD CURRENT VALUES ───────────────────────────────────────────────────────
$sq = $conn->query("SELECT setting_key, setting_value FROM system_settings");
$settings = [];
if ($sq) while ($row = $sq->fetch_assoc()) $settings[$row['setting_key']] = $row['setting_value'];

$low_stock_threshold       = intval($settings['low_stock_threshold']       ?? 10);
$tax_display_mode          = $settings['tax_display_mode']                 ?? 'exclusive';
$price_rounding_rule       = $settings['price_rounding_rule']              ?? 'none';
$damage_ticket_expiry_days = intval($settings['damage_ticket_expiry_days'] ?? 3);

include 'layout_top.php';
?>

<div class="max-w-3xl mx-auto space-y-8 pb-20 animate-in">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">

        <!-- ── Inventory ─────────────────────────────────────────────────── -->
        <div class="card-modern p-8 space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h3 class="serif-title text-lg font-black text-slate-800">Inventory</h3>
                <p class="text-slate-400 text-xs font-bold mt-0.5">Controls how stock levels are evaluated and displayed.</p>
            </div>

            <div class="max-w-xs">
                <label class="label-modern">Low Stock Threshold <span class="text-slate-400 font-normal normal-case">(units)</span></label>
                <input type="number" name="low_stock_threshold" min="1" max="9999"
                       value="<?= $low_stock_threshold ?>"
                       class="input-modern text-sm">
                <p class="text-[10px] text-slate-400 font-bold mt-1">
                    Products with no max_quantity set will be flagged Low when stock falls at or below this number.
                    Products received through the pipeline use 10% of their received qty automatically.
                </p>
            </div>
        </div>

        <!-- ── POS / Checkout ────────────────────────────────────────────── -->
        <div class="card-modern p-8 space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h3 class="serif-title text-lg font-black text-slate-800">POS &amp; Checkout</h3>
                <p class="text-slate-400 text-xs font-bold mt-0.5">Controls how totals are calculated at the counter.</p>
            </div>

            <!-- Tax display mode -->
            <div>
                <label class="label-modern">Tax Display Mode</label>
                <div class="grid grid-cols-2 gap-3 mt-2">
                    <label class="cursor-pointer">
                        <input type="radio" name="tax_display_mode" value="exclusive"
                               <?= $tax_display_mode === 'exclusive' ? 'checked' : '' ?> class="hidden peer">
                        <div class="p-4 border-2 border-slate-100 rounded-2xl peer-checked:border-emerald-500 peer-checked:bg-emerald-50 transition-all">
                            <p class="font-black text-slate-700 text-sm">Exclusive (default)</p>
                            <p class="text-[10px] text-slate-400 font-bold mt-0.5">VAT is added on top of the listed price at checkout.</p>
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="tax_display_mode" value="inclusive"
                               <?= $tax_display_mode === 'inclusive' ? 'checked' : '' ?> class="hidden peer">
                        <div class="p-4 border-2 border-slate-100 rounded-2xl peer-checked:border-emerald-500 peer-checked:bg-emerald-50 transition-all">
                            <p class="font-black text-slate-700 text-sm">Inclusive</p>
                            <p class="text-[10px] text-slate-400 font-bold mt-0.5">VAT is already embedded in the listed price.</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Price rounding -->
            <div>
                <label class="label-modern">Price Rounding Rule</label>
                <select name="price_rounding_rule" class="input-modern text-sm mt-2">
                    <?php foreach ([
                        'none'           => 'No rounding (exact total)',
                        'nearest_25c'    => 'Round to nearest ₱0.25',
                        'nearest_50c'    => 'Round to nearest ₱0.50',
                        'nearest_peso'   => 'Round to nearest ₱1.00',
                        'nearest_5peso'  => 'Round to nearest ₱5.00',
                    ] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $price_rounding_rule === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[10px] text-slate-400 font-bold mt-1">Applied to the final grand total at checkout. Rounding is consistent between the POS screen and the server.</p>
            </div>
        </div>

        <!-- ── Procurement ───────────────────────────────────────────────── -->
        <div class="card-modern p-8 space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h3 class="serif-title text-lg font-black text-slate-800">Procurement Pipeline</h3>
                <p class="text-slate-400 text-xs font-bold mt-0.5">Controls behaviour of the receiving and validation workflow.</p>
            </div>

            <div class="max-w-xs">
                <label class="label-modern">Damage Ticket Expiry <span class="text-slate-400 font-normal normal-case">(days)</span></label>
                <input type="number" name="damage_ticket_expiry_days" min="1" max="30"
                       value="<?= $damage_ticket_expiry_days ?>"
                       class="input-modern text-sm">
                <p class="text-[10px] text-slate-400 font-bold mt-1">
                    If a Damage Return Ticket is not reviewed within this many days, it automatically expires
                    and the batch is re-flagged as a counting discrepancy for manual resolution.
                    Set to <strong>0</strong> to disable auto-expiry.
                </p>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="btn-pos-primary px-12 py-3 text-sm font-black uppercase tracking-widest">
                Save Settings
            </button>
        </div>

    </form>
</div>

<?php include 'layout_bottom.php'; ?>
