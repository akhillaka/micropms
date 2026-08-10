ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(50) DEFAULT 'Asia/Kolkata';
ALTER TABLE `jobs_queue` ADD COLUMN IF NOT EXISTS `dead_letter` TINYINT(1) DEFAULT 0;
ALTER TABLE `pos_inventory` ADD COLUMN IF NOT EXISTS `reorder_level` INT(11) DEFAULT 0;
ALTER TABLE `pos_inventory` ADD COLUMN IF NOT EXISTS `reorder_quantity` INT(11) DEFAULT 0;
