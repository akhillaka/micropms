-- Persist OCR / ID-proof fields that were previously shown in the UI only.

ALTER TABLE `guests`
  ADD COLUMN IF NOT EXISTS `id_number` varchar(50) DEFAULT NULL AFTER `photo`,
  ADD COLUMN IF NOT EXISTS `id_type` varchar(30) DEFAULT NULL AFTER `id_number`,
  ADD COLUMN IF NOT EXISTS `date_of_birth` date DEFAULT NULL AFTER `id_type`,
  ADD COLUMN IF NOT EXISTS `gender` varchar(20) DEFAULT NULL AFTER `date_of_birth`;
