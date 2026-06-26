<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_RECEIVER, ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$batch_id = intval($_GET['batch_id'] ?? 0);
$readonly = !empty($_GET['readonly']);

if (!$batch_id) {
    header("Location: receive_batch.php?error=" . urlencode("No batch selected."));
    exit();
}

// Load batch — receiver can see their own batches OR unclaimed admin-created vouchers
if ($role === ROLE_RECEIVER) {
    $bq = $conn->prepare("SELECT * FROM receiving_batches WHERE id = ? AND (receiver_id = ? OR receiver_id IS NULL) LIMIT 1");
    $bq->bind_param("ii", $batch_id, $user_id);
} else {
    $bq = $conn->prepare("SELECT * FROM receiving_batches WHERE id = ? LIMIT 1");
    $bq->bind_param("i", $batch_id);
}
$bq->execute();
$batch = $bq->get_result()->fetch_assoc();

if (!$batch) {
    header("Location: receive_batch.php?error=" . urlencode("Batch not found."));
    exit();
}

if (!$readonly && $batch['status'] !== 'pending_request') {
    $readonly = true;
}

// Reopened-to-receiver batches start their quantities at 0 so the receiver
// must re-count from scratch (the discrepancy was a counting issue).
$is_reopen = ($batch['resolution_action'] ?? '') === 'reopen_receiver';

// Load existing items
$iq = $conn->prepare("SELECT * FROM receiving_items WHERE batch_id = ? ORDER BY id ASC");
$iq->bind_param("i", $batch_id);
$iq->execute();
$items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

$error   = trim($_GET['error']   ?? '');
$success = trim($_GET['success'] ?? '');

// ── Soft lock: claim this batch while encoding; show "on-going" if someone else holds it ──
require_once '../../includes/batch_lock.php';
$is_admin_role = in_array($role, [ROLE_ADMIN, ROLE_SUPERADMIN]);
$lock_holder   = null;
if (!$readonly) {
    if ($is_admin_role && ($_GET['takeover'] ?? '') === '1') {
        batch_lock_force($conn, $batch_id, $user_id, $username, $role);
    }
    if (!batch_lock_acquire($conn, $batch_id, $user_id, $username, $role)) {
        $lock_holder = batch_lock_holder($conn, $batch_id);   // someone else is processing it
    }
}

include '../layout_top.php';
?>

<div class="max-w-6xl mx-auto space-y-6">

    <!-- Batch Info Header -->
    <div class="card-modern p-6 flex flex-wrap gap-6 items-start">
        <div class="flex-1 min-w-0">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Batch #<?= $batch['id'] ?></p>
            <h3 class="serif-title text-xl font-black text-slate-800"><?= htmlspecialchars($batch['supplier_name'] ?? 'Unknown Supplier') ?></h3>
            <?php if ($batch['supplier_contact']): ?>
            <p class="text-sm text-slate-400 font-bold mt-1"><?= htmlspecialchars($batch['supplier_contact']) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <a href="receive_batch.php" class="text-sm text-slate-500 font-bold hover:underline">&larr; Back to Batches</a>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($readonly): ?>
    <div class="bg-sky-50 border border-sky-200 text-sky-700 rounded-2xl px-5 py-4 text-sm font-bold">
        This batch is in <strong><?= htmlspecialchars($batch['status']) ?></strong> status — items are read-only.
    </div>
    <?php endif; ?>

    <!-- ON-GOING PROCESS — another user is encoding this batch -->
    <?php if ($lock_holder): ?>
    <div class="card-modern p-8 text-center" data-batch="<?= $batch_id ?>">
        <div class="w-16 h-16 bg-amber-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-amber-500 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <h3 class="serif-title text-xl font-black text-slate-800">On-going process</h3>
        <p class="text-slate-500 font-bold mt-1">
            Being worked on by <span class="text-slate-800">@<?= htmlspecialchars($lock_holder['working_username']) ?></span>
            <span class="text-slate-400">(<?= htmlspecialchars(ucfirst($lock_holder['working_role'])) ?>)</span>
        </p>
        <p class="text-xs text-slate-400 font-bold mt-1">
            Started <?= date('g:i A', strtotime($lock_holder['working_at'])) ?> ·
            <span id="ongoing-idle">last active <?= intval($lock_holder['idle_secs']) ?>s ago</span>
        </p>
        <div class="flex gap-3 justify-center mt-6">
            <a href="receive_batch.php" class="btn-secondary px-6 py-3 rounded-2xl text-xs font-black uppercase tracking-widest">&larr; Back</a>
            <?php if ($is_admin_role): ?>
            <a href="receive_items.php?batch_id=<?= $batch_id ?>&takeover=1"
               onclick="return confirm('Take over this batch from @<?= htmlspecialchars(addslashes($lock_holder['working_username'])) ?>? Their in-progress lock will be released.');"
               class="bg-amber-500 hover:bg-amber-600 text-white px-6 py-3 rounded-2xl text-xs font-black uppercase tracking-widest transition-all shadow-md">Take over</a>
            <?php endif; ?>
        </div>
        <p class="text-[10px] text-slate-300 font-bold mt-3">This view updates automatically when the batch becomes free.</p>
    </div>
    <script>
    (function () {
        var bid = <?= (int)$batch_id ?>, idleEl = document.getElementById('ongoing-idle');
        var t = setInterval(function () {
            fetch('/project/staff/api/batch_lock_status.php?ids=' + bid)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var s = d && d[bid];
                    if (!s || !s.locked) { clearInterval(t); window.location.href = 'receive_items.php?batch_id=' + bid; return; }
                    if (idleEl) idleEl.textContent = 'last active ' + s.idle_secs + 's ago';
                }).catch(function () {});
        }, 10000);
    })();
    </script>

    <!-- Item Encoding Form -->
    <?php elseif (!$readonly): ?>
    <style>
        @keyframes rowFlash {
            0%, 100% { background-color: transparent; }
            20%      { background-color: #fde68a; }
            60%      { background-color: #fef3c7; }
        }
        .row-flash {
            animation: rowFlash 0.9s ease-in-out 3;
            outline: 3px solid #f59e0b;
            outline-offset: -2px;
            border-radius: 6px;
        }
        @keyframes scanPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } 50% { box-shadow: 0 0 0 4px rgba(16,185,129,.25); } }
        #scan-box { outline: 2px solid transparent; transition: outline-color .2s ease, opacity .2s ease; opacity: .65; }
        #scan-box.scan-active { animation: scanPulse 1.4s ease-in-out infinite; outline-color: #10b981; opacity: 1; }

        /* "Synced from previous data" pop-up */
        #sync-pop { position: fixed; top: 6rem; right: 2.5rem; z-index: 400; transform: translateX(130%); transition: transform .45s cubic-bezier(.2,.8,.2,1); }
        #sync-pop.show { transform: translateX(0); }
        @keyframes fieldSync { 0% { background-color: #a7f3d0; } 100% { background-color: transparent; } }
        .field-synced { animation: fieldSync 1.5s ease-out; }
    </style>
    <?php
    // Shared renderer: one item row in the list (hidden submit inputs + read-only summary).
    // Mirrors the JS buildItemRow() exactly so server- and client-added items are identical.
    function render_item_row(int $i, array $item, bool $is_reopen): void {
        $total_raw = intval($item['quantity']) + intval($item['damaged_qty'] ?? 0);
        $bc      = $is_reopen ? '' : '';
        $barcode = $item['barcode']      ?? '';
        $boxbc   = $item['box_barcode']  ?? '';
        $desc    = $item['description']  ?? '';
        $cat     = $item['category']     ?? '';
        $qpb     = $is_reopen ? 0 : 1;
        $box     = $is_reopen ? 0 : $total_raw;
        $dmg     = $is_reopen ? 0 : intval($item['damaged_qty'] ?? 0);
        $good    = $is_reopen ? 0 : intval($item['quantity']);
        $total   = $is_reopen ? 0 : $total_raw;
        $expd    = $item['expiry_date']  ?? '';
        $hasexp  = !empty($expd) ? 1 : 0;   // reopen zeros quantities only — expiry is preserved
        $notes   = $item['damage_notes'] ?? '';
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="item-row bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3.5 flex items-center gap-4"
             data-barcode="<?= $h($barcode) ?>" data-boxbc="<?= $h($boxbc) ?>" data-desc="<?= $h($desc) ?>"
             data-cat="<?= $h($cat) ?>" data-qpb="<?= $qpb ?>" data-box="<?= $box ?>" data-dmg="<?= $dmg ?>"
             data-good="<?= $good ?>" data-total="<?= $total ?>" data-hasexp="<?= $hasexp ?>"
             data-expdate="<?= $h($expd) ?>" data-notes="<?= $h($notes) ?>">
            <input type="hidden" name="items[<?= $i ?>][barcode]"      class="f-barcode"  value="<?= $h($barcode) ?>">
            <input type="hidden" name="items[<?= $i ?>][box_barcode]"  class="f-boxbc"    value="<?= $h($boxbc) ?>">
            <input type="hidden" name="items[<?= $i ?>][description]"  class="f-desc"     value="<?= $h($desc) ?>">
            <input type="hidden" name="items[<?= $i ?>][category]"     class="f-cat"      value="<?= $h($cat) ?>">
            <input type="hidden" name="items[<?= $i ?>][qty_per_box]"  class="f-qpb qty-per-box" value="<?= $qpb ?>">
            <input type="hidden" name="items[<?= $i ?>][box_qty]"      class="f-box box-qty"     value="<?= $box ?>">
            <input type="hidden" name="items[<?= $i ?>][damaged_qty]"  class="f-dmg damaged-qty" value="<?= $dmg ?>">
            <input type="hidden" name="items[<?= $i ?>][qty]"          class="f-good qty-hidden" value="<?= $good ?>">
            <input type="hidden" name="items[<?= $i ?>][expiry_date]"  class="f-expdate"  value="<?= $h($expd) ?>">
            <input type="hidden" name="items[<?= $i ?>][damage_notes]" class="f-notes"    value="<?= $h($notes) ?>">
            <?php if ($hasexp): ?><input type="hidden" name="items[<?= $i ?>][has_expiry]" class="f-hasexp" value="1"><?php endif; ?>

            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <p class="font-bold text-slate-800 leading-tight row-name"><?= $h($desc) ?></p>
                    <span class="row-cat text-[9px] font-black text-blue-500 uppercase bg-blue-50/70 px-2 py-0.5 rounded"><?= $h($cat) ?></span>
                </div>
                <div class="flex items-center gap-x-3 gap-y-1 flex-wrap mt-1 text-[10px] font-black text-slate-400 uppercase tracking-wide">
                    <code class="text-slate-400 bg-slate-100 px-2 py-0.5 rounded border row-bc"><?= $h($barcode ?: $boxbc ?: '—') ?></code>
                    <span class="text-slate-600 row-good">Good: <?= $good ?></span>
                    <span class="row-total">Total: <?= $total ?></span>
                    <?php if ($dmg > 0): ?><span class="text-rose-500 row-dmg">Damaged: <?= $dmg ?></span><?php endif; ?>
                    <?php if ($expd): ?><span class="row-exp">Exp: <?= date('M j, Y', strtotime($expd)) ?></span><?php endif; ?>
                    <?php if ($notes): ?><span class="text-slate-400 italic normal-case row-notes">“<?= $h($notes) ?>”</span><?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <button type="button" onclick="editItem(this)" class="text-[10px] font-black text-slate-500 hover:text-white border border-slate-200 hover:bg-slate-700 px-3 py-1.5 rounded-lg uppercase tracking-widest transition-all">Edit</button>
                <button type="button" onclick="removeItem(this)" class="text-rose-400 hover:text-rose-600 font-black text-xl leading-none px-1">&times;</button>
            </div>
        </div>
        <?php
    }
    ?>

    <!-- ── ENTRY PANEL — fill this and "Add Item" ──────────────────────────── -->
    <div class="bg-white rounded-[2.5rem] border border-slate-100 shadow-xl shadow-slate-200/50 overflow-hidden">

        <!-- ── Header strip ─────────────────────────────────────────────── -->
        <div class="flex items-center justify-between gap-3 flex-wrap px-8 py-5 bg-gradient-to-r from-slate-900 via-slate-900 to-slate-800">
            <div class="flex items-center gap-3.5 min-w-0">
                <div class="w-11 h-11 rounded-2xl bg-emerald-500/15 text-emerald-400 flex items-center justify-center flex-shrink-0 ring-1 ring-emerald-400/30">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <div class="min-w-0">
                    <h3 id="panel-title" class="serif-title text-lg font-black text-white leading-tight">Add Received Item</h3>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mt-0.5">Fill the form, then add to the batch</p>
                </div>
            </div>
            <button type="button" id="nb-toggle" onclick="toggleNonBarcode()"
                    class="flex items-center gap-1.5 text-amber-300 hover:text-slate-900 text-[10px] font-black px-4 py-2.5 rounded-xl uppercase tracking-widest transition-all hover:bg-amber-400 border border-amber-400/40 hover:border-amber-400">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                Non-barcode item
            </button>
        </div>

        <div class="p-8 space-y-7">

            <!-- ── Scan Station — pre-fills the form below ──────────────── -->
            <div id="scan-box" class="bg-slate-900 rounded-2xl px-5 py-3 flex items-center gap-3 cursor-pointer ring-1 ring-white/5"
                 onclick="document.getElementById('scan-input').focus()">
                <span class="flex items-center justify-center w-8 h-8 rounded-xl bg-emerald-500/15 text-emerald-400 flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5v14M7 5v14M11 5v14M15 5v14M19 5v14"/>
                    </svg>
                </span>
                <input type="text" id="scan-input" autocomplete="off" inputmode="numeric"
                       placeholder="Scan a barcode then Enter — it fills the form below…"
                       class="flex-1 min-w-0 bg-transparent text-white text-sm font-bold placeholder-slate-500 focus:outline-none"
                       onfocus="document.getElementById('scan-box').classList.add('scan-active'); setHint('Active Barcode Entry','ok');"
                       onblur="document.getElementById('scan-box').classList.remove('scan-active'); setHint('Click and Hover to scan','idle');"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();handleScan();}">
                <span id="scan-hint" class="text-[11px] font-black text-slate-400 whitespace-nowrap">Click to scan</span>
            </div>

            <!-- "Synced from previous data" pop-up -->
            <div id="sync-pop">
                <div class="bg-slate-900 text-white px-5 py-3.5 rounded-2xl shadow-2xl flex items-center gap-3 border border-emerald-500 max-w-xs">
                    <div class="bg-emerald-500 p-1.5 rounded-lg text-white flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-widest text-emerald-400">Synced from previous data</p>
                        <p id="sync-pop-name" class="font-bold text-sm truncate">Description filled — no need to type it.</p>
                    </div>
                </div>
            </div>

            <!-- ── SECTION 1 · Product ───────────────────────────────────── -->
            <div class="rounded-2xl border border-slate-100 bg-slate-50/40 p-5">
                <p class="flex items-center gap-2 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Product Details
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Barcode</label>
                        <input type="text" id="p-bc" class="input-modern text-sm w-full bg-white" placeholder="Per-item barcode" onblur="panelLookup()" onkeydown="panelEnter(event)">
                        <input type="text" id="p-boxbc" class="input-modern text-xs w-full mt-1 bg-white hidden" placeholder="📦 Box barcode" onkeydown="panelEnter(event)">
                        <span id="p-nb-badge" class="hidden inline-block mt-1 text-[8px] font-black text-amber-700 bg-amber-100 border border-amber-200 px-2 py-0.5 rounded-full uppercase tracking-widest">Non-barcode Item</span>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Description <span class="text-rose-500">*</span></label>
                        <input type="text" id="p-desc" class="input-modern text-sm w-full bg-white uppercase font-bold" placeholder="Product name"
                               oninput="this.value = this.value.toUpperCase(); panelPreviewName();" onkeydown="panelEnter(event)">
                        <p id="p-name-preview" class="hidden text-[10px] font-black text-emerald-600 mt-1 truncate"></p>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Size / Unit <span class="text-slate-300 normal-case font-normal">(optional)</span></label>
                        <div class="flex gap-2">
                            <input type="number" id="p-size" min="0" step="any" class="input-modern text-sm w-1/2 bg-white text-center" placeholder="e.g. 500" oninput="panelPreviewName()" onkeydown="panelEnter(event)">
                            <select id="p-unit" class="input-modern text-sm w-1/2 bg-white" onchange="panelPreviewName()">
                                <option value="">— unit —</option>
                                <option value="G">g</option>
                                <option value="KG">kg</option>
                                <option value="MG">mg</option>
                                <option value="ML">ml</option>
                                <option value="L">L (liters)</option>
                            </select>
                        </div>
                    </div>
                    <div class="lg:col-span-1">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Category <span class="text-rose-500">*</span></label>
                        <select id="p-cat" class="input-modern text-sm w-full bg-white">
                            <option value="">— select —</option>
                            <?php foreach (PRODUCT_CATEGORIES as $val => $label): ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ── SECTION 2 · Quantity ──────────────────────────────────── -->
            <div class="rounded-2xl border border-slate-100 bg-slate-50/40 p-5">
                <p class="flex items-center gap-2 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Quantity
                </p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Qty / Box</label>
                        <input type="number" id="p-qpb" min="0" value="0" class="input-modern text-base font-black w-full bg-white text-center" oninput="panelRecalc()" onkeydown="panelEnter(event)">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Boxes</label>
                        <input type="number" id="p-box" min="0" value="0" class="input-modern text-base font-black w-full bg-white text-center" oninput="panelRecalc()" onkeydown="panelEnter(event)">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-rose-400 uppercase tracking-widest block mb-1">Damaged</label>
                        <input type="number" id="p-dmg" min="0" value="0" class="input-modern text-base font-black w-full bg-white text-center text-rose-600" oninput="panelRecalc()" onkeydown="panelEnter(event)">
                    </div>
                    <div class="bg-white border border-emerald-100 rounded-2xl px-4 py-2.5 flex items-center justify-around shadow-sm">
                        <div class="text-center"><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Total</p><p id="p-total" class="text-2xl font-black text-slate-800 leading-none">0</p></div>
                        <div class="w-px h-8 bg-slate-100"></div>
                        <div class="text-center"><p class="text-[8px] font-black text-emerald-500 uppercase tracking-widest mb-0.5">Good</p><p id="p-good" class="text-2xl font-black text-emerald-600 leading-none">0</p></div>
                    </div>
                </div>
            </div>

            <!-- ── SECTION 3 · Other Details ─────────────────────────────── -->
            <div class="rounded-2xl border border-slate-100 bg-slate-50/40 p-5">
                <p class="flex items-center gap-2 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-4">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Other Details
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                    <div>
                        <label class="flex items-center gap-1.5 text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1 cursor-pointer select-none">
                            <input type="checkbox" id="p-hasexp" class="accent-emerald-500 w-3.5 h-3.5" onchange="panelToggleExpiry()"> With expiry
                        </label>
                        <input type="date" id="p-expdate" class="input-modern text-sm w-full bg-white hidden" onkeydown="panelEnter(event)">
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Damage Notes</label>
                        <input type="text" id="p-notes" class="input-modern text-sm w-full bg-white" placeholder="e.g. crushed packaging" onkeydown="panelEnter(event)">
                    </div>
                </div>
            </div>

            <!-- ── Actions ───────────────────────────────────────────────── -->
            <div class="flex gap-3 pt-1">
                <button type="button" id="p-add" onclick="addOrUpdateItem()"
                        class="flex items-center gap-2 bg-gradient-to-r from-emerald-600 to-emerald-500 hover:from-emerald-500 hover:to-emerald-400 text-white px-8 py-3.5 rounded-2xl text-sm font-black uppercase tracking-widest transition-all shadow-lg shadow-emerald-200 active:scale-[0.98]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                    Add Item
                </button>
                <button type="button" id="p-cancel" onclick="cancelEdit()"
                        class="hidden border border-slate-200 text-slate-500 px-6 py-3.5 rounded-2xl text-sm font-black uppercase tracking-widest hover:bg-slate-50 transition-all">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- ── ITEMS LIST — the form that submits ──────────────────────────────── -->
    <form method="POST" action="receive_process.php" id="itemsForm" class="card-modern p-8 mt-6">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_items">
        <input type="hidden" name="batch_id" value="<?= $batch_id ?>">

        <h3 class="serif-title text-lg font-black text-slate-800 mb-4">Items in this Batch (<span id="item-count"><?= count($items) ?></span>)</h3>

        <div id="items-body" class="space-y-2.5">
            <?php foreach ($items as $i => $item) render_item_row($i, $item, $is_reopen); ?>
        </div>
        <div id="empty-list" class="<?= empty($items) ? '' : 'hidden' ?> text-center text-slate-300 font-black italic text-sm py-12">
            No items added yet — fill the form above and click “Add Item”.
        </div>

        <div class="flex gap-3 mt-6">
            <button type="button" id="saveBtn"
                    class="btn-pos-primary px-8 py-3 text-sm font-black uppercase tracking-widest">
                Save Items
            </button>
            <button type="button" id="submitBtn" onclick="openSubmitConfirm()"
                    class="bg-emerald-600 hover:bg-emerald-700 text-white px-8 py-3 rounded-2xl text-sm font-black uppercase tracking-widest transition-all shadow-lg shadow-emerald-100">
                Submit Batch
            </button>
        </div>
    </form>

    <!-- ── Submit Batch Confirm Modal ──────────────────────────────────────── -->
    <div id="submit-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm hidden">
        <div class="bg-white rounded-[2rem] shadow-2xl p-8 w-full max-w-md mx-4">
            <h4 class="serif-title text-2xl font-black text-slate-800 mb-1">Submit this batch?</h4>
            <p class="text-slate-400 text-sm font-bold mb-6">Once submitted, the items are locked and sent for validation — you won't be able to edit them.</p>

            <div class="bg-slate-50 rounded-2xl p-5 mb-6 space-y-2">
                <div class="flex justify-between items-center">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Supplier</span>
                    <span class="font-black text-slate-800 text-right"><?= htmlspecialchars($batch['supplier_name'] ?? '—') ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Items to submit</span>
                    <span id="sm-item-count" class="font-black text-emerald-600 text-lg text-right">0</span>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeSubmitConfirm()"
                    class="flex-1 border border-slate-200 text-slate-500 font-black text-[10px] uppercase tracking-widest py-3.5 rounded-2xl hover:bg-slate-50 transition-all">
                    Cancel
                </button>
                <button type="button" onclick="confirmSubmitBatch()"
                    class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white font-black text-[10px] uppercase tracking-widest py-3.5 rounded-2xl transition-all shadow-lg active:scale-95">
                    Yes, Submit Batch
                </button>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Read-only item list -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-lg font-black text-slate-800 mb-6">Encoded Items (<?= count($items) ?>)</h3>
        <?php if (empty($items)): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-6">No items encoded.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Barcode</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Total Qty</th>
                        <th>Expiry</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $i => $item): ?>
                    <tr>
                        <td class="text-slate-400"><?= $i + 1 ?></td>
                        <td class="font-mono text-xs"><?= htmlspecialchars($item['barcode'] ?? '—') ?></td>
                        <td class="font-bold"><?= htmlspecialchars($item['description']) ?></td>
                        <td class="text-slate-500"><?= htmlspecialchars($item['category'] ?? '—') ?></td>
                        <td class="text-center font-black"><?= intval($item['quantity']) ?></td>
                        <td class="text-slate-400"><?= $item['expiry_date'] ? date('M j, Y', strtotime($item['expiry_date'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
let _rowIdx = <?= count($items) ?>;
const _catSelectOptions = <?= json_encode(
    '<option value="">— select —</option>' .
    implode('', array_map(fn($v) => "<option value=\"{$v}\">{$v}</option>", array_keys(PRODUCT_CATEGORIES)))
) ?>;

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Entry panel: live Total / Good preview ───────────────────────────────────
function panelRecalc() {
    const perBox   = parseInt(document.getElementById('p-qpb').value) || 0;
    const boxesRaw = parseInt(document.getElementById('p-box').value);
    const hasBox   = !isNaN(boxesRaw) && boxesRaw >= 1;     // a real box count was entered
    const effBoxes = hasBox ? boxesRaw : 1;                  // no box → Qty/Box is the plain quantity
    const damaged  = parseInt(document.getElementById('p-dmg').value) || 0;
    const total    = perBox * effBoxes;
    const good     = Math.max(0, total - damaged);
    document.getElementById('p-total').textContent = total;
    document.getElementById('p-good').textContent  = good;
    const boxBc = document.getElementById('p-boxbc');
    boxBc.classList.toggle('hidden', !(hasBox || boxBc.value.trim() !== ''));
    return { perBox: perBox, effBoxes: effBoxes, boxes: hasBox ? boxesRaw : 0, total: total, good: good, damaged: damaged };
}
function panelToggleExpiry() {
    const cb = document.getElementById('p-hasexp');
    const dt = document.getElementById('p-expdate');
    if (cb.checked) { dt.classList.remove('hidden'); setTimeout(function(){ dt.focus(); }, 0); }
    else { dt.classList.add('hidden'); dt.value = ''; }
}
function panelEnter(e) {
    if (e.key === 'Enter') { e.preventDefault(); addOrUpdateItem(); }
}

// ── Compose the final product name: BASE + SIZE + UNIT, always UPPERCASE ───────
// e.g. "Pocari Sweat" + 500 + ML → "POCARI SWEAT 500 ML". Folds into description
// only — no separate field is stored.
function buildFinalName() {
    const base = document.getElementById('p-desc').value.trim().toUpperCase();
    const size = document.getElementById('p-size').value.trim();
    const unit = document.getElementById('p-unit').value;   // already uppercase
    let suffix = '';
    if (size) suffix = ' ' + size + (unit ? ' ' + unit : '');
    return (base + suffix).trim();
}
function panelPreviewName() {
    const el = document.getElementById('p-name-preview');
    if (!el) return;
    const base = document.getElementById('p-desc').value.trim();
    const size = document.getElementById('p-size').value.trim();
    if (base && size) { el.textContent = '→ ' + buildFinalName(); el.classList.remove('hidden'); }
    else el.classList.add('hidden');
}

// ── Validate the panel; returns the item object or null ───────────────────────
function panelValidate() {
    const bc      = document.getElementById('p-bc').value.trim();
    const boxbc   = document.getElementById('p-boxbc').value.trim();
    const base    = document.getElementById('p-desc').value.trim();
    const cat     = document.getElementById('p-cat').value;
    const hasexp  = document.getElementById('p-hasexp').checked;
    const expdate = document.getElementById('p-expdate').value;
    const r       = panelRecalc();
    if (!bc && !boxbc) { showFlash('Enter a barcode (per-item or box), or use “Non-barcode item”.', 'error'); document.getElementById('p-bc').focus(); return null; }
    if (!base)         { showFlash('Enter a product description.', 'error'); document.getElementById('p-desc').focus(); return null; }
    if (!cat)          { showFlash('Select a category.', 'error'); document.getElementById('p-cat').focus(); return null; }
    if (r.total < 1)   { showFlash('Enter a quantity of at least 1 in Qty/Box.', 'error'); document.getElementById('p-qpb').focus(); return null; }
    if (hasexp && !expdate) { showFlash('Marked “With expiry” — pick a date or untick it.', 'error'); document.getElementById('p-expdate').focus(); return null; }
    return {
        barcode: bc, boxbc: boxbc, desc: buildFinalName(), cat: cat,
        qpb: r.perBox, box: r.boxes, dmg: r.damaged, good: r.good, total: r.total,
        hasexp: hasexp ? 1 : 0, expdate: hasexp ? expdate : '',
        notes: document.getElementById('p-notes').value.trim()
    };
}

// ── Submit guards — items are already validated when added to the list ────────
function syncQtys() {
    document.querySelectorAll('#items-body .item-row').forEach(function (row) {
        const perBox   = parseInt(row.querySelector('.qty-per-box').value) || 0;
        const boxesRaw = parseInt(row.querySelector('.box-qty').value);
        const hasBox   = !isNaN(boxesRaw) && boxesRaw >= 1;
        const effBoxes = hasBox ? boxesRaw : 1;
        const damaged  = parseInt(row.querySelector('.damaged-qty').value) || 0;
        row.querySelector('.qty-hidden').value = Math.max(0, perBox * effBoxes - damaged);
    });
}
function beforeSubmit() {
    if (document.querySelectorAll('#items-body .item-row').length === 0) {
        showFlash('Add at least one item before saving.', 'error');
        document.getElementById('p-bc').focus();
        return false;
    }
    syncQtys();
    return true;
}

async function lookupBarcodeData(barcode) {
    try {
        const res = await fetch(`../api/product_lookup.php?barcode=${encodeURIComponent(barcode)}`);
        return await res.json();
    } catch (_) { return null; }
}

async function panelLookup() {
    const bcEl    = document.getElementById('p-bc');
    const barcode = bcEl.value.trim();
    if (!barcode || barcode.startsWith('NB-')) return;   // NB- codes are internal, no lookup
    const data = await lookupBarcodeData(barcode);
    if (!data || !data.found) return;
    const desc = document.getElementById('p-desc');
    if (!desc.value.trim()) { desc.value = (data.name || '').toUpperCase(); flashSynced(desc); showSyncPop(data.name); panelPreviewName(); }
    // A BOX code typed into the per-item field → move it to the box field.
    if (data.match === 'box') {
        const boxBc = document.getElementById('p-boxbc');
        bcEl.value  = data.barcode || '';
        boxBc.value = barcode; boxBc.classList.remove('hidden'); flashSynced(boxBc);
        if (data.box_units > 0) document.getElementById('p-qpb').value = data.box_units;
        const bq = document.getElementById('p-box'); if (!(parseInt(bq.value) >= 1)) bq.value = 1;
        panelRecalc();
    }
    // Expiry is NOT carried over — each delivery sets its own expiry date.
}

// ── "Synced from previous data" feedback ───────────────────────────────────
let _syncPopTimer = null;
function showSyncPop(name) {
    const pop = document.getElementById('sync-pop');
    if (!pop) return;
    const label = document.getElementById('sync-pop-name');
    if (label) label.textContent = name ? (name + ' — no need to type it.') : 'Description filled — no need to type it.';
    pop.classList.add('show');
    clearTimeout(_syncPopTimer);
    _syncPopTimer = setTimeout(() => pop.classList.remove('show'), 2400);
}
function flashSynced(field) {
    if (!field) return;
    field.classList.remove('field-synced');
    void field.offsetWidth;               // restart the animation
    field.classList.add('field-synced');
}

// ── Scan station ───────────────────────────────────────────────────────────
function setHint(text, tone) {
    const hint = document.getElementById('scan-hint');
    hint.textContent = text;
    const colors = { idle: 'text-slate-400', ok: 'text-emerald-400', warn: 'text-amber-400', busy: 'text-sky-400' };
    hint.className = 'text-xs font-black whitespace-nowrap ' + (colors[tone] || colors.idle);
}

function findRowByBarcode(barcode) {
    const norm = barcode.trim().toLowerCase();
    if (!norm) return null;
    let match = null;
    document.querySelectorAll('#items-body .item-row').forEach(function (row) {
        const a = (row.dataset.barcode || '').trim().toLowerCase();
        const b = (row.dataset.boxbc   || '').trim().toLowerCase();
        if (a === norm || b === norm) match = row;
    });
    return match;
}

function flashRow(row) {
    row.classList.remove('row-flash');
    void row.offsetWidth;                       // restart the animation
    row.classList.add('row-flash');
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => row.classList.remove('row-flash'), 2800);
}

// Scanning fills the entry panel (the clerk reviews, then clicks Add Item).
async function handleScan() {
    const input   = document.getElementById('scan-input');
    const barcode = input.value.trim();
    if (!barcode) return;
    input.value = '';

    const existing = findRowByBarcode(barcode);
    if (existing) {
        flashRow(existing);
        showFlash('⚠ "' + (existing.dataset.desc || 'this item') + '" is already in the list — click Edit to re-count it.', 'error');
        setHint('Already on list ↑ verify', 'warn');
        return;
    }

    setHint('Looking up…', 'busy');
    const data  = await lookupBarcodeData(barcode);
    const isBox = data && data.found && data.match === 'box';

    cancelEdit();            // make sure we're adding fresh, not mid-edit
    const bcEl  = document.getElementById('p-bc');
    const boxBc = document.getElementById('p-boxbc');
    if (isBox) {
        boxBc.value = barcode; boxBc.classList.remove('hidden'); flashSynced(boxBc);
        if (data.barcode) bcEl.value = data.barcode;
        if (data.box_units > 0) document.getElementById('p-qpb').value = data.box_units;
        document.getElementById('p-box').value = 1;
    } else {
        bcEl.value = barcode;
    }
    if (data && data.found) {
        const desc = document.getElementById('p-desc');
        desc.value = (data.name || '').toUpperCase(); flashSynced(desc); showSyncPop(data.name); panelPreviewName();
        setHint(isBox ? '✓ Box synced — set boxes & expiry' : '✓ Synced — set qty & expiry', 'ok');
        panelRecalc();
        document.getElementById('p-qpb').focus();
    } else {
        setHint('New product — type its name', 'warn');
        document.getElementById('p-desc').focus();
    }
}

// ── Non-barcode item: generate NB-YYYYMMDD-XXXXXX and lock the barcode field ──
function genNbCode() {
    var now = new Date();
    var d   = now.getFullYear().toString()
            + String(now.getMonth() + 1).padStart(2, '0')
            + String(now.getDate()).padStart(2, '0');
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // unambiguous charset
    var rnd = '';
    if (window.crypto && window.crypto.getRandomValues) {
        var buf = new Uint8Array(6);
        window.crypto.getRandomValues(buf);
        buf.forEach(function(b) { rnd += chars[b % chars.length]; });
    } else {
        for (var n = 0; n < 6; n++) rnd += chars[Math.floor(Math.random() * chars.length)];
    }
    return 'NB-' + d + '-' + rnd;
}

// ── Non-barcode toggle (in the panel) ─────────────────────────────────────────
let _nbOn = false;
function applyNbVisual(on) {
    _nbOn = on;
    const bc = document.getElementById('p-bc');
    const badge = document.getElementById('p-nb-badge');
    const btn = document.getElementById('nb-toggle');
    if (on) {
        bc.readOnly = true;
        bc.classList.add('bg-amber-50', 'text-amber-800', 'cursor-not-allowed', 'font-mono');
        badge.classList.remove('hidden');
        btn.classList.add('bg-amber-500', 'text-white');
    } else {
        bc.readOnly = false;
        bc.classList.remove('bg-amber-50', 'text-amber-800', 'cursor-not-allowed', 'font-mono');
        badge.classList.add('hidden');
        btn.classList.remove('bg-amber-500', 'text-white');
    }
}
function toggleNonBarcode() {
    if (_nbOn) { applyNbVisual(false); document.getElementById('p-bc').value = ''; }
    else { applyNbVisual(true); document.getElementById('p-bc').value = genNbCode(); document.getElementById('p-desc').focus(); }
}

// ── Items list: build / edit / remove ─────────────────────────────────────────
let _editingRow = null;
function fmtDate(s) {
    const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const p = (s || '').split('-'); if (p.length !== 3) return s;
    return m[parseInt(p[1], 10) - 1] + ' ' + parseInt(p[2], 10) + ', ' + p[0];
}
function setRowData(row, d) {
    row.dataset.barcode = d.barcode; row.dataset.boxbc = d.boxbc; row.dataset.desc = d.desc;
    row.dataset.cat = d.cat; row.dataset.qpb = d.qpb; row.dataset.box = d.box; row.dataset.dmg = d.dmg;
    row.dataset.good = d.good; row.dataset.total = d.total; row.dataset.hasexp = d.hasexp;
    row.dataset.expdate = d.expdate; row.dataset.notes = d.notes;
}
function getRowIndex(row) {
    const inp = row.querySelector('.f-barcode');
    const m = inp ? inp.name.match(/items\[(\d+)\]/) : null;
    return m ? parseInt(m[1], 10) : _rowIdx++;
}
function rowInner(d, i) {
    const N = function (f) { return 'items[' + i + '][' + f + ']'; };
    const exp     = d.hasexp == 1 ? '<input type="hidden" name="' + N('has_expiry') + '" class="f-hasexp" value="1">' : '';
    const dmgSpan = d.dmg > 0    ? '<span class="text-rose-500 row-dmg">Damaged: ' + d.dmg + '</span>' : '';
    const expSpan = d.expdate    ? '<span class="row-exp">Exp: ' + fmtDate(d.expdate) + '</span>' : '';
    const noteSpan = d.notes     ? '<span class="text-slate-400 italic normal-case row-notes">“' + esc(d.notes) + '”</span>' : '';
    return ''
      + '<input type="hidden" name="' + N('barcode')      + '" class="f-barcode"  value="' + esc(d.barcode) + '">'
      + '<input type="hidden" name="' + N('box_barcode')  + '" class="f-boxbc"    value="' + esc(d.boxbc)   + '">'
      + '<input type="hidden" name="' + N('description')  + '" class="f-desc"     value="' + esc(d.desc)    + '">'
      + '<input type="hidden" name="' + N('category')     + '" class="f-cat"      value="' + esc(d.cat)     + '">'
      + '<input type="hidden" name="' + N('qty_per_box')  + '" class="f-qpb qty-per-box" value="' + d.qpb + '">'
      + '<input type="hidden" name="' + N('box_qty')      + '" class="f-box box-qty"     value="' + d.box + '">'
      + '<input type="hidden" name="' + N('damaged_qty')  + '" class="f-dmg damaged-qty" value="' + d.dmg + '">'
      + '<input type="hidden" name="' + N('qty')          + '" class="f-good qty-hidden" value="' + d.good + '">'
      + '<input type="hidden" name="' + N('expiry_date')  + '" class="f-expdate"  value="' + esc(d.expdate) + '">'
      + '<input type="hidden" name="' + N('damage_notes') + '" class="f-notes"    value="' + esc(d.notes)   + '">'
      + exp
      + '<div class="flex-1 min-w-0">'
      +   '<div class="flex items-center gap-2 flex-wrap">'
      +     '<p class="font-bold text-slate-800 leading-tight row-name">' + esc(d.desc) + '</p>'
      +     '<span class="row-cat text-[9px] font-black text-blue-500 uppercase bg-blue-50/70 px-2 py-0.5 rounded">' + esc(d.cat) + '</span>'
      +   '</div>'
      +   '<div class="flex items-center gap-x-3 gap-y-1 flex-wrap mt-1 text-[10px] font-black text-slate-400 uppercase tracking-wide">'
      +     '<code class="text-slate-400 bg-slate-100 px-2 py-0.5 rounded border row-bc">' + esc(d.barcode || d.boxbc || '—') + '</code>'
      +     '<span class="text-slate-600 row-good">Good: ' + d.good + '</span>'
      +     '<span class="row-total">Total: ' + d.total + '</span>'
      +     dmgSpan + expSpan + noteSpan
      +   '</div>'
      + '</div>'
      + '<div class="flex items-center gap-2 flex-shrink-0">'
      +   '<button type="button" onclick="editItem(this)" class="text-[10px] font-black text-slate-500 hover:text-white border border-slate-200 hover:bg-slate-700 px-3 py-1.5 rounded-lg uppercase tracking-widest transition-all">Edit</button>'
      +   '<button type="button" onclick="removeItem(this)" class="text-rose-400 hover:text-rose-600 font-black text-xl leading-none px-1">&times;</button>'
      + '</div>';
}
function addOrUpdateItem() {
    const d = panelValidate();
    if (!d) return;
    if (_editingRow) {
        const i = getRowIndex(_editingRow);
        setRowData(_editingRow, d);
        _editingRow.innerHTML = rowInner(d, i);
        _editingRow = null;
    } else {
        const i = _rowIdx++;
        const div = document.createElement('div');
        div.className = 'item-row bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3.5 flex items-center gap-4';
        setRowData(div, d);
        div.innerHTML = rowInner(d, i);
        document.getElementById('items-body').appendChild(div);
        flashRow(div);
    }
    resetPanelFields();
    setPanelMode(false);
    refreshItemCount();
    document.getElementById('p-bc').focus();
}
function editItem(btn) {
    const row = btn.closest('.item-row');
    _editingRow = row;
    const d = row.dataset;
    applyNbVisual(false);
    document.getElementById('p-bc').value = d.barcode || '';
    const boxBc = document.getElementById('p-boxbc');
    boxBc.value = d.boxbc || '';
    boxBc.classList.toggle('hidden', !((d.boxbc || '').trim()));
    document.getElementById('p-desc').value = (d.desc || '').toUpperCase();
    // Size/Unit are already folded into the saved name, so start them blank on edit.
    document.getElementById('p-size').value = '';
    document.getElementById('p-unit').value = '';
    panelPreviewName();
    document.getElementById('p-cat').value  = d.cat || '';
    document.getElementById('p-qpb').value  = d.qpb || 0;
    document.getElementById('p-box').value  = d.box || 0;
    document.getElementById('p-dmg').value  = d.dmg || 0;
    document.getElementById('p-notes').value = d.notes || '';
    const hasexp = d.hasexp == 1;
    document.getElementById('p-hasexp').checked = hasexp;
    const expEl = document.getElementById('p-expdate');
    expEl.value = d.expdate || '';
    expEl.classList.toggle('hidden', !hasexp);
    if ((d.barcode || '').startsWith('NB-')) applyNbVisual(true);   // keep the existing NB code, locked
    panelRecalc();
    setPanelMode(true);
    document.getElementById('panel-title').scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.getElementById('p-desc').focus();
}
function removeItem(btn) {
    const row = btn.closest('.item-row');
    if (_editingRow === row) cancelEdit();
    row.remove();
    refreshItemCount();
}
function cancelEdit() {
    _editingRow = null;
    resetPanelFields();
    setPanelMode(false);
}
function setPanelMode(editing) {
    document.getElementById('panel-title').textContent = editing ? 'Edit Item' : 'Add Received Item';
    document.getElementById('p-add').textContent       = editing ? 'Update Item' : '+ Add Item';
    document.getElementById('p-cancel').classList.toggle('hidden', !editing);
}
function resetPanelFields() {
    applyNbVisual(false);
    document.getElementById('p-bc').value = '';
    const boxBc = document.getElementById('p-boxbc'); boxBc.value = ''; boxBc.classList.add('hidden');
    document.getElementById('p-desc').value = '';
    document.getElementById('p-size').value = '';
    document.getElementById('p-unit').value = '';
    document.getElementById('p-cat').value  = '';
    document.getElementById('p-qpb').value  = 0;
    document.getElementById('p-box').value  = 0;
    document.getElementById('p-dmg').value  = 0;
    document.getElementById('p-notes').value = '';
    document.getElementById('p-hasexp').checked = false;
    const expEl = document.getElementById('p-expdate'); expEl.value = ''; expEl.classList.add('hidden');
    const prev = document.getElementById('p-name-preview'); if (prev) prev.classList.add('hidden');
    panelRecalc();
}
function refreshItemCount() {
    const n = document.querySelectorAll('#items-body .item-row').length;
    document.getElementById('item-count').textContent = n;
    document.getElementById('empty-list').classList.toggle('hidden', n > 0);
}

// ── AJAX form submission ──────────────────────────────────────────────────────
var _submitAction = 'save';
var _isSubmitting = false;   // re-entrancy guard — blocks accidental double submits

document.getElementById('saveBtn').addEventListener('click', function () {
    _submitAction = 'save';
    document.getElementById('itemsForm').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
});

document.getElementById('itemsForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    if (_isSubmitting) return;          // a request is already in flight — ignore the repeat
    if (!beforeSubmit()) return;
    _isSubmitting = true;

    var saveBtn   = document.getElementById('saveBtn');
    var submitBtn = document.getElementById('submitBtn');
    saveBtn.disabled   = true;
    submitBtn.disabled = true;
    var origSaveTxt   = saveBtn.textContent;
    var origSubmitTxt = submitBtn.textContent;
    saveBtn.textContent   = 'Saving…';
    submitBtn.textContent = 'Submitting…';

    var fd = new FormData(this);
    fd.set('submit_action', _submitAction);
    fd.append('_ajax', '1');

    var navigated = false;
    try {
        var res  = await fetch('receive_process.php', { method: 'POST', body: fd });
        var data = await res.json();
        // Success → go to the success page. Failure with a redirect (e.g. the batch is
        // no longer editable) → land on the live batch list instead of a dead page.
        if (data.success || data.redirect) {
            navigated = true;
            // Bust the SPA cache for this page — the batch state just changed on the
            // server (status moved to pending_validation). Serving stale cached HTML
            // would show the editable form even though the batch is now read-only,
            // causing the "already submitted" error if the user navigates back here.
            if (typeof pageCache !== 'undefined') pageCache.clear();
            navigate(data.redirect);
        } else {
            showFlash(data.error, 'error');
        }
    } catch (_) {
        showFlash('Connection error — check your network and try again.', 'error');
    } finally {
        // Re-enable only when staying on this page; if navigating away, keep the
        // buttons locked so a stale click can't fire another request mid-transition.
        if (!navigated) {
            saveBtn.disabled      = false;
            submitBtn.disabled    = false;
            saveBtn.textContent   = origSaveTxt;
            submitBtn.textContent = origSubmitTxt;
            _isSubmitting = false;
        }
    }
});

// ── Submit confirm modal ──────────────────────────────────────────────────────
function openSubmitConfirm() {
    var count = document.querySelectorAll('.item-row').length;
    if (count === 0) {
        showFlash('Scan or add at least one item before submitting.', 'error');
        document.getElementById('scan-input').focus();
        return;
    }
    if (!beforeSubmit()) return;   // run validation before opening modal
    document.getElementById('sm-item-count').textContent = count;
    document.getElementById('submit-modal').classList.remove('hidden');
}
function closeSubmitConfirm() {
    document.getElementById('submit-modal').classList.add('hidden');
}
function confirmSubmitBatch() {
    if (_isSubmitting) return;          // already submitting — ignore a repeat confirm click
    closeSubmitConfirm();
    syncQtys();
    _submitAction = 'submit';
    document.getElementById('itemsForm').dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
}
document.getElementById('submit-modal')?.addEventListener('click', function (e) {
    if (e.target === this) closeSubmitConfirm();
});

// Pressing Enter inside any grid field jumps back to the scan box (and never
// submits the batch by accident).
document.getElementById('items-body').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
        e.preventDefault();
        document.getElementById('scan-input').focus();
    }
});

// Keep the scan box focused as the default landing spot.
window.addEventListener('load', () => document.getElementById('scan-input')?.focus());
</script>

<?php include '../layout_bottom.php'; ?>
