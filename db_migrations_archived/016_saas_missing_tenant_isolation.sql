-- MicroPMS Migration 016: SaaS Missing Tenant Isolation Fixes
-- Safe to run multiple times (uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)

-- ═══════════════════════════════════════════════════════════════
-- 1. ADD property_id TO guests
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `guests` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

-- Update unique index to be tenant-scoped
-- Since phone was UNIQUE KEY `phone`, we must drop it safely
-- But IF EXISTS for dropping indexes isn't standard in older MySQL.
-- We can use a safe procedure or just do it. Let's do it directly.
-- Using a common trick to drop index safely in MySQL 8 / MariaDB or ignore error
SET @s = (SELECT IF(
    (SELECT COUNT(1)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE table_schema = DATABASE() AND table_name = 'guests' AND index_name = 'phone'
    ) > 0,
    'ALTER TABLE `guests` DROP INDEX `phone`',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the new tenant-scoped unique index
-- Same safe technique for adding
SET @s = (SELECT IF(
    (SELECT COUNT(1)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE table_schema = DATABASE() AND table_name = 'guests' AND index_name = 'uq_guest_prop_phone'
    ) = 0,
    'ALTER TABLE `guests` ADD UNIQUE KEY `uq_guest_prop_phone` (`property_id`, `phone`)',
    'SELECT 1'
));
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ═══════════════════════════════════════════════════════════════
-- 2. ADD property_id TO sequence_counters
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `sequence_counters` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

-- Drop primary key and add property_id to it
ALTER TABLE `sequence_counters` DROP PRIMARY KEY, ADD PRIMARY KEY (`property_id`, `module`, `period`);

-- ═══════════════════════════════════════════════════════════════
-- 3. ADD property_id TO night_audit_log
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `night_audit_log` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

-- Safely drop old unique keys (uk_audit_date or idx_audit_date)
SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'night_audit_log' AND index_name = 'uk_audit_date') > 0, 'ALTER TABLE `night_audit_log` DROP INDEX `uk_audit_date`', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'night_audit_log' AND index_name = 'idx_audit_date') > 0, 'ALTER TABLE `night_audit_log` DROP INDEX `idx_audit_date`', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'night_audit_log' AND index_name = 'uq_audit_prop_date') = 0, 'ALTER TABLE `night_audit_log` ADD UNIQUE KEY `uq_audit_prop_date` (`property_id`, `audit_date`)', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════
-- 4. ADD property_id TO error_logs & login_attempts & housekeeping
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `error_logs` ADD COLUMN IF NOT EXISTS `property_id` INT(11) DEFAULT NULL;
ALTER TABLE `login_attempts` ADD COLUMN IF NOT EXISTS `property_id` INT(11) DEFAULT NULL;
ALTER TABLE `housekeeping_checklist_items` ADD COLUMN IF NOT EXISTS `property_id` INT(11) DEFAULT NULL;

-- ═══════════════════════════════════════════════════════════════
-- 5. ADD property_id TO WhatsApp Tables
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE `wa_templates` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;
ALTER TABLE `wa_delivery_logs` ADD COLUMN IF NOT EXISTS `property_id` INT(11) NOT NULL DEFAULT 1;

-- Safely replace unique index on wa_templates
SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'wa_templates' AND index_name = 'name') > 0, 'ALTER TABLE `wa_templates` DROP INDEX `name`', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'wa_templates' AND index_name = 'uq_wa_tpl_prop_name') = 0, 'ALTER TABLE `wa_templates` ADD UNIQUE KEY `uq_wa_tpl_prop_name` (`property_id`, `name`, `language`)', 'SELECT 1'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

