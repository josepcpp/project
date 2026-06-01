<?php
include '../../includes/auth_check.php';
include '../../config/db.php';
include '../layout_top.php';

/* ==========================
   SEARCH & FILTER LOGIC
========================== */
$search   = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';

$sql = "SELECT * FROM products WHERE 1";
$params = [];
$types  = '';

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR barcode LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= 'ss';
}

if ($category !== '') {
    $sql .= " AND category = ?";
    $params[] = $category;
    $types .= 's';
}

$sql .= " ORDER BY name ASC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<h1 class="page-title">Product Maintenance</h1>

<div class="card-box">
<!-- ➕ ADD / UPDATE PRODUCT -->
<form method="POST" action="products_process.php" class="grid grid-cols-1 md:grid-cols-6 gap-4 mb-6">

  <input
    type="text"
    name="name"
    placeholder="Product Name"
    required
    class="border rounded-lg px-3 py-2
           focus:ring-2 focus:ring-primary transition"
  >

  <input
    type="text"
    name="barcode"
    placeholder="Barcode"
    required
    class="border rounded-lg px-3 py-2
           focus:ring-2 focus:ring-primary transition"
  >

  <select
    name="category"
    class="border rounded-lg px-3 py-2
           focus:ring-2 focus:ring-primary transition">
    <?php foreach (PRODUCT_CATEGORIES as $val => $label): ?>
    <option value="<?= $val ?>"><?= $label ?></option>
    <?php endforeach; ?>
  </select>

  <input
    type="number"
    step="0.01"
    name="price"
    placeholder="Selling Price"
    required
    class="border rounded-lg px-3 py-2
           focus:ring-2 focus:ring-primary transition"
  >

  <input
    type="number"
    name="quantity"
    placeholder="Qty"
    required
    class="border rounded-lg px-3 py-2
           focus:ring-2 focus:ring-primary transition"
  >

  <button
    type="submit"
    class="bg-primary text-white rounded-lg px-4 py-2
           hover:scale-105 transition">
    Save
  </button>

</form>

  <!-- 🔍 SEARCH & CATEGORY FILTER -->
  <form method="GET" class="flex flex-wrap gap-3 mb-4">
    <input
      type="text"
      name="search"
      data-live="#prodRows"
      placeholder="Search name…"
      value="<?= htmlspecialchars($search) ?>"
      class="border rounded-lg px-4 py-2 w-64
             focus:ring-2 focus:ring-primary transition"
    >

    <select
      name="category"
      class="border rounded-lg px-4 py-2
             focus:ring-2 focus:ring-primary transition">
      <option value="">All Categories</option>
      <?php foreach (PRODUCT_CATEGORIES as $val => $label): ?>
      <option value="<?= $val ?>" <?= $category===$val?'selected':'' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>

    <button
      class="bg-primary text-white px-4 py-2 rounded-lg
             hover:scale-105 transition">
      Filter
    </button>
  </form>

  <hr class="mb-4">

  <!-- 📦 PRODUCT TABLE -->
  <div class="overflow-x-auto">
    <table class="table w-full">
      <thead>
      <tr>
        <th>Name</th>
        <th>Barcode</th>
        <th>Category</th>
        <th>Qty</th>
        <th>Price</th>
        <th>Action</th>
      </tr>
      </thead>
      <tbody id="prodRows">

      <?php while ($p = $result->fetch_assoc()): ?>
      <tr class="hover:bg-slate-50 transition">
        <td class="live-name"><?= htmlspecialchars($p['name']) ?></td>
        <td><?= htmlspecialchars($p['barcode']) ?></td>
        <td><?= htmlspecialchars($p['category']) ?></td>
        <td><?= $p['quantity'] ?></td>
        <td>₱<?= number_format($p['price'], 2) ?></td>
        <td>
          <form method="POST" action="products_process.php">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button
              name="delete"
              class="btn danger"
              onclick="confirmForm(event, this.closest('form'), 'This product will be permanently deleted.', 'Delete Product?'); return false;">
              Delete
            </button>
          </form>
        </td>
      </tr>
      <?php endwhile; ?>

      <?php if ($result->num_rows === 0): ?>
      <tr>
        <td colspan="6" class="text-center text-slate-500 py-4">
          No products found.
        </td>
      </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<?php include '../layout_bottom.php'; ?>
