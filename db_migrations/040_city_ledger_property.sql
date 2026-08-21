-- Align city_ledger with admin_record_payment / multi-property inserts.
-- Idempotent: safe to re-run on Hostinger.

SET @col := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'city_ledger'
    AND COLUMN_NAME = 'property_id'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE `city_ledger` ADD COLUMN `property_id` INT NULL AFTER `id`, ADD KEY `idx_city_ledger_property` (`property_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill from company, then booking
UPDATE `city_ledger` cl
INNER JOIN `companies` c ON c.id = cl.company_id
SET cl.property_id = c.property_id
WHERE cl.property_id IS NULL;

UPDATE `city_ledger` cl
INNER JOIN `bookings` b ON b.id = cl.booking_id
SET cl.property_id = b.property_id
WHERE cl.property_id IS NULL AND cl.booking_id IS NOT NULL;

-- Leave nullable if orphans remain; otherwise prefer NOT NULL for new installs via schema_master.
