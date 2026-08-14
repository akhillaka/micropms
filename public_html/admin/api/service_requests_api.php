<?php
require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../pms_core/services/FolioService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requireLogin();
    AuthHelper::requirePermission('housekeeping');
    $propertyId = AuthHelper::getPropertyId();

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    if ($action === 'list') {
        $stmt = $db->prepare("
            SELECT gsr.*, COALESCE(g.name, 'Guest') as guest_name, r.room_number 
            FROM guest_service_requests gsr
            LEFT JOIN bookings b ON gsr.booking_id = b.id
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE gsr.property_id = ? 
              AND (
                gsr.status IN ('pending', 'in_progress')
                OR DATE(gsr.created_at) = CURDATE()
                OR DATE(gsr.resolved_at) = CURDATE()
              )
            ORDER BY gsr.created_at ASC
        ");
        $stmt->execute([$propertyId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ApiResponse::success(['requests' => $requests]);
    } 
    elseif ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if (!in_array($status, ['pending', 'in_progress', 'completed', 'rejected'], true)) {
            ApiResponse::error('Invalid status', 400);
        }
        
        // Verify it belongs to this property
        $check = $db->prepare("
            SELECT gsr.id, gsr.booking_id, gsr.service_type, gsr.status, b.check_out
            FROM guest_service_requests gsr
            JOIN bookings b ON gsr.booking_id = b.id
            WHERE gsr.id = ? AND gsr.property_id = ?
        ");
        $check->execute([$id, $propertyId]);
        $req = $check->fetch(PDO::FETCH_ASSOC);
        
        if (!$req) {
            ApiResponse::error('Request not found', 404);
        }

        $typeKey = strtolower(preg_replace('/[\s_\-]+/', '', (string)$req['service_type']));
        if ($status === 'completed' && $typeKey === 'latecheckout' && ($req['status'] ?? '') !== 'completed') {
            $feeStmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'early_late_fee' AND property_id = ?");
            $feeStmt->execute([$propertyId]);
            $fee = (float)($feeStmt->fetchColumn() ?: 500);
            FolioService::postCharge($db, (int)$req['booking_id'], $fee, 'Late Checkout Fee (Approved)', 'other');
            $newCheckout = date('Y-m-d H:i:s', strtotime($req['check_out'] . ' +3 hours'));
            $db->prepare("UPDATE bookings SET check_out = ? WHERE id = ?")->execute([$newCheckout, $req['booking_id']]);
        }
        
        if ($status === 'completed' || $status === 'rejected') {
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
}, true, ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET', false);
