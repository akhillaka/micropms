<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/services/TelegramOperationsHandler.php';

$db = Database::getInstance()->getConnection();

// Fetch settings
$stmt = $db->query("SELECT key_name, key_value FROM system_settings WHERE key_name IN ('TELEGRAM_OPERATIONS_BOT_TOKEN', 'TELEGRAM_OPERATIONS_CHAT_IDS') AND property_id = 1");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['key_name']] = $row['key_value'];
}

$botToken = $settings['TELEGRAM_OPERATIONS_BOT_TOKEN'] ?? '';
$chatIdsRaw = $settings['TELEGRAM_OPERATIONS_CHAT_IDS'] ?? '';
$allowedChatIds = array_filter(array_map('trim', explode(',', $chatIdsRaw)));

if (empty($botToken) || empty($allowedChatIds)) {
    http_response_code(500);
    echo "Telegram operations bot not configured.";
    exit;
}

$updateRaw = file_get_contents("php://input");
$update = json_decode($updateRaw, true);

if (!$update) {
    http_response_code(400);
    echo "Invalid input.";
    exit;
}

$handler = new TelegramOperationsHandler($botToken, $allowedChatIds);
$handler->handleWebhook($update);

http_response_code(200);
echo "OK";
