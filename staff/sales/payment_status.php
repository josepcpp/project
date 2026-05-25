<?php
include '../../config/db.php';
include '../layout_top.php';

$result = null;

if (isset($_GET['invoice'])) {
    $inv = $_GET['invoice'];

    $stmt = $conn->prepare("
      SELECT invoice_no, amount, status, payment_date
      FROM supplier_payments
      WHERE invoice_no = ?
    ");
    $stmt->bind_param("s",$inv);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
}
?>

<h1 class="page-title">Check Payment Status</h1>

<div class="card">
<form method="GET">
  <input name="invoice" placeholder="Enter Invoice Number" required>
  <button class="btn">Search</button>
</form>
</div>

<?php if ($result): ?>
<div class="card">
  <p><strong>Invoice:</strong> <?= $result['invoice_no'] ?></p>
  <p><strong>Amount:</strong> ₱<?= number_format($result['amount'],2) ?></p>
  <p><strong>Status:</strong> <?= $result['status'] ?></p>
  <p><strong>Date:</strong> <?= $result['payment_date'] ?></p>
</div>
<?php elseif (isset($_GET['invoice'])): ?>
<p>No record found.</p>
<?php endif; ?>

<?php include '../layout_bottom.php'; ?>
