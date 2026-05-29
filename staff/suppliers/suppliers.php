<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ── HELPER: next available sequential supplier code ───────────────────────────
function generate_next_code(mysqli $conn): string {
    $r   = $conn->query("SELECT MAX(CAST(supplier_code AS UNSIGNED)) AS m FROM suppliers WHERE supplier_code REGEXP '^[0-9]+$'");
    $max = intval($r->fetch_assoc()['m'] ?? 1000);
    $next = $max + 1;
    // Guarantee uniqueness (handles non-numeric codes occupying the slot)
    do {
        $chk = $conn->prepare("SELECT id FROM suppliers WHERE supplier_code = ? LIMIT 1");
        $chk->bind_param("s", $next);
        $chk->execute();
        $taken = $chk->get_result()->num_rows > 0;
        if ($taken) $next++;
    } while ($taken);
    return (string)$next;
}

// ── AJAX ENDPOINTS ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $type = $_GET['ajax'];

    if ($type === 'lookup_name') {
        $name = trim($_GET['name'] ?? '');
        $stmt = $conn->prepare("SELECT supplier_code FROM suppliers WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        echo json_encode(['code' => $row['supplier_code'] ?? null]);

    } elseif ($type === 'lookup_code') {
        $code = trim($_GET['code'] ?? '');
        $name = trim($_GET['name'] ?? '');
        $stmt = $conn->prepare("SELECT name FROM suppliers WHERE supplier_code = ? AND LOWER(name) != LOWER(?) LIMIT 1");
        $stmt->bind_param("ss", $code, $name);
        $stmt->execute();
        $row  = $stmt->get_result()->fetch_assoc();
        echo json_encode(['owner' => $row['name'] ?? null]);

    } elseif ($type === 'next_code') {
        echo json_encode(['code' => generate_next_code($conn)]);
    }
    exit();
}

// ── POST HANDLER ──────────────────────────────────────────────────────────────
$msg              = "";
$user_id          = $_SESSION['user_id'] ?? null;
$procurement_for  = intval($_GET['procurement_for'] ?? 0);
$procurement_uname = htmlspecialchars(trim($_GET['uname'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── RECORD SUPPLY ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'record_supply') {
        $name     = trim($_POST['name']);
        $contact  = trim($_POST['contact'] ?? '');
        $amount   = floatval($_POST['amount']);
        if ($amount <= 0) {
            $msg = "<div class='bg-rose-500 text-white p-4 rounded-2xl mb-6 font-bold'>Amount to Pay must be greater than ₱0.00.</div>";
            goto render_page;
        }
        $id_mode  = $_POST['id_mode'] ?? 'manual';
        $raw_code = trim($_POST['supplier_code'] ?? '');

        $auto_corrected = false;
        $correction_msg = '';

        // Step 1 — resolve the supplier code
        $supplier_code = ($id_mode === 'auto' || $raw_code === '')
            ? generate_next_code($conn)
            : $raw_code;

        // Step 2 — if this name already exists, use their established code
        $name_q = $conn->prepare("SELECT supplier_code FROM suppliers WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $name_q->bind_param("s", $name);
        $name_q->execute();
        $existing_by_name = $name_q->get_result()->fetch_assoc();

        if ($existing_by_name && $existing_by_name['supplier_code'] !== $supplier_code) {
            $old_code      = $supplier_code;
            $supplier_code = $existing_by_name['supplier_code'];
            $auto_corrected = true;
            $correction_msg = "ID corrected from #$old_code → #$supplier_code (matched existing supplier record).";
        }

        // Step 3 — if the code is still claimed by a different name, auto-assign a new one
        if (!$auto_corrected) {
            $code_q = $conn->prepare("SELECT name FROM suppliers WHERE supplier_code = ? AND LOWER(name) != LOWER(?) LIMIT 1");
            $code_q->bind_param("ss", $supplier_code, $name);
            $code_q->execute();
            $conflict = $code_q->get_result()->fetch_assoc();

            if ($conflict) {
                $old_code      = $supplier_code;
                $supplier_code = generate_next_code($conn);
                $auto_corrected = true;
                $correction_msg = "ID #$old_code belongs to '{$conflict['name']}'. Auto-assigned new ID #$supplier_code.";
            }
        }

        // Step 4 — insert
        $conn->begin_transaction();
        try {
            $invoice_num = "DEL-" . date('Ymd') . "-" . strtoupper(substr(uniqid(), -4));

            $stmt_sup = $conn->prepare("INSERT INTO suppliers (name, supplier_code, contact, amount, invoice_number) VALUES (?, ?, ?, ?, ?)");
            $stmt_sup->bind_param("sssds", $name, $supplier_code, $contact, $amount, $invoice_num);
            $stmt_sup->execute();
            $last_sup_id = $conn->insert_id;

            $stmt_pay = $conn->prepare("INSERT INTO supplier_payments (supplier_id, invoice_no, amount, status) VALUES (?, ?, ?, '" . SUP_PAY_UNPAID . "')");
            $stmt_pay->bind_param("isd", $last_sup_id, $invoice_num, $amount);
            $stmt_pay->execute();

            $log_msg  = "NEW SHIPMENT: $invoice_num | Supplier: $name (#$supplier_code) | Total: ₱" . number_format($amount, 2);
            if ($auto_corrected) $log_msg .= " | $correction_msg";
            $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
            $log_stmt->bind_param("iis", $user_id, $last_sup_id, $log_msg);
            $log_stmt->execute();

            $conn->commit();

            // If this voucher is being created as part of a staff procurement approval,
            // grant access now and redirect back to user management.
            $for_id = intval($_POST['procurement_for'] ?? 0);
            if ($for_id > 0) {
                $for_q = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = '" . ROLE_STAFF . "' AND procurement_access = '" . PROC_PENDING . "'");
                $for_q->bind_param("i", $for_id); $for_q->execute();
                $for_staff = $for_q->get_result()->fetch_assoc();
                if ($for_staff) {
                    $for_uname   = $for_staff['username'];
                    $admin_uname = $_SESSION['username'] ?? '';

                    $grant = $conn->prepare("UPDATE users SET procurement_access = '" . PROC_APPROVED . "', locked_supplier_id = ? WHERE id = ?");
                    $grant->bind_param("ii", $last_sup_id, $for_id);
                    $grant->execute();

                    $pb = $conn->prepare("INSERT INTO procurement_batches (staff_id, staff_username, approved_by, approved_by_username, approved_at, supplier_id, supplier_name, invoice, status) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, '" . PROC_APPROVED . "')");
                    $pb->bind_param("isisiss", $for_id, $for_uname, $user_id, $admin_uname, $last_sup_id, $name, $invoice_num);
                    $pb->execute();

                    $pal = $conn->prepare("INSERT INTO procurement_access_log (staff_id, staff_username, action, actioned_by, actioned_by_username) VALUES (?, ?, ?, ?, ?)");
                    $proc_status = PROC_APPROVED;
                    $pal->bind_param("isiss", $for_id, $for_uname, $proc_status, $user_id, $admin_uname);
                    $pal->execute();

                    $sf = $conn->prepare("INSERT INTO security_flags (flag_type, severity, reference_id, reference_type, message) VALUES ('" . FLAG_ACCESS_EVENT . "','" . SEV_LOW . "',?,'user',?)");
                    $sf_msg = "Procurement access approved for @{$for_uname}. Voucher #{$invoice_num} ({$name}) assigned by @{$admin_uname}.";
                    $sf->bind_param("is", $for_id, $sf_msg);
                    $sf->execute();

                    $al = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_DELIVERIES . "', ?, ?)");
                    $al_msg = "Procurement access approved for @{$for_uname}: voucher #{$invoice_num} ({$name}) created by @{$admin_uname}.";
                    $al->bind_param("iis", $user_id, $last_sup_id, $al_msg);
                    $al->execute();

                    header("Location: ../users/users.php?success=" . urlencode("Voucher #{$invoice_num} created. @{$for_uname} can now proceed with procurement."));
                    exit();
                }
            }

            $extra = $auto_corrected
                ? "<p class='text-emerald-100 text-xs mt-1 font-normal'>Auto-corrected: $correction_msg</p>"
                : '';
            $msg = "<div class='bg-emerald-500 text-white p-4 rounded-2xl mb-6 font-bold animate-in shadow-lg'>
                        Shipment recorded! Voucher: #$invoice_num &mdash; Supplier ID: #$supplier_code
                        $extra
                    </div>";

        } catch (\Throwable $e) {
            $conn->rollback();
            $msg = "<div class='bg-rose-500 text-white p-4 rounded-2xl mb-6 font-bold'>System Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    // ── DELETE SUPPLY ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'delete_supply') {
        $id = intval($_POST['id']);
        $conn->begin_transaction();
        try {
            $d1 = $conn->prepare("DELETE FROM supplier_payments WHERE supplier_id = ? AND status = '" . SUP_PAY_UNPAID . "'");
            $d1->bind_param("i", $id);
            $d1->execute();
            $d2 = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
            $d2->bind_param("i", $id);
            $d2->execute();
            $conn->commit();
            $msg = "<div class='bg-slate-800 text-white p-4 rounded-2xl mb-6 font-bold'>Shipment and unpaid debt removed.</div>";
        } catch (\Throwable $e) {
            $conn->rollback();
            $msg = "<div class='bg-rose-500 text-white p-4 rounded-2xl mb-6 font-bold'>Deletion failed.</div>";
        }
    }
}

render_page:
include '../layout_top.php';

if (($role ?? '') === ROLE_STAFF && !in_array(basename(__FILE__), $staff_procurement_steps ?? [])) {
    header("Location: ../dashboard.php");
    exit();
}

$suppliers = $conn->query("SELECT * FROM suppliers ORDER BY id DESC");
$next_auto  = generate_next_code($conn);
?>

<div class="max-w-7xl mx-auto space-y-10 animate-in">
    <?= $msg ?>

    <?php if ($procurement_for > 0): ?>
    <div class="bg-emerald-50 border-2 border-emerald-200 rounded-3xl px-8 py-5 flex items-center gap-4">
        <div class="w-10 h-10 bg-emerald-500 rounded-2xl flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <div>
            <p class="font-black text-emerald-800 text-sm uppercase tracking-widest">Creating Supply Voucher for @<?= $procurement_uname ?></p>
            <p class="text-emerald-600 text-xs font-bold mt-0.5">Once saved, this voucher will be automatically assigned and the staff member will gain procurement access.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ENTRY FORM -->
    <div class="card-modern">
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 bg-blue-600 text-white rounded-2xl flex items-center justify-center shadow-lg">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div>
                <h3 class="serif-title text-2xl font-bold text-slate-800">Supply Entry Form</h3>
                <p class="text-slate-400 text-xs uppercase tracking-widest font-bold">Logs automatically reflect in Outgoing Payments</p>
            </div>
        </div>

        <form method="POST" action="" id="supplyForm" class="space-y-5">
            <input type="hidden" name="action"          value="record_supply">
            <input type="hidden" name="id_mode"         id="idModeInput" value="auto">
            <?php if ($procurement_for > 0): ?>
            <input type="hidden" name="procurement_for" value="<?= $procurement_for ?>">
            <?php endif; ?>

            <!-- Row 1: Supplier ID + Mode Toggle -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end">

                <!-- ID Mode Toggle -->
                <div class="md:col-span-3">
                    <label class="label-modern">Supplier ID Mode</label>
                    <div class="flex rounded-2xl border border-slate-200 overflow-hidden bg-slate-50 p-1 gap-1">
                        <button type="button" id="btnAuto"
                                onclick="setIdMode('auto')"
                                class="flex-1 py-2 rounded-xl font-black text-[11px] uppercase tracking-widest transition-all bg-blue-600 text-white shadow">
                            Auto
                        </button>
                        <button type="button" id="btnManual"
                                onclick="setIdMode('manual')"
                                class="flex-1 py-2 rounded-xl font-black text-[11px] uppercase tracking-widest transition-all text-slate-400 hover:bg-slate-100">
                            Manual
                        </button>
                    </div>
                </div>

                <!-- Supplier ID Field -->
                <div class="md:col-span-3">
                    <label class="label-modern">Supplier ID</label>

                    <!-- AUTO: disabled preview -->
                    <div id="autoIdDisplay" class="relative">
                        <input type="text" id="autoIdPreview" disabled
                               value="<?= htmlspecialchars($next_auto) ?>"
                               class="input-modern bg-slate-100 text-slate-400 font-mono font-black cursor-not-allowed">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-black text-blue-400 uppercase">Auto</span>
                        <input type="hidden" name="supplier_code" id="supplierCodeHidden" value="<?= htmlspecialchars($next_auto) ?>">
                    </div>

                    <!-- MANUAL: editable with live feedback -->
                    <div id="manualIdDisplay" class="hidden">
                        <input type="text" id="manualCodeInput" placeholder="e.g. 670"
                               oninput="onCodeInput(this.value)"
                               class="input-modern font-mono font-black">
                        <p id="codeConflictMsg" class="text-[10px] font-bold mt-1 hidden"></p>
                    </div>
                </div>

                <!-- Supplier Name -->
                <div class="md:col-span-6">
                    <label class="label-modern">Supplier Name</label>
                    <div class="relative">
                        <input type="text" name="name" id="supplierName" placeholder="Company Name" required
                               oninput="onNameInput(this.value)"
                               class="input-modern pr-10">
                        <span id="nameCheckIcon" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"></span>
                    </div>
                    <p id="nameMatchMsg" class="text-[10px] font-bold mt-1 text-emerald-600 hidden"></p>
                </div>
            </div>

            <!-- Row 2: Contact + Amount -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
                <div class="md:col-span-6">
                    <label class="label-modern">Reference / Contact</label>
                    <input type="text" name="contact" placeholder="Phone or Ref #" class="input-modern">
                </div>
                <div class="md:col-span-6">
                    <label class="label-modern">Amount to Pay (₱)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" placeholder="0.00" required class="input-modern">
                </div>
            </div>

            <button type="submit" class="btn-pos-primary w-full shadow-lg shadow-blue-200">
                Officialize Shipment &amp; Link to Payments
            </button>
        </form>
    </div>

    <!-- AUDIT LOG -->
    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-xl overflow-hidden">
        <div class="p-6 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
            <h4 class="font-black text-slate-400 text-xs uppercase tracking-[0.2em]">Supply Audit Logs</h4>
            <span class="text-[10px] font-bold text-slate-400"><?= $suppliers->num_rows ?> Entries Found</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table-modern text-left min-w-full">
                <thead>
                    <tr class="bg-slate-50">
                        <th class="px-6 py-4">Voucher No.</th>
                        <th class="px-4 py-4">Supplier ID</th>
                        <th class="px-6 py-4">Supplier Name</th>
                        <th class="px-6 py-4 text-right">Amount</th>
                        <th class="px-6 py-4 text-center">Date</th>
                        <th class="px-6 py-4 text-center">Time</th>
                        <th class="px-6 py-4 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php while ($s = $suppliers->fetch_assoc()): ?>
                    <tr class="hover:bg-blue-50/30 transition-colors">
                        <td class="px-6 py-4 font-mono font-bold text-blue-600 text-xs">
                            #<?= strtoupper(substr($s['invoice_number'], -4)) ?>
                        </td>
                        <td class="px-4 py-4 font-bold text-slate-500">#<?= htmlspecialchars($s['supplier_code']) ?></td>
                        <td class="px-6 py-4 font-bold text-slate-800"><?= htmlspecialchars($s['name']) ?></td>
                        <td class="px-6 py-4 text-right font-black text-slate-900">₱<?= number_format($s['amount'], 2) ?></td>
                        <td class="px-6 py-4 text-center text-slate-500 font-medium"><?= date('Y-m-d', strtotime($s['created_at'])) ?></td>
                        <td class="px-6 py-4 text-center text-slate-400 text-xs"><?= date('H:i', strtotime($s['created_at'])) ?></td>
                        <td class="px-6 py-4 text-right">
                            <form method="POST" onsubmit="confirmForm(event, this, 'This record and all linked unpaid debt will be permanently deleted.', 'Delete Record?')">
                                <input type="hidden" name="id"     value="<?= $s['id'] ?>">
                                <input type="hidden" name="action" value="delete_supply">
                                <button type="submit" class="text-slate-200 hover:text-rose-500 transition-colors">
                                    <svg class="w-5 h-5 ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let currentMode   = 'auto';
let nameTimer     = null;
let codeTimer     = null;
let lastAutoCode  = '<?= htmlspecialchars($next_auto) ?>';

// ── MODE TOGGLE ───────────────────────────────────────────────────────────────
function setIdMode(mode) {
    currentMode = mode;
    document.getElementById('idModeInput').value = mode;

    const btnAuto   = document.getElementById('btnAuto');
    const btnManual = document.getElementById('btnManual');
    const autoDiv   = document.getElementById('autoIdDisplay');
    const manualDiv = document.getElementById('manualIdDisplay');

    if (mode === 'auto') {
        btnAuto.className   = 'flex-1 py-2 rounded-xl font-black text-[11px] uppercase tracking-widest transition-all bg-blue-600 text-white shadow';
        btnManual.className = 'flex-1 py-2 rounded-xl font-black text-[11px] uppercase tracking-widest transition-all text-slate-400 hover:bg-slate-100';
        autoDiv.classList.remove('hidden');
        manualDiv.classList.add('hidden');
        // Refresh auto code in case DB changed
        fetch('suppliers.php?ajax=next_code')
            .then(r => r.json())
            .then(d => {
                lastAutoCode = d.code;
                document.getElementById('autoIdPreview').value    = d.code;
                document.getElementById('supplierCodeHidden').value = d.code;
            });
    } else {
        btnManual.className = 'flex-1 py-2 rounded-xl font-black text-[11px] uppercase tracking-widest transition-all bg-slate-700 text-white shadow';
        btnAuto.className   = 'flex-1 py-2 rounded-xl font-black text-[11px] uppercase tracking-widest transition-all text-slate-400 hover:bg-slate-100';
        autoDiv.classList.add('hidden');
        manualDiv.classList.remove('hidden');
        document.getElementById('manualCodeInput').focus();
    }
}

// ── NAME FIELD: lookup existing supplier code ─────────────────────────────────
function onNameInput(val) {
    clearTimeout(nameTimer);
    const icon    = document.getElementById('nameCheckIcon');
    const matchMsg = document.getElementById('nameMatchMsg');
    icon.classList.add('hidden');
    matchMsg.classList.add('hidden');

    if (val.trim().length < 2) return;

    nameTimer = setTimeout(() => {
        fetch('suppliers.php?ajax=lookup_name&name=' + encodeURIComponent(val.trim()))
            .then(r => r.json())
            .then(d => {
                if (d.code) {
                    // Known supplier found — auto-fill their code
                    if (currentMode === 'auto') {
                        document.getElementById('autoIdPreview').value     = d.code;
                        document.getElementById('supplierCodeHidden').value = d.code;
                    } else {
                        document.getElementById('manualCodeInput').value = d.code;
                        clearCodeFeedback();
                    }
                    icon.textContent = '✓';
                    icon.className   = 'absolute right-3 top-1/2 -translate-y-1/2 text-sm text-emerald-500 font-black';
                    icon.classList.remove('hidden');
                    matchMsg.textContent = 'Known supplier — ID #' + d.code + ' auto-filled.';
                    matchMsg.className   = 'text-[10px] font-bold mt-1 text-emerald-600';
                    matchMsg.classList.remove('hidden');
                } else {
                    // New supplier — restore auto code if in auto mode
                    if (currentMode === 'auto') {
                        document.getElementById('autoIdPreview').value     = lastAutoCode;
                        document.getElementById('supplierCodeHidden').value = lastAutoCode;
                    }
                    icon.classList.add('hidden');
                    matchMsg.classList.add('hidden');
                }
            });
    }, 400);
}

// ── CODE FIELD (manual mode): check for conflicts ─────────────────────────────
function onCodeInput(val) {
    clearTimeout(codeTimer);
    clearCodeFeedback();
    if (val.trim().length < 1) return;

    codeTimer = setTimeout(() => {
        const name = document.getElementById('supplierName').value.trim();
        fetch('suppliers.php?ajax=lookup_code&code=' + encodeURIComponent(val.trim()) + '&name=' + encodeURIComponent(name))
            .then(r => r.json())
            .then(d => {
                const msg = document.getElementById('codeConflictMsg');
                if (d.owner) {
                    msg.textContent = '⚠ #' + val.trim() + ' belongs to "' + d.owner + '". A new ID will be auto-assigned on save.';
                    msg.className   = 'text-[10px] font-bold mt-1 text-amber-500';
                    msg.classList.remove('hidden');
                } else if (val.trim() !== '') {
                    msg.textContent = '✓ ID #' + val.trim() + ' is available.';
                    msg.className   = 'text-[10px] font-bold mt-1 text-emerald-600';
                    msg.classList.remove('hidden');
                }
            });
    }, 400);
}

function clearCodeFeedback() {
    const msg = document.getElementById('codeConflictMsg');
    msg.classList.add('hidden');
}

// ── FORM SUBMIT: wire up manual code to hidden field ──────────────────────────
document.getElementById('supplyForm').addEventListener('submit', function () {
    if (currentMode === 'manual') {
        const manualVal = document.getElementById('manualCodeInput').value.trim();
        // Inject as a standalone input so it gets picked up by $_POST['supplier_code']
        let hidden = document.getElementById('supplierCodeHidden');
        hidden.value = manualVal;
    }
});
</script>

<?php include '../layout_bottom.php'; ?>
