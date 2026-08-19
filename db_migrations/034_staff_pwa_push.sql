-- Staff PWA push subscriptions, remember-me tokens, notification links
ALTER TABLE admin_notifications
  ADD COLUMN IF NOT EXISTS link_url VARCHAR(500) NULL DEFAULT NULL AFTER type;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_user_id INT NOT NULL,
  property_id INT NOT NULL,
  endpoint VARCHAR(768) NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth_key VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY endpoint (endpoint(191)),
  KEY staff_user_id (staff_user_id),
  KEY property_id (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_remember_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  staff_user_id INT NOT NULL,
  selector CHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY selector (selector),
  KEY staff_user_id (staff_user_id),
  KEY expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
