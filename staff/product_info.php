<?php
// --- 1. FUNCTION: Initialization & Logic (UNCHANGED) ---
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = $_SESSION['user_id'] ?? null;
$msg = "";

if (isset($_GET['clear_batch'])) {
    $stale_sid = intval($_SESSION['active_batch_id'] ?? 0);
    if ($stale_sid) {
        $del = $conn->prepare("DELETE FROM products WHERE supplier_id = ? AND status = 'draft'");
        $del->bind_param("i", $stale_sid); $del->execute();
    }
    unset($_SESSION['active_batch_id'], $_SESSION['active_batch_name'], $_SESSION['active_invoice'], $_SESSION['verification_in_progress']);
    header("Location: product_info.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && strtolower($_SESSION['role'] ?? '') === ROLE_STAFF && ($_SESSION['procurement_access'] ?? PROC_NONE) !== PROC_APPROVED) {
    header("Location: product_info.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_for_receiving') {
    if (strtolower($_SESSION['role'] ?? '') === ROLE_STAFF) {
        $_SESSION['verification_in_progress'] = true;
    }
    header("Location: delivery_receive.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_batch') {
    // Staff cannot manually select a batch — their supplier is pre-assigned by the admin
    if (strtolower($_SESSION['role'] ?? '') === ROLE_STAFF) {
        header("Location: product_info.php"); exit();
    }
    $batch_data = explode('|', $_POST['supplier_batch_data']);
    $_SESSION['active_batch_id']   = $batch_data[0];
    $_SESSION['active_batch_name'] = $batch_data[1];
    $_SESSION['active_invoice']    = $batch_data[2];

    // Wipe any leftover drafts from a previous abandoned session for this supplier
    $sup_id   = intval($batch_data[0]);
    $stale_del = $conn->prepare("DELETE FROM products WHERE supplier_id = ? AND status = 'draft'");
    $stale_del->bind_param("i", $sup_id); $stale_del->execute();

    // Update the most recent approved procurement_batches row to 'encoding'
    $sup_name = $batch_data[1];
    $inv_no   = $batch_data[2];
    $pb_find  = $conn->prepare("SELECT id FROM procurement_batches WHERE staff_id = ? AND status = '" . BATCH_APPROVED . "' ORDER BY created_at DESC LIMIT 1");
    $pb_find->bind_param("i", $user_id); $pb_find->execute();
    $pb_row   = $pb_find->get_result()->fetch_assoc();
    if ($pb_row) {
        $pb_id = $pb_row['id'];
        $pb_upd = $conn->prepare("UPDATE procurement_batches SET status='encoding', supplier_id=?, supplier_name=?, invoice=?, encoding_started_at=NOW() WHERE id=?");
        $pb_upd->bind_param("issi", $sup_id, $sup_name, $inv_no, $pb_id);
        $pb_upd->execute();
        $_SESSION['proc_batch_id'] = $pb_id;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_product') {
    $name        = trim($_POST['name']);
    $barcode     = trim($_POST['barcode']);
    $qty         = intval($_POST['quantity']);
    $sup_id      = intval($_SESSION['active_batch_id']);
    $category    = $_POST['category'] ?? 'General';
    $no_expiry   = ($_POST['no_expiry'] ?? '0') === '1';
    $expiry_date = $no_expiry ? null : (trim($_POST['expiry_date']) ?: null);

    if (!$no_expiry && empty($expiry_date)) {
        $msg = "<div class='bg-rose-500 text-white p-4 rounded-2xl mb-6 font-bold shadow-lg animate-in text-center'>Expiry date is required. If this product has no expiry, toggle \"No Expiry\".</div>";
    } else {

    if(empty($barcode)) $barcode = "628" . substr(time(), -7);

    $check_draft = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND supplier_id = ? AND status = 'draft' LIMIT 1");
    $check_draft->bind_param("si", $barcode, $sup_id);
    $check_draft->execute();
    $existing_draft = $check_draft->get_result()->fetch_assoc();

    if ($existing_draft) {
        $stmt = $conn->prepare("UPDATE products SET quantity = quantity + ?, expiry_date = ? WHERE id = ?");
        $stmt->bind_param("isi", $qty, $expiry_date, $existing_draft['id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO products (name, barcode, quantity, supplier_id, category, expiry_date, status) VALUES (?, ?, ?, ?, ?, ?, 'draft')");
        $stmt->bind_param("ssiiss", $name, $barcode, $qty, $sup_id, $category, $expiry_date);
    }

    if ($stmt->execute()) {
        $msg = "<div class='bg-emerald-500 text-white p-4 rounded-2xl mb-6 font-bold shadow-lg animate-in text-center flex items-center justify-center gap-2'>
                <svg class='w-5 h-5' fill='currentColor' viewBox='0 0 20 20'><path d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z'/></svg>
                Item added to draft batch list.
                </div>";
    }
    } // end else (expiry validated)
}

include 'layout_top.php';

$role = strtolower($_SESSION['role'] ?? '');
if ($role === ROLE_STAFF && ($_SESSION['procurement_access'] ?? PROC_NONE) !== PROC_APPROVED) {
    include 'procurement_gate.php';
    include 'layout_bottom.php';
    exit();
}

// Auto-start for staff: load the admin-assigned supplier without showing a dropdown
if ($role === ROLE_STAFF && ($_SESSION['procurement_access'] ?? PROC_NONE) === PROC_APPROVED) {
    // If super admin changed the voucher mid-session, clear stale session state and reload
    if (!empty($_SESSION['active_batch_id'])) {
        $lock_q = $conn->prepare("SELECT locked_supplier_id FROM users WHERE id = ?");
        $lock_q->bind_param("i", $user_id); $lock_q->execute();
        $current_lock = intval($lock_q->get_result()->fetch_assoc()['locked_supplier_id'] ?? 0);
        if ($current_lock && $current_lock !== intval($_SESSION['active_batch_id'])) {
            unset($_SESSION['active_batch_id'], $_SESSION['active_batch_name'], $_SESSION['active_invoice'], $_SESSION['verification_in_progress']);
            header("Location: product_info.php"); exit();
        }
    }

    if (empty($_SESSION['active_batch_id'])) {
        $ls_q = $conn->prepare("SELECT u.locked_supplier_id, s.name, s.invoice_number FROM users u JOIN suppliers s ON s.id = u.locked_supplier_id WHERE u.id = ? AND u.locked_supplier_id IS NOT NULL");
        $ls_q->bind_param("i", $user_id); $ls_q->execute();
        $ls = $ls_q->get_result()->fetch_assoc();

        if (!$ls) {
            header("Location: dashboard.php?error=" . urlencode("No supply voucher has been assigned to your account. Please contact an administrator."));
            exit();
        }

        $sup_id   = intval($ls['locked_supplier_id']);
        $sup_name = $ls['name'];
        $inv_no   = $ls['invoice_number'];

        $del = $conn->prepare("DELETE FROM products WHERE supplier_id = ? AND status = 'draft'");
        $del->bind_param("i", $sup_id); $del->execute();

        $_SESSION['active_batch_id']   = $sup_id;
        $_SESSION['active_batch_name'] = $sup_name;
        $_SESSION['active_invoice']    = $inv_no;

        $pb_find = $conn->prepare("SELECT id FROM procurement_batches WHERE staff_id = ? AND status = '" . BATCH_APPROVED . "' ORDER BY created_at DESC LIMIT 1");
        $pb_find->bind_param("i", $user_id); $pb_find->execute();
        $pb_row = $pb_find->get_result()->fetch_assoc();
        if ($pb_row) {
            $pb_upd = $conn->prepare("UPDATE procurement_batches SET status = 'encoding', supplier_id = ?, supplier_name = ?, invoice = ?, encoding_started_at = NOW() WHERE id = ?");
            $pb_upd->bind_param("issi", $sup_id, $sup_name, $inv_no, $pb_row['id']);
            $pb_upd->execute();
            $_SESSION['proc_batch_id'] = $pb_row['id'];
        }
    }
}

$shipments = $conn->query("SELECT id, name, invoice_number, created_at FROM suppliers ORDER BY id DESC LIMIT 20");
?>

<!-- 📍 SYNC TOAST -->
<div id="syncToast" class="fixed top-24 right-10 z-[300] transform translate-x-full transition-transform duration-500 pointer-events-none">
    <div class="bg-slate-900 text-white px-6 py-4 rounded-3xl shadow-2xl flex items-center gap-4 border border-emerald-500">
        <div class="bg-emerald-500 p-2 rounded-xl text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div>
        <div><p class="text-[10px] font-black uppercase tracking-widest text-emerald-400">Intelligent Sync</p><p class="font-bold text-sm">Product Data Matched!</p></div>
    </div>
</div>

<div class="max-w-7xl mx-auto space-y-6 animate-in pb-20">

    <?php if (!isset($_SESSION['active_batch_id'])): ?>
        <!-- STEP 1: SHIPMENT SELECTION -->
        <div class="card-modern bg-blue-50/50 border-blue-100 p-12 text-center shadow-inner">
            <h3 class="serif-title text-3xl font-bold text-slate-800 mb-4">Open Delivery Shipment</h3>
            <p class="text-slate-500 mb-8 max-w-lg mx-auto italic text-sm uppercase tracking-widest font-black opacity-40">Select the supply voucher to begin.</p>
            <form method="POST" action="" class="max-w-3xl mx-auto flex gap-4">
                <input type="hidden" name="action" value="start_batch">
                <select name="supplier_batch_data" required class="input-modern flex-1 shadow-sm h-[64px] border-blue-200">
                    <option value="">-- Choose Shipment Voucher --</option>
                    <?php while($row = $shipments->fetch_assoc()): ?>
                        <option value="<?= $row['id'] ?>|<?= htmlspecialchars($row['name']) ?>|<?= $row['invoice_number'] ?>">
                            <?= $row['invoice_number'] ?> — <?= htmlspecialchars($row['name']) ?> [<?= date('M d, Y', strtotime($row['created_at'])) ?>]
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit" class="btn-pos-primary px-10 h-[64px] shadow-lg">Open</button>
            </form>
        </div>

    <?php elseif (!empty($_SESSION['verification_in_progress'])): ?>
        <!-- 🔒 LOCKED: Price Verification in Progress -->
        <div class="max-w-3xl mx-auto space-y-6">
            <div class="bg-amber-50 border-2 border-amber-200 rounded-[3rem] p-14 text-center shadow-inner">
                <div class="w-20 h-20 bg-amber-100 rounded-3xl flex items-center justify-center mx-auto mb-6">
                    <svg class="w-10 h-10 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <span class="inline-block bg-amber-200 text-amber-700 text-[9px] font-black uppercase tracking-[0.2em] px-4 py-2 rounded-full mb-4">Entry Locked</span>
                <h3 class="serif-title text-3xl font-bold text-slate-800 mb-3">Verification in Progress</h3>
                <p class="text-slate-500 text-sm mb-1 font-bold"><?= htmlspecialchars($_SESSION['active_batch_name']) ?></p>
                <code class="text-xs text-slate-400 font-mono block mb-6">Invoice: <?= htmlspecialchars($_SESSION['active_invoice']) ?></code>
                <p class="text-slate-400 text-sm leading-relaxed max-w-md mx-auto mb-10">
                    This batch has been moved to price and quantity validation at the <strong>Receiving Station</strong>.
                    No new entries can be made until the batch is officialized or cancelled.
                </p>
                <div class="flex justify-center">
                    <a href="delivery_receive.php" class="btn-pos-primary px-12 py-4 text-sm shadow-lg uppercase tracking-widest">
                        Resume Verification &rarr;
                    </a>
                </div>
            </div>

            <!-- Read-only draft item list -->
            <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden">
                <div class="p-8 border-b border-slate-50 bg-slate-50/20 flex justify-between items-center">
                    <h4 class="font-black text-slate-800 text-xs uppercase tracking-[0.2em]">Items Awaiting Verification</h4>
                    <span class="text-[9px] text-amber-500 font-black uppercase tracking-widest bg-amber-50 px-3 py-1 rounded-full border border-amber-100">Read-Only</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="table-modern text-left min-w-full">
                        <thead>
                            <tr class="bg-slate-50/50">
                                <th class="px-10 py-5 text-[10px] font-black uppercase text-slate-400">Product</th>
                                <th class="px-8 py-5 text-[10px] font-black uppercase text-slate-400 text-center">Category</th>
                                <th class="px-10 py-5 text-center text-emerald-600 text-[10px] font-black uppercase">Units</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php
                            $locked_sid   = intval($_SESSION['active_batch_id']);
                            $locked_items = $conn->query("SELECT name, barcode, category, SUM(quantity) as total_qty FROM products WHERE supplier_id = $locked_sid AND status = 'draft' GROUP BY barcode, TRIM(LOWER(name)) ORDER BY name ASC");
                            if ($locked_items && $locked_items->num_rows > 0):
                                while($lp = $locked_items->fetch_assoc()): ?>
                                <tr>
                                    <td class="px-10 py-6">
                                        <p class="font-black text-slate-800 leading-tight"><?= htmlspecialchars($lp['name']) ?></p>
                                        <code class="text-[10px] text-slate-400 font-mono uppercase opacity-50"><?= htmlspecialchars($lp['barcode']) ?></code>
                                    </td>
                                    <td class="px-8 py-6 text-center text-xs font-black uppercase text-slate-300"><?= htmlspecialchars($lp['category']) ?></td>
                                    <td class="px-10 py-6 text-center font-black text-emerald-600 text-3xl tracking-tighter"><?= number_format($lp['total_qty']) ?></td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="3" class="p-16 text-center text-slate-300 font-bold italic opacity-30 uppercase">No draft items found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- 🔵 STEP 2: ENCODING INTERFACE -->
        <div class="bg-white rounded-[3rem] border border-slate-100 shadow-2xl overflow-hidden animate-in">
            <div class="p-8 bg-slate-900 text-white flex justify-between items-center">
                <div class="flex items-center gap-6">
                    <div class="bg-emerald-500 p-4 rounded-3xl shadow-lg"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></div>
                    <div>
                        <p class="text-[10px] font-black text-emerald-400 uppercase tracking-widest opacity-80 mb-1">RECORDING ENTRIES FOR</p>
                        <h4 class="text-3xl font-black leading-none"><?= $_SESSION['active_batch_name'] ?></h4>
                        <code class="text-slate-500 font-mono text-xs mt-3 block uppercase tracking-widest">ID: <?= $_SESSION['active_invoice'] ?></code>
                    </div>
                </div>
            </div>

            <div class="p-10 bg-[#fdfdfe]">
                <?= $msg ?>
                <form id="productForm" method="POST" action="">
                    <input type="hidden" name="action" value="save_product">
                    <input type="hidden" name="force_mode" id="force_mode" value="">

                    <div class="flex flex-col gap-8">

                        <!-- Row 1: Barcode (first) & Name (second) -->
                        <div class="grid grid-cols-12 gap-6 items-end">

                            <!-- Barcode — typeable, scanner-ready -->
                            <div class="col-span-12 md:col-span-5">
                                <label class="label-modern ml-2 text-xs">Barcode Identification</label>
                                <input type="text" name="barcode" id="p_barcode"
                                       placeholder="Scan or type barcode..."
                                       autocomplete="off"
                                       class="input-modern w-full h-[64px] text-sm font-mono shadow-sm"
                                       onkeydown="onBarcodeKeydown(event)"
                                       oninput="onBarcodeInput(this.value)">
                                <p class="text-[10px] text-slate-400 font-bold ml-2 mt-1">Scan barcode or type manually → press Enter to lookup</p>
                            </div>

                            <!-- Name — with suggestion box -->
                            <div class="col-span-12 md:col-span-7 suggestions-wrapper">
                                <label class="label-modern ml-2 text-xs">Product Description / Master Name</label>
                                <input type="text" name="name" id="p_name" required
                                       placeholder="Type or auto-filled from scan..."
                                       autocomplete="off"
                                       class="input-modern w-full h-[64px] text-lg font-bold shadow-sm"
                                       oninput="showSuggestions(this.value)"
                                       onblur="triggerAutoSync()">
                                <div id="suggestionsBox" class="suggestions-box hidden"></div>
                            </div>
                        </div>

                        <!-- Row 2: Category, Expiry, Qty, Submit -->
                        <div class="grid grid-cols-12 gap-6 items-end pt-6 border-t border-slate-50">
                            <div class="col-span-12 md:col-span-3">
                                <label class="label-modern ml-2 text-xs">Assign Category</label>
                                <select name="category" id="p_cat" class="input-modern w-full h-[64px] cursor-pointer bg-white">
                                    <?php foreach (PRODUCT_CATEGORIES as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-span-12 md:col-span-3">
                                <label class="label-modern ml-2 text-xs">Expiry Date</label>
                                <input type="date" name="expiry_date" id="p_expiry" required
                                       class="input-modern w-full h-[64px] cursor-pointer bg-white text-slate-700 font-bold">
                                <input type="hidden" name="no_expiry" id="p_no_expiry" value="0">
                                <button type="button" id="noExpiryBtn" onclick="toggleNoExpiry()"
                                        class="mt-2 text-[10px] font-black uppercase tracking-widest text-slate-400 border border-slate-200 px-3 py-1.5 rounded-lg hover:border-slate-300 transition-all w-full text-center">
                                    No Expiry Date
                                </button>
                            </div>

                            <div class="col-span-6 md:col-span-2">
                                <label class="label-modern ml-2 text-emerald-600 font-black text-xs uppercase">Units Delivered</label>
                                <input type="number" name="quantity" id="p_qty" required
                                       class="input-modern w-full text-center text-2xl font-black text-slate-900 border-emerald-200 h-[64px] bg-white shadow-sm"
                                       placeholder="0" min="1">
                            </div>

                            <div class="col-span-6 md:col-span-4">
                                <button type="submit" class="btn-pos-primary w-full h-[64px] shadow-lg uppercase font-black text-xs tracking-[0.15em] flex items-center justify-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    Add Item To Shipment List
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- 📊 CHECKLIST TABLE -->
        <div class="bg-white rounded-[3rem] border border-slate-100 shadow-xl overflow-hidden animate-in">
            <div class="p-8 border-b border-slate-50 bg-slate-50/20 flex justify-between items-center">
                <h4 class="font-black text-slate-800 text-xs uppercase tracking-[0.2em] flex items-center gap-2">
                    <span class="w-3 h-3 bg-emerald-500 rounded-full animate-ping"></span> Batch Items Waiting for Validation
                </h4>
                <p class="text-[9px] text-slate-400 font-bold uppercase tracking-widest opacity-60 italic">These are currently in draft mode</p>
            </div>

            <div class="overflow-x-auto">
                <table class="table-modern text-left min-w-full">
                    <thead>
                        <tr class="bg-slate-50/50">
                            <th class="px-12 py-6 text-[10px] font-black uppercase text-slate-400">Description</th>
                            <th class="px-8 py-6 text-[10px] font-black uppercase text-slate-400 text-center">Batch Category</th>
                            <th class="px-12 py-6 text-center text-emerald-600 text-[10px] font-black uppercase">Current Count</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php
                        $sid = $_SESSION['active_batch_id'];
                        $recent = $conn->query("SELECT name, barcode, category, SUM(quantity) as total_qty FROM products WHERE supplier_id = $sid AND status = 'draft' GROUP BY barcode, TRIM(LOWER(name)) ORDER BY id DESC LIMIT 15");
                        if ($recent->num_rows > 0):
                            while($p = $recent->fetch_assoc()): ?>
                            <tr class="hover:bg-blue-50/10 transition-colors">
                                <td class="px-12 py-8">
                                    <p class="font-black text-slate-800 text-lg tracking-tight"><?= htmlspecialchars($p['name']) ?></p>
                                    <code class="text-[10px] text-slate-400 font-mono tracking-tighter uppercase opacity-50">SKU ID: <?= htmlspecialchars($p['barcode']) ?></code>
                                </td>
                                <td class="px-8 py-8 text-center text-xs font-black uppercase text-slate-300"><?= $p['category'] ?></td>
                                <td class="px-12 py-8 text-center font-black text-emerald-600 text-4xl tracking-tighter">
                                    <?= number_format($p['total_qty']) ?>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="3" class="p-24 text-center text-slate-300 font-bold italic opacity-30 text-2xl uppercase">Listing empty.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-12 bg-slate-50 border-t border-slate-100 flex flex-col items-center">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="submit_for_receiving">
                    <button type="submit" class="bg-emerald-500 text-white font-black px-24 py-6 rounded-[2.5rem] shadow-2xl shadow-emerald-200 hover:bg-emerald-600 transition-all uppercase tracking-[0.15em] text-sm">
                        Move to Final Pricing &amp; stock injection
                    </button>
                </form>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
(function() { var bc = document.getElementById('p_barcode'); if (bc) bc.focus(); })();

async function onBarcodeKeydown(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const val = (document.getElementById('p_barcode').value || '').trim();
    if (!val) { document.getElementById('p_name').focus(); return; }
    try {
        const res  = await fetch(`api/get_product_suggestion.php?barcode=${encodeURIComponent(val)}`);
        const item = await res.json();
        if (item) {
            document.getElementById('p_name').value = item.name;
            document.getElementById('p_cat').value  = item.category || 'General';
            resetExpiryField();
            showSyncNotice();
            document.getElementById('p_qty').focus();
        } else {
            document.getElementById('p_name').focus();
        }
    } catch(_) { document.getElementById('p_name').focus(); }
}

function onBarcodeInput(val) {
    document.getElementById('p_barcode').classList.remove('text-emerald-600', 'font-black');
}

async function showSuggestions(val) {
    const box = document.getElementById('suggestionsBox');
    if (val.length < 2) { box.classList.add('hidden'); return; }
    try {
        const res  = await fetch(`api/get_product_suggestion.php?q=${encodeURIComponent(val)}`);
        const data = await res.json();
        if (data.length > 0) {
            box.innerHTML = data.map(item => {
                const badge  = item.status === 'archived'
                    ? `<span class="text-[9px] font-black uppercase tracking-wider bg-slate-100 text-slate-400 px-2 py-0.5 rounded ml-1">archived</span>` : '';
                const expiry = item.expiry_date ? ` · exp ${item.expiry_date}` : '';
                return `<div class="suggestion-item" onclick='selectItem(${JSON.stringify(item)})'>
                    <span class="name">${item.name}${badge}</span>
                    <span class="meta">${item.barcode} | ${item.category}${expiry}</span>
                </div>`;
            }).join('');
            box.classList.remove('hidden');
        } else { box.classList.add('hidden'); }
    } catch(_) { box.classList.add('hidden'); }
}

function selectItem(item) {
    document.getElementById('p_name').value    = item.name;
    document.getElementById('p_barcode').value = item.barcode || '';
    document.getElementById('p_cat').value     = item.category || 'General';
    resetExpiryField();
    document.getElementById('suggestionsBox').classList.add('hidden');
    showSyncNotice();
}

async function triggerAutoSync() {
    setTimeout(async () => {
        const name   = document.getElementById('p_name').value;
        const barBox = document.getElementById('p_barcode');
        if (!name || barBox.value) return;
        try {
            const res  = await fetch(`api/check_duplicate.php?name=${encodeURIComponent(name)}&barcode=FETCH`);
            const data = await res.json();
            if (data.exists) {
                barBox.value = data.old_barcode;
                document.getElementById('p_cat').value    = data.old_category;
                resetExpiryField();
                showSyncNotice();
            }
        } catch(_) {}
    }, 250);
}

function resetExpiryField() {
    const input = document.getElementById('p_expiry');
    const hidden = document.getElementById('p_no_expiry');
    const btn = document.getElementById('noExpiryBtn');
    input.value = '';
    input.required = true;
    input.disabled = false;
    input.classList.remove('opacity-40');
    hidden.value = '0';
    btn.textContent = 'No Expiry Date';
    btn.classList.remove('bg-slate-900', 'text-white', 'border-slate-900');
    btn.classList.add('text-slate-400', 'border-slate-200');
}

function toggleNoExpiry() {
    const input = document.getElementById('p_expiry');
    const hidden = document.getElementById('p_no_expiry');
    const btn = document.getElementById('noExpiryBtn');
    const isOn = hidden.value === '1';
    if (isOn) {
        // Turn off — re-enable expiry field
        hidden.value = '0';
        input.required = true;
        input.disabled = false;
        input.classList.remove('opacity-40');
        btn.textContent = 'No Expiry Date';
        btn.classList.remove('bg-slate-900', 'text-white', 'border-slate-900');
        btn.classList.add('text-slate-400', 'border-slate-200');
    } else {
        // Turn on — clear and disable expiry field
        input.value = '';
        input.required = false;
        input.disabled = true;
        input.classList.add('opacity-40');
        hidden.value = '1';
        btn.textContent = 'Has Expiry Date';
        btn.classList.remove('text-slate-400', 'border-slate-200');
        btn.classList.add('bg-slate-900', 'text-white', 'border-slate-900');
    }
}

function showSyncNotice() {
    const toast  = document.getElementById('syncToast');
    const barBox = document.getElementById('p_barcode');
    toast.classList.add('show');
    toast.style.transform = "translateX(0)";
    barBox.classList.add('text-emerald-600', 'font-black');
    setTimeout(() => {
        toast.style.transform = "translateX(200%)";
        setTimeout(() => toast.classList.remove('show'), 500);
    }, 3000);
}
</script>

<?php include 'layout_bottom.php'; ?>
