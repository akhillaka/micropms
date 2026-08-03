<?php
require_once __DIR__ . '/pms_core/Database.php';
require_once __DIR__ . '/pms_core/config.php';
$db = \Database::getInstance()->getConnection();

$out = [];
try {
    $stmt = $db->query("DESCRIBE pos_orders");
    $out['schema'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $out['error_schema'] = $e->getMessage();
}

$sql = "SELECT DISTINCT po.id AS `pos_orders_id`, po.display_id AS `pos_orders_display_id`, r.room_number AS `pos_orders_room_number`, g.name AS `pos_orders_guest_name`, o.name AS `pos_orders_outlet_name`, po.total_amount AS `pos_orders_total_amount`, po.payment_method AS `pos_orders_payment_method`, po.status AS `pos_orders_status`, po.delivery_status AS `pos_orders_delivery_status`, po.recorded_at AS `pos_orders_recorded_at` FROM `pos_orders` po LEFT JOIN `bookings` b ON po.booking_id = b.id LEFT JOIN `rooms` r ON b.room_id = r.id LEFT JOIN `guests` g ON b.guest_id = g.id LEFT JOIN `pos_outlets` o ON po.outlet_id = o.id WHERE po.property_id = :pid AND po.recorded_at >= :start AND po.recorded_at <= :end";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute(['pid' => 1, 'start' => '2026-08-01', 'end' => '2026-08-31']);
    $out['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $out['error_query'] = $e->getMessage();
}

file_put_contents(__DIR__ . '/test_output.json', json_encode($out, JSON_PRETTY_PRINT));
