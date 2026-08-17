-- Soft-block rooms for 15 minutes while a booking is being confirmed.

CREATE TABLE IF NOT EXISTS `room_holds` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `property_id` INT NOT NULL,
  `room_id` INT NOT NULL,
  `token` CHAR(64) NOT NULL,
  `staff_id` INT DEFAULT NULL,
  `check_in` DATETIME NOT NULL,
  `check_out` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_room_holds_token` (`token`),
  KEY `idx_room_holds_overlap` (`property_id`, `room_id`, `check_in`, `check_out`, `expires_at`),
  KEY `idx_room_holds_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
