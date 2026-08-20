-- Multi-tenant WhatsApp uniqueness: conversations and automations are per-property.
-- Also backfill properties.whatsapp_phone_number_id from system_settings.

-- 1) wa_conversations: global phone unique → (property_id, phone_number)
SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wa_conversations'
    AND INDEX_NAME = 'phone_number'
  LIMIT 1
);
SET @sql := IF(@idx IS NOT NULL,
  'ALTER TABLE `wa_conversations` DROP INDEX `phone_number`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wa_conversations'
    AND INDEX_NAME = 'uq_wa_conv_prop_phone'
  LIMIT 1
);
SET @sql := IF(@idx IS NULL,
  'ALTER TABLE `wa_conversations` ADD UNIQUE KEY `uq_wa_conv_prop_phone` (`property_id`, `phone_number`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) wa_automations: global event_key unique → (property_id, event_key)
SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wa_automations'
    AND INDEX_NAME = 'event_key'
  LIMIT 1
);
SET @sql := IF(@idx IS NOT NULL,
  'ALTER TABLE `wa_automations` DROP INDEX `event_key`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'wa_automations'
    AND INDEX_NAME = 'uq_wa_auto_prop_event'
  LIMIT 1
);
SET @sql := IF(@idx IS NULL,
  'ALTER TABLE `wa_automations` ADD UNIQUE KEY `uq_wa_auto_prop_event` (`property_id`, `event_key`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Backfill Meta phone number id onto properties from settings
UPDATE `properties` p
JOIN `system_settings` s
  ON s.property_id = p.id AND s.key_name = 'WHATSAPP_PHONE_NUMBER_ID'
SET p.whatsapp_phone_number_id = NULLIF(TRIM(s.key_value), '')
WHERE (p.whatsapp_phone_number_id IS NULL OR p.whatsapp_phone_number_id = '')
  AND TRIM(IFNULL(s.key_value, '')) NOT IN ('', 'your_phone_number_id');

-- 4) Unique among non-null whatsapp_phone_number_id values (MySQL allows multiple NULLs)
SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'properties'
    AND INDEX_NAME = 'uq_prop_wa_phone_number_id'
  LIMIT 1
);
SET @sql := IF(@idx IS NULL,
  'ALTER TABLE `properties` ADD UNIQUE KEY `uq_prop_wa_phone_number_id` (`whatsapp_phone_number_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
