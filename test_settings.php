<?php
require_once __DIR__ . '/pms_core/Database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT * FROM system_settings WHERE key_name LIKE 'night_audit%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
