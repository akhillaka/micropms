<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_automations');
    $propertyId = AuthHelper::getPropertyId();
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
        $eventKey = (string)$event['event_key'];
        // Soft-delete this property's rule; rewrite event_key so unique (property_id, event_key) is freed.
        $db->prepare("
            UPDATE automation_rules
            SET deleted_at = NOW(),
                event_key = CONCAT(event_key, '__del_', id),
                is_wa_active = 0,
                is_email_active = 0,
                is_telegram_active = 0
            WHERE event_key = ? AND property_id = ? AND deleted_at IS NULL
        ")->execute([$eventKey, $propertyId]);

        $left = $db->prepare("SELECT COUNT(*) FROM automation_rules WHERE event_key = ? AND deleted_at IS NULL");
        $left->execute([$eventKey]);
        if ((int)$left->fetchColumn() === 0) {
            $db->prepare("DELETE FROM wa_automation_events WHERE id = ?")->execute([$id]);
        }
        
        AuditLogger::log($_SESSION['user_id'] ?? null, 'DELETE_AUTOMATION_EVENT', 'SYSTEM', null, [
            'event_key' => $eventKey,
            'property_id' => $propertyId,
        ]);
        
        ApiResponse::success();
    } else {
        ApiResponse::error('Not found or cannot delete system event');
    }

}, true, true, false);
