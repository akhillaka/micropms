<?php
require_once __DIR__ . '/pms_core/HttpScriptGuard.php';
require 'pms_core/config.php';
require 'pms_core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    $sql = "CREATE TABLE IF NOT EXISTS `admin_notifications` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `property_id` int(11) NOT NULL DEFAULT 1,
      `type` varchar(50) DEFAULT NULL,
      `title` varchar(255) DEFAULT NULL,
      `message` text DEFAULT NULL,
      `link` varchar(255) DEFAULT NULL,
      `is_read` tinyint(1) NOT NULL DEFAULT 0,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    )";
    $db->exec($sql);
    echo "admin_notifications table created successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
