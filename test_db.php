<?php
require_once 'pms_core/Database.php';
$db = Database::getInstance()->getConnection();
echo "--- Bookings count ---\n";
$s = $db->query("SELECT property_id, booking_status, COUNT(*) as cnt FROM bookings GROUP BY property_id, booking_status");
print_r($s->fetchAll(PDO::FETCH_ASSOC));

echo "--- Sample Bookings ---\n";
$s2 = $db->query("SELECT id, property_id, check_in, check_out, total_amount, booking_status FROM bookings LIMIT 5");
print_r($s2->fetchAll(PDO::FETCH_ASSOC));
