<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('view_dashboard');

    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d');

    $propertyId = AuthHelper::getPropertyId();

    $roomsStmt = $db->prepare("
        SELECT r.id, r.room_number, r.state, c.name as category_name
        FROM rooms r
        JOIN room_categories c ON r.category_id = c.id
        WHERE r.property_id = :prop_id
        ORDER BY r.room_number ASC
    ");
    $roomsStmt->execute(['prop_id' => $propertyId]);
    $rooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT b.id, b.room_id, b.check_in, b.check_out, b.booking_status,
               b.payment_status, b.total_amount, b.rate_plan_name,
               g.name as guest_name, g.phone as guest_phone
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.property_id = :prop_id
          AND b.payment_status != 'cancelled'
          AND DATE(b.check_in) <= :end
          AND DATE(b.check_out) >= :start
        ORDER BY b.check_in ASC
    ");
    $stmt->execute(['prop_id' => $propertyId, 'start' => $start, 'end' => $end]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'rooms' => $rooms,
        'bookings' => $bookings,
        'server_time' => date('Y-m-d H:i:s')
    ]);


}, true, false, false);

