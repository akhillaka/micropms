<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_automations');
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $id = $data['id'] ?? null;
    if (!$id) {
        ApiResponse::error('ID required');
    }

    $stmt = $db->prepare("SELECT event_key FROM wa_automation_events WHERE id = ? AND is_system = 0");
    $stmt->execute([$id]);
    $event = $stmt->fetch();
    if ($event) {
        $db->prepare("DELETE FROM wa_automations WHERE event_key = ?")->execute([$event['event_key']]);
        $db->prepare("DELETE FROM wa_automation_events WHERE id = ?")->execute([$id]);
        
        AuditLogger::log($_SESSION['user_id'] ?? null, 'DELETE_AUTOMATION_EVENT', 'SYSTEM', null, ['event_key' => $event['event_key']]);
        
        ApiResponse::success();
    } else {
        ApiResponse::error('Not found or cannot delete system event');
    }

}, true, true, false);
