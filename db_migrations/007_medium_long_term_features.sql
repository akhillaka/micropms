-- Migration 007: Extended Audit Improvements (Medium & Long Term Features)

-- 1. Explicit transaction_type values for folio_ledger to support clean refunds & charges
ALTER TABLE folio_ledger MODIFY COLUMN transaction_type VARCHAR(50) NOT NULL;

-- 2. Ensure guests table has tags & internal notes
ALTER TABLE guests ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL;
ALTER TABLE guests ADD COLUMN IF NOT EXISTS internal_notes TEXT DEFAULT NULL;

-- 3. Room Maintenance table for date-range out-of-order room blocking
CREATE TABLE IF NOT EXISTS `room_maintenance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT NOT NULL,
  `start_date` DATETIME NOT NULL,
  `end_date` DATETIME NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('active','completed','cancelled') DEFAULT 'active',
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Add index for maintenance collision checks
CREATE INDEX IF NOT EXISTS idx_maint_dates ON room_maintenance(room_id, start_date, end_date);
