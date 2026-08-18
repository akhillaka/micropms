-- Payment gateway table (needed if setup.sql was never fully applied)
-- plus housekeeping checklist property scope.

CREATE TABLE IF NOT EXISTS `payment_gateway_configs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `gateway` enum('razorpay','phonepe') NOT NULL,
  `mode` enum('test','live') NOT NULL DEFAULT 'test',
  `key_id` varchar(255) DEFAULT NULL,
  `key_secret` varchar(255) DEFAULT NULL,
  `extra_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_gateway` (`property_id`,`gateway`),
  KEY `idx_pgc_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copy Integrations Razorpay keys into the collection table so folio/guest/assistant see them.
INSERT INTO payment_gateway_configs (property_id, gateway, mode, key_id, key_secret, is_active)
SELECT
  kid.property_id,
  'razorpay',
  'live',
  kid.key_value,
  COALESCE(sec.key_value, ''),
  1
FROM system_settings kid
LEFT JOIN system_settings sec
  ON sec.property_id = kid.property_id AND sec.key_name = 'RAZORPAY_KEY_SECRET'
WHERE kid.key_name = 'RAZORPAY_KEY_ID'
  AND kid.key_value IS NOT NULL
  AND TRIM(kid.key_value) <> ''
ON DUPLICATE KEY UPDATE
  key_id = IF(TRIM(payment_gateway_configs.key_id) = '', VALUES(key_id), payment_gateway_configs.key_id),
  key_secret = IF(TRIM(IFNULL(payment_gateway_configs.key_secret, '')) = '', VALUES(key_secret), payment_gateway_configs.key_secret);

ALTER TABLE `housekeeping_checklist_items`
  ADD COLUMN IF NOT EXISTS `property_id` int(11) DEFAULT NULL;
