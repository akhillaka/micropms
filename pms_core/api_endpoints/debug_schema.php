<?php
// Auth guard — schema debug requires authenticated owner access
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
if (!isset($_SESSION['user_id']) || !AuthHelper::can('manage_settings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';

$db = Database::getInstance()->getConnection();
$tables = ['pos_orders', 'pos_order_items', 'pos_outlets'];
$out = [];
foreach ($tables as $t) {
    try {
        $stmt = $db->query("DESCRIBE $t");
        $out[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $out[$t] = "Not found"; }
}
echo json_encode($out, JSON_PRETTY_PRINT);
