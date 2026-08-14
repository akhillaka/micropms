<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_automations');
    
    $propertyId = AuthHelper::getPropertyId();
    require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';
    if (!SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'whatsapp_module')) {
        ApiResponse::error('WhatsApp module is not enabled for your subscription.', 403);
    }
    
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $event_key = $data['event_key'] ?? null;
    $template_id = $data['template_id'] ?? null;
    $status = $data['status'] ?? 'active';
    $mapping = $data['mapping'] ?? [];
    $propertyId = AuthHelper::getPropertyId();
    if (!$event_key) {
        ApiResponse::error('Missing event key');
    }

    if (empty($template_id)) {
        $stmt = $db->prepare("DELETE FROM wa_automations WHERE event_key = ? AND property_id = ?");
        $stmt->execute([$event_key, $propertyId]);
    } else {
        $mappingJson = json_encode($mapping);
        $stmt = $db->prepare("
            INSERT INTO wa_automations (property_id, event_key, template_id, variable_mapping_json, status)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), variable_mapping_json = VALUES(variable_mapping_json), status = VALUES(status), updated_at = NOW()
        ");
        $stmt->execute([$propertyId, $event_key, $template_id, $mappingJson, $status]);
    }

    AuditLogger::log($_SESSION['user_id'] ?? null, empty($template_id) ? 'DELETE_WA_AUTOMATION' : 'SAVE_WA_AUTOMATION', 'SYSTEM', null, [
        'event_key' => $event_key,
        'template_id' => $template_id
    ]);

    ApiResponse::success();

}, true, true, false);
