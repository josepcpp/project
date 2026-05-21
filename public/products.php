<?php
include '../config/db.php';
include '../includes/header.php';

$stmt = $conn->prepare("SELECT * FROM products WHERE status = ?");
$stmt->bind_param("s", PRODUCT_ACTIVE);
$stmt->execute();
$result = $stmt->get_result();
?>

<section class="products">
    <h2>Available Products</h2>

    <div class="product-grid">
        <?php while ($row = $result->fetch_assoc()): ?>
            <div class="product-card">
                <h3><?= htmlspecialchars($row['name']) ?></h3>
                <p>₱<?= number_format($row['price'], 2) ?></p>
                <p class="<?= $row['quantity'] > 0 ? 'in-stock' : 'out-stock' ?>">
                    <?= $row['quantity'] > 0 ? 'In Stock' : 'Out of Stock' ?>
                </p>
            </div>
        <?php endwhile; ?>
    </div>
</section>

<?php include '../includes/footer.php'; ?>
