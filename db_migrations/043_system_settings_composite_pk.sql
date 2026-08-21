-- Multiproperty-safe system_settings uniqueness.
-- Replace PRIMARY KEY (key_name) with PRIMARY KEY (property_id, key_name).
-- Idempotent.

SET @pk_cols := (
  SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',')
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'system_settings'
    AND CONSTRAINT_NAME = 'PRIMARY'
);

SET @sql := IF(
  @pk_cols IS NOT NULL AND @pk_cols <> 'property_id,key_name',
  'ALTER TABLE `system_settings` DROP PRIMARY KEY, ADD PRIMARY KEY (`property_id`, `key_name`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'system_settings'
    AND INDEX_NAME = 'idx_system_settings_key'
  LIMIT 1
);
SET @sql := IF(@idx IS NULL,
  'ALTER TABLE `system_settings` ADD KEY `idx_system_settings_key` (`key_name`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
