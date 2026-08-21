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

    $mappingJson = json_encode($mapping);
    $waActive = !empty($template_id) && $status === 'active';

    // Source of truth: automation_rules only (no wa_automations mirror)
    if (empty($template_id)) {
        $db->prepare("UPDATE automation_rules SET is_wa_active = 0, wa_template_id = NULL, wa_mapping_json = NULL WHERE event_key = ? AND property_id = ? AND deleted_at IS NULL")
           ->execute([$event_key, $propertyId]);
    } else {
        $db->prepare("
            INSERT INTO automation_rules (property_id, event_key, is_wa_active, wa_template_id, wa_mapping_json)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_wa_active = VALUES(is_wa_active), wa_template_id = VALUES(wa_template_id), wa_mapping_json = VALUES(wa_mapping_json), deleted_at = NULL
        ")->execute([$propertyId, $event_key, $waActive ? 1 : 0, $template_id, $mappingJson]);
    }

    AuditLogger::log($_SESSION['user_id'] ?? null, empty($template_id) ? 'DELETE_WA_AUTOMATION' : 'SAVE_WA_AUTOMATION', 'SYSTEM', null, [
        'event_key' => $event_key,
        'template_id' => $template_id
    ]);

    ApiResponse::success();

}, true, true, false);
