-- Tag Web Push subscriptions so Hotel Assistant devices open the PWA, not admin.
ALTER TABLE `push_subscriptions`
  ADD COLUMN IF NOT EXISTS `client` VARCHAR(16) NOT NULL DEFAULT 'admin' AFTER `auth_key`;
