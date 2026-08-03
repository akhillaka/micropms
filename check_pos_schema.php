<?php
require_once __DIR__ . '/pms_core/Database.php';
require_once __DIR__ . '/pms_core/config.php';
$db = \Database::getInstance()->getConnection();
$stmt = $db->query("DESCRIBE pos_orders");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($res, JSON_PRETTY_PRINT);
