-- MicroPMS Migration 013: Multi-Tenant & Housekeeping Schema Formalization
-- Covers:
--   1. property_id on folio_ledger (webhook + delete ledger fixes)
--   2. property_id on staff_users (manage staff add fix)
--   3. Formalize housekeeping tables (previously created at runtime via CREATE IF NOT EXISTS)
--   4. saas_feature_flags table (referenced by SaaSEntitlementsService)
-- Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)

-- ═══════════════════════════════════════════════════════════════
-- 1. ADD property_id TO folio_ledger
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `folio_ledger`
  ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1
    COMMENT 'Tenant property scope for multi-property isolation';

-- Backfill: derive property_id from the parent booking
UPDATE `folio_ledger` fl
  JOIN `bookings` b ON fl.booking_id = b.id
SET fl.property_id = b.property_id
WHERE fl.property_id = 1 OR fl.property_id IS NULL;

CREATE INDEX IF NOT EXISTS `idx_folio_property_id`
  ON `folio_ledger` (`property_id`);

-- ═══════════════════════════════════════════════════════════════
-- 2. ADD property_id TO staff_users (if missing)
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `staff_users`
  ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1
    COMMENT 'The tenant property this staff member belongs to';

CREATE INDEX IF NOT EXISTS `idx_staff_property_id`
  ON `staff_users` (`property_id`);

-- ═══════════════════════════════════════════════════════════════
-- 3. FORMALIZE housekeeping tables (previously runtime-created)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `housekeeping_checklist_items` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `category_id`   INT(11)      DEFAULT NULL COMMENT 'NULL = applies to all room categories',
  `item_text`     VARCHAR(150) NOT NULL,
  `is_mandatory`  TINYINT(1)   NOT NULL DEFAULT 1,
  `display_order` INT(11)      NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hk_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `housekeeping_logs` (
  `id`                  INT(11)     NOT NULL AUTO_INCREMENT,
  `room_id`             INT(11)     NOT NULL,
  `staff_id`            INT(11)     NOT NULL,
  `cleaned_at`          TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `inspector_staff_id`  INT(11)     DEFAULT NULL,
  `inspected_at`        DATETIME    DEFAULT NULL,
  `status`              ENUM('in_progress','cleaned','inspected_ready') NOT NULL DEFAULT 'cleaned',
  `photo_proof`         VARCHAR(255) DEFAULT NULL,
  `notes`               TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hk_log_room`  (`room_id`),
  KEY `idx_hk_log_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `housekeeping_log_items` (
  `id`         INT(11)    NOT NULL AUTO_INCREMENT,
  `hk_log_id`  INT(11)    NOT NULL,
  `item_id`    INT(11)    NOT NULL,
  `is_checked` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_hk_log_items_log` (`hk_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 4. SaaS Feature Flags table (used by SaaSEntitlementsService)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `saas_feature_flags` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `property_id` INT(11)      DEFAULT NULL COMMENT 'NULL = global platform default',
  `flag_key`    VARCHAR(100) NOT NULL,
  `flag_value`  VARCHAR(20)  NOT NULL DEFAULT 'false',
  `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_feature_flag` (`property_id`, `flag_key`),
  KEY `idx_global_flags` (`flag_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 5. team_invitations table (referenced by manage_staff invite action)
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `team_invitations` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `property_id` INT(11)      NOT NULL,
  `email`       VARCHAR(255) NOT NULL,
  `role`        VARCHAR(50)  NOT NULL DEFAULT 'manager',
  `token`       VARCHAR(64)  NOT NULL,
  `used`        TINYINT(1)   NOT NULL DEFAULT 0,
  `expires_at`  DATETIME     NOT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_token` (`token`),
  KEY `idx_invite_property` (`property_id`, `used`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- 6. Missing Core Columns (Catch-up checks)
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `adults` INT(11) NOT NULL DEFAULT 2,
  ADD COLUMN IF NOT EXISTS `children` INT(11) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `extra_bed` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `display_id` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `offline_folio_id` VARCHAR(50) DEFAULT NULL;

ALTER TABLE `folio_ledger`
  ADD COLUMN IF NOT EXISTS `display_id` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(50) DEFAULT NULL;

ALTER TABLE `guests`
  ADD COLUMN IF NOT EXISTS `display_id` VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) DEFAULT NULL;

ALTER TABLE `finance_transactions`
  ADD COLUMN IF NOT EXISTS `display_id` VARCHAR(50) DEFAULT NULL;

-- ═══════════════════════════════════════════════════════════════
-- 7. Missing System Tables (Catch-up checks)
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

CREATE TABLE IF NOT EXISTS `idempotency_keys` (
  `idempotency_key` VARCHAR(255) NOT NULL,
  `response_body` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
