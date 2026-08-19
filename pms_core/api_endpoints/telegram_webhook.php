<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/TelegramOpsConfig.php';
require_once __DIR__ . '/../services/TelegramOperationsHandler.php';

$db = Database::getInstance()->getConnection();

$secret = TelegramOpsConfig::webhookSecret($db);
$provided = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($secret !== '') {
    if ($provided === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        echo 'Invalid webhook secret.';
        exit;
    }
}

$bot = TelegramOpsConfig::resolveBot($db);
$botToken = $bot['token'];
$allowedChatIds = $bot['chat_ids'];
$resolvedPropertyId = (int)$bot['property_id'];

if ($botToken === '' || $allowedChatIds === []) {
    http_response_code(500);
    echo 'Telegram operations bot not configured.';
    exit;
}

$updateRaw = file_get_contents('php://input');
$update = json_decode((string)$updateRaw, true);

if (!is_array($update)) {
    http_response_code(400);
    echo 'Invalid input.';
    exit;
}

$handler = new TelegramOperationsHandler($botToken, $allowedChatIds, $resolvedPropertyId > 0 ? $resolvedPropertyId : null);
$handler->handleWebhook($update);

http_response_code(200);
echo 'OK';
