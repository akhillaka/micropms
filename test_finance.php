<?php
require_once __DIR__ . '/pms_core/HttpScriptGuard.php';
require 'pms_core/config.php';
require 'pms_core/Database.php';
try {
    $db = Database::getInstance()->getConnection();
    $q = "SELECT recorded_at AS date, 'due' AS type, 'Room Booking Due' AS category, description AS actual_desc, booking_id AS ref_id, booking_id AS booking_id, ABS(fl.amount) AS amount, fl.payment_method, fl.display_id FROM folio_ledger fl JOIN bookings b ON fl.booking_id = b.id WHERE b.property_id = 1 AND fl.amount > 0 UNION ALL SELECT recorded_at AS date, CASE WHEN type = 'income' THEN 'collection' ELSE type END AS type, CASE WHEN type = 'income' THEN 'Room Received Payment' ELSE category END AS category, description AS actual_desc, id AS ref_id, booking_id AS booking_id, amount, payment_method, display_id FROM finance_transactions WHERE property_id = 1 ORDER BY date DESC LIMIT 10";
    $s = $db->query($q);
    if (!$s) { print_r($db->errorInfo()); } else { print_r($s->fetchAll(PDO::FETCH_ASSOC)); }
} catch (Exception $e) {
    echo $e->getMessage();
}
