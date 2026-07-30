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
UPDATE `properties` SET `plan` = 'enterprise', `max_rooms` = 999, `subscription_status` = 'active' WHERE `id` = 1;
