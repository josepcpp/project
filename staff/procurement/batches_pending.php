<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../../includes/require_role.php';
include '../../includes/csrf.php';
require_role([ROLE_ADMIN, ROLE_SUPERADMIN]);

$user_id  = $_SESSION['user_id']  ?? null;
$username = $_SESSION['username'] ?? 'unknown';
$role     = strtolower($_SESSION['role'] ?? '');

$success = trim($_GET['success'] ?? '');
$error   = '';

// ── CREATE VOUCHER ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('batches_pending.php');

    $supplier_name    = trim($_POST['supplier_name']    ?? '');
    $supplier_contact = trim($_POST['supplier_contact'] ?? '');
    $control_subtotal = trim($_POST['control_subtotal'] ?? '');

    if ($supplier_name === '') {
        $error = "Supplier name is required.";
    } elseif (!is_numeric($control_subtotal) || floatval($control_subtotal) <= 0) {
        $error = "Receipt subtotal must be a valid positive amount.";
    }

    if (!$error) {
        $control_subtotal = round(floatval($control_subtotal), 2);

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare(
                "INSERT INTO receiving_batches
                 (supplier_name, supplier_contact, control_subtotal, status, request_created_by, request_created_at, created_at)
                 VALUES (?, ?, ?, 'pending_request', ?, NOW(), NOW())"
            );
            $ins->bind_param("ssdi", $supplier_name, $supplier_contact, $control_subtotal, $user_id);
            $ins->execute();
            $batch_id = $conn->insert_id;

            $al = $conn->prepare("INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action) VALUES (?,?,?,?,'voucher_created')");
            $al->bind_param("iiss", $batch_id, $user_id, $username, $role);
            $al->execute();

            $conn->commit();
            header("Location: batches_pending.php?success=" . urlencode("Voucher #$batch_id created. Receiver can now select and encode items."));
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
// Vouchers awaiting receiver (no receiver assigned yet)
$open_q = $conn->query(
    "SELECT rb.id, rb.supplier_name, rb.supplier_contact, rb.created_at,
            COUNT(ri.id) AS item_count
     FROM receiving_batches rb
     LEFT JOIN receiving_items ri ON ri.batch_id = rb.id
     WHERE rb.status = 'pending_request' AND rb.receiver_id IS NULL
     GROUP BY rb.id
     ORDER BY rb.created_at ASC"
);

// Vouchers being encoded by a receiver
$inprog_q = $conn->query(
    "SELECT rb.id, rb.supplier_name, rb.receiver_username, rb.created_at,
            COUNT(ri.id) AS item_count
     FROM receiving_batches rb
     LEFT JOIN receiving_items ri ON ri.batch_id = rb.id
     WHERE rb.status = 'pending_request' AND rb.receiver_id IS NOT NULL
     GROUP BY rb.id
     ORDER BY rb.created_at ASC"
);

include '../layout_top.php';
?>

<div class="max-w-4xl mx-auto space-y-8">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl px-5 py-4 text-sm font-bold"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Create Voucher Form -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-1">Create Receiving Voucher</h3>
        <p class="text-slate-400 text-sm font-bold mb-6">Enter the supplier details and the invoice subtotal. The Receiver will encode the items against this voucher.</p>
        <form id="voucher-form" method="POST" class="space-y-5">
            <?= csrf_field() ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="label-modern">Supplier Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="supplier_name" required maxlength="255"
                           class="input-modern" placeholder="e.g. ABC Trading Co.">
                </div>
                <div>
                    <label class="label-modern">Supplier Contact</label>
                    <input type="text" name="supplier_contact" maxlength="255"
                           class="input-modern" placeholder="Phone / email (optional)">
                </div>
            </div>
            <div class="max-w-xs">
                <label class="label-modern">Receipt / Invoice Subtotal <span class="text-rose-500">*</span></label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-black">₱</span>
                    <input type="number" name="control_subtotal" required min="0.01" step="0.01"
                           class="input-modern pl-8" placeholder="0.00">
                </div>
                <p class="text-[10px] text-rose-500 font-bold mt-1 uppercase tracking-wider">
                    Stored securely — never shown to the Receiver.
                </p>
            </div>
            <button type="button" onclick="openVoucherConfirm()" class="btn-pos-primary px-10 py-3 text-sm font-black uppercase tracking-widest">
                Create Voucher
            </button>
        </form>
    </div>

    <!-- Open Vouchers (awaiting Receiver) -->
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">
            Open Vouchers
            <span class="text-slate-400 font-bold text-sm ml-2">— awaiting Receiver</span>
        </h3>
        <?php if (!$open_q || $open_q->num_rows === 0): ?>
            <p class="text-slate-400 text-sm font-bold text-center py-6">No open vouchers. Create one above.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Voucher #</th>
                        <th>Supplier</th>
                        <th>Contact</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($b = $open_q->fetch_assoc()): ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $b['id'] ?></td>
                        <td class="font-bold"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
                        <td class="text-slate-400"><?= htmlspecialchars($b['supplier_contact'] ?? '—') ?></td>
                        <td class="text-slate-400 text-xs"><?= date('M j, Y g:i A', strtotime($b['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- In-Progress Vouchers (Receiver encoding) -->
    <?php if ($inprog_q && $inprog_q->num_rows > 0): ?>
    <div class="card-modern p-8">
        <h3 class="serif-title text-xl font-black text-slate-800 mb-6">
            Being Encoded
            <span class="text-slate-400 font-bold text-sm ml-2">— Receiver working on these</span>
        </h3>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-sm">
                <thead>
                    <tr>
                        <th>Voucher #</th>
                        <th>Supplier</th>
                        <th>Receiver</th>
                        <th>Items</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($b = $inprog_q->fetch_assoc()): ?>
                    <tr>
                        <td class="font-black text-slate-500">#<?= $b['id'] ?></td>
                        <td class="font-bold"><?= htmlspecialchars($b['supplier_name'] ?? '—') ?></td>
                        <td class="text-slate-500">@<?= htmlspecialchars($b['receiver_username'] ?? '—') ?></td>
                        <td class="text-center font-black text-slate-600"><?= intval($b['item_count']) ?></td>
                        <td class="text-slate-400 text-xs"><?= date('M j, Y g:i A', strtotime($b['created_at'])) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── Confirm Voucher Modal ──────────────────────────────────────────────── -->
<div id="voucher-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm hidden">
    <div class="bg-white rounded-[2.5rem] shadow-2xl p-8 w-full max-w-md mx-4 animate-in">
        <h4 class="serif-title text-2xl font-black text-slate-800 mb-1">Are the details correct?</h4>
        <p class="text-slate-400 text-sm font-bold mb-6">Please review carefully — the subtotal is never shown to the Receiver.</p>

        <div class="space-y-1 mb-6 bg-slate-50 rounded-2xl p-5">
            <div class="flex justify-between items-center py-2 border-b border-slate-100">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Supplier Name</span>
                <span id="vc-name" class="font-black text-slate-800 text-right"></span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-slate-100">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Contact Details</span>
                <span id="vc-contact" class="font-bold text-slate-600 text-right"></span>
            </div>
            <div class="flex justify-between items-center py-2">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Receipt Subtotal</span>
                <span id="vc-subtotal" class="font-black text-emerald-600 text-lg text-right"></span>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="button" onclick="closeVoucherConfirm()"
                class="flex-1 border border-slate-200 text-slate-500 font-black text-[10px] uppercase tracking-widest py-3.5 rounded-2xl hover:bg-slate-50 transition-all">
                Cancel
            </button>
            <button type="button" id="vc-yes" onclick="submitVoucher()" disabled
                class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white font-black text-[10px] uppercase tracking-widest py-3.5 rounded-2xl transition-all shadow-lg active:scale-95 opacity-50 cursor-not-allowed">
                Please wait… 3s
            </button>
        </div>
    </div>
</div>

<script>
let _vcTimer = null;

function openVoucherConfirm() {
    const form = document.getElementById('voucher-form');
    if (!form.reportValidity()) return;   // native required / min validation first

    const name     = form.querySelector('[name="supplier_name"]').value.trim();
    const contact  = form.querySelector('[name="supplier_contact"]').value.trim();
    const subtotal = parseFloat(form.querySelector('[name="control_subtotal"]').value || 0);

    document.getElementById('vc-name').textContent     = name || '—';
    document.getElementById('vc-contact').textContent  = contact || '—';
    document.getElementById('vc-subtotal').textContent = '₱' + subtotal.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    document.getElementById('voucher-modal').classList.remove('hidden');

    // 3-second countdown before "Yes" is clickable
    const yes = document.getElementById('vc-yes');
    let secs = 3;
    yes.disabled = true;
    yes.classList.add('opacity-50', 'cursor-not-allowed');
    yes.textContent = 'Please wait… ' + secs + 's';

    clearInterval(_vcTimer);
    _vcTimer = setInterval(function () {
        secs--;
        if (secs > 0) {
            yes.textContent = 'Please wait… ' + secs + 's';
        } else {
            clearInterval(_vcTimer);
            yes.disabled = false;
            yes.classList.remove('opacity-50', 'cursor-not-allowed');
            yes.textContent = 'Yes, Create Voucher';
        }
    }, 1000);
}

function closeVoucherConfirm() {
    clearInterval(_vcTimer);
    document.getElementById('voucher-modal').classList.add('hidden');
}

function submitVoucher() {
    if (document.getElementById('vc-yes').disabled) return;
    document.getElementById('voucher-form').submit();
}

// Close when clicking the backdrop
document.getElementById('voucher-modal').addEventListener('click', function (e) {
    if (e.target === this) closeVoucherConfirm();
});
</script>

<?php include '../layout_bottom.php'; ?>
