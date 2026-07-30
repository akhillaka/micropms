<?php
require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query('SHOW CREATE TABLE bookings');
    echo $stmt->fetchColumn(1);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
