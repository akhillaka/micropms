<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/TelegramOperationsHandler.php';

$db = Database::getInstance()->getConnection();

$secret = (string)(getenv('TELEGRAM_WEBHOOK_SECRET') ?: ($_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? ''));
if ($secret === '' && defined('TELEGRAM_WEBHOOK_SECRET')) {
    $secret = (string)TELEGRAM_WEBHOOK_SECRET;
}
if ($secret === '' || $secret === 'your_telegram_webhook_secret') {
    http_response_code(403);
    echo "Telegram webhook secret is not configured.";
    exit;
}
$provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals($secret, $provided)) {
    http_response_code(403);
    echo "Invalid webhook secret.";
    exit;
}

$stmt = $db->query("SELECT property_id, key_name, key_value FROM system_settings WHERE key_name IN ('TELEGRAM_OPERATIONS_BOT_TOKEN', 'TELEGRAM_OPERATIONS_CHAT_IDS')");
$byProperty = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pid = (int)$row['property_id'];
    $byProperty[$pid][$row['key_name']] = $row['key_value'];
}

$envToken = (string)(getenv('TELEGRAM_OPERATIONS_BOT_TOKEN') ?: ($_ENV['TELEGRAM_OPERATIONS_BOT_TOKEN'] ?? ''));
$botToken = '';
$allowedChatIds = [];
$resolvedPropertyId = 0;

foreach ($byProperty as $pid => $settings) {
    $token = trim((string)($settings['TELEGRAM_OPERATIONS_BOT_TOKEN'] ?? ''));
    $ids = array_values(array_filter(array_map('trim', explode(',', (string)($settings['TELEGRAM_OPERATIONS_CHAT_IDS'] ?? '')))));
    if ($token === '') {
        continue;
    }
    if ($envToken !== '' && !hash_equals($envToken, $token)) {
        continue;
    }
    $botToken = $token;
    $allowedChatIds = $ids;
    $resolvedPropertyId = $pid;
    if ($envToken !== '' || count($ids) > 0) {
        break;
    }
}

if ($botToken === '' && $envToken !== '') {
    $botToken = $envToken;
    $chatRaw = (string)(getenv('TELEGRAM_OPERATIONS_CHAT_IDS') ?: ($_ENV['TELEGRAM_OPERATIONS_CHAT_IDS'] ?? ''));
    $allowedChatIds = array_values(array_filter(array_map('trim', explode(',', $chatRaw))));
}

if ($botToken === '' || empty($allowedChatIds)) {
    http_response_code(500);
    echo "Telegram operations bot not configured.";
    exit;
}

$updateRaw = file_get_contents('php://input');
$update = json_decode($updateRaw, true);

if (!$update) {
    http_response_code(400);
    echo "Invalid input.";
    exit;
}

$handler = new TelegramOperationsHandler($botToken, $allowedChatIds, $resolvedPropertyId > 0 ? $resolvedPropertyId : null);
$handler->handleWebhook($update);

http_response_code(200);
echo "OK";
