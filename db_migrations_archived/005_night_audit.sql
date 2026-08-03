-- Migration 005: Night Audit System
-- Safe to re-run.

-- Night Audit Settings (inserted via system_settings)
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES 
('night_audit_enabled', 'false'),
('night_audit_time', '02:00'),
('night_audit_auto_checkout', 'true'),
('night_audit_auto_checkout_hours', '2'),
('night_audit_mark_dirty', 'true'),
('night_audit_notify_telegram', 'true'),
('night_audit_notify_whatsapp', 'false'),
('night_audit_report_revenue', 'true'),
('night_audit_report_occupancy', 'true'),
('night_audit_report_room_status', 'true'),
('night_audit_report_bookings', 'true');

-- Night Audit Log Table
CREATE TABLE IF NOT EXISTS `night_audit_log` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `audit_date` DATE NOT NULL,
  `run_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `run_by` VARCHAR(50) DEFAULT 'system' COMMENT 'system (cron) or staff username',
  `total_rooms` INT UNSIGNED NOT NULL DEFAULT 0,
  `occupied_rooms` INT UNSIGNED NOT NULL DEFAULT 0,
  `arrivals_today` INT UNSIGNED NOT NULL DEFAULT 0,
  `departures_today` INT UNSIGNED NOT NULL DEFAULT 0,
  `overdue_checkouts` INT UNSIGNED NOT NULL DEFAULT 0,
  `auto_checkout_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `rooms_marked_dirty` INT UNSIGNED NOT NULL DEFAULT 0,
  `revenue_collected` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `revenue_pending` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `actions_json` JSON NULL COMMENT 'Detailed actions taken during audit',
  `status` ENUM('success','partial','failed') NOT NULL DEFAULT 'success',
  `error_message` TEXT NULL,
  UNIQUE KEY `uk_audit_date` (`audit_date`),
  INDEX `idx_audit_date` (`audit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
