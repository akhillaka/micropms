-- DNS verification, iCal channel feeds, and nightly report cache

ALTER TABLE `properties`
  ADD COLUMN IF NOT EXISTS `dns_txt_token` VARCHAR(64) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `dns_verified_at` DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `dns_status` VARCHAR(40) NOT NULL DEFAULT 'unverified';

ALTER TABLE `room_maintenance`
  ADD COLUMN IF NOT EXISTS `property_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `external_uid` VARCHAR(190) DEFAULT NULL;

ALTER TABLE `room_maintenance`
  ADD INDEX IF NOT EXISTS `idx_maint_property` (`property_id`),
  ADD UNIQUE INDEX IF NOT EXISTS `uq_maint_external` (`room_id`, `external_uid`);

UPDATE `room_maintenance` m
JOIN `rooms` r ON r.id = m.room_id
SET m.property_id = r.property_id
WHERE m.property_id IS NULL;

CREATE TABLE IF NOT EXISTS `room_ical_feeds` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `property_id` INT(11) NOT NULL,
  `room_id` INT(11) NOT NULL,
  `export_token` CHAR(32) NOT NULL,
  `import_url` VARCHAR(500) DEFAULT NULL,
  `last_synced_at` DATETIME DEFAULT NULL,
  `last_error` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ical_room` (`room_id`),
  UNIQUE KEY `uq_ical_token` (`export_token`),
  KEY `idx_ical_property` (`property_id`),
  CONSTRAINT `fk_ical_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ical_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `saas_subscriptions`
  ADD UNIQUE INDEX IF NOT EXISTS `uq_saas_sub_gateway` (`gateway_sub_id`);

CREATE TABLE IF NOT EXISTS `report_daily_stats` (
  `property_id` INT(11) NOT NULL,
  `stat_date` DATE NOT NULL,
  `total_rooms` INT(11) NOT NULL DEFAULT 0,
  `occupied_rooms` INT(11) NOT NULL DEFAULT 0,
  `occupancy_percent` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `room_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `adr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `revpar` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`property_id`, `stat_date`),
  CONSTRAINT `fk_report_stats_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
