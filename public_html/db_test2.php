<?php
require_once __DIR__ . '/../pms_core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("ALTER TABLE `properties` MODIFY COLUMN `plan` ENUM('trial','free_tier','starter','pro','enterprise') DEFAULT 'starter'");
    $stmt->execute();
    
    echo "Successfully updated properties.plan enum to include free_tier.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
