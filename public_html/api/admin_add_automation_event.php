<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_settings');
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $name = trim($data['name'] ?? '');
    if (empty($name)) {
        ApiResponse::error('Name required');
    }
    $key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));

    $stmt = $db->prepare("INSERT INTO wa_automation_events (event_name, event_key, is_system) VALUES (?, ?, 0)");
    $stmt->execute([$name, $key]);
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'ADD_AUTOMATION_EVENT', 'SYSTEM', null, ['event_name' => $name, 'event_key' => $key]);
    
    ApiResponse::success();

}, true, true, false);
