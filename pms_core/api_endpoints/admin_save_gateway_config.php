<?php
require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/Database.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_payment_gateways');

    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? [];
    $propId = AuthHelper::getPropertyId();
    $gateway = $data['gateway'] ?? '';
    $mode = $data['mode'] ?? 'test';
    $keyId = trim($data['key_id'] ?? '');
    $keySecret = trim($data['key_secret'] ?? '');
    $isActive = (int)($data['is_active'] ?? 0);
    $saltIndex = trim($data['salt_index'] ?? '1');

    if (!in_array($gateway, ['razorpay', 'phonepe'])) {
        ApiResponse::error("Invalid gateway specified");
    }
    
    $extraConfig = null;
    if ($gateway === 'phonepe') {
        $extraConfig = json_encode(['salt_index' => $saltIndex]);
    }

    // Upsert the config
    $stmt = $db->prepare("
        INSERT INTO payment_gateway_configs 
        (property_id, gateway, mode, key_id, key_secret, extra_config, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        mode = VALUES(mode),
        key_id = VALUES(key_id),
        key_secret = VALUES(key_secret),
        extra_config = VALUES(extra_config),
        is_active = VALUES(is_active)
    ");
    
    $stmt->execute([
        $propId, 
        $gateway, 
        $mode, 
        $keyId, 
        $keySecret, 
        $extraConfig, 
        $isActive
    ]);

    ApiResponse::success([
        'message' => ucfirst($gateway) . ' configuration saved successfully.'
    ]);
});
