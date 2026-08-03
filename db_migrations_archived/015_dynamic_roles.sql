-- Migration 015: Dynamic Roles Configuration
-- Adds roles table and links it to staff_users

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `property_id` INT(11) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `permissions` JSON NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_roles_property` (`property_id`),
  CONSTRAINT `fk_roles_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `staff_users`
  ADD COLUMN IF NOT EXISTS `role_id` INT(11) DEFAULT NULL;

ALTER TABLE `staff_users`
  ADD CONSTRAINT `fk_staff_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL;
