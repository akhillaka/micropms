<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('housekeeping');
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? $data['action'] ?? '';

    $propertyId = AuthHelper::getPropertyId();
    if ($action === 'list') {
        $stmt = $db->prepare("
            SELECT r.id, r.room_number, r.state,
                   (SELECT COUNT(*) FROM bookings b WHERE b.room_id = r.id AND b.booking_status = 'checked_in' AND b.property_id = :prop_id) as is_occupied
            FROM rooms r 
            WHERE r.property_id = :prop_id
            ORDER BY r.room_number ASC
        ");
        $stmt->execute(['prop_id' => $propertyId]);
        $rooms = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $dirty = [];
        $clean = [];
        foreach ($rooms as $r) {
            if ($r['state'] === 'dirty') {
                $dirty[] = $r;
            } else {
                $clean[] = $r;
            }
        }
        
        ApiResponse::success([
            'dirty' => $dirty,
            'clean' => $clean,
            'checklist_items' => []
        ]);
    } elseif ($action === 'mark_clean' || $action === 'mark_deep_clean') {
        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) {
            ApiResponse::error('Invalid room_id');
        }
        if ($action === 'mark_deep_clean') {
            $stmt = $db->prepare("UPDATE rooms SET state = 'clean', last_deep_clean = CURRENT_TIMESTAMP WHERE id = ? AND property_id = ?");
        } else {
            $stmt = $db->prepare("UPDATE rooms SET state = 'clean' WHERE id = ? AND property_id = ?");
        }
        $stmt->execute([$roomId, $propertyId]);
        
        AuditLogger::log((int)($_SESSION['user_id'] ?? 0), 'UPDATE_HK_STATUS', 'ROOMS', $roomId, ['status' => 'clean', 'deep_clean' => ($action === 'mark_deep_clean')]);
        ApiResponse::success();
    } elseif ($action === 'mark_dirty') {
        $roomId = (int)($data['room_id'] ?? 0);
        if ($roomId <= 0) {
            ApiResponse::error('Invalid room_id');
        }
        $stmt = $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ? AND property_id = ?");
        $stmt->execute([$roomId, $propertyId]);
        
        AuditLogger::log((int)($_SESSION['user_id'] ?? 0), 'UPDATE_HK_STATUS', 'ROOMS', $roomId, ['status' => 'dirty']);
        
        require_once __DIR__ . '/../NotificationRelay.php';
        try {
            $rStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = ? AND property_id = ?");
            $rStmt->execute([$roomId, $propertyId]);
            $roomNumber = $rStmt->fetchColumn();
            
            $hkStaffStmt = $db->prepare("SELECT phone FROM staff_users WHERE (access_level = 'housekeeping' OR access_level = 'admin') AND property_id = ? AND is_active = 1 LIMIT 1");
            $hkStaffStmt->execute([$propertyId]);
            $staffPhone = $hkStaffStmt->fetchColumn();
            
            if ($staffPhone) {
                NotificationRelay::triggerAutomation('room_marked_dirty', $staffPhone, $roomId, ['room_number' => $roomNumber]);
            }
        } catch (\Throwable $t) {
            error_log("HK Notification failed: " . $t->getMessage());
        }

        ApiResponse::success();
    } else {
        ApiResponse::error('Invalid action');
    }

}, true, true, true);
