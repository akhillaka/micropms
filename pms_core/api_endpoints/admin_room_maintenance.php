<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_maintenance');

    $data = json_decode(file_get_contents('php://input'), true);
    
    $roomId = (int)($data['room_id'] ?? 0);
    $startDate = trim($data['start_date'] ?? '');
    $endDate = trim($data['end_date'] ?? '');
    $reason = trim($data['reason'] ?? 'Maintenance / Repair');
    
    if (!$roomId || !$startDate || !$endDate) {
        ApiResponse::error('Room ID, start date, and end date are required');
    }

    $stmt = $db->prepare("INSERT INTO room_maintenance (room_id, start_date, end_date, reason, created_by) VALUES (:rid, :s, :e, :reason, :uid)");
    $stmt->execute([
        'rid' => $roomId,
        's' => $startDate . ' 00:00:00',
        'e' => $endDate . ' 23:59:59',
        'reason' => $reason,
        'uid' => $_SESSION['user_id'] ?? null
    ]);

    // Update room state to out_of_order if today falls within range
    $today = date('Y-m-d');
    if ($startDate <= $today && $endDate >= $today) {
        $db->prepare("UPDATE rooms SET state = 'out_of_order' WHERE id = :id")->execute(['id' => $roomId]);
    }
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'CREATE_ROOM_MAINTENANCE', 'ROOM', $roomId, [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'reason' => $reason
    ]);
    
    ApiResponse::success(['message' => 'Room maintenance block created successfully']);

}, true, true, false);
