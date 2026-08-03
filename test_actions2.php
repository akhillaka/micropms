<?php
require_once '/Users/lakaakhilyadav/Documents/s/pms_core/Database.php';
$db = Database::getInstance()->getConnection();
$propertyId = 1;

    $stmt = $db->prepare("
        SELECT g.id, g.name, g.phone
        FROM guests g
        WHERE (g.id_proof_front IS NULL OR g.id_proof_front = '')
          AND EXISTS (
              SELECT 1 FROM bookings b WHERE b.guest_id = g.id
              AND b.booking_status IN ('booked', 'checked_in')
              AND b.payment_status != 'cancelled'
              AND b.property_id = :pid
          )
    ");
    $stmt->execute(['pid' => $propertyId]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
