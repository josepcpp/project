<?php
/**
 * push_inventory.php — Applies a validated receiving batch to the products table.
 *
 * Designed to be included and called as push_inventory($batch_id, ...).
 * NOT directly browsable — no layout, no direct output.
 *
 * KEY RULE: the Validator's base_price is the SUPPLIER COST, never the selling price.
 * Selling price (products.price) is set ONLY by the Admin. Any stock without an
 * admin-set selling price is status='draft' — counted in Inventory but invisible to POS.
 *
 * Logic per item (base_price = supplier cost):
 *  - Brand-new item (no row for barcode)        → draft lot (reason 'new'), notify Admin to price it
 *  - Existing active lot, SAME cost             → add qty to the active lot, refresh last_buy_cost
 *  - Existing active lot, DIFFERENT cost         → held draft lot (reason 'cost_change'), notify Admin
 *  - Only an unpriced draft lot exists           → merge if same cost, else another draft lot
 */

if (!function_exists('push_inventory')) {

function push_inventory(int $batch_id, ?int $actor_id, string $actor_username, string $actor_role, mysqli $conn): void
{
    // Load batch
    $bq = $conn->prepare("SELECT * FROM receiving_batches WHERE id = ? LIMIT 1");
    $bq->bind_param("i", $batch_id);
    $bq->execute();
    $batch = $bq->get_result()->fetch_assoc();
    if (!$batch) throw new Exception("Batch #$batch_id not found.");

    // Idempotency guard: a completed batch was already pushed — never stock it twice.
    if ($batch['status'] === 'completed') {
        throw new Exception("Batch #$batch_id was already pushed to inventory.");
    }

    // Load items with base_price (supplier cost) already set by validator
    $iq = $conn->prepare("SELECT * FROM receiving_items WHERE batch_id = ? ORDER BY id ASC");
    $iq->bind_param("i", $batch_id);
    $iq->execute();
    $items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($items)) throw new Exception("Batch has no items to push.");

    foreach ($items as $item) {
        if ($item['base_price'] === null) throw new Exception("Item '{$item['description']}' has no base price. Cannot push.");

        $barcode     = $item['barcode']     ?: null;
        $box_barcode = $item['box_barcode'] ?: null;
        $box_units   = max(1, intval($item['box_units'] ?? 1));
        $cost    = floatval($item['base_price']);   // supplier cost
        $qty     = intval($item['quantity']);
        $expiry  = $item['expiry_date'] ?: null;
        $desc    = $item['description'];

        // Resolve an existing active/draft lot by EITHER code (per-item OR box barcode),
        // so a sealed box (no per-item code yet) still matches its prior lot on restock.
        $active     = null;   // lot matching barcode + expiry exactly  → merge candidate
        $active_ref = null;   // any active lot for this barcode         → price/tier reference
        $draft      = null;   // draft lot matching barcode + expiry     → draft merge candidate
        if ($barcode || $box_barcode) {
            $match_bc    = " AND ((? IS NOT NULL AND barcode = ?) OR (? IS NOT NULL AND box_barcode = ?))";
            // <=> is the NULL-safe equals operator: NULL <=> NULL is TRUE, so expiry_date <=> NULL
            // correctly finds lots with no expiry when incoming item has no expiry.
            $match_exact = $match_bc . " AND expiry_date <=> ?";

            // Exact-expiry active lot (for merge decisions)
            $aq = $conn->prepare("SELECT id, name, price, cost_price, last_buy_cost, quantity, expiry_date FROM products WHERE status = '" . PRODUCT_ACTIVE . "'" . $match_exact . " LIMIT 1");
            $aq->bind_param("sssss", $barcode, $barcode, $box_barcode, $box_barcode, $expiry);
            $aq->execute();
            $active = $aq->get_result()->fetch_assoc() ?: null;

            // Any active lot — used as selling-price / bulk-tier reference when a new lot must be created
            if ($active) {
                $active_ref = $active;   // same row, no extra query needed
            } else {
                $aq_ref = $conn->prepare("SELECT id, name, price, cost_price, last_buy_cost, quantity, expiry_date FROM products WHERE status = '" . PRODUCT_ACTIVE . "'" . $match_bc . " LIMIT 1");
                $aq_ref->bind_param("ssss", $barcode, $barcode, $box_barcode, $box_barcode);
                $aq_ref->execute();
                $active_ref = $aq_ref->get_result()->fetch_assoc() ?: null;
            }

            // Exact-expiry draft lot (for draft merge decisions)
            $dq = $conn->prepare("SELECT id, cost_price, quantity, expiry_date FROM products WHERE status = '" . PRODUCT_DRAFT . "'" . $match_exact . " LIMIT 1");
            $dq->bind_param("sssss", $barcode, $barcode, $box_barcode, $box_barcode, $expiry);
            $dq->execute();
            $draft = $dq->get_result()->fetch_assoc() ?: null;
        }

        if ($active) {
            // Exact match: $active has the same barcode AND same expiry — safe to compare cost only.
            $ref_cost     = floatval($active['cost_price']) > 0 ? floatval($active['cost_price']) : floatval($active['last_buy_cost']);
            $cost_changed = abs($ref_cost - $cost) >= 0.01;

            if (!$cost_changed) {
                // Same cost + same expiry → merge into existing lot
                $new_qty = intval($active['quantity']) + $qty;
                $upd = $conn->prepare(
                    "UPDATE products SET quantity = ?, max_quantity = ?, last_buy_cost = ?, status = '" . PRODUCT_ACTIVE . "', archived_at = NULL, receiving_batch_id = ?" .
                    ($expiry ? ", expiry_date = ?" : "") .
                    " WHERE id = ?"
                );
                if ($expiry) {
                    $upd->bind_param("iidisi", $new_qty, $new_qty, $cost, $batch_id, $expiry, $active['id']);
                } else {
                    $upd->bind_param("iidii", $new_qty, $new_qty, $cost, $batch_id, $active['id']);
                }
                $upd->execute();
                _set_box_fields($conn, $active['id'], $box_barcode, $box_units);
                _push_log($conn, $actor_id, $active['id'], "Pipeline push: +$qty units for \"{$active['name']}\" (Batch #$batch_id) — cost & expiry match, merged.");
            } else {
                // Cost changed → hold as draft for Admin to price
                $new_id = _insert_draft_lot($conn, $desc, $barcode, $cost, $qty, $expiry, $batch_id, 'cost_change', $box_barcode, $box_units);
                _notify_admin($conn, $batch_id,
                    "Cost change on delivery: \"$desc\" (barcode: $barcode) now ₱" . number_format($cost, 2)
                    . " vs previous ₱" . number_format($ref_cost, 2) . ". $qty unit(s) held in Inventory — set a selling price to release to POS.");
                _push_log($conn, $actor_id, $new_id, "Pipeline push: $qty units of \"{$desc}\" HELD (cost change) — awaiting Admin selling price (Batch #$batch_id).");
            }
        } elseif ($active_ref) {
            // Product is already priced and active, but no lot with this exact expiry exists yet.
            // Create a new active lot (FIFO lot) — copy selling price and bulk tiers from $active_ref.
            $ref_cost     = floatval($active_ref['cost_price']) > 0 ? floatval($active_ref['cost_price']) : floatval($active_ref['last_buy_cost']);
            $cost_changed = abs($ref_cost - $cost) >= 0.01;

            if (!$cost_changed) {
                $selling_price = floatval($active_ref['price']);
                if ($selling_price > 0) {
                    $new_id = _insert_active_lot($conn, $desc, $barcode, $cost, $selling_price, $qty, $expiry, $batch_id, $box_barcode, $box_units, $active_ref['id']);
                    _push_log($conn, $actor_id, $new_id, "Pipeline push: new FIFO lot for \"{$desc}\" (Batch #$batch_id, expiry {$expiry}) — same cost, new expiry, separate lot created.");
                } else {
                    // Active lot exists but has no selling price — hold as draft
                    $new_id = _insert_draft_lot($conn, $desc, $barcode, $cost, $qty, $expiry, $batch_id, 'new', $box_barcode, $box_units);
                    _push_log($conn, $actor_id, $new_id, "Pipeline push: draft lot for \"{$desc}\" (Batch #$batch_id, expiry {$expiry}) — new expiry, active price missing.");
                }
            } else {
                // Cost changed → hold as draft for Admin to price
                $new_id = _insert_draft_lot($conn, $desc, $barcode, $cost, $qty, $expiry, $batch_id, 'cost_change', $box_barcode, $box_units);
                _notify_admin($conn, $batch_id,
                    "Cost change on delivery: \"$desc\" (barcode: $barcode) now ₱" . number_format($cost, 2)
                    . " vs previous ₱" . number_format($ref_cost, 2) . ". $qty unit(s) held in Inventory — set a selling price to release to POS.");
                _push_log($conn, $actor_id, $new_id, "Pipeline push: $qty units of \"{$desc}\" HELD (cost change, new expiry) — awaiting Admin selling price (Batch #$batch_id).");
            }
        } elseif ($draft) {
            // No active lot at all; a draft with this exact expiry exists — merge if cost matches
            if (abs(floatval($draft['cost_price']) - $cost) < 0.01) {
                // Same cost + same expiry → merge into existing draft
                $new_qty = intval($draft['quantity']) + $qty;
                $upd = $conn->prepare(
                    "UPDATE products SET quantity = ?, max_quantity = ?, last_buy_cost = ?, receiving_batch_id = ?" .
                    ($expiry ? ", expiry_date = ?" : "") .
                    " WHERE id = ?"
                );
                if ($expiry) {
                    $upd->bind_param("iidisi", $new_qty, $new_qty, $cost, $batch_id, $expiry, $draft['id']);
                } else {
                    $upd->bind_param("iidii", $new_qty, $new_qty, $cost, $batch_id, $draft['id']);
                }
                $upd->execute();
                _set_box_fields($conn, $draft['id'], $box_barcode, $box_units);
                _push_log($conn, $actor_id, $draft['id'], "Pipeline push: +$qty units to unpriced draft \"{$desc}\" (Batch #$batch_id).");
            } else {
                // Different cost → new draft lot
                $new_id = _insert_draft_lot($conn, $desc, $barcode, $cost, $qty, $expiry, $batch_id, 'new', $box_barcode, $box_units);
                _notify_admin($conn, $batch_id, "New item awaiting price: \"$desc\" (barcode: " . ($barcode ?: $box_barcode ?: 'none') . ") — $qty unit(s) in Inventory. Set a selling price to release to POS.");
                _push_log($conn, $actor_id, $new_id, "Pipeline push: NEW unpriced draft \"{$desc}\" (Batch #$batch_id).");
            }
        } else {
            // Brand-new item — stock as draft, no selling price, notify Admin to price it
            $new_id = _insert_draft_lot($conn, $desc, $barcode, $cost, $qty, $expiry, $batch_id, 'new', $box_barcode, $box_units);
            _notify_admin($conn, $batch_id, "New item awaiting price: \"$desc\" (barcode: " . ($barcode ?: 'none') . ") — $qty unit(s) in Inventory. Set a selling price to release to POS.");
            _push_log($conn, $actor_id, $new_id, "Pipeline push: NEW item \"{$desc}\" (barcode: " . ($barcode ?: 'none') . ") — $qty units @ cost ₱" . number_format($cost, 2) . " — awaiting Admin selling price (Batch #$batch_id).");
        }
    }

    // Mark batch completed
    $fin = $conn->prepare("UPDATE receiving_batches SET status = 'completed', inventory_pushed_at = NOW() WHERE id = ?");
    $fin->bind_param("i", $batch_id);
    $fin->execute();

    // Audit log
    $al2 = $conn->prepare(
        "INSERT INTO procurement_audit_log (batch_id, actor_id, actor_username, actor_role, action) VALUES (?,?,?,?,'inventory_pushed')"
    );
    $al2->bind_param("iiss", $batch_id, $actor_id, $actor_username, $actor_role);
    $al2->execute();
}

/** Insert a draft (unpriced) product lot. Returns the new product id. */
function _insert_draft_lot(mysqli $conn, string $desc, ?string $barcode, float $cost, int $qty, ?string $expiry, int $batch_id, string $reason, ?string $box_barcode = null, int $box_units = 1): int
{
    $ins = $conn->prepare(
        "INSERT INTO products (supplier_id, name, barcode, box_barcode, box_units, price, cost_price, last_buy_cost, quantity, max_quantity, status, draft_reason, expiry_date, receiving_batch_id)
         VALUES (NULL, ?, ?, ?, ?, NULL, ?, ?, ?, ?, '" . PRODUCT_DRAFT . "', ?, ?, ?)"
    );
    $ins->bind_param("sssiddiissi", $desc, $barcode, $box_barcode, $box_units, $cost, $cost, $qty, $qty, $reason, $expiry, $batch_id);
    $ins->execute();
    return $conn->insert_id;
}

/**
 * Insert a new ACTIVE lot for a product that is already priced.
 * Used when a restock has the same cost as an existing lot but a different expiry date,
 * so both lots can live side-by-side and FIFO drains the earliest-expiry lot first.
 * Bulk tiers are copied from the reference lot so pricing stays consistent.
 */
function _insert_active_lot(mysqli $conn, string $desc, ?string $barcode, float $cost, float $selling_price, int $qty, ?string $expiry, int $batch_id, ?string $box_barcode, int $box_units, int $ref_id): int
{
    // Copy supplier_id and bulk tiers from the reference lot for full traceability.
    $tq = $conn->prepare("SELECT supplier_id, bulk_qty_half, price_half_box, bulk_qty_full, price_full_box FROM products WHERE id = ?");
    $tq->bind_param("i", $ref_id);
    $tq->execute();
    $t = $tq->get_result()->fetch_assoc() ?: ['supplier_id' => null, 'bulk_qty_half' => 0, 'price_half_box' => 0.0, 'bulk_qty_full' => 0, 'price_full_box' => 0.0];

    $ins = $conn->prepare(
        "INSERT INTO products
             (supplier_id, name, barcode, box_barcode, box_units, price, cost_price, last_buy_cost,
              quantity, max_quantity, status, expiry_date, receiving_batch_id,
              bulk_qty_half, price_half_box, bulk_qty_full, price_full_box)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '" . PRODUCT_ACTIVE . "', ?, ?, ?, ?, ?, ?)"
    );
    $ins->bind_param("isssidddiisiidid",
        $t['supplier_id'],
        $desc, $barcode, $box_barcode, $box_units,
        $selling_price, $cost, $cost,
        $qty, $qty,
        $expiry, $batch_id,
        $t['bulk_qty_half'], $t['price_half_box'],
        $t['bulk_qty_full'], $t['price_full_box']
    );
    $ins->execute();
    return $conn->insert_id;
}

/** Fill/refresh the box barcode + units on a product lot (no-op for plain unit items). */
function _set_box_fields(mysqli $conn, int $id, ?string $box_barcode, int $box_units): void
{
    if ($box_barcode === null && $box_units <= 1) return;     // nothing box-related to record
    $u = $conn->prepare("UPDATE products SET box_barcode = COALESCE(?, box_barcode), box_units = ? WHERE id = ?");
    $u->bind_param("sii", $box_barcode, $box_units, $id);
    $u->execute();
}

/** Notify the Admin role of a pricing action. */
function _notify_admin(mysqli $conn, int $batch_id, string $msg): void
{
    $notif = $conn->prepare("INSERT INTO notifications (recipient_role, type, batch_id, message) VALUES ('admin', 'price_change', ?, ?)");
    $notif->bind_param("is", $batch_id, $msg);
    $notif->execute();
}

/** Write an activity_logs entry for a push action. */
function _push_log(mysqli $conn, ?int $actor_id, int $item_id, string $message): void
{
    $al = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?,'" . LOG_PROCUREMENT . "',?,?)");
    $al->bind_param("iis", $actor_id, $item_id, $message);
    $al->execute();
}

} // end function_exists guard
