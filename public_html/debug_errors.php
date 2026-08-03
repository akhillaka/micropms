<?php
require_once __DIR__ . '/../pms_core/Database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query('SELECT id, created_at, exception_class, file, line FROM error_logs ORDER BY id DESC LIMIT 5');
header('Content-Type: application/json');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
