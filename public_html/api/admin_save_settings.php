<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    // Fix #2/#13: owner check inside callback so Content-Type header is set first
    AuthHelper::requirePermission('manage_settings');

    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data) || empty($data)) {
        throw new \Exception('No data provided');
    }

    $stmt = $db->prepare("INSERT INTO system_settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    
    foreach ($data as $key => $value) {
        if ($key === '_csrf_token') continue;
        $stmt->execute([$key, $value]);
    }

    AuditLogger::log($_SESSION['user_id'], 'UPDATE_SETTINGS', 'SYSTEM', null, ['updated_keys' => array_keys($data)]);
    
    ApiResponse::success();

}, false, true, false);
