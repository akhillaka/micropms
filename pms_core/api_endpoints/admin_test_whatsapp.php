<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('send_whatsapp');
    $data = ApiHandler::getJsonInput();
    $phone = $data['phone'] ?? '';
    if ($phone === '') {
        ApiResponse::error('Phone number required');
    }

    $message = "✅ *MicroPMS WhatsApp Test*\n\nYour integration is fully functional!\nTime: " . date('Y-m-d H:i:s');
    $result = NotificationRelay::sendWhatsApp($phone, $message, false);

    if (!empty($result['ok'])) {
        ApiResponse::success(['message' => 'Test message sent successfully']);
    }
    ApiResponse::error('Failed to send test message: ' . ($result['error_message'] ?? 'Unknown error'));
}, true, true, false);
