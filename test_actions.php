<?php
require_once '/Users/lakaakhilyadav/Documents/s/pms_core/Database.php';
$db = Database::getInstance()->getConnection();
$propertyId = 1;

    $stmt = $db->prepare("
        SELECT b.id, b.total_amount, b.payment_status, b.booking_status,
               r.room_number, g.name as guest_name, g.phone as guest_phone,
               COALESCE(SUM(fl.amount), 0) as balance
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN folio_ledger fl ON b.id = fl.booking_id
        WHERE b.booking_status IN ('booked', 'checked_in')
          AND b.payment_status != 'cancelled'
          AND b.property_id = :pid
        GROUP BY b.id
        HAVING balance > 0
    ");
    $stmt->execute(['pid' => $propertyId]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
