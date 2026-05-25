<?php
include '../../config/db.php';

// ── DATA ──────────────────────────────────────────────────────────────────────
$history = $conn->query("
    SELECT ph.*, p.name
      FROM price_history ph
      JOIN products p ON ph.product_id = p.id
     ORDER BY ph.id DESC
");

include '../layout_top.php';
?>

<div class="max-w-6xl mx-auto pb-20 animate-in">
    <div class="card-modern">
        <h3 class="serif-title text-3xl font-bold mb-8">Price Adjustment Logs</h3>
        <table class="table-modern">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Old Price</th>
                    <th>New Price</th>
                    <th>Change Date</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($h = $history->fetch_assoc()): ?>
                    <tr>
                        <td class="font-bold"><?= htmlspecialchars($h['name']) ?></td>
                        <td class="line-through text-slate-400">₱<?= number_format($h['old_price'], 2) ?></td>
                        <td class="text-emerald-600 font-black">₱<?= number_format($h['new_price'], 2) ?></td>
                        <td class="text-slate-400 text-sm"><?= date('M d, Y | h:i A', strtotime($h['change_date'])) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../layout_bottom.php'; ?>
