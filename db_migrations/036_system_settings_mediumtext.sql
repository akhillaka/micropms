-- Property logos and other large settings values exceed TEXT (64KB).
ALTER TABLE `system_settings`
  MODIFY COLUMN `key_value` MEDIUMTEXT DEFAULT NULL;
