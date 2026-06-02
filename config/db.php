<?php
require_once __DIR__ . '/constants.php';

$conn = new mysqli("localhost", "root", "", "posiisdb");

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// L-01: run schema migrations only once per version — skipped on every subsequent request
$_db_version   = '1.6.0';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {

// One-time migration: procurement_access column
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS procurement_access ENUM('none','pending','approved','denied') DEFAULT 'none'");

// One-time migration: quantity discrepancy alerts table
$conn->query("CREATE TABLE IF NOT EXISTS quantity_alerts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(255),
    barcode      VARCHAR(100),
    invoice      VARCHAR(100),
    supplier_id  INT,
    batch_qty    INT,
    received_qty INT,
    flagged_by   INT,
    status       ENUM('pending','recounting','resolved') DEFAULT 'pending',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// One-time migration: procurement batch lifecycle tracking
$conn->query("CREATE TABLE IF NOT EXISTS procurement_batches (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    staff_id             INT,
    staff_username       VARCHAR(100),
    approved_by          INT DEFAULT NULL,
    approved_by_username VARCHAR(100) DEFAULT NULL,
    supplier_id          INT DEFAULT NULL,
    supplier_name        VARCHAR(255) DEFAULT NULL,
    invoice              VARCHAR(100) DEFAULT NULL,
    approved_at          DATETIME DEFAULT NULL,
    encoding_started_at  DATETIME DEFAULT NULL,
    receiving_started_at DATETIME DEFAULT NULL,
    officialized_at      DATETIME DEFAULT NULL,
    status               ENUM('approved','encoding','receiving','complete_clean','complete_errors','recount_pending') DEFAULT 'approved',
    item_count           INT DEFAULT 0,
    discrepancy_count    INT DEFAULT 0,
    price_flag_count     INT DEFAULT 0,
    minutes_to_complete  INT DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// One-time migration: procurement access audit log
$conn->query("CREATE TABLE IF NOT EXISTS procurement_access_log (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    staff_id             INT,
    staff_username       VARCHAR(100),
    action               ENUM('requested','approved','denied','consumed','recount_auto') DEFAULT 'requested',
    actioned_by          INT DEFAULT NULL,
    actioned_by_username VARCHAR(100) DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// One-time migration: security anomaly flags
$conn->query("CREATE TABLE IF NOT EXISTS security_flags (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    flag_type      VARCHAR(50),
    severity       ENUM('low','medium','high') DEFAULT 'medium',
    reference_id   INT DEFAULT NULL,
    reference_type VARCHAR(50) DEFAULT NULL,
    message        TEXT,
    reviewed_by    INT DEFAULT NULL,
    reviewed_at    DATETIME DEFAULT NULL,
    status         ENUM('open','reviewed','dismissed') DEFAULT 'open',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// One-time migration: support ticket system
$conn->query("CREATE TABLE IF NOT EXISTS support_tickets (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    username     VARCHAR(100) DEFAULT NULL,
    subject      VARCHAR(255) NOT NULL,
    status       ENUM('open','in_progress','resolved') DEFAULT 'open',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at  DATETIME DEFAULT NULL
)");
$conn->query("CREATE TABLE IF NOT EXISTS support_messages (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id        INT NOT NULL,
    sender_id        INT NOT NULL,
    sender_username  VARCHAR(100) DEFAULT NULL,
    sender_role      VARCHAR(50)  DEFAULT NULL,
    message          TEXT NOT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// One-time migration: per-product low-stock intake baseline
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS max_quantity INT DEFAULT 0");

// One-time migration: support ticket last-activity timestamp
$conn->query("ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT NULL");

// One-time migration: two-step payment approval workflow
$conn->query("CREATE TABLE IF NOT EXISTS payment_approvals (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    payment_id              INT NOT NULL,
    requested_by            INT DEFAULT NULL,
    requested_by_username   VARCHAR(100) DEFAULT NULL,
    step1_approver_id       INT DEFAULT NULL,
    step1_username          VARCHAR(100) DEFAULT NULL,
    step1_at                DATETIME DEFAULT NULL,
    step1_action            ENUM('approved','denied') DEFAULT NULL,
    step2_approver_id       INT DEFAULT NULL,
    step2_username          VARCHAR(100) DEFAULT NULL,
    step2_at                DATETIME DEFAULT NULL,
    step2_action            ENUM('approved','denied') DEFAULT NULL,
    status                  ENUM('pending_step1','pending_step2','approved','denied') DEFAULT 'pending_step1',
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// One-time migration: controlled price update approval workflow
$conn->query("CREATE TABLE IF NOT EXISTS price_update_requests (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    product_id          INT NOT NULL,
    product_name        VARCHAR(255),
    barcode             VARCHAR(100),
    current_price       DECIMAL(10,2) NOT NULL,
    proposed_price      DECIMAL(10,2) NOT NULL,
    supplier_id         INT DEFAULT NULL,
    supplier_name       VARCHAR(255) DEFAULT NULL,
    invoice             VARCHAR(100) DEFAULT NULL,
    submitted_by        INT DEFAULT NULL,
    submitted_username  VARCHAR(100) DEFAULT NULL,
    step1_by            INT DEFAULT NULL,
    step1_username      VARCHAR(100) DEFAULT NULL,
    step1_at            DATETIME DEFAULT NULL,
    step2_by            INT DEFAULT NULL,
    step2_username      VARCHAR(100) DEFAULT NULL,
    step2_at            DATETIME DEFAULT NULL,
    applied_by          INT DEFAULT NULL,
    applied_username    VARCHAR(100) DEFAULT NULL,
    applied_at          DATETIME DEFAULT NULL,
    rejected_by         INT DEFAULT NULL,
    rejected_username   VARCHAR(100) DEFAULT NULL,
    rejected_at         DATETIME DEFAULT NULL,
    reject_reason       TEXT DEFAULT NULL,
    status              ENUM('pending','step1_approved','approved','applied','rejected') DEFAULT 'pending',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS price_update_logs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    request_id      INT NOT NULL,
    action          ENUM('submitted','step1_approved','step1_rejected','step2_approved','step2_rejected','applied','cancelled') DEFAULT 'submitted',
    actor_id        INT NOT NULL,
    actor_username  VARCHAR(100),
    old_price       DECIMAL(10,2) DEFAULT NULL,
    new_price       DECIMAL(10,2) DEFAULT NULL,
    note            TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// One-time migration: delivery return ticket request workflow
$conn->query("CREATE TABLE IF NOT EXISTS delivery_return_requests (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    ticket_no           VARCHAR(50) DEFAULT NULL,
    invoice_no          VARCHAR(100) NOT NULL,
    supplier_id         INT NOT NULL,
    supplier_name       VARCHAR(255) DEFAULT NULL,
    purpose             TEXT,
    deduct_pay          TINYINT(1) DEFAULT 1,
    requested_by        INT DEFAULT NULL,
    requested_username  VARCHAR(100) DEFAULT NULL,
    reviewed_by         INT DEFAULT NULL,
    reviewed_username   VARCHAR(100) DEFAULT NULL,
    reviewed_at         DATETIME DEFAULT NULL,
    reject_reason       TEXT DEFAULT NULL,
    status              ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS delivery_return_request_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    request_id   INT NOT NULL,
    product_id   INT NOT NULL,
    product_name VARCHAR(255) DEFAULT NULL,
    qty          INT NOT NULL,
    reason       VARCHAR(255) DEFAULT 'Damaged',
    unit_price   DECIMAL(10,2) DEFAULT 0.00,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// H-01: separate rejection reason from superadmin override note
$conn->query("ALTER TABLE refunds ADD COLUMN IF NOT EXISTS reject_note TEXT DEFAULT NULL");

// system_settings table + default rows (was missing — caused low-stock threshold to never save)
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT DEFAULT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('store_name',                 'My Store'),
    ('low_stock_threshold',        '10'),
    ('expiry_warning_days',        '7'),
    ('price_spike_pct',            '30'),
    ('speed_anomaly_minutes',      '3'),
    ('speed_anomaly_min_items',    '3'),
    ('repeat_discrepancy_count',   '3')
");

// One-time migration: tier lock flag — set after retail price approval, cleared after admin saves tiers
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS tiers_locked TINYINT(1) DEFAULT 0");

// One-time migration: locked_qty — batch units held until price request is applied
$conn->query("ALTER TABLE price_update_requests ADD COLUMN IF NOT EXISTS locked_qty INT DEFAULT 0");

// One-time migration: recount workflow — expected/actual/variance + lifecycle columns
$conn->query("ALTER TABLE quantity_alerts
    ADD COLUMN IF NOT EXISTS actual_qty   INT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS expected_qty INT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS variance     INT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS requested_by INT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS submitted_by INT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS submitted_at DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS approved_by  INT          DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS approved_at  DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reject_reason TEXT        DEFAULT NULL
");
$conn->query("ALTER TABLE quantity_alerts MODIFY COLUMN status ENUM('pending','recounting','submitted','approved','rejected','resolved') DEFAULT 'pending'");

// One-time migration: link quantity_alerts to the specific product row being recounted
$conn->query("ALTER TABLE quantity_alerts ADD COLUMN IF NOT EXISTS product_id INT DEFAULT NULL");

// One-time migration: deferred price apply — status and log action extended
$conn->query("ALTER TABLE price_update_requests MODIFY COLUMN status ENUM('pending','step1_approved','approved','deferred','applied','rejected') DEFAULT 'pending'");
$conn->query("ALTER TABLE price_update_logs MODIFY COLUMN action ENUM('submitted','step1_approved','step1_rejected','step2_approved','step2_rejected','applied','cancelled','deferred','auto_applied') DEFAULT 'submitted'");

// One-time migration: expired/damaged items disposal workflow
$conn->query("CREATE TABLE IF NOT EXISTS product_disposals (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    product_id          INT NOT NULL,
    product_name        VARCHAR(255),
    barcode             VARCHAR(100),
    qty                 INT NOT NULL,
    reason              ENUM('Expired','Contaminated','Damaged','Spoiled','Other') DEFAULT 'Expired',
    expiry_date         DATE DEFAULT NULL,
    notes               TEXT DEFAULT NULL,
    requested_by        INT DEFAULT NULL,
    requested_username  VARCHAR(100) DEFAULT NULL,
    approved_by         INT DEFAULT NULL,
    approved_username   VARCHAR(100) DEFAULT NULL,
    approved_at         DATETIME DEFAULT NULL,
    reject_reason       TEXT DEFAULT NULL,
    status              ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS expiry_date DATE DEFAULT NULL");

// One-time migration: per-item notes on delivery return request items
$conn->query("ALTER TABLE delivery_return_request_items ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL");

// One-time migration: delivery_returns audit table (moved from inline page creation)
$conn->query("CREATE TABLE IF NOT EXISTS delivery_returns (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    product_id  INT NOT NULL,
    qty         INT NOT NULL,
    reason      VARCHAR(255) DEFAULT 'Damaged',
    deduct_pay  TINYINT(1)  DEFAULT 1,
    created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
)");

// One-time migration: track when a product was archived for dashboard notifications
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS archived_at DATETIME DEFAULT NULL");

// One-time migration: supervision system — recount double-fail flagging
$conn->query("ALTER TABLE quantity_alerts ADD COLUMN IF NOT EXISTS fail_count INT DEFAULT 0");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS supervision_flag ENUM('none','supervised') DEFAULT 'none'");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS supervision_flagged_at DATETIME DEFAULT NULL");
$conn->query("CREATE TABLE IF NOT EXISTS recount_mismatch_log (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    alert_id            INT NOT NULL,
    product_id          INT DEFAULT NULL,
    product_name        VARCHAR(255),
    barcode             VARCHAR(100),
    invoice             VARCHAR(100),
    supplier_id         INT DEFAULT NULL,
    expected_qty        INT NOT NULL,
    submitted_qty       INT NOT NULL,
    variance            INT NOT NULL,
    fail_number         INT DEFAULT 1,
    submitted_by        INT DEFAULT NULL,
    submitted_username  VARCHAR(100) DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// v1.4.2 — expiry date on products
$conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS expiry_date DATE NULL");

// v1.4.3 — old_value / new_value audit columns used by refund_approve, price_maintenance, recount_finalize
$conn->query("ALTER TABLE activity_logs ADD COLUMN IF NOT EXISTS old_value VARCHAR(255) DEFAULT NULL");
$conn->query("ALTER TABLE activity_logs ADD COLUMN IF NOT EXISTS new_value VARCHAR(255) DEFAULT NULL");

// v1.4.4 — procurement denial reason so staff can see why access was denied
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS procurement_denial_reason TEXT DEFAULT NULL");

// ── v1.5.0 — Phase 2 Growth Features ─────────────────────────────────────────

// F-06: Customer-group pricing (employee, wholesale, VIP, etc.)
$conn->query("CREATE TABLE IF NOT EXISTS customer_groups (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100) NOT NULL,
    label          VARCHAR(50)  NOT NULL,
    discount_type  ENUM('Percentage','Fixed') NOT NULL DEFAULT 'Percentage',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_by     INT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("INSERT IGNORE INTO customer_groups (id, name, label, discount_type, discount_value) VALUES
    (1, 'Employee',  'EMPLOYEE',  'Percentage', 10.00),
    (2, 'Wholesale', 'WHOLESALE', 'Percentage', 5.00)
");
$conn->query("ALTER TABLE sales
    ADD COLUMN IF NOT EXISTS customer_group_id  INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS group_discount_amt DECIMAL(10,2) DEFAULT 0.00
");

// F-07: Exchange workflow (even swap + collect/refund delta)
$conn->query("CREATE TABLE IF NOT EXISTS exchanges (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    exchange_no         VARCHAR(50) NOT NULL,
    original_sale_id    INT NOT NULL,
    original_receipt_no VARCHAR(50) DEFAULT NULL,
    delta_type          ENUM('none','collect','refund') NOT NULL DEFAULT 'none',
    delta_amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_mode        VARCHAR(50)  DEFAULT NULL,
    reference_no        VARCHAR(100) DEFAULT NULL,
    processed_by        INT          DEFAULT NULL,
    processed_username  VARCHAR(100) DEFAULT NULL,
    notes               TEXT         DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS exchange_items (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    exchange_id         INT NOT NULL,
    direction           ENUM('return','outgoing') NOT NULL,
    product_id          INT NOT NULL,
    product_name        VARCHAR(255) DEFAULT NULL,
    qty                 INT NOT NULL DEFAULT 1,
    unit_price          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total          DECIMAL(10,2) NOT NULL DEFAULT 0.00
)");

// F-08: Tiered % pricing (supplements existing half-box/full-box fixed tiers)
$conn->query("CREATE TABLE IF NOT EXISTS pricing_tiers (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT NOT NULL,
    min_qty     INT NOT NULL,
    discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    label       VARCHAR(100) DEFAULT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product_active (product_id, is_active)
)");

// F-09: Promotion conflict resolution — priority + conflict rule on discounts
$conn->query("ALTER TABLE discounts
    ADD COLUMN IF NOT EXISTS priority      INT  NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS conflict_rule ENUM('best_for_customer','priority_order','stack') NOT NULL DEFAULT 'best_for_customer'
");

// F-10: Automatic backup scheduling
$conn->query("CREATE TABLE IF NOT EXISTS backup_logs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    filename         VARCHAR(255) DEFAULT NULL,
    size_kb          INT          DEFAULT NULL,
    status           ENUM('success','failed') DEFAULT 'success',
    method           ENUM('manual','auto') DEFAULT 'manual',
    triggered_by     INT DEFAULT NULL,
    trigger_username VARCHAR(100) DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('backup_enabled',          '0'),
    ('backup_interval_hours',   '24'),
    ('backup_retention_days',   '30'),
    ('backup_path',             'backups'),
    ('backup_last_run',         NULL)
");

// F-11: Price rounding rules
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('price_rounding_rule', 'none')
");

// F-12: IP/device access restrictions
$conn->query("CREATE TABLE IF NOT EXISTS ip_restrictions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ip_cidr    VARCHAR(50) NOT NULL,
    label      VARCHAR(100) DEFAULT NULL,
    note       TEXT DEFAULT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip (ip_cidr)
)");
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('ip_restriction_enabled', '0')
");

// ── v1.5.1 — Phase 3: Bundle deals + Tax-inclusive mode ──────────────────────

// F-13: Bundle deals / combo pricing
$conn->query("CREATE TABLE IF NOT EXISTS bundles (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(255) NOT NULL,
    description  TEXT DEFAULT NULL,
    bundle_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_by   INT DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS bundle_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    bundle_id  INT NOT NULL,
    product_id INT NOT NULL,
    qty        INT NOT NULL DEFAULT 1,
    KEY idx_bundle (bundle_id)
)");

// F-14: Tax-inclusive display mode setting
$conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('tax_display_mode', 'exclusive')
");

// GAP-03: store bundle discount as a numeric column on sales for audit/reporting
$conn->query("ALTER TABLE sales ADD COLUMN IF NOT EXISTS bundle_discount_amt DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER group_discount_amt");

// GAP-15: enforce unique tier threshold per product to prevent ambiguous lookups
$conn->query("ALTER TABLE pricing_tiers ADD UNIQUE KEY IF NOT EXISTS uq_tier_product_minqty (product_id, min_qty)");

// ── v1.6.0 — Procurement Pipeline (Receiver / Validator / Price Checker) ──────

$conn->query("CREATE TABLE IF NOT EXISTS receiving_batches (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    receiver_id          INT DEFAULT NULL,
    receiver_username    VARCHAR(100) DEFAULT NULL,
    status               ENUM('pending_request','pending_validation','pending_inventory',
                              'validated_tally','validated_discrepancy','on_hold',
                              'completed','rejected') DEFAULT 'pending_request',
    supplier_name        VARCHAR(255) DEFAULT NULL,
    supplier_contact     VARCHAR(255) DEFAULT NULL,
    control_subtotal     DECIMAL(12,2) DEFAULT NULL,
    computed_subtotal    DECIMAL(12,2) DEFAULT NULL,
    tally_result         ENUM('match','discrepancy') DEFAULT NULL,
    request_created_by   INT DEFAULT NULL,
    request_created_at   DATETIME DEFAULT NULL,
    validator_id         INT DEFAULT NULL,
    validated_at         DATETIME DEFAULT NULL,
    resolution_action    ENUM('reopen_receiver','reopen_validator','override','rejected') DEFAULT NULL,
    resolution_by        INT DEFAULT NULL,
    resolution_reason    TEXT DEFAULT NULL,
    resolution_at        DATETIME DEFAULT NULL,
    inventory_pushed_at  DATETIME DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS receiving_items (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    batch_id     INT NOT NULL,
    barcode      VARCHAR(100) DEFAULT NULL,
    description  VARCHAR(255) NOT NULL,
    quantity     INT NOT NULL DEFAULT 1,
    expiry_date  DATE DEFAULT NULL,
    base_price   DECIMAL(10,2) DEFAULT NULL,
    amount       DECIMAL(12,2) DEFAULT NULL,
    match_flag   TINYINT(1) DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_batch (batch_id)
)");

$conn->query("CREATE TABLE IF NOT EXISTS procurement_audit_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    batch_id       INT NOT NULL,
    actor_id       INT DEFAULT NULL,
    actor_username VARCHAR(100) DEFAULT NULL,
    actor_role     VARCHAR(50) DEFAULT NULL,
    action         VARCHAR(80) NOT NULL,
    tally_result   VARCHAR(20) DEFAULT NULL,
    reason         TEXT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_batch (batch_id)
)");

$conn->query("CREATE TABLE IF NOT EXISTS pipeline_price_changes (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    batch_id            INT NOT NULL,
    item_id             INT NOT NULL,
    barcode             VARCHAR(100) DEFAULT NULL,
    description         VARCHAR(255) DEFAULT NULL,
    old_price           DECIMAL(10,2) NOT NULL,
    new_price           DECIMAL(10,2) NOT NULL,
    supplier_name       VARCHAR(255) DEFAULT NULL,
    raised_by           INT DEFAULT NULL,
    raised_by_username  VARCHAR(100) DEFAULT NULL,
    status              ENUM('pending','approved','rejected') DEFAULT 'pending',
    reviewed_by         INT DEFAULT NULL,
    reviewed_at         DATETIME DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_batch (batch_id)
)");

$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id   INT DEFAULT NULL,
    recipient_role VARCHAR(30) DEFAULT NULL,
    type           ENUM('discrepancy','price_change','override','batch_rejected') NOT NULL,
    batch_id       INT DEFAULT NULL,
    message        TEXT NOT NULL,
    is_read        TINYINT(1) NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_recipient (recipient_id),
    KEY idx_role (recipient_role)
)");

// Extend users.role ENUM to include the three new pipeline roles
$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','staff','owner','member','receiver','validator','price_checker') DEFAULT NULL");

// Allow pipeline-created products to have no legacy supplier FK
$conn->query("ALTER TABLE products MODIFY COLUMN supplier_id INT(11) DEFAULT NULL");

// Mark migrations as done for this version
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    // A failed migration must never lock out the app — write the flag anyway
    // so the next request skips the broken migration entirely
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end migration block

// ── v1.6.1 — Legacy staff procurement cleanup ────────────────────────────────
$_db_version   = '1.6.1';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {

$conn->query("DROP TABLE IF EXISTS procurement_batches");
$conn->query("DROP TABLE IF EXISTS delivery_items");
$conn->query("DROP TABLE IF EXISTS quantity_alerts");
$conn->query("DROP TABLE IF EXISTS recount_mismatch_log");
$conn->query("DROP TABLE IF EXISTS procurement_access_log");

$conn->query("ALTER TABLE users DROP COLUMN IF EXISTS procurement_access");
$conn->query("ALTER TABLE users DROP COLUMN IF EXISTS procurement_denial_reason");
$conn->query("ALTER TABLE users DROP COLUMN IF EXISTS locked_supplier_id");
$conn->query("ALTER TABLE users DROP COLUMN IF EXISTS supervision_flag");
$conn->query("ALTER TABLE users DROP COLUMN IF EXISTS supervision_flagged_at");

file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.1

// ── v1.6.2 — Link products to receiving_batches ───────────────────────────────
$_db_version   = '1.6.2';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS receiving_batch_id INT DEFAULT NULL");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.2

// ── v1.6.3 — Damaged item tracking & delivery damage tickets ─────────────────
$_db_version   = '1.6.3';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("ALTER TABLE receiving_items ADD COLUMN IF NOT EXISTS damaged_qty INT DEFAULT 0");
    $conn->query("ALTER TABLE receiving_items ADD COLUMN IF NOT EXISTS damage_notes VARCHAR(500) DEFAULT NULL");
    $conn->query("CREATE TABLE IF NOT EXISTS delivery_damage_tickets (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        batch_id            INT NOT NULL,
        raised_by           INT NULL,
        raised_by_username  VARCHAR(100) NOT NULL,
        status              ENUM('pending','approved','rejected') DEFAULT 'pending',
        damage_summary      TEXT NULL,
        total_deduction     DECIMAL(10,2) DEFAULT 0.00,
        reviewed_by         INT NULL,
        reviewed_by_username VARCHAR(100) NULL,
        reviewed_at         DATETIME NULL,
        admin_notes         TEXT NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (batch_id) REFERENCES receiving_batches(id)
    )");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.3

// ── v1.6.4 — Damage ticket expiry + settings ─────────────────────────────────
$_db_version   = '1.6.4';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("ALTER TABLE delivery_damage_tickets MODIFY COLUMN status ENUM('pending','approved','rejected','expired') DEFAULT 'pending'");
    $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('damage_ticket_expiry_days','3') ON DUPLICATE KEY UPDATE setting_value = setting_value");
    $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('low_stock_threshold','10') ON DUPLICATE KEY UPDATE setting_value = setting_value");
    $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tax_display_mode','exclusive') ON DUPLICATE KEY UPDATE setting_value = setting_value");
    $conn->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('price_rounding_rule','none') ON DUPLICATE KEY UPDATE setting_value = setting_value");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.4

// ── v1.6.5 — Reopen-to-Price-Checker on damage ticket rejection ──────────────
$_db_version   = '1.6.5';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("ALTER TABLE receiving_batches MODIFY COLUMN status ENUM('pending_request','pending_validation','pending_inventory','validated_tally','validated_discrepancy','on_hold','completed','rejected','pending_reprice') DEFAULT 'pending_request'");
    $conn->query("ALTER TABLE receiving_batches MODIFY COLUMN resolution_action ENUM('reopen_receiver','reopen_validator','override','rejected','reopen_price_checker') DEFAULT NULL");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.5

// ── v1.6.6 — Snapshot discrepancy on damage tickets ──────────────────────────
$_db_version   = '1.6.6';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("ALTER TABLE delivery_damage_tickets ADD COLUMN IF NOT EXISTS snapshot_discrepancy DECIMAL(10,2) DEFAULT NULL AFTER total_deduction");
    // Backfill existing rows: best proxy is total_deduction (ticket was raised to cover the discrepancy)
    $conn->query("UPDATE delivery_damage_tickets SET snapshot_discrepancy = total_deduction WHERE snapshot_discrepancy IS NULL");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.6

// ── v1.6.7 — Separate supplier cost from selling price ───────────────────────
// Validator base_price = supplier cost. Stock with no admin-set selling price is
// status='draft' (in Inventory, invisible to POS). draft_reason explains why.
$_db_version   = '1.6.7';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS draft_reason ENUM('new','cost_change') DEFAULT NULL");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.7

// ── v1.6.8 — Supplier payment verification ───────────────────────────────────
// One payment record per validated batch: receipt subtotal − approved damage
// deductions = net paid to supplier. A row means the batch has been paid.
$_db_version   = '1.6.8';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("CREATE TABLE IF NOT EXISTS procurement_payments (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        batch_id             INT NOT NULL,
        receipt_subtotal     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        damage_deduction     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        net_amount           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_reference    VARCHAR(100) DEFAULT NULL,
        payment_method       VARCHAR(50)  DEFAULT NULL,
        notes                TEXT         DEFAULT NULL,
        status               ENUM('paid') NOT NULL DEFAULT 'paid',
        verified_by          INT          DEFAULT NULL,
        verified_by_username VARCHAR(100) DEFAULT NULL,
        verified_at          DATETIME     DEFAULT NULL,
        created_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_batch (batch_id)
    )");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.8

// ── v1.6.9 — Retire legacy sales-side supplier payment feature ───────────────
// Replaced by the procurement pipeline's supplier_payments.php (procurement_payments
// table). The old supplier_payments + payment_approvals tables are now unused.
$_db_version   = '1.6.9';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("DROP TABLE IF EXISTS payment_approvals");
    $conn->query("DROP TABLE IF EXISTS supplier_payments");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.6.9

// ── v1.7.0 — Supplier discount at payment time ───────────────────────────────
// A trade/volume discount from the supplier's receipt, deducted only when the
// payment is recorded: Net Payable = receipt subtotal − approved damage − discount.
// The blind validation tally (computed vs control) is intentionally left untouched.
$_db_version   = '1.7.0';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("ALTER TABLE procurement_payments ADD COLUMN IF NOT EXISTS supplier_discount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER damage_deduction");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.7.0

// ── v1.7.1 — Box (case) barcode support ──────────────────────────────────────
// A product can carry TWO codes: per-item barcode (products.barcode → +1 unit) and
// a box/case barcode (box_barcode → +box_units). Sealed boxes are received by box
// barcode only; the per-item code is learned on first individual sale at POS.
// Existing products get box_units=1 and box_barcode=NULL → unchanged behavior.
$_db_version   = '1.7.1';
$_db_init_flag = __DIR__ . '/../.db_init_v' . str_replace('.', '', $_db_version);
if (!file_exists($_db_init_flag)) { try {
    $conn->query("ALTER TABLE products         ADD COLUMN IF NOT EXISTS box_barcode VARCHAR(50)  DEFAULT NULL AFTER barcode");
    $conn->query("ALTER TABLE products         ADD COLUMN IF NOT EXISTS box_units   INT NOT NULL  DEFAULT 1 AFTER box_barcode");
    $conn->query("ALTER TABLE receiving_items  ADD COLUMN IF NOT EXISTS box_barcode VARCHAR(100) DEFAULT NULL AFTER barcode");
    $conn->query("ALTER TABLE receiving_items  ADD COLUMN IF NOT EXISTS box_units   INT NOT NULL  DEFAULT 1 AFTER box_barcode");
file_put_contents($_db_init_flag, date('Y-m-d H:i:s'));
} catch (Throwable $_e) {
    file_put_contents($_db_init_flag, date('Y-m-d H:i:s') . ' [error: ' . $_e->getMessage() . ']');
}} // end v1.7.1