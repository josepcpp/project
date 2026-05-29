<?php
/**
 * constants.php — Single source of truth for all magic strings and numbers.
 * Include this via config/db.php so every file gets it automatically.
 * To change a value system-wide, change it here ONLY.
 */

// ── USER ROLES ────────────────────────────────────────────────────────────────
define('ROLE_STAFF',      'staff');
define('ROLE_ADMIN',      'admin');
define('ROLE_OWNER',      'owner');
define('ROLE_SUPERADMIN', 'superadmin');
define('ROLE_MEMBER',     'member');

/** Roles that can access the admin dashboard (non-staff) */
define('ROLES_ADMIN_AND_UP', [ROLE_ADMIN, ROLE_SUPERADMIN, ROLE_OWNER]);

/** Admin + Owner only — excludes superadmin (used for nav section gating) */
define('ROLES_ADMIN_OWNER', [ROLE_ADMIN, ROLE_OWNER]);

/** Roles that can approve payments (step 1) */
define('ROLES_PAYMENT_APPROVERS', [ROLE_ADMIN, ROLE_SUPERADMIN]);

/** Roles that can act on other users (used in can_act() logic) */
define('ROLES_PROTECTED', [ROLE_ADMIN, ROLE_SUPERADMIN, ROLE_OWNER]);

// ── USER STATUS ───────────────────────────────────────────────────────────────
define('USER_ACTIVE',     'ACTIVE');
define('USER_INACTIVE',   'INACTIVE');
define('USER_TERMINATED', 'TERMINATED');

// ── PRODUCT STATUS ────────────────────────────────────────────────────────────
define('PRODUCT_ACTIVE',   'active');
define('PRODUCT_ARCHIVED', 'archived');
define('PRODUCT_DRAFT',    'draft');

/** Statuses considered "live" (usable in POS/procurement) */
define('PRODUCT_LIVE_STATUSES', [PRODUCT_ACTIVE, PRODUCT_ARCHIVED]);

// ── PRODUCT CATEGORIES ────────────────────────────────────────────────────────
define('CAT_GENERAL',   'General');
define('CAT_FOOD',      'Food');
define('CAT_BEVERAGE',  'Beverage');
define('CAT_SUPPLIES',  'Supplies');
define('CAT_MEDICINE',  'Medicine');

define('PRODUCT_CATEGORIES', [
    CAT_GENERAL  => 'General Items',
    CAT_FOOD     => 'Food Products',
    CAT_BEVERAGE => 'Beverages',
    CAT_SUPPLIES => 'Daily Supplies',
    CAT_MEDICINE => 'Medical/First Aid',
]);

// ── PAYMENT METHODS ───────────────────────────────────────────────────────────
define('PAY_METHOD_CASH',  'Cash');
define('PAY_METHOD_GCASH', 'GCash');
define('PAY_METHOD_MAYA',  'Maya');

define('PAYMENT_METHODS', [PAY_METHOD_CASH, PAY_METHOD_GCASH, PAY_METHOD_MAYA]);

// ── SUPPLIER PAYMENT STATUS ───────────────────────────────────────────────────
define('SUP_PAY_UNPAID', 'UNPAID');
define('SUP_PAY_PAID',   'PAID');

// ── PAYMENT APPROVAL STATUS ───────────────────────────────────────────────────
define('APPROVAL_PENDING_STEP1', 'pending_step1');
define('APPROVAL_PENDING_STEP2', 'pending_step2');
define('APPROVAL_APPROVED',      'approved');
define('APPROVAL_DENIED',        'denied');

define('APPROVAL_ACTIVE_STATUSES', [APPROVAL_PENDING_STEP1, APPROVAL_PENDING_STEP2]);


// ── PRICE UPDATE REQUEST STATUS ───────────────────────────────────────────────
define('PRICE_REQ_PENDING',        'pending');
define('PRICE_REQ_STEP1_APPROVED', 'step1_approved');
define('PRICE_REQ_APPROVED',       'approved');
define('PRICE_REQ_DEFERRED',       'deferred');
define('PRICE_REQ_APPLIED',        'applied');
define('PRICE_REQ_REJECTED',       'rejected');

define('PRICE_REQ_CLOSED_STATUSES', [PRICE_REQ_APPLIED, PRICE_REQ_REJECTED]);
define('PRICE_REQ_APPLY_STATUSES',  [PRICE_REQ_APPROVED, PRICE_REQ_DEFERRED]);

// ── REFUND STATUS ─────────────────────────────────────────────────────────────
define('REFUND_PENDING',    'pending');
define('REFUND_APPROVED',   'approved');
define('REFUND_REJECTED',   'rejected');
define('REFUND_OVERRIDDEN', 'overridden');

// ── REFUND DISPOSITION ────────────────────────────────────────────────────────
define('DISP_RESTOCK', 'restock');
define('DISP_DISPOSE', 'dispose');

// ── DELIVERY STATUS ───────────────────────────────────────────────────────────
define('DEL_PENDING',   'PENDING');
define('DEL_VERIFIED',  'VERIFIED');
define('DEL_VALIDATED', 'VALIDATED');

// ── DELIVERY RETURN REQUEST STATUS ───────────────────────────────────────────
define('DR_PENDING',  'pending');
define('DR_APPROVED', 'approved');
define('DR_REJECTED', 'rejected');

// ── DISPOSAL REASONS ──────────────────────────────────────────────────────────
define('DISPOSE_EXPIRED',      'Expired');
define('DISPOSE_CONTAMINATED', 'Contaminated');
define('DISPOSE_DAMAGED',      'Damaged');
define('DISPOSE_SPOILED',      'Spoiled');
define('DISPOSE_OTHER',        'Other');

define('DISPOSAL_REASONS', [
    DISPOSE_EXPIRED,
    DISPOSE_CONTAMINATED,
    DISPOSE_DAMAGED,
    DISPOSE_SPOILED,
    DISPOSE_OTHER,
]);

// ── DISPOSAL / PRODUCT DISPOSAL STATUS ───────────────────────────────────────
define('DISPOSAL_PENDING',  'pending');
define('DISPOSAL_APPROVED', 'approved');
define('DISPOSAL_REJECTED', 'rejected');

// ── SECURITY FLAG STATUS ──────────────────────────────────────────────────────
define('FLAG_OPEN',      'open');
define('FLAG_REVIEWED',  'reviewed');
define('FLAG_DISMISSED', 'dismissed');

// ── SECURITY FLAG SEVERITY ────────────────────────────────────────────────────
define('SEV_HIGH',   'high');
define('SEV_MEDIUM', 'medium');
define('SEV_LOW',    'low');

// ── SECURITY FLAG TYPES ───────────────────────────────────────────────────────
define('FLAG_PRICE_SPIKE',          'price_spike');
define('FLAG_SPEED_ANOMALY',        'speed_anomaly');
define('FLAG_REPEAT_DISCREPANCY',   'repeat_discrepancy');
define('FLAG_STAFF_CHANGE',         'staff_change');
define('FLAG_DUPLICATE_REFUND',     'duplicate_refund');
define('FLAG_ACCESS_EVENT',         'access_event');
define('FLAG_PAYMENT_REVERSAL',     'payment_reversal');
define('FLAG_RECOUNT_DOUBLE_FAIL',  'recount_double_fail');

// ── SUPPORT TICKET STATUS ─────────────────────────────────────────────────────
define('TICKET_OPEN',        'open');
define('TICKET_IN_PROGRESS', 'in_progress');
define('TICKET_RESOLVED',    'resolved');

define('TICKET_STATUSES', [TICKET_OPEN, TICKET_IN_PROGRESS, TICKET_RESOLVED]);


// ── DISCOUNT TYPES ────────────────────────────────────────────────────────────
define('DISCOUNT_PERCENTAGE', 'Percentage');
define('DISCOUNT_FIXED',      'Fixed');

// ── ACTIVITY LOG TYPES ────────────────────────────────────────────────────────
define('LOG_SALES',      'Sales');
define('LOG_DELIVERIES', 'Deliveries');
define('LOG_INVENTORY',  'Inventory');
define('LOG_PRICES',     'Prices');
define('LOG_USERS',      'Users');
define('LOG_SECURITY',   'Security');
define('LOG_DISPOSAL',   'Disposal');
define('LOG_PAYMENTS',   'Payments');

// ── REFERENCE PREFIXES ────────────────────────────────────────────────────────
define('PREFIX_RECEIPT', 'RCPT-');
define('PREFIX_PAYMENT', 'PAY-');
define('PREFIX_BARCODE', '628');

// ── BUSINESS RULES — configurable defaults ────────────────────────────────────
// These are fallback defaults only. Live values come from system_settings table.
// To override system-wide: update the row in system_settings, not here.
define('DEFAULT_LOW_STOCK_THRESHOLD',      10);   // units
define('DEFAULT_EXPIRY_WARNING_DAYS',       7);   // days before expiry to alert
define('DEFAULT_PRICE_SPIKE_PCT',          30);   // % increase that triggers flag
define('DEFAULT_PRICE_SPIKE_MULTIPLIER', 1.30);   // pre-computed: 1 + (30/100)
define('DEFAULT_LOW_STOCK_PCT',          0.10);   // 10% of max_quantity
define('DEFAULT_SPEED_ANOMALY_MINUTES',    3);    // min time to complete a batch
define('DEFAULT_SPEED_ANOMALY_MIN_ITEMS',  3);    // min items to trigger speed check
define('DEFAULT_REPEAT_DISCREPANCY_COUNT', 3);    // error batches before flagging

// ── EXCHANGE STATUS ───────────────────────────────────────────────────────────
define('EXCHANGE_NONE',    'none');
define('EXCHANGE_COLLECT', 'collect');
define('EXCHANGE_REFUND',  'refund');

// ── BACKUP ────────────────────────────────────────────────────────────────────
define('BACKUP_SUCCESS', 'success');
define('BACKUP_FAILED',  'failed');

// ── PRICE ROUNDING RULES ──────────────────────────────────────────────────────
define('ROUND_NONE',          'none');
define('ROUND_NEAREST_25C',   'nearest_25c');
define('ROUND_NEAREST_50C',   'nearest_50c');
define('ROUND_NEAREST_PESO',  'nearest_peso');
define('ROUND_NEAREST_5PESO', 'nearest_5peso');

// ── ACTIVITY LOG TYPES (Phase 2 additions) ────────────────────────────────────
define('LOG_EXCHANGE', 'Exchange');
define('LOG_BACKUP',   'Backup');

// ── VALIDATION RULES ──────────────────────────────────────────────────────────
define('MIN_USERNAME_LENGTH', 3);
define('MAX_USERNAME_LENGTH', 50);
define('MIN_PASSWORD_LENGTH', 8);

// ── PROCUREMENT PIPELINE ROLES ────────────────────────────────────────────────
define('ROLE_RECEIVER',      'receiver');
define('ROLE_VALIDATOR',     'validator');
define('ROLE_PRICE_CHECKER', 'price_checker');

/** All three procurement-only specialist roles */
define('ROLES_PROCUREMENT_STAFF', [ROLE_RECEIVER, ROLE_VALIDATOR, ROLE_PRICE_CHECKER]);

// ── RECEIVING BATCH STATUS ────────────────────────────────────────────────────
define('RB_PENDING_REQUEST',    'pending_request');
define('RB_PENDING_VALIDATION', 'pending_validation');
define('RB_PENDING_INVENTORY',  'pending_inventory');
define('RB_VALIDATED_TALLY',    'validated_tally');
define('RB_VALIDATED_DISC',     'validated_discrepancy');
define('RB_ON_HOLD',            'on_hold');
define('RB_COMPLETED',          'completed');
define('RB_REJECTED',           'rejected');

// ── PRICE CHANGE (PIPELINE) STATUS ───────────────────────────────────────────
define('PC_PENDING',  'pending');
define('PC_APPROVED', 'approved');
define('PC_REJECTED', 'rejected');

// ── NOTIFICATION TYPES ────────────────────────────────────────────────────────
define('NOTIF_DISCREPANCY',    'discrepancy');
define('NOTIF_PRICE_CHANGE',   'price_change');
define('NOTIF_OVERRIDE',       'override');
define('NOTIF_BATCH_REJECTED', 'batch_rejected');

// ── ACTIVITY LOG TYPES (Phase 4 — procurement pipeline) ──────────────────────
define('LOG_PROCUREMENT', 'Procurement');
