<?php
require_once __DIR__ . '/../pms_core/Database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT * FROM sliding_rates");
$rates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt2 = $db->query("SELECT id, display_id, property_id, room_id, total_amount FROM bookings ORDER BY created_at DESC LIMIT 5");
$bookings = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['rates' => $rates, 'bookings' => $bookings]);
