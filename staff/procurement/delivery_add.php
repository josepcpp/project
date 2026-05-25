<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../layout_top.php';

if (!isset($_SESSION['delivery_cart'])) {
    $_SESSION['delivery_cart'] = [];
}
$suppliers = $conn->query("SELECT id, name FROM suppliers");
/* ADD PRODUCT BY BARCODE */
if (isset($_POST['barcode'])) {
    $barcode = trim($_POST['barcode']);
    
    $stmt = $conn->prepare("SELECT id,name FROM products WHERE barcode=?");
    $stmt->bind_param("s",$barcode);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows) {
        $p = $res->fetch_assoc();
        $pid = $p['id'];

        if (isset($_POST['supplier_id'])) {
    $_SESSION['delivery_supplier'] = $_POST['supplier_id'];
}


        $_SESSION['delivery_cart'][$pid] = [
            'name'  => $p['name'],
            'qty'   => $_POST['qty'],
            'price' => $_POST['price']
        ];
    }
}
?>

<h1 class="page-title">Add Delivery</h1>

<div class="card">
<form method="POST">
  <label>Supplier</label>
  <select name="supplier_id" required>
    <option value="">-- Select Supplier --</option>
    <?php while($s = $suppliers->fetch_assoc()): ?>
      <option value="<?= $s['id'] ?>"><?= $s['name'] ?></option>
    <?php endwhile; ?>
  </select>

  <input name="barcode" placeholder="Scan product barcode" autofocus required>
  <input type="number" name="qty" placeholder="Delivered Quantity" required>
  <input type="number" name="price" step="0.01" placeholder="Price on Receipt" required>
  <button class="btn">Add Item</button>
</form>

</div>

<table class="table">
<tr>
  <th>Product</th>
  <th>Qty</th>
  <th>Price</th>
</tr>

<?php foreach($_SESSION['delivery_cart'] as $i): ?>
<tr>
  <td><?= $i['name'] ?></td>
  <td><?= $i['qty'] ?></td>
  <td>₱<?= number_format($i['price'],2) ?></td>
</tr>
<?php endforeach; ?>

<?php if (!$_SESSION['delivery_cart']): ?>
<tr><td colspan="3" align="center">No items added</td></tr>
<?php endif; ?>
</table>

<form method="POST" action="delivery_save.php">
  <button class="btn">Save Delivery</button>
</form>

<?php include '../layout_bottom.php'; ?>
