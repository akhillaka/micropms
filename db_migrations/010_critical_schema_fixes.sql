-- MicroPMS Migration 010: Critical Schema Fixes
-- Addresses: missing tables, missing columns, ENUM fix, missing indexes
-- Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)

-- ═══════════════════════════════════════════════════════════════
-- 1. MISSING TABLE: error_logs
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `error_logs` (
  `id`           INT(11)      NOT NULL AUTO_INCREMENT,
  `severity`     ENUM('info','warning','error','critical') NOT NULL DEFAULT 'error',
  `category`     VARCHAR(50)  NOT NULL DEFAULT 'general',
  `message`      TEXT         NOT NULL,
  `context`      JSON         DEFAULT NULL,
  `staff_id`     INT(11)      DEFAULT NULL,
  `request_uri`  VARCHAR(255) DEFAULT NULL,
  `ip_address`   VARCHAR(45)  DEFAULT NULL,
  `resolved`     TINYINT(1)   NOT NULL DEFAULT 0,
  `resolved_at`  DATETIME     DEFAULT NULL,
  `resolved_by`  INT(11)      DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_error_severity`  (`severity`, `resolved`),
  KEY `idx_error_category`  (`category`, `resolved`),
  KEY `idx_error_created`   (`created_at`),
  KEY `idx_error_staff`     (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 2. MISSING TABLE: night_audit_log
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `night_audit_log` (
  `id`                  INT(11)        NOT NULL AUTO_INCREMENT,
  `audit_date`          DATE           NOT NULL,
  `run_by`              VARCHAR(50)    NOT NULL DEFAULT 'system',
  `total_rooms`         INT(11)        NOT NULL DEFAULT 0,
  `occupied_rooms`      INT(11)        NOT NULL DEFAULT 0,
  `arrivals_today`      INT(11)        NOT NULL DEFAULT 0,
  `departures_today`    INT(11)        NOT NULL DEFAULT 0,
  `overdue_checkouts`   INT(11)        NOT NULL DEFAULT 0,
  `auto_checkout_count` INT(11)        NOT NULL DEFAULT 0,
  `rooms_marked_dirty`  INT(11)        NOT NULL DEFAULT 0,
  `revenue_collected`   DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `revenue_pending`     DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
  `actions_json`        JSON           DEFAULT NULL,
  `status`              VARCHAR(20)    NOT NULL DEFAULT 'success',
  `error_message`       TEXT           DEFAULT NULL,
  `created_at`          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_audit_date` (`audit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 3. MISSING COLUMNS: bookings
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `adults`    INT(11)     NOT NULL DEFAULT 2,
  ADD COLUMN IF NOT EXISTS `children`  INT(11)     NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `extra_bed` TINYINT(1)  NOT NULL DEFAULT 0;

-- ═══════════════════════════════════════════════════════════════
-- 4. MISSING COLUMNS: guests
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `guests`
  ADD COLUMN IF NOT EXISTS `display_id` VARCHAR(50)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `tags`       VARCHAR(255) DEFAULT NULL;

-- ═══════════════════════════════════════════════════════════════
-- 5. MISSING COLUMNS: folio_ledger
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `folio_ledger`
  ADD COLUMN IF NOT EXISTS `display_id` VARCHAR(50) DEFAULT NULL;

-- ═══════════════════════════════════════════════════════════════
-- 6. MISSING COLUMNS: finance_transactions
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `finance_transactions`
  ADD COLUMN IF NOT EXISTS `display_id` VARCHAR(50) DEFAULT NULL;

-- ═══════════════════════════════════════════════════════════════
-- 7. FIX ENUM: folio_ledger.transaction_type — add 'REFUND' value
-- (admin_refund_razorpay.php inserts 'REFUND'; previous ENUM lacked it)
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `folio_ledger`
  MODIFY COLUMN `transaction_type`
    ENUM('online','cash','card','payment','ROOM_CHARGE','INCIDENTAL','REFUND') NOT NULL;

-- ═══════════════════════════════════════════════════════════════
-- 8. MISSING INDEXES (performance & correctness)
-- ═══════════════════════════════════════════════════════════════

-- Fast lookup of a booking by Razorpay order ID (webhook handler)
CREATE INDEX IF NOT EXISTS `idx_bookings_razorpay_order`
  ON `bookings` (`razorpay_order_id`);

-- Fast guest lookup in GuestService::findOrCreate (phone is UNIQUE but explicit idx helps explain plan)
CREATE INDEX IF NOT EXISTS `idx_guests_phone_lookup`
  ON `guests` (`phone`);

-- Fast filtering of active bookings (booking_status + payment_status used everywhere)
CREATE INDEX IF NOT EXISTS `idx_bookings_combined_status`
  ON `bookings` (`booking_status`, `payment_status`);

-- Fast display_id lookup for receipt queries
CREATE INDEX IF NOT EXISTS `idx_folio_display_id`
  ON `folio_ledger` (`display_id`);

-- Fast display_id lookup for finance
CREATE INDEX IF NOT EXISTS `idx_finance_display_id`
  ON `finance_transactions` (`display_id`);

-- Fast display_id lookup for guests
CREATE INDEX IF NOT EXISTS `idx_guests_display_id`
  ON `guests` (`display_id`);

-- ═══════════════════════════════════════════════════════════════
-- 9. SYSTEM SETTINGS defaults for new sequence types
-- ═══════════════════════════════════════════════════════════════
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES
  ('SEQ_BOOKING_FORMAT',     'BKG-{YYYY}{MM}-{ID:4}'),
  ('SEQ_BOOKING_RESET',      'monthly'),
  ('SEQ_BOOKING_MAX',        '9999'),
  ('SEQ_RECEIPT_FORMAT',     'RCPT-{YYYY}{MM}-{ID:5}'),
  ('SEQ_RECEIPT_RESET',      'monthly'),
  ('SEQ_RECEIPT_MAX',        '99999'),
  ('SEQ_TRANSACTION_FORMAT', 'TXN-{YYYY}{MM}-{ID:5}'),
  ('SEQ_TRANSACTION_RESET',  'monthly'),
  ('SEQ_TRANSACTION_MAX',    '99999'),
  ('SEQ_GUEST_FORMAT',       'GST-{ID:6}'),
  ('SEQ_GUEST_RESET',        'never'),
  ('SEQ_GUEST_MAX',          '0');
