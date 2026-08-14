<?php
require_once __DIR__ . '/pms_core/HttpScriptGuard.php';
require 'pms_core/Database.php';
$db = Database::getInstance()->getConnection();

echo "=== DESCRIBE guests ===\n";
$stmt = $db->query("DESCRIBE guests");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));



