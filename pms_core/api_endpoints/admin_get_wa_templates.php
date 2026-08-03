<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLogin();
require_once __DIR__ . '/../../pms_core/Database.php';

header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();
$propId = AuthHelper::getPropertyId();
$stmt = $db->prepare("SELECT id, name, language, status, components_json FROM wa_templates WHERE property_id = ? AND status = 'APPROVED' ORDER BY name ASC");
$stmt->execute([$propId]);
$templates = $stmt->fetchAll();

// decode JSON for frontend
foreach ($templates as &$t) {
    $t['components'] = json_decode($t['components_json'], true);
    unset($t['components_json']);
}

echo json_encode(['success' => true, 'templates' => $templates]);
