<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/services/TelegramOpsConfig.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('manage_settings');

    $data = CsrfToken::getJsonPayload();
    $action = trim((string)($data['action'] ?? 'status'));
    $propertyId = (int)AuthHelper::getPropertyId();
    $bot = TelegramOpsConfig::resolveBot($db, $propertyId);
    $secret = TelegramOpsConfig::webhookSecret($db, $propertyId);
    $webhookUrl = TelegramOpsConfig::webhookUrlFromRequest();

    if ($action === 'status') {
        $info = ['ok' => false, 'description' => 'Bot token missing'];
        if ($bot['token'] !== '') {
            $info = NotificationRelay::callTelegramBot($bot['token'], 'getWebhookInfo', []);
        }
        $result = is_array($info['data']['result'] ?? null) ? $info['data']['result'] : [];
        ApiResponse::success([
            'ok' => true,
            'source' => $bot['source'],
            'has_token' => $bot['token'] !== '',
            'chat_ids' => $bot['chat_ids'],
            'secret_configured' => $secret !== '',
            'local_webhook_url' => $webhookUrl,
            'public_https' => TelegramOpsConfig::isPublicHttpsUrl($webhookUrl),
            'telegram_url' => (string)($result['url'] ?? ''),
            'pending_update_count' => (int)($result['pending_update_count'] ?? 0),
            'last_error_message' => (string)($result['last_error_message'] ?? ''),
            'last_error_date' => (int)($result['last_error_date'] ?? 0),
        ]);
    }

    if ($action !== 'connect') {
        ApiResponse::error('Unknown action', 400);
    }

    if ($bot['token'] === '') {
        ApiResponse::error('Save an operations bot token (or the notifier bot token) first.', 400);
    }
    if ($bot['chat_ids'] === []) {
        ApiResponse::error('Save at least one authorized chat ID, then tap Start in that Telegram chat.', 400);
    }
    if (!TelegramOpsConfig::isPublicHttpsUrl($webhookUrl)) {
        ApiResponse::error(
            'Telegram cannot reach this computer. Open Settings on your live HTTPS hotel URL (Hostinger), not localhost, then click Connect operations bot.',
            400
        );
    }

    if ($secret === '') {
        $secret = bin2hex(random_bytes(24));
        $stmt = $db->prepare('INSERT INTO system_settings (property_id, key_name, key_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)');
        $stmt->execute([$propertyId, 'TELEGRAM_WEBHOOK_SECRET', $secret]);
    }

    $set = NotificationRelay::callTelegramBot($bot['token'], 'setWebhook', [
        'url' => $webhookUrl,
        'secret_token' => $secret,
        'drop_pending_updates' => false,
        'allowed_updates' => ['message', 'callback_query'],
    ]);
    if (empty($set['ok'])) {
        ApiResponse::error((string)($set['error_message'] ?? $set['description'] ?? 'setWebhook failed'), 400);
    }

    $pingErrors = [];
    foreach ($bot['chat_ids'] as $chatId) {
        $ping = NotificationRelay::callTelegramBot($bot['token'], 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "Hotel operations bot is connected.\nType /start for the menu.",
        ]);
        if (empty($ping['ok'])) {
            $pingErrors[] = $chatId . ': ' . (string)($ping['error_message'] ?? 'send failed');
        }
    }

    $message = 'Webhook registered. Check Telegram — you should see a connected message. Then type /start.';
    if ($pingErrors !== []) {
        $message .= ' Ping issue: ' . implode('; ', $pingErrors);
    }

    ApiResponse::success([
        'ok' => true,
        'message' => $message,
        'webhook_url' => $webhookUrl,
        'source' => $bot['source'],
    ]);
}, true, true, false);
