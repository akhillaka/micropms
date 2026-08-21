-- Folio ledger P1: separate entry_kind (charge/payment/tax/…) from payment_method (cash/upi/…).
-- transaction_type historically mixed both; keep it dual-written as entry_kind for one release.
-- Idempotent.

-- 1) Widen transaction_type if still ENUM (allows REFUND and future kinds)
SET @col_type := (
  SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND COLUMN_NAME = 'transaction_type'
  LIMIT 1
);
SET @sql := IF(
  @col_type IS NOT NULL AND @col_type LIKE 'enum%',
  'ALTER TABLE `folio_ledger` MODIFY COLUMN `transaction_type` VARCHAR(50) NOT NULL DEFAULT ''payment''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Add entry_kind
SET @has_ek := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND COLUMN_NAME = 'entry_kind'
);
SET @sql := IF(@has_ek = 0,
  'ALTER TABLE `folio_ledger` ADD COLUMN `entry_kind` VARCHAR(50) NULL AFTER `transaction_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Ensure payment_method exists
SET @has_pm := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND COLUMN_NAME = 'payment_method'
);
SET @sql := IF(@has_pm = 0,
  'ALTER TABLE `folio_ledger` ADD COLUMN `payment_method` VARCHAR(50) NULL AFTER `entry_kind`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Backfill payment_method from legacy tender values stored in transaction_type
UPDATE `folio_ledger`
SET `payment_method` = LOWER(`transaction_type`)
WHERE (`payment_method` IS NULL OR TRIM(`payment_method`) = '')
  AND LOWER(`transaction_type`) IN ('cash', 'card', 'upi', 'online', 'bank_transfer');

-- 5) Backfill entry_kind
UPDATE `folio_ledger`
SET `entry_kind` = CASE
  WHEN LOWER(`transaction_type`) IN ('cash', 'card', 'upi', 'online', 'bank_transfer') THEN 'payment'
  WHEN LOWER(`transaction_type`) IN ('payment', 'refund') THEN LOWER(`transaction_type`)
  WHEN UPPER(`transaction_type`) IN ('ROOM_CHARGE', 'INCIDENTAL', 'POS_ORDER', 'POS_REFUND', 'TAX', 'REFUND') THEN UPPER(`transaction_type`)
  WHEN IFNULL(`is_refund`, 0) = 1 THEN 'REFUND'
  WHEN `amount` < 0 THEN 'payment'
  ELSE COALESCE(NULLIF(TRIM(`transaction_type`), ''), 'payment')
END
WHERE `entry_kind` IS NULL OR TRIM(`entry_kind`) = '';

-- 6) Normalize legacy tender rows: transaction_type becomes entry kind for dual-compat readers
UPDATE `folio_ledger`
SET `transaction_type` = `entry_kind`
WHERE LOWER(`transaction_type`) IN ('cash', 'card', 'upi', 'online', 'bank_transfer')
  AND `entry_kind` IS NOT NULL;

-- 7) NOT NULL default for new rows
UPDATE `folio_ledger` SET `entry_kind` = 'payment' WHERE `entry_kind` IS NULL OR TRIM(`entry_kind`) = '';
ALTER TABLE `folio_ledger` MODIFY COLUMN `entry_kind` VARCHAR(50) NOT NULL DEFAULT 'payment';

-- 8) Index
SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'folio_ledger'
    AND INDEX_NAME = 'idx_folio_booking_entry_kind'
  LIMIT 1
);
SET @sql := IF(@idx IS NULL,
  'ALTER TABLE `folio_ledger` ADD KEY `idx_folio_booking_entry_kind` (`booking_id`, `entry_kind`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
