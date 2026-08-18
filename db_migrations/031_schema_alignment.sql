-- Align live DBs with columns/tables the app already uses.
-- Idempotent for MariaDB (ADD COLUMN IF NOT EXISTS / CREATE IF NOT EXISTS).

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `import_ref` VARCHAR(80) NULL DEFAULT NULL AFTER `deleted_at`,
  ADD COLUMN IF NOT EXISTS `actual_checkout` DATETIME NULL DEFAULT NULL AFTER `check_out`;

ALTER TABLE `rooms`
  ADD COLUMN IF NOT EXISTS `last_deep_clean` TIMESTAMP NULL DEFAULT NULL AFTER `state`;

ALTER TABLE `pos_inventory`
  ADD COLUMN IF NOT EXISTS `reorder_level` INT(11) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `reorder_quantity` INT(11) DEFAULT 0;

ALTER TABLE `properties`
  ADD COLUMN IF NOT EXISTS `is_exempt_from_billing` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;

ALTER TABLE `staff_properties`
  ADD COLUMN IF NOT EXISTS `role_id` INT(11) NULL DEFAULT NULL AFTER `property_id`;

ALTER TABLE `staff_users`
  ADD COLUMN IF NOT EXISTS `assistant_role` VARCHAR(40) NULL DEFAULT NULL AFTER `assistant_access`,
  ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) NULL DEFAULT NULL AFTER `username`;

ALTER TABLE `room_maintenance`
  ADD COLUMN IF NOT EXISTS `created_by` INT(11) NULL DEFAULT NULL AFTER `reason`;

ALTER TABLE `wa_messages`
  ADD COLUMN IF NOT EXISTS `property_id` INT(11) NULL DEFAULT NULL AFTER `id`;

ALTER TABLE `folio_ledger`
  MODIFY COLUMN `transaction_type` ENUM('online','cash','card','upi','bank_transfer','payment','ROOM_CHARGE','INCIDENTAL','pos_order','pos_refund','TAX') NOT NULL DEFAULT 'payment';

CREATE TABLE IF NOT EXISTS `guest_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `guest_id` INT(11) NOT NULL,
  `property_id` INT(11) DEFAULT NULL,
  `document_type` VARCHAR(50) NOT NULL DEFAULT 'id_proof',
  `file_path` VARCHAR(255) NOT NULL,
  `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_guest_docs_guest` (`guest_id`, `document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `guest_reviews` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `booking_id` INT(11) NOT NULL,
  `property_id` INT(11) NOT NULL,
  `rating` TINYINT(1) NOT NULL DEFAULT 5,
  `comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reviews_booking` (`booking_id`),
  KEY `idx_reviews_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `email_report_config` (
  `property_id` INT(11) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `daily_audit_emails` TEXT DEFAULT NULL,
  `weekly_revenue_emails` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `saas_leads` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `hotel_name` VARCHAR(190) NOT NULL,
  `contact_name` VARCHAR(190) DEFAULT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `city` VARCHAR(120) DEFAULT NULL,
  `plan` VARCHAR(40) NOT NULL DEFAULT 'starter',
  `rooms_estimate` INT(11) DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'new',
  `property_id` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leads_status` (`status`),
  KEY `idx_leads_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
