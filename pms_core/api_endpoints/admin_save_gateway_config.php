<?php
require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/Database.php';

ApiHandler::handle(function($data, $auth) {
    if ($auth['role'] !== 'owner') {
        throw new Exception("Only property owners can configure payment gateways", 403);
    }

    $propId = $auth['property_id'];
    $gateway = $data['gateway'] ?? '';
    $mode = $data['mode'] ?? 'test';
    $keyId = trim($data['key_id'] ?? '');
    $keySecret = trim($data['key_secret'] ?? '');
    $isActive = (int)($data['is_active'] ?? 0);
    $saltIndex = trim($data['salt_index'] ?? '1');

    if (!in_array($gateway, ['razorpay', 'phonepe'])) {
        throw new Exception("Invalid gateway specified");
    }

    $db = Database::getInstance()->getConnection();
    
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

    return [
        'success' => true,
        'message' => ucfirst($gateway) . ' configuration saved successfully.'
    ];
});
