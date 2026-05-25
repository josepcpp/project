<?php
include '../../includes/auth_check.php';
include '../../config/db.php';

// ── DATA ──────────────────────────────────────────────────────────────────────
$result = $conn->query("
    SELECT d.delivery_date,
           p.name AS product,
           di.delivered_qty,
           di.delivered_price,
           (di.delivered_qty * di.delivered_price) AS total_cost
      FROM delivery_items di
      JOIN deliveries d ON d.id = di.delivery_id
      JOIN products p ON p.id = di.product_id
     ORDER BY d.delivery_date DESC
");

include '../layout_top.php';
?>

<div class="max-w-6xl mx-auto pb-20 animate-in">
    <div class="card-modern">
        <h2 class="serif-title text-2xl font-bold text-slate-800 mb-6">Delivery Cost Report</h2>
        <div class="overflow-x-auto">
            <table class="table-modern w-full text-left">
                <thead>
                    <tr>
                        <th class="px-6 py-5">Date</th>
                        <th class="px-6 py-5">Product</th>
                        <th class="px-6 py-5 text-center">Qty</th>
                        <th class="px-6 py-5 text-right">Delivery Cost</th>
                        <th class="px-6 py-5 text-right">Total Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td class="px-6 py-4 text-slate-600"><?= htmlspecialchars($row['delivery_date']) ?></td>
                        <td class="px-6 py-4 font-bold text-slate-800"><?= htmlspecialchars($row['product']) ?></td>
                        <td class="px-6 py-4 text-center"><?= intval($row['delivered_qty']) ?></td>
                        <td class="px-6 py-4 text-right">₱<?= number_format($row['delivered_price'], 2) ?></td>
                        <td class="px-6 py-4 text-right font-black text-emerald-600">₱<?= number_format($row['total_cost'], 2) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
