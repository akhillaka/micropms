-- Phase 2 P3: drop folio transaction_type; drop dead properties.razorpay_*;
-- automation_rules.deleted_at; archive wa_automations.
-- Idempotent. Requires 044 + 045 already applied.

-- ── Ensure entry_kind is fully populated before dropping transaction_type ─
UPDATE `folio_ledger`
SET `entry_kind` = CASE
  WHEN LOWER(`transaction_type`) IN ('cash', 'card', 'upi', 'online', 'bank_transfer') THEN 'payment'
  WHEN LOWER(`transaction_type`) = 'refund' THEN 'REFUND'
  WHEN UPPER(`transaction_type`) IN ('ROOM_CHARGE', 'INCIDENTAL', 'POS_ORDER', 'POS_REFUND', 'TAX', 'REFUND', 'PAYMENT') THEN
    IF(LOWER(`transaction_type`) = 'payment', 'payment', UPPER(`transaction_type`))
  ELSE COALESCE(NULLIF(TRIM(`entry_kind`), ''), NULLIF(TRIM(`transaction_type`), ''), 'payment')
END
WHERE `entry_kind` IS NULL OR TRIM(`entry_kind`) = '';

UPDATE `folio_ledger` SET `entry_kind` = 'payment' WHERE `entry_kind` IS NULL OR TRIM(`entry_kind`) = '';

SET @ek_null := (
  SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'folio_ledger' AND COLUMN_NAME = 'entry_kind'
  LIMIT 1
);
SET @sql := IF(@ek_null = 'YES',
  'ALTER TABLE `folio_ledger` MODIFY COLUMN `entry_kind` VARCHAR(50) NOT NULL DEFAULT ''payment'' COMMENT ''payment|ROOM_CHARGE|INCIDENTAL|pos_order|pos_refund|TAX|REFUND''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop legacy index on transaction_type
SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'folio_ledger' AND INDEX_NAME = 'idx_folio_booking_type'
  LIMIT 1
);
SET @sql := IF(@idx IS NOT NULL,
  'ALTER TABLE `folio_ledger` DROP INDEX `idx_folio_booking_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'folio_ledger' AND COLUMN_NAME = 'transaction_type'
);
SET @sql := IF(@col > 0,
  'ALTER TABLE `folio_ledger` DROP COLUMN `transaction_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx2 := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'folio_ledger' AND INDEX_NAME = 'idx_folio_booking_entry_kind'
  LIMIT 1
);
SET @sql := IF(@idx2 IS NULL,
  'ALTER TABLE `folio_ledger` ADD KEY `idx_folio_booking_entry_kind` (`booking_id`, `entry_kind`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Drop dead properties.razorpay_* credential columns ───────────────────
SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'razorpay_key_id'
);
SET @sql := IF(@col > 0, 'ALTER TABLE `properties` DROP COLUMN `razorpay_key_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'razorpay_key_secret'
);
SET @sql := IF(@col > 0, 'ALTER TABLE `properties` DROP COLUMN `razorpay_key_secret`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── automation_rules.deleted_at ──────────────────────────────────────────
SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'automation_rules' AND COLUMN_NAME = 'deleted_at'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `automation_rules` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Final promote from wa_automations (if table still exists) then archive
SET @wa_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_automations'
);
SET @arch_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_automations_archive'
);

-- Promote only when live table exists
SET @sql := IF(@wa_exists > 0,
  'INSERT INTO `automation_rules` (`property_id`, `event_key`, `is_wa_active`, `wa_template_id`, `wa_mapping_json`)
   SELECT w.`property_id`, w.`event_key`, 1, w.`template_id`, w.`variable_mapping_json`
   FROM `wa_automations` w
   LEFT JOIN `automation_rules` ar
     ON ar.`property_id` = w.`property_id` AND ar.`event_key` = w.`event_key` AND ar.`deleted_at` IS NULL
   WHERE w.`status` = ''active'' AND w.`template_id` IS NOT NULL AND ar.`id` IS NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@wa_exists > 0 AND @arch_exists = 0,
  'RENAME TABLE `wa_automations` TO `wa_automations_archive`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
