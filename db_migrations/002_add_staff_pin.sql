-- Database Migration: Add Booking Assistant Quick Login PIN and access permission
-- Safe to re-run.

ALTER TABLE staff_users
  ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) DEFAULT NULL COMMENT 'Hashed 4-digit PIN for quick login',
  ADD COLUMN IF NOT EXISTS assistant_access TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Allowed to access Booking Assistant PWA';

-- Give admin/owner full access by default
UPDATE staff_users SET assistant_access = 1 WHERE access_level = 'owner' OR username = 'admin';
