-- Migration 004: Add guest notes, preferences, and quick charges setting
-- Safe to re-run.

-- Add notes and preferences columns to guests
ALTER TABLE `guests` 
  ADD COLUMN IF NOT EXISTS `notes` TEXT DEFAULT NULL COMMENT 'Internal staff notes about guest preferences',
  ADD COLUMN IF NOT EXISTS `preferences` TEXT DEFAULT NULL COMMENT 'JSON: dietary, room type, pillow, etc.';

-- Add folio quick charges setting
INSERT IGNORE INTO `system_settings` (`key_name`, `key_value`) VALUES 
('folio_quick_charges', '[{"name":"Breakfast","amount":150,"icon":"ph-coffee"},{"name":"Lunch","amount":250,"icon":"ph-fork-knife"},{"name":"Dinner","amount":300,"icon":"ph-fork-knife"},{"name":"Laundry","amount":100,"icon":"ph-t-shirt"},{"name":"Room Service","amount":200,"icon":"ph-bell"},{"name":"Minibar","amount":150,"icon":"ph-wine"}]');

-- Add booking notes table for internal staff notes
CREATE TABLE IF NOT EXISTS `booking_notes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NOT NULL,
  `staff_id` INT DEFAULT NULL,
  `note` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_booking_notes` (`booking_id`),
  CONSTRAINT `fk_booking_notes_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_notes_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
