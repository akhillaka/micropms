<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_settings');

    $data = json_decode(file_get_contents('php://input'), true);
    
    $settings = [
        'GUEST_PORTAL_UPSELL_ENABLED' => ($data['upsell_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_SELF_CHECKOUT_ENABLED' => ($data['self_checkout_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_HOUSEKEEPING_ENABLED' => ($data['housekeeping_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_EARLY_LATE_FEE' => strval(floatval($data['early_late_fee'] ?? 0.00))
    ];

    $stmt = $db->prepare("INSERT INTO system_settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    
    foreach ($settings as $key => $val) {
        $stmt->execute([$key, $val]);
    }

    AuditLogger::log($_SESSION['user_id'], 'UPDATE_GUEST_PORTAL_SETTINGS', 'SYSTEM', null, $settings);

    ApiResponse::success(['message' => 'Guest Portal settings updated successfully']);
}, false, true, false);
