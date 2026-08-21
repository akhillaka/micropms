-- Rename folio_ledger identity/category columns for clarity.
-- display_id (RCPT-…) stays; transaction_ref → transaction_id (gateway/idempotency);
-- category → payment_category (revenue bucket from payment settings).
-- Idempotent: safe to re-run on Hostinger.

-- 1) transaction_ref → transaction_id
SET @has_ref := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND COLUMN_NAME = 'transaction_ref'
);
SET @has_txn := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND COLUMN_NAME = 'transaction_id'
);
SET @sql := IF(@has_ref > 0 AND @has_txn = 0,
  'ALTER TABLE `folio_ledger` CHANGE COLUMN `transaction_ref` `transaction_id` VARCHAR(100) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Normalize empty transaction_id to NULL (unique index friendly)
UPDATE `folio_ledger` SET `transaction_id` = NULL WHERE `transaction_id` = '';

-- 2) Rebuild unique key on (booking_id, transaction_id)
SET @idx_old := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND INDEX_NAME = 'uq_folio_booking_ref'
  LIMIT 1
);
SET @sql := IF(@idx_old IS NOT NULL,
  'ALTER TABLE `folio_ledger` DROP INDEX `uq_folio_booking_ref`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_new := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND INDEX_NAME = 'uq_folio_booking_txn'
  LIMIT 1
);
SET @sql := IF(@idx_new IS NULL,
  'ALTER TABLE `folio_ledger` ADD UNIQUE KEY `uq_folio_booking_txn` (`booking_id`, `transaction_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) category → payment_category
SET @has_cat := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND COLUMN_NAME = 'category'
);
SET @has_pcat := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND COLUMN_NAME = 'payment_category'
);
SET @sql := IF(@has_cat > 0 AND @has_pcat = 0,
  'ALTER TABLE `folio_ledger` CHANGE COLUMN `category` `payment_category` VARCHAR(50) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
