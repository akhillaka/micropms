<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

ApiHandler::run(function(\PDO $db) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';
    $bookingId = $data['booking_id'] ?? $_GET['booking_id'] ?? '';
    $token = $data['token'] ?? $_GET['token'] ?? '';
    
    if (empty($bookingId) || empty($token)) {
        ApiResponse::error('Missing authentication tokens.', 401);
    }
    
    // Validate booking first so we can bind v2 tokens to property_id
    $stmt = $db->prepare("SELECT b.id, b.property_id, b.room_id, b.guest_id, b.booking_status, b.check_out, r.room_number FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ? AND b.booking_status = 'checked_in'");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        ApiResponse::error('Active booking not found.', 404);
    }
    if (!GuestAccessToken::verify($bookingId, $token, (int)$booking['property_id'])) {
        ApiResponse::error('Invalid secure token.', 403);
    }
    if (!GuestAccessToken::bookingIsAccessible($booking)) {
        ApiResponse::error('This stay link has expired or the reservation is no longer accessible', 403);
    }
    
    $propertyId = (int)$booking['property_id'];

    if ($action === 'create') {
        $allowedTypes = [
            'Housekeeping', 'Extra Towels', 'Toiletries', 'Extra Bed', 'Blanket',
            'Do Not Disturb',
            'Extend Stay', 'Room Upgrade', 'Room Service',
            'Late Checkout', 'Wake-up Call'
        ];
        $aliases = [
            'late_checkout' => 'Late Checkout',
            'latecheckout' => 'Late Checkout',
            'housekeeping' => 'Housekeeping',
            'extra_towels' => 'Extra Towels',
            'toiletries' => 'Toiletries',
            'extra_bed' => 'Extra Bed',
            'blanket' => 'Blanket',
            'do_not_disturb' => 'Do Not Disturb',
            'dnd' => 'Do Not Disturb',
            'extend_stay' => 'Extend Stay',
            'room_upgrade' => 'Room Upgrade',
            'room_service' => 'Room Service',
            'wakeup_call' => 'Wake-up Call',
            'wake-up call' => 'Wake-up Call',
        ];
        $rawType = trim(strip_tags((string)($data['service_type'] ?? '')));
        $aliasKey = strtolower(str_replace(['-', ' '], '_', $rawType));
        $serviceType = $aliases[$aliasKey] ?? $rawType;
        if ($serviceType === '' || !in_array($serviceType, $allowedTypes, true)) {
            ApiResponse::error('Invalid service type.', 400);
        }

        $flagForType = [
            'Housekeeping' => 'GUEST_PORTAL_HOUSEKEEPING_ENABLED',
            'Extra Towels' => 'GUEST_PORTAL_HOUSEKEEPING_ENABLED',
            'Toiletries' => 'GUEST_PORTAL_HOUSEKEEPING_ENABLED',
            'Extra Bed' => 'GUEST_PORTAL_HOUSEKEEPING_ENABLED',
            'Blanket' => 'GUEST_PORTAL_HOUSEKEEPING_ENABLED',
            'Do Not Disturb' => 'GUEST_PORTAL_HOUSEKEEPING_ENABLED',
            'Wake-up Call' => 'GUEST_PORTAL_WAKEUP_ENABLED',
            'Extend Stay' => 'GUEST_PORTAL_EXTEND_STAY_ENABLED',
            'Room Upgrade' => 'GUEST_PORTAL_UPGRADE_ENABLED',
            'Late Checkout' => 'GUEST_PORTAL_UPSELL_ENABLED',
            'Room Service' => 'GUEST_PORTAL_POS_ENABLED',
        ];
        $flagKey = $flagForType[$serviceType] ?? '';
        if ($flagKey !== '') {
            $flagStmt = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = ?");
            $flagStmt->execute([$propertyId, $flagKey]);
            $flagVal = $flagStmt->fetchColumn();
            $defaultOn = in_array($flagKey, ['GUEST_PORTAL_WAKEUP_ENABLED', 'GUEST_PORTAL_EXTEND_STAY_ENABLED', 'GUEST_PORTAL_UPGRADE_ENABLED'], true);
            $enabled = $flagVal === false ? $defaultOn : ($flagVal === 'true');
            if (!$enabled) {
                ApiResponse::error('This request type is turned off for this property.', 403);
            }
        }

        $housekeepingTypes = ['Housekeeping', 'Extra Towels', 'Toiletries', 'Extra Bed', 'Blanket', 'Do Not Disturb'];
        if (in_array($serviceType, $housekeepingTypes, true)) {
            require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';
            if (!SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'housekeeping_module')) {
                ApiResponse::error('Housekeeping requests are not enabled for this property.', 403);
            }
        }
        
        $insert = $db->prepare("INSERT INTO guest_service_requests (property_id, booking_id, service_type, status) VALUES (?, ?, ?, 'pending')");
        $insert->execute([$propertyId, $bookingId, $serviceType]);
        
        // If it's housekeeping, mark room dirty automatically
        if ($serviceType === 'Housekeeping') {
            $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ? AND property_id = ?")->execute([$booking['room_id'], $propertyId]);
        }
        if ($serviceType === 'Do Not Disturb') {
            require_once __DIR__ . '/../../pms_core/services/HousekeepingFlow.php';
            HousekeepingFlow::setDoNotDisturb($db, $propertyId, (int)$booking['room_id'], true);
        }
        
        // Notify PMS dashboard
        $roomNum = $booking['room_number'] ?? 'Unknown';
        NotificationRelay::sendInAppNotification($propertyId, 'New Service Request', "Guest in Room {$roomNum} requested {$serviceType}.", 'service_request', '/admin/modules/housekeeping/service_requests');
        
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
