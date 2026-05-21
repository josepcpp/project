    <?php
include '../includes/auth_check.php';
include '../config/db.php';

if (isset($_POST['invoice_no'])) {
    $invoice_no  = trim($_POST['invoice_no']);
    $supplier_id = intval($_POST['supplier_id']);
    $amount      = floatval($_POST['amount']);
    $status      = $_POST['status'];

    $dup = $conn->prepare("SELECT id FROM supplier_payments WHERE invoice_no = ?");
    $dup->bind_param("s", $invoice_no);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        header("Location: supplier_payments.php?error=" . urlencode("Invoice #$invoice_no already exists."));
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO supplier_payments (supplier_id, invoice_no, amount, status) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isds", $supplier_id, $invoice_no, $amount, $status);
    $stmt->execute();
}

if (isset($_POST['toggle'])) {
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("UPDATE supplier_payments SET status = IF(status='" . SUP_PAY_PAID . "','" . SUP_PAY_UNPAID . "','" . SUP_PAY_PAID . "') WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

header("Location: supplier_payments.php");
exit();
