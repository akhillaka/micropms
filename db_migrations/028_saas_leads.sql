-- Landing-page hotel interest leads (no account until SaaS grants access)

CREATE TABLE IF NOT EXISTS `saas_leads` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `hotel_name` VARCHAR(190) NOT NULL,
  `contact_name` VARCHAR(190) DEFAULT NULL,
  `email` VARCHAR(190) NOT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `city` VARCHAR(120) DEFAULT NULL,
  `plan` VARCHAR(40) NOT NULL DEFAULT 'starter',
  `rooms_estimate` INT(11) DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'new',
  `property_id` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_leads_status` (`status`),
  KEY `idx_leads_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
