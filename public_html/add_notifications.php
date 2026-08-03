<?php
require_once __DIR__ . '/pms_core/config.php';
require_once __DIR__ . '/pms_core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    $sql = "CREATE TABLE IF NOT EXISTS `admin_notifications` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `property_id` INT NOT NULL,
      `title` VARCHAR(255) NOT NULL,
      `message` TEXT NOT NULL,
      `type` ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
      `is_read` TINYINT(1) DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_admin_notif_prop_read` (`property_id`, `is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $db->exec($sql);
    
    // Also append it to schema_master.sql for future setups
    $schemaPath = __DIR__ . '/pms_core/schema_master.sql';
    if (file_exists($schemaPath)) {
        $schema = file_get_contents($schemaPath);
        if (strpos($schema, 'CREATE TABLE IF NOT EXISTS `admin_notifications`') === false) {
            file_put_contents($schemaPath, "\n\n" . $sql, FILE_APPEND);
        }
    }
    
    echo "Notifications table created successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
