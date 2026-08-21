<?php
declare(strict_types=1);
require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_payment_gateways');

    $data = ApiHandler::getJsonInput();
    $propId = AuthHelper::getPropertyId();
    $gateway = strtolower(trim((string)($data['gateway'] ?? '')));
    $mode = in_array(($data['mode'] ?? 'test'), ['test', 'live'], true) ? $data['mode'] : 'test';
    $keyId = trim((string)($data['key_id'] ?? ''));
    $keySecret = trim((string)($data['key_secret'] ?? ''));
    $isActive = (int)($data['is_active'] ?? 0) === 1 ? 1 : 0;
    $saltIndex = trim((string)($data['salt_index'] ?? '1'));

    if (!in_array($gateway, ['razorpay', 'phonepe'], true)) {
        ApiResponse::error("Invalid gateway specified");
    }

    if ($isActive === 1 && $keyId === '') {
        ApiResponse::error('Enter the ' . ($gateway === 'phonepe' ? 'Merchant ID' : 'Key ID') . ' before marking this gateway active.');
    }

    $extraConfig = null;
    if ($gateway === 'phonepe') {
        $extraConfig = json_encode(['salt_index' => $saltIndex]);
    } elseif ($gateway === 'razorpay') {
        $webhookSecret = trim((string)($data['webhook_secret'] ?? $data['RAZORPAY_WEBHOOK_SECRET'] ?? ''));
        if ($webhookSecret !== '' && strcasecmp($webhookSecret, 'your_webhook_secret') !== 0) {
            $extraConfig = json_encode(['webhook_secret' => $webhookSecret], JSON_THROW_ON_ERROR);
            $stmt = $db->prepare("INSERT INTO system_settings (property_id, key_name, key_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            $stmt->execute([$propId, 'RAZORPAY_WEBHOOK_SECRET', $webhookSecret]);
        }
    }

    upsert_payment_gateway_config($db, $propId, $gateway, $keyId, $keySecret, $isActive, $mode, $extraConfig);

    if ($gateway === 'razorpay' && $keyId !== '') {
        $stmt = $db->prepare("INSERT INTO system_settings (property_id, key_name, key_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        $stmt->execute([$propId, 'RAZORPAY_KEY_ID', $keyId]);
        if ($keySecret !== '') {
            $stmt->execute([$propId, 'RAZORPAY_KEY_SECRET', $keySecret]);
        }
    }

    ApiResponse::success([
        'message' => ucfirst($gateway) . ' configuration saved. Active gateways now appear when collecting payments.'
    ]);
});
