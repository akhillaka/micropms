-- Migration 006: Micro PMS Operational & Audit Improvements

-- 1. Add transaction_type, folio_bucket, and reference fields to folio_ledger if not present
ALTER TABLE folio_ledger ADD COLUMN IF NOT EXISTS folio_bucket ENUM('main', 'incidentals') DEFAULT 'main';
ALTER TABLE folio_ledger ADD COLUMN IF NOT EXISTS is_refund TINYINT(1) DEFAULT 0;

-- 2. Add tags and internal notes to guests table
ALTER TABLE guests ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL;
ALTER TABLE guests ADD COLUMN IF NOT EXISTS internal_notes TEXT DEFAULT NULL;

-- 3. Room Maintenance & Out Of Order Date Blocking Table
CREATE TABLE IF NOT EXISTS `room_maintenance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Add index for faster ledger queries
CREATE INDEX IF NOT EXISTS idx_folio_booking ON folio_ledger(booking_id, recorded_at);
CREATE INDEX IF NOT EXISTS idx_finance_date ON finance_transactions(recorded_at);
