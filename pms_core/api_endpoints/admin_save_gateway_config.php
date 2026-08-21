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
    $prevExtra = null;
    try {
        $ex = $db->prepare("SELECT extra_config FROM payment_gateway_configs WHERE property_id = ? AND gateway = ? LIMIT 1");
        $ex->execute([$propId, $gateway]);
        $raw = $ex->fetchColumn();
        if (is_string($raw) && $raw !== '') {
            $prevExtra = json_decode($raw, true);
            if (!is_array($prevExtra)) {
                $prevExtra = null;
            }
        }
    } catch (\Throwable $e) {
        $prevExtra = null;
    }

    if ($gateway === 'phonepe') {
        $merged = is_array($prevExtra) ? $prevExtra : [];
        $merged['salt_index'] = $saltIndex !== '' ? $saltIndex : ($merged['salt_index'] ?? '1');
        $extraConfig = json_encode($merged, JSON_THROW_ON_ERROR);
    } elseif ($gateway === 'razorpay') {
        $merged = is_array($prevExtra) ? $prevExtra : [];
        $webhookSecret = trim((string)($data['webhook_secret'] ?? $data['RAZORPAY_WEBHOOK_SECRET'] ?? ''));
        if ($webhookSecret !== '' && strcasecmp($webhookSecret, 'your_webhook_secret') !== 0) {
            $merged['webhook_secret'] = $webhookSecret;
        }
        $extraConfig = $merged !== [] ? json_encode($merged, JSON_THROW_ON_ERROR) : null;
    }

    // Canonical store only — do not dual-write RAZORPAY_* into system_settings.
    upsert_payment_gateway_config($db, $propId, $gateway, $keyId, $keySecret, $isActive, $mode, $extraConfig);

    ApiResponse::success([
        'message' => ucfirst($gateway) . ' configuration saved. Active gateways now appear when collecting payments.'
    ]);
});
