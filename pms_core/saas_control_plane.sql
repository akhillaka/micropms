-- SaaS Control Plane Schema Definitions

CREATE TABLE IF NOT EXISTS `properties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_code` varchar(50) NOT NULL UNIQUE,
  `name` varchar(150) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'India',
  `pincode` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `gstin` varchar(20) DEFAULT NULL,
  `custom_domain` varchar(255) DEFAULT NULL UNIQUE,
  `plan` varchar(50) NOT NULL DEFAULT 'starter',
  `max_rooms` int(11) NOT NULL DEFAULT 15,
  `max_staff` int(11) NOT NULL DEFAULT 5,
  `stripe_customer_id` varchar(100) DEFAULT NULL,
  `stripe_subscription_id` varchar(100) DEFAULT NULL,
  `subscription_status` varchar(50) NOT NULL DEFAULT 'trialing',
  `valid_until` datetime DEFAULT NULL,
  `features_json` text DEFAULT NULL, -- Plan-specific granular configurations/limits
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prop_custom_domain` (`custom_domain`),
  KEY `idx_prop_stripe` (`stripe_customer_id`, `stripe_subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `team_invitations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'manager',
  `token` varchar(100) NOT NULL UNIQUE,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `idx_invitation_token` (`token`),
  CONSTRAINT `team_invitations_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `saas_feature_flags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) DEFAULT NULL, -- Null implies a global platform flag
  `flag_key` varchar(100) NOT NULL,
  `flag_value` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_property_flag` (`property_id`, `flag_key`),
  CONSTRAINT `saas_feature_flags_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
