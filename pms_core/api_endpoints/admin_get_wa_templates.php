<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLogin();
require_once __DIR__ . '/../../pms_core/Database.php';

header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id, name, language, status, components_json FROM wa_templates WHERE status = 'APPROVED' ORDER BY name ASC");
$templates = $stmt->fetchAll();

// decode JSON for frontend
foreach ($templates as &$t) {
    $t['components'] = json_decode($t['components_json'], true);
    unset($t['components_json']);
}

echo json_encode(['success' => true, 'templates' => $templates]);
