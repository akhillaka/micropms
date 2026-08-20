<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../pms_core/services/FolioService.php';
require_once __DIR__ . '/../../../pms_core/services/BookingService.php';
require_once __DIR__ . '/../../../pms_core/services/HousekeepingFlow.php';

ApiHandler::run(function(\PDO $db) {
    try {
        $col = $db->query("SHOW COLUMNS FROM guest_service_requests LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)$col['Type'], 'rejected') === false) {
            $db->exec("ALTER TABLE guest_service_requests MODIFY status ENUM('pending','in_progress','completed','rejected') NOT NULL DEFAULT 'pending'");
        }
        $resCol = $db->query("SHOW COLUMNS FROM guest_service_requests LIKE 'resolved_at'")->fetch();
        if (!$resCol) {
            $db->exec("ALTER TABLE guest_service_requests ADD COLUMN resolved_at DATETIME NULL DEFAULT NULL");
        }
    } catch (\Throwable $e) {
        // Schema already current
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $action = $data['action'] ?? $_GET['action'] ?? '';
    
    AuthHelper::requirePermission('housekeeping');
    $propertyId = AuthHelper::getPropertyId();

    if ($action === 'update_status') {
        $requestId = (int)($data['request_id'] ?? 0);
        $status = $data['status'] ?? '';
        
        if (!$requestId || !in_array($status, ['completed', 'rejected'])) {
            ApiResponse::error('Invalid parameters');
        }

        $stmt = $db->prepare("
            SELECT gsr.*, b.check_out, b.room_id AS booking_room_id, g.name as guest_name
            FROM guest_service_requests gsr
            JOIN bookings b ON gsr.booking_id = b.id
            LEFT JOIN guests g ON b.guest_id = g.id
            WHERE gsr.id = ? AND gsr.property_id = ?
        ");
        $stmt->execute([$requestId, $propertyId]);
        $req = $stmt->fetch();

        if (!$req) {
            ApiResponse::error('Service request not found');
        }

        $typeKey = strtolower(preg_replace('/[\s_\-]+/', '', (string)$req['service_type']));
        $isLateCheckout = ($typeKey === 'latecheckout');

        if ($status === 'completed' && $isLateCheckout && ($req['status'] ?? '') !== 'completed') {
            // Apply the late checkout logic here
            $db->beginTransaction();
            try {
                // Determine fee
                $feeStmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name IN ('GUEST_PORTAL_EARLY_LATE_FEE', 'early_late_fee') AND property_id = ? ORDER BY key_name = 'GUEST_PORTAL_EARLY_LATE_FEE' DESC LIMIT 1");
                $feeStmt->execute([$propertyId]);
                $fee = (float)($feeStmt->fetchColumn() ?: 500);

                FolioService::postCharge($db, (int)$req['booking_id'], $fee, 'Late Checkout Fee (Approved)', 'other');
                $extended = BookingService::applyLateCheckoutHours($db, (int)$req['booking_id'], $propertyId, 3);

                AuditLogger::log($_SESSION['user_id'] ?? 0, 'APPROVE_LATE_CHECKOUT', 'BOOKING', $req['booking_id'], [
                    'charge' => $fee,
                    'new_checkout' => $extended['new_total'] ?? null,
                    'extra_cost' => $extended['extra_cost'] ?? null
                ], $propertyId);

                $db->prepare("UPDATE guest_service_requests SET status = 'completed', resolved_at = NOW() WHERE id = ?")->execute([$requestId]);
                
                $db->commit();
                ApiResponse::success(['message' => 'Late checkout approved and applied.']);
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                ApiResponse::error('Failed to apply late checkout: ' . $e->getMessage());
            }
        } else {
            if ($status === 'completed' && in_array($typeKey, ['housekeeping', 'stayoverclean', 'extratowels', 'toiletries', 'blanket'], true)) {
                $roomId = (int)($req['room_id'] ?: ($req['booking_room_id'] ?? 0));
                if ($roomId > 0) {
                    HousekeepingFlow::afterRoomClean($db, $propertyId, $roomId, false);
                }
            }
            if (in_array($status, ['completed', 'rejected'], true) && $typeKey === 'donotdisturb') {
                $roomId = (int)($req['room_id'] ?: ($req['booking_room_id'] ?? 0));
                if ($roomId > 0) {
                    HousekeepingFlow::setDoNotDisturb($db, $propertyId, $roomId, false);
                }
            }
            // For other requests like rejected late checkout or completed housekeeping
            if ($status === 'completed' || $status === 'rejected') {
                $db->prepare("UPDATE guest_service_requests SET status = ?, resolved_at = NOW() WHERE id = ?")->execute([$status, $requestId]);
            } else {
                $db->prepare("UPDATE guest_service_requests SET status = ? WHERE id = ?")->execute([$status, $requestId]);
            }
            ApiResponse::success(['message' => 'Service request ' . $status]);
        }
    } else {
        ApiResponse::error('Invalid action');
    }
});
