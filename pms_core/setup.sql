SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `staff_id` (`staff_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(255) NOT NULL,
  `contact_details` varchar(255) DEFAULT NULL,
  `credit_limit` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `city_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('charge','payment') NOT NULL,
  `status` enum('pending','paid') DEFAULT 'pending',
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `city_ledger_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `city_ledger_booking_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB ;


CREATE TABLE IF NOT EXISTS `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `display_id` varchar(50) DEFAULT NULL,
  `offline_folio_id` varchar(50) DEFAULT NULL,
  `room_id` int(11) NOT NULL,
  `guest_id` int(11) DEFAULT NULL,
  `check_in` datetime NOT NULL,
  `check_out` datetime NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `payment_status` enum('pending_hold','completed_paid','cancelled') DEFAULT 'pending_hold',
  `booking_status` enum('booked','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'booked',
  `razorpay_order_id` varchar(50) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `tax_preference` enum('exclusive','inclusive','exempt') DEFAULT 'exclusive',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `checkout_warning_sent` tinyint(1) DEFAULT 0,
  `rate_plan_name` varchar(100) DEFAULT NULL,
  `booking_source` varchar(50) DEFAULT 'Walk-in',
  `price_override` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guest_id` (`guest_id`),
  KEY `idx_collision_guard` (`room_id`,`check_in`,`check_out`,`payment_status`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`),
  CONSTRAINT `bookings_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `finance_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('income','expense') NOT NULL,
  `category` varchar(50) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `staff_id` (`staff_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `finance_transactions_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_booking_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `folio_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `transaction_type` enum('online','cash','card','payment','ROOM_CHARGE','INCIDENTAL') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `transaction_ref` varchar(100) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  UNIQUE KEY `uq_folio_booking_ref` (`booking_id`,`transaction_ref`),
  CONSTRAINT `folio_ledger_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `guests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'India',
  `pincode` varchar(10) DEFAULT NULL,
  `id_proof_front` varchar(255) DEFAULT NULL,
  `id_proof_back` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `digital_signature` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `room_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `property_name_idx` (`property_id`, `name`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL DEFAULT 1,
  `room_number` varchar(10) NOT NULL,
  `category_id` int(11) NOT NULL,
  `state` enum('clean','dirty','out_of_order') DEFAULT 'clean',
  PRIMARY KEY (`id`),
  UNIQUE KEY `property_room_idx` (`property_id`, `room_number`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `room_categories` (`id`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `sliding_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `hours` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `rate_plan_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_plan_hours` (`category_id`,`rate_plan_name`,`hours`),
  CONSTRAINT `sliding_rates_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `room_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `staff_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `access_level` enum('owner','manager','housekeeping') NOT NULL DEFAULT 'manager',
  `role` varchar(50) DEFAULT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `assistant_access` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `login_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `key_name` varchar(100) NOT NULL,
  `key_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `wa_automation_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_name` varchar(100) NOT NULL,
  `event_key` varchar(100) NOT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_key` (`event_key`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `wa_automations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_key` varchar(100) NOT NULL,
  `template_id` int(11) NOT NULL,
  `variable_mapping_json` text NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_key` (`event_key`),
  KEY `template_id` (`template_id`),
  CONSTRAINT `wa_automations_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `wa_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `wa_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guest_id` int(11) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `last_message_at` datetime DEFAULT current_timestamp(),
  `status` enum('open','resolved','snoozed') DEFAULT 'open',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_number` (`phone_number`),
  KEY `guest_id` (`guest_id`),
  CONSTRAINT `wa_conversations_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `wa_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `message_text` text NOT NULL,
  `status` enum('sent','delivered','read','received','failed') DEFAULT 'sent',
  `message_id` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  CONSTRAINT `wa_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `wa_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `wa_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `language` varchar(10) NOT NULL,
  `components_json` text NOT NULL,
  `status` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`,`language`)
) ENGINE=InnoDB ;


CREATE TABLE IF NOT EXISTS `wa_delivery_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_key` varchar(100) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `message_id` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `meta_status` varchar(20) DEFAULT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_key` (`event_key`),
  KEY `message_id` (`message_id`)
) ENGINE=InnoDB;

-- Initial Data for System Automation Events
INSERT IGNORE INTO `wa_automation_events` (`event_name`, `event_key`, `is_system`) VALUES 
('Booking Confirmed', 'booking_confirmed', 1),
('Guest Checked In', 'guest_check_in', 1),
('Guest Checked Out', 'guest_check_out', 1),
('Booking Cancelled', 'booking_cancelled', 1),
('Payment Link Sent', 'payment_link', 1),
('Guest Review Form', 'guest_review_form', 1),
('Guest Invoice', 'guest_invoice', 1),
('Pre-Departure Warning', 'pre_departure', 1);

-- Default System Settings
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES 
('payment_methods', '["Cash","UPI","Online / Gateway","Card / POS","Bank Transfer"]'),
('SEQ_FOLIO_FORMAT', '{ID}'),
('SEQ_FOLIO_RESET', 'never'),
('SEQ_FOLIO_MAX', '150');

-- Performance Indexes
CREATE INDEX IF NOT EXISTS `idx_bookings_dates` ON `bookings` (`check_in`, `check_out`);
CREATE INDEX IF NOT EXISTS `idx_finance_dates` ON `finance_transactions` (`recorded_at`);
CREATE INDEX IF NOT EXISTS `idx_folio_booking_type` ON `folio_ledger` (`booking_id`, `transaction_type`);
CREATE INDEX IF NOT EXISTS `idx_folio_recorded_at` ON `folio_ledger` (`recorded_at`);
-- WhatsApp: index for fast idempotency check (webhook deduplication)
CREATE INDEX IF NOT EXISTS `idx_wa_messages_message_id` ON `wa_messages` (`message_id`);
-- WhatsApp: index for fast unread count query
CREATE INDEX IF NOT EXISTS `idx_wa_messages_status_dir` ON `wa_messages` (`direction`, `status`);
-- WhatsApp: conversation list sort index
CREATE INDEX IF NOT EXISTS `idx_wa_conv_last_msg` ON `wa_conversations` (`last_message_at` DESC);
CREATE INDEX IF NOT EXISTS `idx_bookings_room_status` ON `bookings` (`room_id`, `booking_status`);
CREATE INDEX IF NOT EXISTS `idx_guests_name` ON `guests` (`name`);

-- Track and prevent API double submit idempotency
CREATE TABLE IF NOT EXISTS `idempotency_keys` (
  `property_id` int(11) NOT NULL DEFAULT 1,
  `idempotency_key` varchar(255) NOT NULL,
  `response_body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`property_id`, `idempotency_key`)
) ENGINE=InnoDB;

-- Migration: Add tax_preference to bookings if upgrading existing database
ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `tax_preference` ENUM('exclusive','inclusive','exempt') DEFAULT 'exclusive';

-- Migration: Fix audit_logs FK to allow staff deletion
ALTER TABLE `audit_logs` MODIFY COLUMN `staff_id` int(11) DEFAULT NULL;
ALTER TABLE `audit_logs` DROP FOREIGN KEY IF EXISTS `audit_logs_ibfk_1`;
ALTER TABLE `audit_logs` ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL;

-- Migration: Add email column to guests if upgrading existing database
ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `email` varchar(255) DEFAULT NULL;

-- Sequence counters table for custom sequence resets
CREATE TABLE IF NOT EXISTS `sequence_counters` (
  `module` varchar(50) NOT NULL,
  `period` varchar(10) NOT NULL,
  `current_value` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`module`, `period`)
) ENGINE=InnoDB;

-- Housekeeping Checklist & Inspection Logs
CREATE TABLE IF NOT EXISTS `housekeeping_checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `item_text` varchar(150) NOT NULL,
  `is_mandatory` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `housekeeping_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `cleaned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `inspector_staff_id` int(11) DEFAULT NULL,
  `inspected_at` datetime DEFAULT NULL,
  `status` enum('in_progress','cleaned','inspected_ready') DEFAULT 'cleaned',
  `photo_proof` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `room_id` (`room_id`),
  KEY `staff_id` (`staff_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `housekeeping_log_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hk_log_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `is_checked` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `hk_log_id` (`hk_log_id`),
  CONSTRAINT `hk_log_items_fk_1` FOREIGN KEY (`hk_log_id`) REFERENCES `housekeeping_logs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO `housekeeping_checklist_items` (`id`, `item_text`, `is_mandatory`, `display_order`) VALUES 
(1, 'Replace Bed Linen & Pillow Covers', 1, 1),
(2, 'Sanitize & Scrub Bathroom / Toilet', 1, 2),
(3, 'Replenish Towels & Toiletries', 1, 3),
(4, 'Sweep & Mop Floor', 1, 4),
(5, 'Restock Drinking Water Bottles', 0, 5),
(6, 'Sanitize TV & AC Remote Controls', 0, 6),
(7, 'Empty Trash Cans & Insert Liners', 1, 7);

-- SaaS Control Plane Integration Schema
CREATE TABLE IF NOT EXISTS `properties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'India',
  `pincode` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `gstin` varchar(20) DEFAULT NULL,
  `custom_domain` varchar(255) DEFAULT NULL UNIQUE,
  `plan` varchar(50) NOT NULL DEFAULT 'starter',
  `max_rooms` int(11) NOT NULL DEFAULT 15,
  `max_staff` int(11) NOT NULL DEFAULT 5,
  `stripe_customer_id` varchar(100) DEFAULT NULL,
  `stripe_subscription_id` varchar(100) DEFAULT NULL,
  `subscription_status` varchar(50) NOT NULL DEFAULT 'trialing',
  `valid_until` datetime DEFAULT NULL,
  `features_json` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prop_custom_domain` (`custom_domain`),
  KEY `idx_prop_stripe` (`stripe_customer_id`, `stripe_subscription_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4;

-- Create roles master table for granular RBAC permissions
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `property_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `permissions` JSON NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_roles_property` (`property_id`),
  FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `team_invitations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'manager',
  `token` varchar(100) NOT NULL UNIQUE,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `idx_invitation_token` (`token`),
  CONSTRAINT `team_invitations_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `saas_feature_flags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) DEFAULT NULL,
  `flag_key` varchar(100) NOT NULL,
  `flag_value` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_property_flag` (`property_id`, `flag_key`),
  CONSTRAINT `saas_feature_flags_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration: add property_id to core tables if missing
ALTER TABLE `rooms` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `staff_users` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `room_categories` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `sliding_rates` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `finance_transactions` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `audit_logs` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;


-- MicroPMS: Roles & Monitoring Migration
-- Run once on the production database.
-- Safe to re-run — all statements use IF NOT EXISTS / MODIFY safely.

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Upgrade staff_users
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE staff_users
  MODIFY COLUMN access_level
    ENUM('superadmin','owner','admin','manager','receptionist','housekeeping','front_desk')
    NOT NULL DEFAULT 'manager';

ALTER TABLE staff_users
  ADD COLUMN IF NOT EXISTS last_login_at  TIMESTAMP    NULL     COMMENT 'Last successful login time',
  ADD COLUMN IF NOT EXISTS last_login_ip  VARCHAR(45)  NULL     COMMENT 'Last login IP address',
  ADD COLUMN IF NOT EXISTS login_count    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total successful logins',
  ADD COLUMN IF NOT EXISTS is_active      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = deactivated';

-- Migrate any legacy 'front_desk' accounts to 'manager'
UPDATE staff_users SET access_level = 'manager' WHERE access_level = 'front_desk';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Login brute-force tracking
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(100) NOT NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  success      TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_username_time (username, attempted_at),
  INDEX idx_ip_time       (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Structured error log
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS error_logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  severity     ENUM('info','warning','error','critical') NOT NULL DEFAULT 'error',
  category     VARCHAR(50)  NOT NULL COMMENT 'payment|whatsapp|database|auth|booking|system',
  message      TEXT         NOT NULL,
  context      JSON         NULL     COMMENT 'booking_id, guest, amount, api_response, uri, etc.',
  staff_id     INT UNSIGNED NULL,
  request_uri  VARCHAR(500) NULL,
  ip_address   VARCHAR(45)  NULL,
  resolved     TINYINT(1)   NOT NULL DEFAULT 0,
  resolved_at  TIMESTAMP    NULL,
  resolved_by  INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_severity_cat (severity, category),
  INDEX idx_unresolved   (resolved, created_at),
  INDEX idx_category     (category, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Database Migration: Add Booking Assistant Quick Login PIN and access permission
-- Safe to re-run.

ALTER TABLE staff_users
  ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) DEFAULT NULL COMMENT 'Hashed 4-digit PIN for quick login',
  ADD COLUMN IF NOT EXISTS assistant_access TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Allowed to access Booking Assistant PWA';

-- Give admin/owner full access by default
UPDATE staff_users SET assistant_access = 1 WHERE access_level = 'owner' OR username = 'admin';
-- Migration 003: Add role column for assistant compatibility
-- Safe to re-run. Builds on migrations 001 and 002.

-- Add role column if it doesn't exist
ALTER TABLE `staff_users` 
  ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) DEFAULT NULL COMMENT 'Normalized role name for assistant compatibility';

-- Sync role column with access_level for existing users
UPDATE `staff_users` SET `role` = `access_level` WHERE `role` IS NULL;
-- Migration 004: Add guest notes, preferences, and quick charges setting
-- Safe to re-run.

-- Add notes and preferences columns to guests
ALTER TABLE `guests` 
  ADD COLUMN IF NOT EXISTS `notes` TEXT DEFAULT NULL COMMENT 'Internal staff notes about guest preferences',
  ADD COLUMN IF NOT EXISTS `preferences` TEXT DEFAULT NULL COMMENT 'JSON: dietary, room type, pillow, etc.';

-- Add folio quick charges setting
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES 
('folio_quick_charges', '[{"name":"Breakfast","amount":150,"icon":"ph-coffee"},{"name":"Lunch","amount":250,"icon":"ph-fork-knife"},{"name":"Dinner","amount":300,"icon":"ph-fork-knife"},{"name":"Laundry","amount":100,"icon":"ph-t-shirt"},{"name":"Room Service","amount":200,"icon":"ph-bell"},{"name":"Minibar","amount":150,"icon":"ph-wine"}]');

-- Add booking notes table for internal staff notes
CREATE TABLE IF NOT EXISTS `booking_notes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NOT NULL,
  `staff_id` INT DEFAULT NULL,
  `note` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_booking_notes` (`booking_id`),
  CONSTRAINT `fk_booking_notes_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_notes_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Migration 005: Night Audit System
-- Safe to re-run.

-- Night Audit Settings (inserted via system_settings)
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES 
('night_audit_enabled', 'false'),
('night_audit_time', '02:00'),
('night_audit_auto_checkout', 'true'),
('night_audit_auto_checkout_hours', '2'),
('night_audit_mark_dirty', 'true'),
('night_audit_notify_telegram', 'true'),
('night_audit_notify_whatsapp', 'false'),
('night_audit_report_revenue', 'true'),
('night_audit_report_occupancy', 'true'),
('night_audit_report_room_status', 'true'),
('night_audit_report_bookings', 'true');

-- Night Audit Log Table
CREATE TABLE IF NOT EXISTS `night_audit_log` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `audit_date` DATE NOT NULL,
  `run_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `run_by` VARCHAR(50) DEFAULT 'system' COMMENT 'system (cron) or staff username',
  `total_rooms` INT UNSIGNED NOT NULL DEFAULT 0,
  `occupied_rooms` INT UNSIGNED NOT NULL DEFAULT 0,
  `arrivals_today` INT UNSIGNED NOT NULL DEFAULT 0,
  `departures_today` INT UNSIGNED NOT NULL DEFAULT 0,
  `overdue_checkouts` INT UNSIGNED NOT NULL DEFAULT 0,
  `auto_checkout_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `rooms_marked_dirty` INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue_collected` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `revenue_pending` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `actions_json` JSON NULL COMMENT 'Detailed actions taken during audit',
  `status` ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
  `error_message` TEXT NULL,
  UNIQUE KEY `uk_audit_date` (`audit_date`),
  INDEX `idx_audit_date` (`audit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Migration 006: Micro PMS Operational & Audit Improvements

-- 1. Add transaction_type, folio_bucket, and reference fields to folio_ledger if not present
ALTER TABLE folio_ledger ADD COLUMN IF NOT EXISTS folio_bucket ENUM('main', 'incidentals') DEFAULT 'main';
ALTER TABLE folio_ledger ADD COLUMN IF NOT EXISTS is_refund TINYINT(1) DEFAULT 0;

-- 2. Add tags and internal notes to guests table
ALTER TABLE guests ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL;
ALTER TABLE guests ADD COLUMN IF NOT EXISTS internal_notes TEXT DEFAULT NULL;

-- 3. Room Maintenance & Out Of Order Date Blocking Table
CREATE TABLE IF NOT EXISTS `room_maintenance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Add index for faster ledger queries
CREATE INDEX IF NOT EXISTS idx_folio_booking ON folio_ledger(booking_id, recorded_at);
CREATE INDEX IF NOT EXISTS idx_finance_date ON finance_transactions(recorded_at);
-- Migration 007: Extended Audit Improvements (Medium & Long Term Features)

-- 1. Explicit transaction_type values for folio_ledger to support clean refunds & charges
ALTER TABLE folio_ledger MODIFY COLUMN transaction_type VARCHAR(50) NOT NULL;

-- 2. Ensure guests table has tags & internal notes
ALTER TABLE guests ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL;
ALTER TABLE guests ADD COLUMN IF NOT EXISTS internal_notes TEXT DEFAULT NULL;

-- 3. Room Maintenance table for date-range out-of-order room blocking
CREATE TABLE IF NOT EXISTS `room_maintenance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT NOT NULL,
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('active','completed','cancelled') DEFAULT 'active',
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Add index for maintenance collision checks
CREATE INDEX IF NOT EXISTS idx_maint_dates ON room_maintenance(room_id, start_date, end_date);
-- Migration 008: Multi-Property Tenant Isolation Schema for MicroPMS SaaS Edition

-- 1. Create properties master table
CREATE TABLE IF NOT EXISTS `properties` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT 'India',
  `pincode` VARCHAR(10) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4;

-- Seed default primary property

-- 2. Add property_id to staff_users
ALTER TABLE `staff_users` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `staff_users` ADD INDEX IF NOT EXISTS `idx_staff_property` (`property_id`);

-- 3. Add property_id to room_categories and rooms
ALTER TABLE `room_categories` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `room_categories` ADD INDEX IF NOT EXISTS `idx_room_cat_property` (`property_id`);

ALTER TABLE `rooms` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `rooms` ADD INDEX IF NOT EXISTS `idx_rooms_property` (`property_id`);

-- 4. Add property_id to sliding_rates
ALTER TABLE `sliding_rates` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `sliding_rates` ADD INDEX IF NOT EXISTS `idx_rates_property` (`property_id`);

-- 5. Add property_id to bookings
ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `bookings` ADD INDEX IF NOT EXISTS `idx_bookings_property` (`property_id`);

-- 6. Add property_id to finance_transactions
ALTER TABLE `finance_transactions` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `finance_transactions` ADD INDEX IF NOT EXISTS `idx_finance_property` (`property_id`);

-- 7. Add property_id to wa_automations and wa_conversations
ALTER TABLE `wa_automations` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `wa_automations` ADD INDEX IF NOT EXISTS `idx_wa_auto_property` (`property_id`);

ALTER TABLE `wa_conversations` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
ALTER TABLE `wa_conversations` ADD INDEX IF NOT EXISTS `idx_wa_conv_property` (`property_id`);

-- 8. Add property_id to system_settings
ALTER TABLE `system_settings` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;
-- Migration 009: SaaS Commercial Features Upgrade
-- Adds subscription plans, room limits, tenant validity, and custom settings to properties table

ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `plan` ENUM('trial','starter','pro','enterprise') DEFAULT 'starter';
ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `max_rooms` INT DEFAULT 25;
ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `subscription_status` ENUM('active','trialing','past_due','cancelled') DEFAULT 'active';
ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `valid_until` DATE DEFAULT NULL;
ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `custom_domain` VARCHAR(150) DEFAULT NULL;
ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `whatsapp_phone_number_id` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `razorpay_key_id` VARCHAR(100) DEFAULT NULL;
ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `razorpay_key_secret` VARCHAR(100) DEFAULT NULL;

-- Update default property 1 to enterprise unlimited
-- UPDATE `properties` SET `plan` = 'enterprise', `max_rooms` = 999, `subscription_status` = 'active' WHERE `id` = 1;
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
-- Migration 011: Integrated Micro POS and Inventory Management Module

CREATE TABLE IF NOT EXISTS `inventory_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `sku` VARCHAR(100) NULL,
    `stock_qty` INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `cost_price` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `selling_price` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT NOT NULL,
    `booking_id` INT NULL, -- NULL if walk-in direct purchase
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `payment_method` ENUM('cash', 'card', 'upi', 'room_charge') NOT NULL DEFAULT 'cash',
    `status` ENUM('paid', 'posted') NOT NULL DEFAULT 'paid', -- posted if added to folio
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `price_per_unit` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `pos_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexing for quick lookups
CREATE INDEX `idx_inventory_property` ON `inventory_items`(`property_id`);
CREATE INDEX `idx_pos_orders_booking` ON `pos_orders`(`booking_id`);
-- Migration 012: POS Multi-Shop Outlets & Guest Portal Ordering

CREATE TABLE IF NOT EXISTS `pos_outlets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add outlet_id and image fields to inventory_items
ALTER TABLE `inventory_items` 
ADD COLUMN `outlet_id` INT NULL AFTER `property_id`,
ADD COLUMN `image_url` VARCHAR(500) NULL AFTER `selling_price`,
ADD CONSTRAINT `fk_inventory_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `pos_outlets`(`id`) ON DELETE SET NULL;

-- Add outlet_id, delivery_status, and source to pos_orders
ALTER TABLE `pos_orders`
ADD COLUMN `outlet_id` INT NULL AFTER `property_id`,
ADD COLUMN `source` ENUM('admin', 'guest_portal') NOT NULL DEFAULT 'admin' AFTER `status`,
ADD COLUMN `delivery_status` ENUM('delivered', 'pending', 'cancelled') NOT NULL DEFAULT 'delivered' AFTER `source`,
ADD CONSTRAINT `fk_orders_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `pos_outlets`(`id`) ON DELETE SET NULL;

-- Seed default outlets for existing properties
INSERT INTO `pos_outlets` (`property_id`, `name`)
SELECT id, 'Restaurant' FROM properties
UNION ALL
SELECT id, 'Cool Drink Shop' FROM properties
UNION ALL
SELECT id, 'General Store' FROM properties;
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
  MODIFY COLUMN `access_level` ENUM('superadmin','owner','admin','manager','receptionist','housekeeping','front_desk') NOT NULL DEFAULT 'manager';

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
  ADD COLUMN IF NOT EXISTS `logo_url`     VARCHAR(255) DEFAULT NULL;

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

CREATE TABLE IF NOT EXISTS `jobs_queue` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `queue_name` VARCHAR(50) DEFAULT 'default',
    `payload_json` JSON NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    `attempts` INT DEFAULT 0,
    `max_attempts` INT DEFAULT 3,
    `available_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status_queue` (`status`, `queue_name`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 16: Granular Tax Breakdown
ALTER TABLE `folio_ledger` ADD COLUMN IF NOT EXISTS `cgst_amount` DECIMAL(10,2) DEFAULT 0.00;
ALTER TABLE `folio_ledger` ADD COLUMN IF NOT EXISTS `sgst_amount` DECIMAL(10,2) DEFAULT 0.00;

-- Phase 16: F&B Inventory
CREATE TABLE IF NOT EXISTS `pos_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════════════════
-- MicroPMS Migration 016: SaaS Missing Tenant Isolation Fixes
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'guests' AND index_name = 'phone') > 0, 'ALTER TABLE `guests` DROP INDEX `phone`', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'guests' AND index_name = 'uq_guest_prop_phone') = 0, 'ALTER TABLE `guests` ADD UNIQUE KEY `uq_guest_prop_phone` (`property_id`, `phone`)', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `sequence_counters` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `sequence_counters` DROP PRIMARY KEY, ADD PRIMARY KEY (`property_id`, `module`, `period`);

ALTER TABLE `night_audit_log` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'night_audit_log' AND index_name = 'uk_audit_date') > 0, 'ALTER TABLE `night_audit_log` DROP INDEX `uk_audit_date`', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'night_audit_log' AND index_name = 'idx_audit_date') > 0, 'ALTER TABLE `night_audit_log` DROP INDEX `idx_audit_date`', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'night_audit_log' AND index_name = 'uq_audit_prop_date') = 0, 'ALTER TABLE `night_audit_log` ADD UNIQUE KEY `uq_audit_prop_date` (`property_id`, `audit_date`)', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `error_logs` ADD COLUMN IF NOT EXISTS `property_id` INT(11) DEFAULT NULL;
ALTER TABLE `login_attempts` ADD COLUMN IF NOT EXISTS `property_id` INT(11) DEFAULT NULL;
ALTER TABLE `housekeeping_checklist_items` ADD COLUMN IF NOT EXISTS `property_id` INT(11) DEFAULT NULL;

ALTER TABLE `wa_templates` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `wa_delivery_logs` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'wa_templates' AND index_name = 'name') > 0, 'ALTER TABLE `wa_templates` DROP INDEX `name`', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'wa_templates' AND index_name = 'uq_wa_tpl_prop_name') = 0, 'ALTER TABLE `wa_templates` ADD UNIQUE KEY `uq_wa_tpl_prop_name` (`property_id`, `name`, `language`)', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `inventory_restock_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty_added` int(11) NOT NULL,
  `old_stock` int(11) NOT NULL,
  `new_stock` int(11) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `restocked_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_restock_prop` (`property_id`),
  KEY `fk_restock_item` (`item_id`),
  CONSTRAINT `fk_restock_prop` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_restock_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `saved_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `dataset` varchar(50) NOT NULL,
  `columns` text NOT NULL,
  `filters` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  CONSTRAINT `saved_reports_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guest_service_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `service_type` varchar(50) NOT NULL,
  `status` enum('pending','in_progress','completed','rejected') NOT NULL DEFAULT 'pending',
  `resolved_at` datetime DEFAULT NULL,

  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `night_audit_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `issue_type` varchar(50) NOT NULL,
  `amount` decimal(10,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `fk_night_audit_actions_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_night_audit_actions_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Phase 17: Architectural and Enterprise Updates
ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(50) DEFAULT 'Asia/Kolkata';
ALTER TABLE `jobs_queue` ADD COLUMN IF NOT EXISTS `dead_letter` TINYINT(1) DEFAULT 0;
ALTER TABLE `jobs_queue` ADD COLUMN IF NOT EXISTS `error_log` TEXT DEFAULT NULL;
ALTER TABLE `pos_inventory` ADD COLUMN IF NOT EXISTS `reorder_level` INT(11) DEFAULT 0;
ALTER TABLE `pos_inventory` ADD COLUMN IF NOT EXISTS `reorder_quantity` INT(11) DEFAULT 0;



-- Add deleted_at soft-delete column to all relevant tables
ALTER TABLE `audit_logs` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `city_ledger` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `error_logs` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `finance_transactions` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `folio_ledger` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `housekeeping_checklist_items` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `housekeeping_logs` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `idempotency_keys` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `login_attempts` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `night_audit_log` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `payment_gateway_configs` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `pos_inventory` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `pos_orders` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `roles` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `room_categories` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `rooms` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `sequence_counters` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `sliding_rates` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `staff_properties` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `staff_users` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `system_settings` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `wa_automations` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `wa_conversations` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `wa_delivery_logs` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `wa_messages` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;
ALTER TABLE `wa_templates` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME DEFAULT NULL;

ALTER TABLE `jobs_queue` ADD COLUMN IF NOT EXISTS `property_id` INT NULL DEFAULT NULL AFTER `queue_name`;
ALTER TABLE `folio_ledger` ADD COLUMN IF NOT EXISTS `category` VARCHAR(50) DEFAULT NULL AFTER `payment_method`;
ALTER TABLE `guest_service_requests` ADD INDEX IF NOT EXISTS `idx_gsr_property_status` (`property_id`, `status`);
ALTER TABLE `jobs_queue` ADD INDEX IF NOT EXISTS `idx_jobs_queue_property` (`property_id`);

CREATE TABLE IF NOT EXISTS `automation_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `event_key` varchar(100) NOT NULL,
  `is_wa_active` tinyint(1) DEFAULT 0,
  `wa_template_id` int(11) DEFAULT NULL,
  `wa_mapping_json` text DEFAULT NULL,
  `is_email_active` tinyint(1) DEFAULT 0,
  `email_subject` varchar(255) DEFAULT NULL,
  `email_body_html` text DEFAULT NULL,
  `is_telegram_active` tinyint(1) DEFAULT 0,
  `telegram_body_text` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auto_rule_prop_event` (`property_id`, `event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `telegram_bot_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(50) NOT NULL,
  `property_id` int(11) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `context_data` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tg_session_chat` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `processed_webhook_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `provider` varchar(40) NOT NULL,
  `event_id` varchar(120) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_provider_event` (`provider`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` VARCHAR(50) NOT NULL PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `execution_time_ms` INT UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
