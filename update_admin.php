<?php
require_once __DIR__ . '/pms_core/Database.php';
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->exec("UPDATE staff_users SET access_level = 'superadmin', role = 'superadmin' WHERE username = 'admin' OR username = 'superadmin'");
    echo "Successfully updated $stmt rows to superadmin role.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
