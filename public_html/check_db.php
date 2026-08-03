<?php
require_once __DIR__ . '/../pms_core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if staff_users has data
    $stmt = $db->query("SELECT * FROM staff_users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($users);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
