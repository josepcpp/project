================================================================================
  PROCUREMENT PIPELINE — README
  Business ERP | staff/procurement/
  Last updated: 2026-05-30
================================================================================

OVERVIEW
--------
There are TWO separate procurement flows running in parallel in this system:

  1. LEGACY FLOW  — Used by the "staff" role (delivery_receive.php, etc.)
  2. PIPELINE FLOW — New 4-stage, 3-role system (described in this file)

This README covers the PIPELINE FLOW only.
Do NOT modify the legacy flow files (delivery_receive.php, officialize_stock.php,
procurement_gate.php, deliveries.php, etc.) — they are still active.

================================================================================
  PIPELINE OVERVIEW
================================================================================

  [Receiver] → [Admin] → [Validator] → [Inventory] → [Price Checker / Audit]

Goal: Prevent price fraud by separating the person who encodes quantities
      (Receiver) from the person who enters prices (Validator). Admin controls
      a hidden control subtotal that is used for a blind tally check.

================================================================================
  ROLES
================================================================================

  Role            DB value        Landing page
  --------------- --------------- ----------------------------------------------
  receiver        receiver        procurement/receive_batch.php
  validator       validator       procurement/validate_batch.php
  price_checker   price_checker   procurement/price_checker.php
  admin/superadmin (existing)     Can act at every stage

  New roles are created via staff/users/users.php (admin only).
  Login routing is handled in auth/login_process.php.

================================================================================
  STAGE-BY-STAGE FLOW
================================================================================

STAGE 1 — Receiver creates a batch
  File:    receive_batch.php
  Process: receive_process.php (action=create_batch)
  - Receiver enters supplier name + contact.
  - A new row is inserted into receiving_batches with status=pending_request.
  - Receiver is redirected to receive_items.php to encode items.

STAGE 2 — Receiver encodes items
  File:    receive_items.php
  Process: receive_process.php (action=save_items)
  - Receiver adds rows: barcode, description, qty, expiry date.
  - NO prices are entered here.
  - "Save" keeps status=pending_request (editable).
  - "Submit" logs action=items_encoded to procurement_audit_log.
  - Batch remains editable (pending_request) until Admin creates a validator request.

STAGE 3 — Admin creates validator request
  File:    batches_pending.php  (list all pending_request batches)
           validator_request.php (form for a single batch)
  - Admin reviews encoded items (qty, description, barcode, expiry — NO prices).
  - Admin enters supplier name, contact, and the RECEIPT SUBTOTAL (control_subtotal).
  - control_subtotal is stored in receiving_batches and NEVER shown to Validator.
  - On submit: status → pending_validation, audit log action=validator_request_created.

STAGE 4 — Validator enters prices
  File:    validate_batch.php  (list all pending_validation batches)
           validate_items.php  (price entry form)
  Process: validate_process.php
  - Validator sees: description, barcode, qty (read-only), expiry.
  - Validator enters base_price per item.
  - JS shows a running COMPUTED SUBTOTAL = SUM(base_price × qty).
  - Per-item AMOUNT column is intentionally hidden from Validator.
  - On submit: validate_process.php computes amount per item server-side,
    then compares computed_subtotal vs control_subtotal (tolerance: ₱0.01).

    MATCH:       tally_result=match, status → validated_tally
                 → push_inventory.php runs automatically.
                 → Inventory updated. Batch status → completed.

    DISCREPANCY: tally_result=discrepancy, status → on_hold
                 → Admin/Superadmin notified via notifications table.
                 → Batch sent to discrepancy_resolve.php.

STAGE 5 — Inventory push (auto on match, manual on override)
  File:    push_inventory.php  (function file — not directly browsable)
  Called by: validate_process.php (auto), discrepancy_resolve.php (override)
  - For each item:
      Barcode found, price unchanged → UPDATE products qty, reactivate if archived.
      Barcode found, price changed   → Insert into pipeline_price_changes,
                                       notify Admin, still add qty.
      Barcode not found              → INSERT new product row.
  - Logs to activity_logs (LOG_PROCUREMENT) and procurement_audit_log.
  - Sets receiving_batches.inventory_pushed_at and status=completed.

STAGE 6 — Discrepancy resolution (Admin/Superadmin only)
  File:    discrepancy_resolve.php
  - Shows full per-item breakdown INCLUDING the Amount column.
  - Four resolution actions (all require a written reason):

    Action              Status change         What happens next
    ------------------- --------------------- ---------------------------------
    Re-open to Receiver pending_request       Receiver can re-encode items
    Re-open to Validator pending_validation   Validator re-enters prices
    Override & Accept   completed             push_inventory.php runs
    Reject              rejected              Receiver notified, batch closed

STAGE 7 — Price Checker reports (audit role)
  File:    price_checker.php  (3-tab view)
  - Tab 1: Activity Records — all batches, tally results, timestamps.
  - Tab 2: Discrepancy Report — per-item amounts visible, filterable.
  - Tab 3: Price Changes — pipeline_price_changes records, "Raise to Admin" button.

================================================================================
  NOTIFICATION BELL
================================================================================

  API:    staff/api/notifications.php
  Table:  notifications

  Triggered by:
    discrepancy detected     → type=discrepancy,   recipient_role=admin
    price change on push     → type=price_change,  recipient_role=admin
    override accepted        → type=override,       recipient_id=receiver_id
    batch rejected           → type=batch_rejected, recipient_id=receiver_id
    price_checker raises     → type=price_change,  recipient_role=admin

  Bell appears for: admin, superadmin, receiver, validator, price_checker.
  Unread count updates automatically on each page load.

================================================================================
  DATABASE TABLES (new — pipeline only)
================================================================================

  receiving_batches       Main batch record. control_subtotal lives here.
  receiving_items         Per-item rows. amount column hidden from Receiver/Validator.
  procurement_audit_log   Full action trail per batch.
  pipeline_price_changes  Price discrepancy records raised during inventory push.
  notifications           Bell notifications for all pipeline roles.

  Legacy tables (untouched):
  procurement_batches, quantity_alerts, delivery_items, price_update_requests

================================================================================
  VISIBILITY RULES (enforced in code)
================================================================================

  Field              Receiver      Validator     Price Checker  Admin/Superadmin
  ------------------ ------------- ------------- -------------- ----------------
  Item qty           Read-only     Read-only     Read           Read
  Base price         —             Write         Read           Read
  Per-item Amount    HIDDEN        HIDDEN        Visible        Visible
  Control subtotal   NEVER         NEVER         NEVER          Write-only once
  Computed subtotal  —             Visible (own) Visible        Visible
  Tally result       After done    Visible       Visible        Visible

  control_subtotal rule:
    - Written once by Admin in validator_request.php.
    - SELECT'd once in validate_process.php for comparison only — never echoed.
    - Never appears in any HTML response visible to Validator.

================================================================================
  FILES — PIPELINE (new)
================================================================================

  receive_batch.php         Receiver: create batch + history
  receive_items.php         Receiver: encode items (qty, barcode, expiry)
  receive_process.php       POST handler for above two pages
  batches_pending.php       Admin: list pending_request batches
  validator_request.php     Admin: enter invoice details + control_subtotal
  validate_batch.php        Validator: list pending_validation batches
  validate_items.php        Validator: enter base prices
  validate_process.php      POST handler — blind tally + push or notify
  push_inventory.php        Function file — applies batch to products table
  discrepancy_resolve.php   Admin: resolve on_hold batches
  price_checker.php         Price Checker: 3-tab audit reports

FILES — LEGACY (do not modify)
================================================================================

  delivery_receive.php      Staff/Admin: receive deliveries (old flow)
  deliveries.php            Staff/Admin: view all deliveries
  delivery_add.php          Add items to a delivery
  delivery_save.php         POST handler for delivery_add.php
  delivery_process.php      POST handler for delivery verification
  delivery_view.php         View single delivery detail
  officialize_stock.php     Push old-flow batch to inventory
  procurement_gate.php      Access control check included by old flow pages
  recount_submit.php        Staff submits physical recount
  recount_finalize.php      Admin finalises recount result
  delivery_return_ticket.php  View a delivery return ticket
  delivery_return_request.php POST handler for return requests
  delivery_return_approve.php POST handler for return approvals

================================================================================
  END OF README
================================================================================
