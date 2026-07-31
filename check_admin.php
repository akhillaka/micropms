<?php
require_once __DIR__ . '/pms_core/Database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id, username, access_level, role FROM staff_users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($users);
