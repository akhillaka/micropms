-- Deduped staff/guest reminder sends (checkout 30m/15m, overstay, POS abandoned).
CREATE TABLE IF NOT EXISTS `notification_milestones` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `property_id` INT NOT NULL,
  `entity_type` VARCHAR(32) NOT NULL DEFAULT 'booking',
  `entity_id` INT NOT NULL,
  `milestone` VARCHAR(64) NOT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_milestone` (`entity_type`, `entity_id`, `milestone`),
  KEY `idx_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
