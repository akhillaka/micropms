<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('manage_settings');

    $result = NotificationRelay::sendTestTelegram((int)AuthHelper::getPropertyId());
    if (empty($result['ok'])) {
        ApiResponse::error((string)($result['error'] ?? 'Telegram test failed'), 400);
    }

    ApiResponse::success([
        'ok' => true,
        'message' => (string)($result['message'] ?? 'Test message sent'),
    ]);
}, true, true, false);
