<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('view_dashboard');

    $q = $_GET['q'] ?? '';
    if (strlen($q) < 1) {
        ApiResponse::success(['results' => []]);
    }

    $propertyId = AuthHelper::getPropertyId();
    // We search by booking id (folio number), guest name, or guest phone.
    // We can also search by room number.
    $sql = "
        SELECT b.id as folio_id, g.name as guest_name, g.phone as guest_phone, r.room_number, b.check_in, b.payment_status
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.property_id = :prop_id
          AND (b.id = :exact_id 
            OR LOWER(g.name) LIKE LOWER(:q1) 
            OR g.phone LIKE :q2
            OR LOWER(r.room_number) LIKE LOWER(:q3))
        ORDER BY b.created_at DESC
        LIMIT 10
    ";
    
    $exactId = is_numeric($q) ? (int)$q : 0;
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'exact_id' => $exactId,
        'q1' => "%$q%",
        'q2' => "%$q%",
        'q3' => "%$q%",
        'prop_id' => $propertyId
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ApiResponse::success(['results' => $results]);

}, true, false, false);

