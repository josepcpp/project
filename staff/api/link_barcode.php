<?php
/**
 * link_barcode.php — "Learn on first sale" for sealed-box items.
 *
 * A box-only product (received by box barcode, no per-item barcode yet) gets its
 * individual barcode recorded the first time a unit is scanned at POS:
 *   ?action=search&q=...   → active products still missing a per-item barcode
 *   POST action=record     → link a scanned code to a chosen product
 *
 * Guard: re-typed description must match the product name (2nd verification),
 * the code must not already belong to another product, and it's all audited.
 * Any logged-in account may record (per policy).
 */
include '../../includes/auth_check.php';
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

$uid   = intval($_SESSION['user_id'] ?? 0);
$uname = $_SESSION['username'] ?? 'unknown';
if (!$uid) { echo json_encode(['ok' => false, 'error' => 'Not authenticated.']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Search: active products that still need a per-item barcode ────────────────
if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . $q . '%';
    $st = $conn->prepare(
        "SELECT MIN(id) AS id, name, MAX(box_barcode) AS box_barcode, MAX(box_units) AS box_units,
                SUM(quantity) AS qty
         FROM products
         WHERE status = '" . PRODUCT_ACTIVE . "' AND (barcode IS NULL OR barcode = '')
           AND name LIKE ?
         GROUP BY LOWER(TRIM(name))
         ORDER BY name ASC LIMIT 10"
    );
    $st->bind_param("s", $like);
    $st->execute();
    echo json_encode($st->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
}

// ── Record: link a scanned per-item barcode to the chosen product ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'record') {
    $pid     = intval($_POST['product_id'] ?? 0);
    $barcode = trim($_POST['barcode'] ?? '');
    $confirm = trim($_POST['confirm_name'] ?? '');

    if ($pid < 1 || $barcode === '') { echo json_encode(['ok' => false, 'error' => 'Missing product or barcode.']); exit; }

    $pq = $conn->prepare("SELECT id, name, barcode, box_barcode FROM products WHERE id = ? LIMIT 1");
    $pq->bind_param("i", $pid);
    $pq->execute();
    $p = $pq->get_result()->fetch_assoc();
    if (!$p)                       { echo json_encode(['ok' => false, 'error' => 'Product not found.']); exit; }
    if (!empty($p['barcode']))     { echo json_encode(['ok' => false, 'error' => 'This product already has a per-item barcode.']); exit; }

    // 2nd verification — the re-typed description must match exactly (case/space-insensitive).
    if (strtolower(trim($confirm)) !== strtolower(trim($p['name']))) {
        echo json_encode(['ok' => false, 'error' => 'Description does not match — re-type it exactly to confirm.']); exit;
    }

    // The code must not already belong to another product (as item OR box code).
    $dup = $conn->prepare("SELECT id FROM products WHERE (barcode = ? OR box_barcode = ?) AND id != ? LIMIT 1");
    $dup->bind_param("ssi", $barcode, $barcode, $pid);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        echo json_encode(['ok' => false, 'error' => 'That barcode is already assigned to another product.']); exit;
    }

    $conn->begin_transaction();
    try {
        // Set the per-item barcode across ALL lots of this box-only product.
        if (!empty($p['box_barcode'])) {
            $u = $conn->prepare("UPDATE products SET barcode = ? WHERE box_barcode = ? AND (barcode IS NULL OR barcode = '')");
            $u->bind_param("ss", $barcode, $p['box_barcode']);
        } else {
            $u = $conn->prepare("UPDATE products SET barcode = ? WHERE id = ?");
            $u->bind_param("si", $barcode, $pid);
        }
        $u->execute();

        $msg = "BARCODE LINKED: per-item '$barcode' → \"{$p['name']}\" by $uname";
        $al = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?, '" . LOG_PROCUREMENT . "', ?, ?)");
        $al->bind_param("iis", $uid, $pid, $msg);
        $al->execute();

        $conn->commit();
        echo json_encode(['ok' => true, 'barcode' => $barcode, 'name' => $p['name']]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
