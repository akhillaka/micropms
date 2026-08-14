-- Allow Hotel Assistant to reject guest service requests; track resolution time.
ALTER TABLE `guest_service_requests`
  MODIFY `status` ENUM('pending','in_progress','completed','rejected') NOT NULL DEFAULT 'pending';

ALTER TABLE `guest_service_requests`
  ADD COLUMN IF NOT EXISTS `resolved_at` DATETIME NULL DEFAULT NULL AFTER `status`;
