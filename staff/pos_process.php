<?php
include '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid = intval($_POST['id'] ?? 0);
    $barcode = $_POST['barcode'] ?? '';

    // 1. Identify Product ID
    if ($barcode && !$pid) {
        $p_query = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
        $p_query->bind_param("s", $barcode);
        $p_query->execute();
        $res = $p_query->get_result()->fetch_assoc();
        $pid = $res['id'] ?? 0;
    }

    if ($pid > 0) {
        // Fetch full product rules (Wholesale Tiers)
        $product_query = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $product_query->bind_param("i", $pid);
        $product_query->execute();
        $product = $product_query->get_result()->fetch_assoc();

        if ($product) {
            // Effective sellable qty = total minus units locked pending a price approval
            $lq_q = $conn->prepare("SELECT COALESCE(locked_qty,0) AS lq FROM price_update_requests WHERE product_id = ? AND status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "') LIMIT 1");
            $lq_q->bind_param("i", $pid); $lq_q->execute();
            $locked_qty    = intval($lq_q->get_result()->fetch_assoc()['lq'] ?? 0);
            $effective_qty = max(0, intval($product['quantity']) - $locked_qty);

            // Initialize item in cart if not exists
            if (!isset($_SESSION['cart'][$pid])) {
                $_SESSION['cart'][$pid] = [
                    'name' => $product['name'],
                    'qty' => 0
                ];
            }

            // 2. Handle Quantity Actions
            if ($action === 'add' || $action === 'plus') {
                if ($_SESSION['cart'][$pid]['qty'] < $effective_qty) {
                    $_SESSION['cart'][$pid]['qty']++;
                }
            }
            elseif ($action === 'bulk_set') {
                $qty_to_set = intval($_POST['qty_override'] ?? 1);
                $_SESSION['cart'][$pid]['qty'] = min($qty_to_set, $effective_qty);
            }
            elseif ($action === 'bulk_add') {
                $add_qty = max(1, intval($_POST['qty_override'] ?? 1));
                $current = intval($_SESSION['cart'][$pid]['qty'] ?? 0);
                $_SESSION['cart'][$pid]['qty'] = min($current + $add_qty, $effective_qty);
            }
            elseif ($action === 'set_qty') {
                $new_qty = max(0, intval($_POST['qty'] ?? 0));
                if ($new_qty === 0) {
                    unset($_SESSION['cart'][$pid]);
                } else {
                    $_SESSION['cart'][$pid]['qty'] = min($new_qty, $effective_qty);
                }
            }
            elseif ($action === 'minus') {
                if ($_SESSION['cart'][$pid]['qty'] > 1) {
                    $_SESSION['cart'][$pid]['qty']--;
                } else {
                    unset($_SESSION['cart'][$pid]);
                }
            } 
            elseif ($action === 'remove') {
                unset($_SESSION['cart'][$pid]);
            }

            // 3. 🎯 THE FIXED MATH (Package + Extra Pieces)
            if (isset($_SESSION['cart'][$pid])) {
                $qty = $_SESSION['cart'][$pid]['qty'];
                $retail_price = floatval($product['price']);
                
                $line_total = 0;
                $status_note = "Retail Price";
                $is_bulk = false;

                // Priority 1: Full Box Threshold met?
                if ($product['bulk_qty_full'] > 0 && $qty >= $product['bulk_qty_full']) {
                    $extra_pcs = $qty - $product['bulk_qty_full'];
                    $line_total = floatval($product['price_full_box']) + ($extra_pcs * $retail_price);
                    $status_note = "Full Box" . ($extra_pcs > 0 ? " + $extra_pcs pcs" : "");
                    $is_bulk = true;
                } 
                // Priority 2: Half Box Threshold met?
                elseif ($product['bulk_qty_half'] > 0 && $qty >= $product['bulk_qty_half']) {
                    $extra_pcs = $qty - $product['bulk_qty_half'];
                    $line_total = floatval($product['price_half_box']) + ($extra_pcs * $retail_price);
                    $status_note = "½ Box" . ($extra_pcs > 0 ? " + $extra_pcs pcs" : "");
                    $is_bulk = true;
                } 
                // Standard Retail
                else {
                    $line_total = $qty * $retail_price;
                }

                // Store exact values in session
                $_SESSION['cart'][$pid]['line_total'] = $line_total; // EXACT TOTAL
                $_SESSION['cart'][$pid]['price'] = $line_total / $qty; // Effective unit price for receipt fallback
                $_SESSION['cart'][$pid]['is_bulk'] = $is_bulk;
                $_SESSION['cart'][$pid]['bulk_note'] = $status_note;
            }
        }
    }
}

// Return JSON cart snapshot for in-place DOM update (no page reload)
header('Content-Type: application/json');

$cart_rows = [];
$subtotal  = 0;
foreach ($_SESSION['cart'] as $id => $item) {
    $lt           = floatval($item['line_total'] ?? 0);
    $subtotal    += $lt;
    $cart_rows[]  = [
        'id'        => (int)$id,
        'name'      => $item['name'],
        'qty'       => (int)$item['qty'],
        'line_total' => number_format($lt, 2),
    ];
}

echo json_encode([
    'cart'     => $cart_rows,
    'subtotal' => number_format($subtotal, 2),
]);
exit();