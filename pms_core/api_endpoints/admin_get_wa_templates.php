<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requirePermission('send_whatsapp');
require_once __DIR__ . '/../../pms_core/Database.php';

header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();
$propId = AuthHelper::getPropertyId();

require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';
if (!SaaSEntitlementsService::isFeatureEnabled($db, $propId, 'whatsapp_module')) {
    echo json_encode(['success' => false, 'message' => 'WhatsApp module is not enabled.']);
    exit;
}
$stmt = $db->prepare("SELECT id, name, language, status, components_json FROM wa_templates WHERE property_id = ? AND status = 'APPROVED' ORDER BY name ASC");
$stmt->execute([$propId]);
$templates = $stmt->fetchAll();

// decode JSON for frontend
foreach ($templates as &$t) {
    $t['components'] = json_decode($t['components_json'], true);
    unset($t['components_json']);
}

echo json_encode(['success' => true, 'templates' => $templates]);
