CREATE DATABASE IF NOT EXISTS pms_db;
USE pms_db;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` enum('ROOM','BOOKING','FOLIO','SYSTEM') NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `staff_id` (`staff_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `display_id` varchar(50) DEFAULT NULL,
  `offline_folio_id` varchar(50) DEFAULT NULL,
  `room_id` int(11) NOT NULL,
  `guest_id` int(11) DEFAULT NULL,
  `check_in` datetime NOT NULL,
  `check_out` datetime NOT NULL,
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
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`)
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
  CONSTRAINT `finance_transactions_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `room_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB ;

CREATE TABLE IF NOT EXISTS `rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(10) NOT NULL,
  `category_id` int(11) NOT NULL,
  `state` enum('clean','dirty','out_of_order') DEFAULT 'clean',
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_number` (`room_number`),
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
  `idempotency_key` varchar(255) NOT NULL,
  `response_body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`idempotency_key`)
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
  `property_code` varchar(50) NOT NULL UNIQUE,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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


