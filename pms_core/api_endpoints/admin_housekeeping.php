<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('housekeeping');
    $data = ApiHandler::getJsonInput();
    if ($data === []) {
        $data = $_POST;
    }
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
        
        $checklistItems = [];
        try {
            $cStmt = $db->prepare("SELECT * FROM housekeeping_checklist_items WHERE (property_id = ? OR property_id IS NULL) AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY display_order ASC, id ASC");
            $cStmt->execute([$propertyId]);
            $checklistItems = $cStmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $checklistItems = [];
        }
        ApiResponse::success([
            'dirty' => $dirty,
            'clean' => $clean,
            'checklist_items' => $checklistItems
        ]);
    } elseif ($action === 'add_checklist_item') {
        $itemText = trim((string)($data['item_text'] ?? ''));
        $isMandatory = !empty($data['is_mandatory']) ? 1 : 0;
        if ($itemText === '') {
            ApiResponse::error('Item text is required');
        }
        try {
            $stmt = $db->prepare("INSERT INTO housekeeping_checklist_items (property_id, item_text, is_mandatory, display_order) VALUES (?, ?, ?, 0)");
            $stmt->execute([$propertyId, $itemText, $isMandatory]);
        } catch (\PDOException $e) {
            $stmt = $db->prepare("INSERT INTO housekeeping_checklist_items (item_text, is_mandatory) VALUES (?, ?)");
            $stmt->execute([$itemText, $isMandatory]);
        }
        ApiResponse::success(['message' => 'Checklist item added', 'id' => (int)$db->lastInsertId()]);
    } elseif ($action === 'delete_checklist_item') {
        $itemId = (int)($data['item_id'] ?? 0);
        if ($itemId <= 0) {
            ApiResponse::error('Item ID required');
        }
        try {
            $stmt = $db->prepare("DELETE FROM housekeeping_checklist_items WHERE id = ? AND (property_id = ? OR property_id IS NULL)");
            $stmt->execute([$itemId, $propertyId]);
        } catch (\PDOException $e) {
            $stmt = $db->prepare("DELETE FROM housekeeping_checklist_items WHERE id = ?");
            $stmt->execute([$itemId]);
        }
        ApiResponse::success(['message' => 'Checklist item deleted']);
    } elseif ($action === 'mark_clean' || $action === 'mark_deep_clean') {
        if (!AuthHelper::can('update_room_status')) {
            ApiResponse::error('Unauthorized to update room status', 403);
        }
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
        if (!AuthHelper::can('update_room_status')) {
            ApiResponse::error('Unauthorized to update room status', 403);
        }
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
