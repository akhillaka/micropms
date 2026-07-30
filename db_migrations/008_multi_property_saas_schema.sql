-- Migration 008: Multi-Property Tenant Isolation Schema for MicroPMS SaaS Edition

-- 1. Create properties master table
CREATE TABLE IF NOT EXISTS `properties` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `property_code` VARCHAR(50) NOT NULL UNIQUE,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default primary property
INSERT IGNORE INTO `properties` (`id`, `property_code`, `name`) VALUES (1, 'PROP-DEFAULT', 'Primary Hotel Property');

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
