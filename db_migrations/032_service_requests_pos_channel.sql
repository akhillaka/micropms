-- Service request source/channel + stayover clean support.
-- Idempotent for MariaDB.

ALTER TABLE `guest_service_requests`
  ADD COLUMN IF NOT EXISTS `source` VARCHAR(20) NOT NULL DEFAULT 'guest' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(30) NULL DEFAULT NULL AFTER `source`,
  ADD COLUMN IF NOT EXISTS `notes` TEXT NULL DEFAULT NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `room_id` INT(11) NULL DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `linked_pos_order_id` INT(11) NULL DEFAULT NULL AFTER `room_id`;
