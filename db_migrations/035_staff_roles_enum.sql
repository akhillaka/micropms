-- Widen staff_users.access_level to match Settings roles, persist custom role_id.

ALTER TABLE `staff_users`
  MODIFY COLUMN `access_level` ENUM(
    'superadmin','owner','admin','manager','receptionist','housekeeping','front_desk',
    'maintenance','fb_cashier','night_auditor'
  ) NOT NULL DEFAULT 'manager';

ALTER TABLE `staff_users`
  ADD COLUMN IF NOT EXISTS `role_id` INT(11) NULL DEFAULT NULL AFTER `role`;
