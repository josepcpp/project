<?php 
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$user_id = $_SESSION['user_id'] ?? null;
$msg = ""; 

// Auto-archive zero-stock products on every load so POS never sells phantom stock
$conn->query("UPDATE products SET status = '" . PRODUCT_ARCHIVED . "', archived_at = IF(archived_at IS NULL, NOW(), archived_at) WHERE quantity <= 0 AND status != '" . PRODUCT_ARCHIVED . "'");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save_product') {
        $name = trim($_POST['name']);
        $barcode = trim($_POST['barcode']);
        $qty = intval($_POST['quantity']);
        $price = floatval($_POST['price']);
        $sup_id = intval($_POST['supplier_id']);
        $category = $_POST['category'] ?? 'General';
        $force_mode = $_POST['force_mode'] ?? '';

        $check = $conn->prepare("SELECT barcode FROM products WHERE LOWER(TRIM(name)) = LOWER(?) ORDER BY id ASC LIMIT 1");
        $check->bind_param("s", $name);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();

        if ($existing && $force_mode !== 'dup') {
            $barcode  = $existing['barcode'];
            $msg_text = "Stock added to existing Product Master ($barcode)";
        } else {
            if (empty($barcode)) $barcode = "628" . substr(time(), -7);
            if ($force_mode === 'dup') $barcode .= "-DUP";
            $msg_text = "New Product Registered ($barcode)";
        }

        $stmt = $conn->prepare("INSERT INTO products (name, barcode, quantity, price, supplier_id, category, status)
                               VALUES (?, ?, ?, ?, ?, ?, '" . PRODUCT_ACTIVE . "')
                               ON DUPLICATE KEY UPDATE
                               quantity = quantity + VALUES(quantity),
                               price = VALUES(price),
                               status = '" . PRODUCT_ACTIVE . "'");
        $stmt->bind_param("ssdiss", $name, $barcode, $qty, $price, $sup_id, $category);
        
        if ($stmt->execute()) {
            $msg = "<div class='bg-emerald-500 text-white p-4 rounded-2xl mb-6 font-bold shadow-lg animate-in text-center'>$msg_text</div>";
        }
    }

    if ($action === 'archive_product') {
        $name = $_POST['name'];
        $stmt = $conn->prepare("UPDATE products SET status = '" . PRODUCT_ARCHIVED . "', archived_at = IF(archived_at IS NULL, NOW(), archived_at) WHERE name = ?");
        $stmt->bind_param("s", $name);
        if ($stmt->execute()) {
            $msg = "<div class='bg-slate-800 text-white p-4 rounded-2xl mb-6 font-bold shadow-lg text-center'>Product stack moved to Archive.</div>";
        }
    }
}

include '../layout_top.php';

$sql = "SELECT MIN(id) as id, name, MIN(barcode) as barcode, SUM(quantity) as quantity, MAX(price) as price, MAX(category) as category
        FROM products WHERE status = '" . PRODUCT_ACTIVE . "' GROUP BY LOWER(TRIM(name)) ORDER BY name ASC";
$result = $conn->query($sql);
?>

<div class="max-w-7xl mx-auto space-y-10 animate-in">
    <?= $msg ?>

    <!-- ➕ UNIFIED ENCODING CARD -->
    <div class="card-modern">
        <h3 class="serif-title text-2xl font-bold mb-6">Inventory Management</h3>
        <form id="productForm" method="POST" action="">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="force_mode" id="force_mode" value="">
            
            <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
                <div class="md:col-span-4">
                    <label class="label-modern">Supply Invoice Source</label>
                    <select name="supplier_id" id="p_supplier" required class="input-modern">
                        <option value="">-- Choose Entry --</option>
                        <?php 
                        $sups = $conn->query("SELECT id, name, invoice_number FROM suppliers ORDER BY id DESC");
                        while($s = $sups->fetch_assoc()) echo "<option value='{$s['id']}'>{$s['invoice_number']} (".htmlspecialchars($s['name']).")</option>"; 
                        ?>
                    </select>
                </div>
                <div class="md:col-span-4">
                    <label class="label-modern">Product Name</label>
                    <input type="text" name="name" id="p_name" required class="input-modern" placeholder="Exact name">
                </div>
                <div class="md:col-span-4">
                    <label class="label-modern">Barcode</label>
                    <input type="text" name="barcode" id="p_barcode" class="input-modern" placeholder="Optional">
                </div>
                <div class="md:col-span-3">
                    <label class="label-modern">Unit Price</label>
                    <input type="number" step="0.01" name="price" required class="input-modern">
                </div>
                <div class="md:col-span-3">
                    <label class="label-modern">Add Stock</label>
                    <input type="number" name="quantity" required class="input-modern">
                </div>
                <div class="md:col-span-6">
                    <label class="label-modern">Category</label>
                    <select name="category" class="input-modern">
                        <?php foreach (PRODUCT_CATEGORIES as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="md:col-span-12">
                    <button type="button" onclick="startProductCheck()" class="btn-pos-primary w-full">Save to Inventory</button>
                </div>
            </div>
        </form>
    </div>

    <!-- 📊 STOCK LEVELS -->
    <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm overflow-hidden mb-20">
        <table class="table-modern text-left border-collapse">
            <thead>
                <tr class="bg-slate-50/30">
                    <th class="px-10 py-6">Product Master</th>
                    <th class="px-6 py-6 text-center">Total Stock</th>
                    <th class="px-6 py-6 text-center">Price</th>
                    <th class="px-10 py-6 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php while ($p = $result->fetch_assoc()): ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-10 py-8">
                        <p class="font-bold text-slate-800 text-lg"><?= htmlspecialchars($p['name']) ?></p>
                        <code class="text-[10px] text-emerald-600 font-bold bg-emerald-50 px-2 py-0.5 rounded"><?= $p['barcode'] ?></code>
                    </td>
                    <td class="px-6 py-8 text-center"><span class="text-xl font-black text-slate-700"><?= number_format($p['quantity']) ?></span></td>
                    <td class="px-6 py-8 text-center"><span class="text-lg font-bold text-emerald-500">₱<?= number_format($p['price'], 2) ?></span></td>
                    <td class="px-10 py-8 text-right">
                        <form method="POST" action="" onsubmit="confirmForm(event, this, 'This product will be archived and hidden from the POS.', 'Archive Product?')">
                             <input type="hidden" name="action" value="archive_product">
                             <input type="hidden" name="name" value="<?= htmlspecialchars($p['name']) ?>">
                             <button type="submit" class="text-slate-300 hover:text-amber-600 text-xs font-bold uppercase tracking-widest">Archive</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 🤖 DUPLICATE HANDLER MODAL -->
<div id="dupModal" class="fixed inset-0 z-[200] hidden flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/80 backdrop-blur-sm"></div>
    <div class="relative bg-white w-full max-w-md rounded-[2.5rem] p-10 shadow-2xl animate-in">
        <div class="text-center">
            <h3 class="serif-title text-2xl font-bold text-slate-800 mb-2">Barcode Conflict</h3>
            <p class="text-slate-500 text-sm mb-8 leading-relaxed">This name exists with a different barcode. How should we save this?</p>
            <div class="space-y-3">
                <button onclick="resolveDup('old')" class="w-full py-4 bg-emerald-500 text-white font-bold rounded-2xl hover:bg-emerald-600 transition-all">Use Original Barcode</button>
                <button onclick="resolveDup('dup')" class="w-full py-4 bg-slate-100 text-slate-600 font-bold rounded-2xl hover:bg-slate-200 transition-all">Add as New with "-DUP"</button>
                <button onclick="closeDupModal()" class="w-full py-2 text-slate-400 text-xs font-bold uppercase mt-4">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
async function startProductCheck() {
    const name = document.getElementById('p_name').value;
    const barcode = document.getElementById('p_barcode').value;
    if (!name || !barcode) { alert("Fill in Name and Barcode"); return; }

    const response = await fetch(`api/check_duplicate.php?name=${encodeURIComponent(name)}&barcode=${encodeURIComponent(barcode)}`);
    const data = await response.json();

    if (data.exists) {
        document.getElementById('dupModal').classList.remove('hidden');
    } else {
        finalizeSubmission();
    }
}

function resolveDup(choice) {
    document.getElementById('force_mode').value = choice;
    document.getElementById('dupModal').classList.add('hidden');
    finalizeSubmission();
}

function finalizeSubmission() {
    const form = document.getElementById('productForm');
    const formData = new FormData(form);
    navigate('inventory.php', formData);
}

function closeDupModal() { document.getElementById('dupModal').classList.add('hidden'); }
</script>

<?php include '../layout_bottom.php'; ?>