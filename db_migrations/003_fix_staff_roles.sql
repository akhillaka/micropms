-- Migration 003: Add role column for assistant compatibility
-- Safe to re-run. Builds on migrations 001 and 002.

-- Add role column if it doesn't exist
ALTER TABLE `staff_users` 
  ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) DEFAULT NULL COMMENT 'Normalized role name for assistant compatibility';

-- Sync role column with access_level for existing users
UPDATE `staff_users` SET `role` = `access_level` WHERE `role` IS NULL;
