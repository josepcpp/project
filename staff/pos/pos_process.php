<?php
include '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /project/auth/login.php");
    exit();
}

$skip_locked = false; // CALC-6: set true when a product is found but fully locked (no usable stock)

// ── Bundle discount revalidator ───────────────────────────────────────────────
// Called before every JSON response. Recomputes how many times each bundle is
// actually satisfiable by the current cart contents, and adjusts the discount
// amount accordingly. Removes bundles whose components are no longer in the cart.
function recalc_bundle_discounts(mysqli $conn): void {
    if (empty($_SESSION['bundle_discounts'])) return;

    foreach ($_SESSION['bundle_discounts'] as $bid => &$bd) {
        // Fetch live bundle price
        $bq = $conn->prepare("SELECT bundle_price FROM bundles WHERE id = ? AND is_active = 1 LIMIT 1");
        $bq->bind_param("i", $bid); $bq->execute();
        $bundle_row = $bq->get_result()->fetch_assoc();
        if (!$bundle_row) { unset($_SESSION['bundle_discounts'][$bid]); continue; }

        // Fetch bundle components with current retail price
        $iq = $conn->prepare(
            "SELECT bi.product_id, bi.qty AS req_qty, p.price
             FROM bundle_items bi
             JOIN products p ON p.id = bi.product_id
             WHERE bi.bundle_id = ?"
        );
        $iq->bind_param("i", $bid); $iq->execute();
        $components = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

        if (count($components) < 2) { unset($_SESSION['bundle_discounts'][$bid]); continue; }

        // How many complete bundles does the cart currently satisfy?
        $max_times = PHP_INT_MAX;
        $individual_per_bundle = 0.0;
        foreach ($components as $c) {
            $cart_qty = intval($_SESSION['cart'][$c['product_id']]['qty'] ?? 0);
            $req      = max(1, intval($c['req_qty']));
            $max_times = min($max_times, (int) floor($cart_qty / $req));
            $individual_per_bundle += floatval($c['price']) * $req;
        }
        if ($max_times === PHP_INT_MAX) $max_times = 0;

        if ($max_times <= 0) {
            unset($_SESSION['bundle_discounts'][$bid]);
        } else {
            $discount_per = max(0.0, $individual_per_bundle - floatval($bundle_row['bundle_price']));
            $bd['qty']    = $max_times;
            $bd['amount'] = round($discount_per * $max_times, 2);
        }
    }
    unset($bd); // break reference
}

// ── F-13: Bundle add helper — called before the single-product flow ────────────
// Adds all bundle components to the cart using the same stock/pricing rules as
// the existing 'add' action, then records the bundle discount in session.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bundle_add') {
    header('Content-Type: application/json');
    $bundle_id = intval($_POST['bundle_id'] ?? 0);
    if (!$bundle_id) { echo json_encode(['ok'=>false,'msg'=>'Invalid bundle.']); exit(); }

    $bq = $conn->prepare("SELECT * FROM bundles WHERE id=? AND is_active=1 LIMIT 1");
    $bq->bind_param("i", $bundle_id); $bq->execute();
    $bundle = $bq->get_result()->fetch_assoc();
    if (!$bundle) { echo json_encode(['ok'=>false,'msg'=>'Bundle not found or inactive.']); exit(); }

    $iq = $conn->prepare("SELECT bi.product_id, bi.qty, p.name, p.price, p.barcode, p.quantity, p.bulk_qty_half, p.bulk_qty_full, p.price_half_box, p.price_full_box FROM bundle_items bi JOIN products p ON p.id=bi.product_id WHERE bi.bundle_id=? AND p.status='" . PRODUCT_ACTIVE . "'");
    $iq->bind_param("i", $bundle_id); $iq->execute();
    $items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

    if (count($items) < 2) { echo json_encode(['ok'=>false,'msg'=>'Bundle has missing or inactive products.']); exit(); }

    // 1. Pre-check stock for ALL items before touching cart
    $individual_total = 0.0;
    foreach ($items as $it) {
        $needed = intval($it['qty']);
        $in_cart = intval($_SESSION['cart'][$it['product_id']]['qty'] ?? 0);
        if ($it['quantity'] < $in_cart + $needed)
            { echo json_encode(['ok'=>false,'msg'=>"Not enough stock for \"{$it['name']}\"."]); exit(); }
        // Retail value of this component
        $individual_total += $it['price'] * $needed;
    }

    // 2. Add each item using the SAME pricing math as the existing single-product flow
    foreach ($items as $it) {
        $pid = $it['product_id']; $qty = intval($it['qty']);
        if (!isset($_SESSION['cart'][$pid]))
            $_SESSION['cart'][$pid] = ['name' => $it['name'], 'qty' => 0];
        $_SESSION['cart'][$pid]['qty'] += $qty;
        // Recalculate line_total (reuses bulk priority logic identical to pos_process.php step 3)
        $total_qty = $_SESSION['cart'][$pid]['qty'];
        $retail = floatval($it['price']);
        $bqf = intval($it['bulk_qty_full'] ?? 0); $bqh = intval($it['bulk_qty_half'] ?? 0);
        $pfb = floatval($it['price_full_box'] ?? 0); $phb = floatval($it['price_half_box'] ?? 0);
        if ($bqf > 0 && $total_qty >= $bqf) {
            $lt = $pfb + (($total_qty - $bqf) * $retail);
        } elseif ($bqh > 0 && $total_qty >= $bqh) {
            $lt = $phb + (($total_qty - $bqh) * $retail);
        } else {
            $lt = $total_qty * $retail;
        }
        $_SESSION['cart'][$pid]['line_total'] = $lt;
        $_SESSION['cart'][$pid]['price']      = $total_qty > 0 ? $lt / $total_qty : $retail;
        $_SESSION['cart'][$pid]['is_bulk']    = false;
        $_SESSION['cart'][$pid]['bulk_note']  = 'Retail Price';
    }

    // 3. Record bundle discount in session (discount = individual_total - bundle_price)
    //    This is picked up by checkout_process.php as an additional discount layer.
    $bundle_price    = floatval($bundle['bundle_price']);
    $bundle_discount = max(0.0, round($individual_total - $bundle_price, 2));
    if (!isset($_SESSION['bundle_discounts'])) $_SESSION['bundle_discounts'] = [];
    // Accumulate — same bundle can be added multiple times
    if (isset($_SESSION['bundle_discounts'][$bundle_id])) {
        $_SESSION['bundle_discounts'][$bundle_id]['amount'] += $bundle_discount;
        $_SESSION['bundle_discounts'][$bundle_id]['qty']++;
    } else {
        $_SESSION['bundle_discounts'][$bundle_id] = [
            'name'   => $bundle['name'],
            'amount' => $bundle_discount,
            'qty'    => 1,
        ];
    }

    // 4. Revalidate bundle discounts against actual cart, then return JSON
    recalc_bundle_discounts($conn);
    $cart_rows = []; $subtotal = 0;
    foreach ($_SESSION['cart'] as $id => $it2) {
        $lt = floatval($it2['line_total'] ?? 0); $subtotal += $lt;
        $cart_rows[] = ['id'=>(int)$id,'name'=>$it2['name'],'qty'=>(int)$it2['qty'],'line_total'=>number_format($lt,2)];
    }
    $bd_total = 0.0;
    foreach ($_SESSION['bundle_discounts'] as $bd) { $bd_total += floatval($bd['amount']); }
    $bd_total = min($bd_total, $subtotal);
    echo json_encode([
        'cart'             => $cart_rows,
        'subtotal'         => number_format($subtotal, 2),
        'bundle_discount'  => number_format($bd_total, 2),
        'display_subtotal' => number_format(max(0, $subtotal - $bd_total), 2),
        'found'            => true,
        'bundle_added'     => $bundle['name'],
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pid = intval($_POST['id'] ?? 0);
    $barcode = $_POST['barcode'] ?? '';

    // 1. Identify Product ID — a scan resolves to a per-item barcode (→ 1 unit)
    //    or, failing that, a box/case barcode (→ box_units units).
    $is_box_scan       = false;
    $box_units_scanned = 1;
    if ($barcode && !$pid) {
        // a) per-item barcode → individual unit
        $p_query = $conn->prepare("SELECT id FROM products WHERE barcode = ? AND status = '" . PRODUCT_ACTIVE . "' AND quantity > 0 AND (expiry_date IS NULL OR expiry_date > CURDATE()) ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, id ASC LIMIT 1");
        $p_query->bind_param("s", $barcode);
        $p_query->execute();
        $pid = intval($p_query->get_result()->fetch_assoc()['id'] ?? 0);

        // b) else box/case barcode → a whole box
        if (!$pid) {
            $box_q = $conn->prepare("SELECT id, box_units FROM products WHERE box_barcode = ? AND status = '" . PRODUCT_ACTIVE . "' AND quantity > 0 AND (expiry_date IS NULL OR expiry_date > CURDATE()) ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, id ASC LIMIT 1");
            $box_q->bind_param("s", $barcode);
            $box_q->execute();
            $box_row = $box_q->get_result()->fetch_assoc();
            if ($box_row) {
                $pid               = intval($box_row['id']);
                $is_box_scan       = true;
                $box_units_scanned = max(1, intval($box_row['box_units']));
            }
        }
    }

    if ($pid > 0) {
        // Fetch full product rules (Wholesale Tiers)
        $product_query = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $product_query->bind_param("i", $pid);
        $product_query->execute();
        $product = $product_query->get_result()->fetch_assoc();

        if ($product) {
            // Sum ALL active rows for this barcode — mirrors pos.php's grouped SUM(quantity).
            // Using a single row's quantity would break when multiple lots exist (e.g. cross-supplier),
            // because locked_qty spans the whole barcode but the LIMIT 1 row might be the small locked batch.
            // Sum across the product's lots by EITHER code, so box-only items (no
            // per-item barcode yet) still total their stock correctly.
            $pbc  = $product['barcode'];
            $pbox = $product['box_barcode'] ?? null;
            $tq_q = $conn->prepare("SELECT COALESCE(SUM(quantity),0) AS tq FROM products WHERE status = '" . PRODUCT_ACTIVE . "' AND (expiry_date IS NULL OR expiry_date > CURDATE()) AND ((? IS NOT NULL AND barcode = ?) OR (? IS NOT NULL AND box_barcode = ?))");
            $tq_q->bind_param("ssss", $pbc, $pbc, $pbox, $pbox); $tq_q->execute();
            $total_qty     = intval($tq_q->get_result()->fetch_assoc()['tq'] ?? 0);

            // price_update_requests are keyed by per-item barcode; box-only products (pbc = NULL)
            // have no records there, so their locked_qty is always 0.
            if ($pbc !== null && $pbc !== '') {
                $lq_q = $conn->prepare("SELECT COALESCE(SUM(locked_qty),0) AS lq FROM price_update_requests WHERE barcode = ? AND status NOT IN ('" . PRICE_REQ_APPLIED . "','" . PRICE_REQ_REJECTED . "')");
                $lq_q->bind_param("s", $pbc); $lq_q->execute();
                $locked_qty = intval($lq_q->get_result()->fetch_assoc()['lq'] ?? 0);
            } else {
                $locked_qty = 0;
            }
            $effective_qty = max(0, $total_qty - $locked_qty);

            // CALC-6: Only create a new cart entry when effective stock exists.
            // Scanning a fully-locked product (quantity > 0 but all locked) would otherwise
            // insert a ghost entry with qty=0 and line_total=₱0 that the cashier can't use.
            if (!isset($_SESSION['cart'][$pid])) {
                if ($effective_qty <= 0) {
                    $skip_locked = true; // Signal not-found to the barcode scan indicator
                } else {
                    $_SESSION['cart'][$pid] = [
                        'name' => $product['name'],
                        'qty'  => 0,
                    ];
                }
            }

            // 2. Handle Quantity Actions
            if ($skip_locked) {
                // No usable stock — skip all cart mutations
            }
            elseif ($action === 'add' || $action === 'plus') {
                // A box scan adds box_units at once; a per-item scan adds 1.
                $inc = $is_box_scan ? $box_units_scanned : 1;
                $_SESSION['cart'][$pid]['qty'] = min($_SESSION['cart'][$pid]['qty'] + $inc, $effective_qty);
            }
            elseif ($action === 'bulk_set') {
                $qty_to_set = max(0, intval($_POST['qty_override'] ?? 1));
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
                    $capped = min($new_qty, $effective_qty);
                    if ($capped === 0) { unset($_SESSION['cart'][$pid]); }
                    else { $_SESSION['cart'][$pid]['qty'] = $capped; }
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
                // F-08: Priority 3 — Tiered % discount (only when no box pricing applies and tiers not locked)
                // GAP-07: skip tier lookup entirely when tiers_locked=1 (price under review).
                // Finds the highest min_qty tier this product has that the cart quantity meets.
                else {
                    $tier = null;
                    if (!intval($product['tiers_locked'] ?? 0)) {
                        $tier_q = $conn->prepare("
                            SELECT discount_pct, label FROM pricing_tiers
                            WHERE product_id = ? AND is_active = 1 AND min_qty <= ?
                            ORDER BY min_qty DESC LIMIT 1
                        ");
                        $tier_q->bind_param("ii", $pid, $qty); $tier_q->execute();
                        $tier = $tier_q->get_result()->fetch_assoc();
                    }
                    if ($tier && floatval($tier['discount_pct']) > 0) {
                        $disc_pct    = min(99.99, floatval($tier['discount_pct'])); // POS-1: cap so price never hits zero
                        $tier_price  = $retail_price * (1 - $disc_pct / 100);
                        $line_total  = round($tier_price * $qty, 4);
                        $status_note = ($tier['label'] ?: "Tiered") . " (−{$disc_pct}%)";
                        $is_bulk     = true;
                    } else {
                        // Standard Retail
                        $line_total  = $qty * $retail_price;
                        $status_note = "Retail Price";
                    }
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

// Revalidate bundle discounts against actual cart quantities before responding
recalc_bundle_discounts($conn);

// Return JSON cart snapshot for in-place DOM update (no page reload)
header('Content-Type: application/json');

$cart_rows = [];
$subtotal  = 0;
foreach ($_SESSION['cart'] as $id => $item) {
    $lt           = floatval($item['line_total'] ?? 0);
    $subtotal    += $lt;
    $cart_rows[]  = [
        'id'         => (int)$id,
        'name'       => $item['name'],
        'qty'        => (int)$item['qty'],
        'line_total' => number_format($lt, 2),
    ];
}

// Include bundle discount total so the cart sidebar can show the savings row
$bd_total = 0.0;
foreach ($_SESSION['bundle_discounts'] ?? [] as $bd) { $bd_total += floatval($bd['amount']); }
$bd_total = min($bd_total, $subtotal);

echo json_encode([
    'cart'             => $cart_rows,
    'subtotal'         => number_format($subtotal, 2),
    'bundle_discount'  => number_format($bd_total, 2),
    'display_subtotal' => number_format(max(0.0, $subtotal - $bd_total), 2),
    'found'            => !$skip_locked && isset($pid) && $pid > 0,
]);
exit();