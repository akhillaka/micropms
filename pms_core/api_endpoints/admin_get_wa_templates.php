<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('send_whatsapp');
    $propId = AuthHelper::getPropertyId();

    if (!SaaSEntitlementsService::isFeatureEnabled($db, $propId, 'whatsapp_module')) {
        ApiResponse::error('WhatsApp module is not enabled.');
    }

    $stmt = $db->prepare("SELECT id, name, language, status, components_json FROM wa_templates WHERE property_id = ? AND status = 'APPROVED' ORDER BY name ASC");
    $stmt->execute([$propId]);
    $templates = $stmt->fetchAll();

    foreach ($templates as &$t) {
        $t['components'] = json_decode($t['components_json'], true);
        unset($t['components_json']);
    }

    ApiResponse::success(['templates' => $templates]);
}, true, false, false);
