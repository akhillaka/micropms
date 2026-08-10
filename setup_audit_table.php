<?php
require_once __DIR__ . '/pms_core/Database.php';
$db = Database::getInstance()->getConnection();
$db->exec("
CREATE TABLE IF NOT EXISTS `night_audit_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `issue_type` varchar(50) NOT NULL,
  `amount` decimal(10,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `fk_night_audit_actions_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
echo "Table created.\n";
