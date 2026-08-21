-- Phase 2 P2: enforce property_id NOT NULL on city_ledger + wa_messages after backfill;
-- one-shot promote Razorpay settings → payment_gateway_configs;
-- one-shot promote remaining wa_automations → automation_rules.
-- Idempotent.

-- ── city_ledger.property_id ──────────────────────────────────────────────
UPDATE `city_ledger` cl
INNER JOIN `companies` c ON c.id = cl.company_id
SET cl.property_id = c.property_id
WHERE cl.property_id IS NULL;

UPDATE `city_ledger` cl
INNER JOIN `bookings` b ON b.id = cl.booking_id
SET cl.property_id = b.property_id
WHERE cl.property_id IS NULL AND cl.booking_id IS NOT NULL;

-- Drop orphans that still lack a property (cannot satisfy NOT NULL)
DELETE FROM `city_ledger` WHERE `property_id` IS NULL;

SET @cl_null := (
  SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'city_ledger' AND COLUMN_NAME = 'property_id'
  LIMIT 1
);
SET @sql := IF(@cl_null = 'YES',
  'ALTER TABLE `city_ledger` MODIFY COLUMN `property_id` INT NOT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'city_ledger'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_city_ledger_property'
  LIMIT 1
);
SET @sql := IF(@fk IS NULL,
  'ALTER TABLE `city_ledger` ADD CONSTRAINT `fk_city_ledger_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── wa_messages.property_id ──────────────────────────────────────────────
UPDATE `wa_messages` m
INNER JOIN `wa_conversations` c ON c.id = m.conversation_id
SET m.property_id = c.property_id
WHERE m.property_id IS NULL;

DELETE FROM `wa_messages` WHERE `property_id` IS NULL;

SET @wm_null := (
  SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_messages' AND COLUMN_NAME = 'property_id'
  LIMIT 1
);
SET @sql := IF(@wm_null = 'YES',
  'ALTER TABLE `wa_messages` MODIFY COLUMN `property_id` INT NOT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk2 := (
  SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_messages'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_wa_messages_property'
  LIMIT 1
);
SET @sql := IF(@fk2 IS NULL,
  'ALTER TABLE `wa_messages` ADD CONSTRAINT `fk_wa_messages_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wa_messages' AND INDEX_NAME = 'idx_wa_messages_property'
  LIMIT 1
);
SET @sql := IF(@idx IS NULL,
  'ALTER TABLE `wa_messages` ADD KEY `idx_wa_messages_property` (`property_id`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ── Promote active wa_automations into automation_rules (SoT) ────────────
-- Insert rules that do not exist yet
INSERT INTO `automation_rules` (`property_id`, `event_key`, `is_wa_active`, `wa_template_id`, `wa_mapping_json`)
SELECT w.`property_id`, w.`event_key`, 1, w.`template_id`, w.`variable_mapping_json`
FROM `wa_automations` w
LEFT JOIN `automation_rules` ar
  ON ar.`property_id` = w.`property_id` AND ar.`event_key` = w.`event_key`
WHERE w.`status` = 'active' AND w.`template_id` IS NOT NULL AND ar.`id` IS NULL;

-- Fill WA channel on existing rules that still lack a template
UPDATE `automation_rules` ar
INNER JOIN `wa_automations` w
  ON w.`property_id` = ar.`property_id` AND w.`event_key` = ar.`event_key`
SET ar.`is_wa_active` = 1,
    ar.`wa_template_id` = w.`template_id`,
    ar.`wa_mapping_json` = COALESCE(NULLIF(ar.`wa_mapping_json`, ''), NULLIF(ar.`wa_mapping_json`, '[]'), w.`variable_mapping_json`)
WHERE w.`status` = 'active'
  AND w.`template_id` IS NOT NULL
  AND (ar.`wa_template_id` IS NULL OR ar.`is_wa_active` = 0);
-- ── Promote Razorpay system_settings into payment_gateway_configs ────────
-- Insert missing configs from settings (do not overwrite existing key_secret)
INSERT INTO `payment_gateway_configs` (`property_id`, `gateway`, `mode`, `key_id`, `key_secret`, `extra_config`, `is_active`)
SELECT
  kid.`property_id`,
  'razorpay',
  'live',
  kid.`key_value`,
  COALESCE(NULLIF(TRIM(ksec.`key_value`), ''), 'PENDING_MIGRATE'),
  CASE
    WHEN wh.`key_value` IS NOT NULL AND TRIM(wh.`key_value`) != '' AND TRIM(wh.`key_value`) NOT IN ('your_webhook_secret', 'rzp_secret_placeholder')
    THEN JSON_OBJECT('webhook_secret', wh.`key_value`)
    ELSE NULL
  END,
  1
FROM `system_settings` kid
LEFT JOIN `system_settings` ksec
  ON ksec.`property_id` = kid.`property_id` AND ksec.`key_name` = 'RAZORPAY_KEY_SECRET'
LEFT JOIN `system_settings` wh
  ON wh.`property_id` = kid.`property_id` AND wh.`key_name` = 'RAZORPAY_WEBHOOK_SECRET'
LEFT JOIN `payment_gateway_configs` pgc
  ON pgc.`property_id` = kid.`property_id` AND pgc.`gateway` = 'razorpay'
WHERE kid.`key_name` = 'RAZORPAY_KEY_ID'
  AND TRIM(kid.`key_value`) != ''
  AND pgc.`id` IS NULL;
