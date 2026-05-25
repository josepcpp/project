<?php
include '../../includes/auth_check.php';
include '../../config/db.php';

// ── DATA ──────────────────────────────────────────────────────────────────────
$id = intval($_GET['id'] ?? 0);

$dq = $conn->prepare("SELECT d.*, s.name AS supplier FROM deliveries d JOIN suppliers s ON s.id = d.supplier_id WHERE d.id = ?");
$dq->bind_param("i", $id);
$dq->execute();
$delivery = $dq->get_result()->fetch_assoc();

if (!$delivery) {
    header("Location: deliveries.php");
    exit();
}

$iq = $conn->prepare("SELECT di.*, p.name FROM delivery_items di JOIN products p ON p.id = di.product_id WHERE di.delivery_id = ?");
$iq->bind_param("i", $id);
$iq->execute();
$items = $iq->get_result();

include '../layout_top.php';
?>

<div class="max-w-4xl mx-auto pb-20 animate-in space-y-6">
    <div class="card-modern">
        <h2 class="serif-title text-2xl font-bold text-slate-800 mb-4">Delivery Details</h2>
        <p class="text-slate-600"><strong>Supplier:</strong> <?= htmlspecialchars($delivery['supplier']) ?></p>
        <p class="text-slate-600"><strong>Date:</strong> <?= htmlspecialchars($delivery['delivery_date']) ?></p>
        <p class="text-slate-600"><strong>Status:</strong> <?= htmlspecialchars($delivery['status']) ?></p>
    </div>

    <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm overflow-hidden">
        <table class="table-modern w-full text-left">
            <thead>
                <tr>
                    <th class="px-8 py-5">Product</th>
                    <th class="px-6 py-5 text-center">Qty Received</th>
                    <th class="px-6 py-5 text-right">Price on Receipt</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php while ($item = $items->fetch_assoc()): ?>
                <tr>
                    <td class="px-8 py-4 font-bold text-slate-700"><?= htmlspecialchars($item['name']) ?></td>
                    <td class="px-6 py-4 text-center"><?= intval($item['delivered_qty']) ?></td>
                    <td class="px-6 py-4 text-right">₱<?= number_format($item['delivered_price'], 2) ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <?php if ($delivery['status'] === DEL_PENDING): ?>
    <form method="POST" action="delivery_process.php">
        <input type="hidden" name="delivery_id" value="<?= $id ?>">
        <button name="action" value="verify"
            onclick="confirmAction('This will add the items to inventory.', function(){ this.form.submit(); }.bind(this), 'Verify &amp; Add?'); return false;"
            class="btn-pos-primary w-full shadow-lg">
            Verify &amp; Add to Inventory
        </button>
    </form>
    <?php endif; ?>
</div>

<?php include '../layout_bottom.php'; ?>
