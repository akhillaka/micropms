-- MicroPMS: Roles & Monitoring Migration
-- Run once on the production database.
-- Safe to re-run — all statements use IF NOT EXISTS / MODIFY safely.

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. Upgrade staff_users
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE staff_users
  MODIFY COLUMN access_level
    ENUM('owner','manager','housekeeping','front_desk')
    NOT NULL DEFAULT 'manager';

ALTER TABLE staff_users
  ADD COLUMN IF NOT EXISTS last_login_at  TIMESTAMP    NULL     COMMENT 'Last successful login time',
  ADD COLUMN IF NOT EXISTS last_login_ip  VARCHAR(45)  NULL     COMMENT 'Last login IP address',
  ADD COLUMN IF NOT EXISTS login_count    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total successful logins',
  ADD COLUMN IF NOT EXISTS is_active      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = deactivated';

-- Migrate any legacy 'front_desk' accounts to 'manager'
UPDATE staff_users SET access_level = 'manager' WHERE access_level = 'front_desk';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. Login brute-force tracking
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(100) NOT NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  success      TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_username_time (username, attempted_at),
  INDEX idx_ip_time       (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. Structured error log
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS error_logs (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  severity     ENUM('info','warning','error','critical') NOT NULL DEFAULT 'error',
  category     VARCHAR(50)  NOT NULL COMMENT 'payment|whatsapp|database|auth|booking|system',
  message      TEXT         NOT NULL,
  context      JSON         NULL     COMMENT 'booking_id, guest, amount, api_response, uri, etc.',
  staff_id     INT UNSIGNED NULL,
  request_uri  VARCHAR(500) NULL,
  ip_address   VARCHAR(45)  NULL,
  resolved     TINYINT(1)   NOT NULL DEFAULT 0,
  resolved_at  TIMESTAMP    NULL,
  resolved_by  INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_severity_cat (severity, category),
  INDEX idx_unresolved   (resolved, created_at),
  INDEX idx_category     (category, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
