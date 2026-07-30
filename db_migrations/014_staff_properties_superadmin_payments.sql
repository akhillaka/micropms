-- MicroPMS Migration 014: Staff Properties, Superadmin Role, Payment Gateways, Login Attempts
-- Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)

-- ═══════════════════════════════════════════════════════════════
-- 1. staff_properties — maps staff to multiple properties
--    (Referenced in router.php but never created — critical fix)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `staff_properties` (
  `id`          INT(11)   NOT NULL AUTO_INCREMENT,
  `staff_id`    INT(11)   NOT NULL,
  `property_id` INT(11)   NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_property` (`staff_id`, `property_id`),
  KEY `idx_sp_property` (`property_id`),
  KEY `idx_sp_staff`    (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill existing staff into their assigned property
INSERT IGNORE INTO `staff_properties` (`staff_id`, `property_id`)
SELECT `id`, `property_id` FROM `staff_users` WHERE `property_id` IS NOT NULL AND `property_id` > 0;

-- ═══════════════════════════════════════════════════════════════
-- 2. superadmin access level on staff_users
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `staff_users`
  MODIFY COLUMN `access_level` ENUM('superadmin','owner','admin','manager','receptionist','housekeeping') NOT NULL DEFAULT 'manager';

-- ═══════════════════════════════════════════════════════════════
-- 3. login_attempts — brute force tracking (referenced in login.php)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT(11)     NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(50) NOT NULL,
  `ip_address`   VARCHAR(45) NOT NULL,
  `success`      TINYINT(1)  NOT NULL DEFAULT 0,
  `attempted_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_user_ip` (`username`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 4. payment_gateway_configs — per property gateway settings
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `payment_gateway_configs` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `property_id`   INT(11)      NOT NULL,
  `gateway`       ENUM('razorpay','phonepe') NOT NULL,
  `mode`          ENUM('test','live') NOT NULL DEFAULT 'test',
  `key_id`        VARCHAR(255) DEFAULT NULL COMMENT 'Razorpay Key ID or PhonePe Merchant ID',
  `key_secret`    VARCHAR(255) DEFAULT NULL COMMENT 'Razorpay Key Secret or PhonePe Salt Key',
  `extra_config`  JSON         DEFAULT NULL COMMENT 'Additional config (PhonePe: salt_index, redirect_url)',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_gateway` (`property_id`, `gateway`),
  KEY `idx_pgc_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 5. saas_subscriptions — SaaS billing records
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `saas_subscriptions` (
  `id`              INT(11)       NOT NULL AUTO_INCREMENT,
  `property_id`     INT(11)       NOT NULL,
  `gateway`         ENUM('razorpay','phonepe','manual') NOT NULL DEFAULT 'manual',
  `gateway_sub_id`  VARCHAR(255)  DEFAULT NULL,
  `plan`            VARCHAR(50)   NOT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `currency`        VARCHAR(10)   NOT NULL DEFAULT 'INR',
  `status`          ENUM('active','trialing','past_due','cancelled','manual') NOT NULL DEFAULT 'trialing',
  `starts_at`       DATETIME      NOT NULL,
  `ends_at`         DATETIME      NOT NULL,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_saas_sub_property` (`property_id`),
  KEY `idx_saas_sub_status`   (`status`),
  CONSTRAINT `fk_saas_sub_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 6. Fix folio_ledger transaction_type enum — add POS types
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `folio_ledger`
  MODIFY COLUMN `transaction_type` ENUM(
    'online','cash','card','upi','bank_transfer',
    'payment','ROOM_CHARGE','INCIDENTAL',
    'pos_order','pos_refund'
  ) NOT NULL DEFAULT 'payment';

-- ═══════════════════════════════════════════════════════════════
-- 7. Add property_id to housekeeping_logs for multi-tenancy
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `housekeeping_logs`
  ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

CREATE INDEX IF NOT EXISTS `idx_hk_log_property` ON `housekeeping_logs` (`property_id`);

-- ═══════════════════════════════════════════════════════════════
-- 8. system_settings defaults for setup wizard
-- ═══════════════════════════════════════════════════════════════
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES
  ('SETUP_COMPLETE', '0'),
  ('SAAS_MODE', '0'),
  ('DEFAULT_CURRENCY', 'INR'),
  ('DEFAULT_TIMEZONE', 'Asia/Kolkata'),
  ('PLATFORM_NAME', 'MicroPMS');

-- ═══════════════════════════════════════════════════════════════
-- 9. Additional columns on properties table
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `properties`
  ADD COLUMN IF NOT EXISTS `trial_days`   INT(11) NOT NULL DEFAULT 14,
  ADD COLUMN IF NOT EXISTS `notes`        TEXT    DEFAULT NULL COMMENT 'Internal superadmin notes',
  ADD COLUMN IF NOT EXISTS `timezone`     VARCHAR(100) NOT NULL DEFAULT 'Asia/Kolkata',
  ADD COLUMN IF NOT EXISTS `currency`     VARCHAR(10)  NOT NULL DEFAULT 'INR',
  ADD COLUMN IF NOT EXISTS `logo_url`     VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_exempt_from_billing` TINYINT(1) DEFAULT 0;

-- ═══════════════════════════════════════════════════════════════
-- 10. Add property_id to pos_orders and inventory (multi-tenancy)
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `pos_orders`
  ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

ALTER TABLE `inventory_items`
  ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

ALTER TABLE `pos_outlets`
  ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

CREATE INDEX IF NOT EXISTS `idx_pos_orders_property` ON `pos_orders` (`property_id`);
CREATE INDEX IF NOT EXISTS `idx_inventory_property`  ON `inventory_items` (`property_id`);
CREATE INDEX IF NOT EXISTS `idx_pos_outlets_property` ON `pos_outlets` (`property_id`);
