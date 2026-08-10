<?php
require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requireLoginOrRedirect();
    $propertyId = AuthHelper::getPropertyId();

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'list') {
        $stmt = $db->prepare("
            SELECT gsr.*, b.guest_name, r.room_number 
            FROM guest_service_requests gsr
            LEFT JOIN bookings b ON gsr.booking_id = b.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE gsr.property_id = ? 
              AND (gsr.status != 'completed' OR DATE(gsr.created_at) = CURDATE() OR DATE(gsr.resolved_at) = CURDATE())
            ORDER BY gsr.created_at ASC
        ");
        $stmt->execute([$propertyId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ApiResponse::success(['requests' => $requests]);
    } 
    elseif ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if (!in_array($status, ['pending', 'in_progress', 'completed'])) {
            ApiResponse::error('Invalid status', 400);
        }
        
        // Verify it belongs to this property
        $check = $db->prepare("SELECT id, booking_id, service_type FROM guest_service_requests WHERE id = ? AND property_id = ?");
        $check->execute([$id, $propertyId]);
        $req = $check->fetch(PDO::FETCH_ASSOC);
        
        if (!$req) {
            ApiResponse::error('Request not found', 404);
        }
        
        if ($status === 'completed') {
            $update = $db->prepare("UPDATE guest_service_requests SET status = ?, resolved_at = NOW() WHERE id = ?");
        } else {
            $update = $db->prepare("UPDATE guest_service_requests SET status = ?, resolved_at = NULL WHERE id = ?");
        }
        $update->execute([$status, $id]);
        
        AuditLogger::log($_SESSION['user_id'] ?? 0, 'SERVICE_REQUEST_UPDATED', 'BOOKING', $req['booking_id'], [
            'service_type' => $req['service_type'],
            'new_status' => $status
        ], $propertyId);
        
        ApiResponse::success(['message' => 'Status updated']);
    }
    else {
        ApiResponse::error('Invalid action', 400);
    }
}, true, true, false); // requires admin auth, handles csrf, etc.
