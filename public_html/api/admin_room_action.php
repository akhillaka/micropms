<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('housekeeping');

    $data = json_decode(file_get_contents('php://input'), true);
    
    $action = $data['action'] ?? '';
    $roomId = (int)($data['room_id'] ?? 0);
    
    if (!$roomId || !in_array($action, ['mark_clean', 'mark_ooo'])) {
        ApiResponse::error('Invalid request');
    }

    if ($action === 'mark_clean') {
        // Guard: only transition from dirty to clean (matching assistant behavior)
        $stmt = $db->prepare("UPDATE rooms SET state = 'clean' WHERE id = :id AND state = 'dirty'");
        $stmt->execute(['id' => $roomId]);
        
        if ($stmt->rowCount() === 0) {
            ApiResponse::error('Room is not dirty or not found');
        }
        
        AuditLogger::log($_SESSION['user_id'], 'MARKED_ROOM_CLEAN', 'ROOM', $roomId, ['source' => 'admin']);
    } else {
        // Mark out of order
        $stmt = $db->prepare("UPDATE rooms SET state = 'out_of_order' WHERE id = :id");
        $stmt->execute(['id' => $roomId]);
        
        AuditLogger::log($_SESSION['user_id'], 'MARKED_ROOM_OOO', 'ROOM', $roomId, ['source' => 'admin']);
    }
    
    ApiResponse::success();

}, true, true, false);
