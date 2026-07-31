<?php
require_once __DIR__ . '/pms_core/Database.php';
try {
    $db = Database::getInstance()->getConnection();
    
    $username = 'superadmin';
    $password = 'superadmin';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM staff_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Update existing user
        $updateStmt = $db->prepare("UPDATE staff_users SET password_hash = ?, access_level = 'superadmin', role = 'superadmin', is_active = 1 WHERE username = ?");
        $updateStmt->execute([$hash, $username]);
        echo "Updated existing superadmin user.\n";
    } else {
        // Insert new user
        $insertStmt = $db->prepare("INSERT INTO staff_users (username, password_hash, access_level, role, property_id, is_active) VALUES (?, ?, 'superadmin', 'superadmin', 1, 1)");
        $insertStmt->execute([$username, $hash]);
        echo "Created new superadmin user.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
