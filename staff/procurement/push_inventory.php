<?php
/**
 * push_inventory.php — Applies a validated receiving batch to the products table.
 *
 * Designed to be included and called as push_inventory($batch_id, ...).
 * NOT directly browsable — no layout, no direct output.
 *
 * Logic per item:
 *  - Product found by barcode, price UNCHANGED → add qty, reactivate if archived
 *  - Product found by barcode, price CHANGED   → insert pipeline_price_changes, notify admin, add qty
 *  - Product NOT found by barcode              → INSERT new product row with base_price
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

    // Load items with base_price already set by validator
    $iq = $conn->prepare("SELECT * FROM receiving_items WHERE batch_id = ? ORDER BY id ASC");
    $iq->bind_param("i", $batch_id);
    $iq->execute();
    $items = $iq->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($items)) throw new Exception("Batch has no items to push.");

    foreach ($items as $item) {
        if ($item['base_price'] === null) throw new Exception("Item '{$item['description']}' has no base price. Cannot push.");

        $barcode    = $item['barcode'];
        $base_price = floatval($item['base_price']);
        $qty        = intval($item['quantity']);
        $expiry     = $item['expiry_date'] ?: null;
        $desc       = $item['description'];

        if ($barcode) {
            // Try to find existing product by barcode
            $pq = $conn->prepare("SELECT id, name, price, quantity, status FROM products WHERE barcode = ? LIMIT 1");
            $pq->bind_param("s", $barcode);
            $pq->execute();
            $prod = $pq->get_result()->fetch_assoc();
        } else {
            $prod = null;
        }

        if ($prod) {
            $old_price = floatval($prod['price']);
            $price_changed = abs($old_price - $base_price) >= 0.01;

            if ($price_changed) {
                // Log price change for admin review — do NOT change price yet
                $pc = $conn->prepare(
                    "INSERT INTO pipeline_price_changes
                        (batch_id, item_id, barcode, description, old_price, new_price, supplier_name, raised_by, raised_by_username)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );
                $pc->bind_param("iissddsis",
                    $batch_id, $item['id'], $barcode, $desc,
                    $old_price, $base_price,
                    $batch['supplier_name'],
                    $actor_id, $actor_username
                );
                $pc->execute();

                // Notify admin
                $msg = "Price change detected for \"{$desc}\" (barcode: $barcode): ₱" . number_format($old_price, 2) . " → ₱" . number_format($base_price, 2) . " (Batch #$batch_id).";
                $notif = $conn->prepare("INSERT INTO notifications (recipient_role, type, batch_id, message) VALUES ('admin', 'price_change', ?, ?)");
                $notif->bind_param("is", $batch_id, $msg);
                $notif->execute();
            }

            // Add qty regardless of price change; reactivate if archived; link to this batch.
            // max_quantity = new total so the 10%-of-intake low-stock threshold is accurate.
            $new_qty = intval($prod['quantity']) + $qty;
            $upd = $conn->prepare(
                "UPDATE products SET quantity = ?, max_quantity = ?, status = '" . PRODUCT_ACTIVE . "', archived_at = NULL, receiving_batch_id = ?" .
                ($expiry ? ", expiry_date = ?" : "") .
                " WHERE id = ?"
            );
            if ($expiry) {
                $upd->bind_param("iiiisi", $new_qty, $new_qty, $batch_id, $expiry, $prod['id']);
            } else {
                $upd->bind_param("iiii", $new_qty, $new_qty, $batch_id, $prod['id']);
            }
            $upd->execute();

            // Activity log
            $log_msg = "Pipeline push: +$qty units for \"{$prod['name']}\" (Batch #$batch_id)" . ($price_changed ? " [PRICE CHANGE PENDING REVIEW]" : "");
            $al = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?,'" . LOG_PROCUREMENT . "',?,?)");
            $al->bind_param("iis", $actor_id, $prod['id'], $log_msg);
            $al->execute();

        } else {
            // New product — INSERT with base_price as starting price.
            // max_quantity = received qty so the 10%-of-intake low-stock threshold is accurate.
            // supplier_id = NULL (pipeline flow has no legacy supplier FK)
            $ins = $conn->prepare(
                "INSERT INTO products (supplier_id, name, barcode, price, cost_price, quantity, max_quantity, status, expiry_date, receiving_batch_id)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, '" . PRODUCT_ACTIVE . "', ?, ?)"
            );
            $ins->bind_param("ssddiiisi", $desc, $barcode, $base_price, $base_price, $qty, $qty, $expiry, $batch_id);
            $ins->execute();
            $new_product_id = $conn->insert_id;

            $log_msg = "Pipeline push: NEW product \"{$desc}\" (barcode: " . ($barcode ?: 'none') . ") — $qty units @ ₱" . number_format($base_price, 2) . " (Batch #$batch_id)";
            $al = $conn->prepare("INSERT INTO activity_logs (user_id, log_type, item_id, message) VALUES (?,'" . LOG_PROCUREMENT . "',?,?)");
            $al->bind_param("iis", $actor_id, $new_product_id, $log_msg);
            $al->execute();
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

} // end function_exists guard
