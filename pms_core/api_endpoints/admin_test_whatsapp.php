<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requirePermission('send_whatsapp');
header('Content-Type: application/json');

require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

CsrfToken::requireValid();

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['phone'])) {
    echo json_encode(['success' => false, 'message' => 'Phone number required']);
    exit;
}

$phone = $data['phone'];
$message = "✅ *MicroPMS WhatsApp Test*\n\nYour integration is fully functional!\nTime: " . date('Y-m-d H:i:s');

$result = NotificationRelay::sendWhatsApp($phone, $message, false);

if (isset($result['ok']) && $result['ok']) {
    echo json_encode(['success' => true, 'message' => 'Test message sent successfully']);
} else {
    $error = $result['error_message'] ?? 'Unknown error';
    echo json_encode(['success' => false, 'message' => "Failed to send test message: {$error}"]);
}
