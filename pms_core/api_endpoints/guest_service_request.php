<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/config.php';

ApiHandler::run(function(\PDO $db) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';
    $bookingId = $data['booking_id'] ?? $_GET['booking_id'] ?? '';
    $token = $data['token'] ?? $_GET['token'] ?? '';
    
    if (empty($bookingId) || empty($token)) {
        ApiResponse::error('Missing authentication tokens.', 401);
    }
    
    // Verify token
    $computedToken = hash_hmac('sha256', (string)$bookingId, INVOICE_SECRET);
    if (!hash_equals($computedToken, $token)) {
        ApiResponse::error('Invalid secure token.', 403);
    }
    
    // Validate booking
    $stmt = $db->prepare("SELECT b.id, b.property_id, b.room_id, b.guest_id, r.room_number FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ? AND b.booking_status = 'checked_in'");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        ApiResponse::error('Active booking not found.', 404);
    }
    
    $propertyId = (int)$booking['property_id'];
    
    require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';
    if (!SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'housekeeping_module')) {
        ApiResponse::error('Service requests are not enabled for this property.', 403);
    }

    if ($action === 'create') {
        $serviceType = $data['service_type'] ?? '';
        if (empty($serviceType)) {
            ApiResponse::error('Service type is required.', 400);
        }
        
        $insert = $db->prepare("INSERT INTO guest_service_requests (property_id, booking_id, service_type, status) VALUES (?, ?, ?, 'pending')");
        $insert->execute([$propertyId, $bookingId, $serviceType]);
        
        // If it's housekeeping, mark room dirty automatically
        if ($serviceType === 'Housekeeping') {
            $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ?")->execute([$booking['room_id']]);
        }
        
        // Notify PMS dashboard
        $roomNum = $booking['room_number'] ?? 'Unknown';
        $db->prepare("INSERT INTO admin_notifications (property_id, type, title, message) VALUES (?, 'service_request', ?, ?)")
           ->execute([$propertyId, 'New Service Request', "Guest in Room {$roomNum} requested {$serviceType}."]);
        
        AuditLogger::log(0, 'PORTAL_SERVICE_REQUEST', 'BOOKING', $bookingId, [
            'service_type' => $serviceType
        ], $propertyId);
        
        ApiResponse::success(['message' => 'Service request submitted successfully.']);
    } 
    elseif ($action === 'list') {
        $stmt = $db->prepare("SELECT id, service_type, status, created_at FROM guest_service_requests WHERE booking_id = ? ORDER BY created_at DESC");
        $stmt->execute([$bookingId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ApiResponse::success(['requests' => $requests]);
    }
    else {
        ApiResponse::error('Invalid action.', 400);
    }
}, false, false, false);
