<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PhoneHelper.php';

class NotificationRelay {

    /**
     * Check if a specific notification event is enabled.
     */
    public static function isEnabled(string $eventKey): bool {
        $key = self::normalizeNotifyEventKey($eventKey);
        $defaults = [
            'booking_confirmed' => true,
            'check_in' => true,
            'check_out' => true,
            'overstay' => true,
            'payment_received' => true,
            'room_dirty' => true,
            'daily_summary' => true,
            'pre_departure' => false,
            'folio_activity' => true,
        ];
        $events = $defaults;
        if (defined('NOTIFY_EVENTS')) {
            $decoded = json_decode((string)NOTIFY_EVENTS, true);
            if (is_array($decoded) && $decoded !== []) {
                $events = $decoded;
            }
        }
        if (!array_key_exists($key, $events)) {
            return true;
        }
        $val = $events[$key];
        return $val === true || $val === 1 || $val === '1' || $val === 'true';
    }

    private static function normalizeNotifyEventKey(string $eventKey): string {
        return match ($eventKey) {
            'new_booking' => 'booking_confirmed',
            'room_service_order' => 'folio_activity',
            default => $eventKey,
        };
    }

    private static function resolveNotifyPropertyId(?int $propertyId): int {
        if ($propertyId !== null && (int)$propertyId > 0) {
            return (int)$propertyId;
        }
        require_once __DIR__ . '/AuthHelper.php';
        try {
            return (int)AuthHelper::getPropertyId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{token: string, chat_ids: list<string>}
     */
    private static function resolveTelegramConfig(\PDO $db, int $propertyId): array {
        $settings = [];
        if ($propertyId > 0) {
            $stmt = $db->prepare("SELECT key_name, key_value FROM system_settings WHERE property_id = ? AND key_name IN ('TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID')");
            $stmt->execute([$propertyId]);
            while ($row = $stmt->fetch()) {
                $settings[$row['key_name']] = $row['key_value'];
            }
        }
        $token = trim((string)($settings['TELEGRAM_BOT_TOKEN'] ?? ''));
        $chats = (string)($settings['TELEGRAM_CHAT_ID'] ?? '');
        if ($token === '' && defined('TELEGRAM_BOT_TOKEN')) {
            $token = trim((string)TELEGRAM_BOT_TOKEN);
        }
        if (trim($chats) === '' && defined('TELEGRAM_CHAT_ID')) {
            $chats = (string)TELEGRAM_CHAT_ID;
        }
        $ids = preg_split('/[\s,]+/', $chats, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        return ['token' => $token, 'chat_ids' => $ids];
    }

    private static function telegramRequestSucceeded(array|false $res): bool {
        if (!is_array($res)) {
            return false;
        }
        if (isset($res['data']) && is_array($res['data']) && array_key_exists('ok', $res['data'])) {
            return !empty($res['data']['ok']);
        }
        return !empty($res['ok']);
    }

    private static function telegramErrorMessage(array|false $res): string {
        if (!is_array($res)) {
            return 'Could not reach Telegram';
        }
        $msg = (string)($res['error_message'] ?? $res['description'] ?? '');
        if ($msg === '' && isset($res['data']['description'])) {
            $msg = (string)$res['data']['description'];
        }
        if ($msg === '') {
            $msg = 'Telegram rejected the request';
        }
        $lower = strtolower($msg);
        if (str_contains($lower, 'chat not found') || str_contains($lower, 'chat_id')) {
            $msg .= '. Open the bot in Telegram, tap Start, then paste your numeric chat ID from @userinfobot (groups start with -).';
        } elseif (str_contains($lower, 'unauthorized')) {
            $msg .= '. The bot token is invalid.';
        }
        return $msg;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private static function deliverTelegram(string $token, array $chatIds, string $text): array {
        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $sent = 0;
        $lastError = '';
        foreach ($chatIds as $id) {
            $res = self::makePostRequest($url, [
                'chat_id' => $id,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
            if (!self::telegramRequestSucceeded($res)) {
                $err = self::telegramErrorMessage($res);
                if (str_contains(strtolower($err), 'parse')) {
                    $res = self::makePostRequest($url, [
                        'chat_id' => $id,
                        'text' => html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'),
                    ]);
                }
            }
            if (self::telegramRequestSucceeded($res)) {
                $sent++;
            } else {
                $lastError = self::telegramErrorMessage($res);
            }
        }
        if ($sent > 0) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => $lastError !== '' ? $lastError : 'Telegram send failed'];
    }

    public static function sendTelegramDocument(string $filePath, string $caption, ?int $propertyId = null): bool {
        if ($filePath === '' || !is_readable($filePath)) {
            return false;
        }
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();
        if ($propertyId === null) {
            require_once __DIR__ . '/AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }
        $cfg = self::resolveTelegramConfig($db, (int)$propertyId);
        if ($cfg['token'] === '' || $cfg['token'] === 'your_telegram_bot_token' || $cfg['chat_ids'] === []) {
            return false;
        }
        $url = 'https://api.telegram.org/bot' . $cfg['token'] . '/sendDocument';
        $sent = 0;
        foreach ($cfg['chat_ids'] as $chatId) {
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }
            $doc = new \CURLFile($filePath, 'application/pdf', basename($filePath));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_POSTFIELDS => [
                    'chat_id' => $chatId,
                    'caption' => substr($caption, 0, 1024),
                    'document' => $doc,
                ],
            ]);
            $raw = @curl_exec($ch);
            curl_close($ch);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && !empty($decoded['ok'])) {
                $sent++;
            }
        }
        return $sent > 0;
    }

    /**
     * Replace placeholders with context data.
     */
    public static function formatTemplate(string $template, array $context): string {
        foreach (['guest_name', 'action_url', 'first_name', 'hotel_name', 'room_number', 'room_type', 'booking_id', 'check_in_date', 'check_out_date', 'checkout_time', 'total_amount', 'paid_amount', 'balance_amount', 'amount_due', 'invoice_link', 'payment_link', 'review_link', 'guest_phone', 'guest_email'] as $fallbackKey) {
            if (!isset($context[$fallbackKey])) {
                $context[$fallbackKey] = '';
            }
        }
        if (!isset($context['hotel_name']) || $context['hotel_name'] === '') {
            $context['hotel_name'] = defined('PROPERTY_NAME') ? PROPERTY_NAME : 'Our Hotel';
        }
        
        $search = [];
        $replace = [];
        foreach ($context as $k => $v) {
            $search[] = '{' . $k . '}';
            $replace[] = (string)$v;
        }
        return str_replace($search, $replace, $template);
    }

    /**
     * Queue Telegram delivery (never blocks the HTTP request on Telegram API).
     * Cron / QueueService::drainNotifyQueues() deliver asynchronously.
     */
    public static function sendTelegram(string $fallbackMessage, ?string $eventKey = null, array $context = [], ?int $propertyId = null): bool {
        require_once __DIR__ . '/Database.php';
        require_once __DIR__ . '/DeferredSideEffects.php';
        require_once __DIR__ . '/services/QueueService.php';
        $db = Database::getInstance()->getConnection();

        $propertyId = self::resolveNotifyPropertyId($propertyId);
        if ($propertyId <= 0) {
            error_log('Telegram send skipped: no property context');
            return false;
        }

        if ($eventKey !== null && !self::isEnabled($eventKey)) {
            return false;
        }

        $cfg = self::resolveTelegramConfig($db, $propertyId);
        if ($cfg['token'] === '' || $cfg['token'] === 'your_telegram_bot_token' || $cfg['chat_ids'] === []) {
            return false;
        }

        $message = $fallbackMessage;
        if ($eventKey !== null) {
            $constName = 'TG_TEMPLATE_' . strtoupper($eventKey);
            if (defined($constName)) {
                $message = constant($constName);
            }
        }

        $formatted = self::formatTemplate($message, $context);
        DeferredSideEffects::afterCommit(static function () use ($formatted, $propertyId): void {
            try {
                QueueService::push('telegram', ['message' => $formatted], 0, $propertyId);
            } catch (\Throwable $e) {
                error_log('Failed to queue telegram job: ' . $e->getMessage());
            }
        });
        return true;
    }

    /**
     * Send Telegram message synchronously.
     */
    public static function sendTelegramSync(string $fallbackMessage, ?string $eventKey = null, array $context = [], ?int $propertyId = null): bool {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();

        $propertyId = self::resolveNotifyPropertyId($propertyId);
        if ($propertyId <= 0) {
            error_log('Telegram sync send skipped: no property context');
            return false;
        }

        if ($eventKey !== null && !self::isEnabled($eventKey)) {
            return false;
        }

        $cfg = self::resolveTelegramConfig($db, $propertyId);
        if ($cfg['token'] === '' || $cfg['token'] === 'your_telegram_bot_token' || $cfg['chat_ids'] === []) {
            return false;
        }

        $message = $fallbackMessage;
        if ($eventKey !== null) {
            $constName = 'TG_TEMPLATE_' . strtoupper($eventKey);
            if (defined($constName)) {
                $message = constant($constName);
            }
        }

        $formatted = self::formatTemplate($message, $context);
        $delivered = self::deliverTelegram($cfg['token'], $cfg['chat_ids'], $formatted);
        return !empty($delivered['ok']);
    }

    /**
     * Send a test message to verify Telegram integration.
     */
    public static function sendTestTelegram(?int $propertyId = null): array {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();

        if ($propertyId === null) {
            require_once __DIR__ . '/AuthHelper.php';
            $propertyId = (int)AuthHelper::getPropertyId();
        }

        $cfg = self::resolveTelegramConfig($db, (int)$propertyId);
        $token = $cfg['token'];
        $idList = $cfg['chat_ids'];

        if ($token === '' || $token === 'your_telegram_bot_token') {
            return ['ok' => false, 'success' => false, 'error' => 'Bot token not configured. Save Settings → Integrations first.'];
        }
        if ($idList === []) {
            return ['ok' => false, 'success' => false, 'error' => 'Chat ID not configured. Message your bot, then paste the numeric ID from @userinfobot.'];
        }

        $msg = "✅ <b>MicroPMS Telegram Test</b>\n\n"
             . "Bot is connected and working!\n"
             . "Time: " . date('d M Y, h:i A');

        $delivered = self::deliverTelegram($token, $idList, $msg);
        if (!empty($delivered['ok'])) {
            return ['ok' => true, 'success' => true, 'message' => 'Test message sent'];
        }
        return ['ok' => false, 'success' => false, 'error' => $delivered['error'] ?? 'Telegram test failed'];
    }

    public static function sendWhatsApp(string $phoneNumber, array|string $payloadData, bool $isTemplate = true, ?int $propertyId = null): array {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();

        if ($propertyId === null) {
            require_once __DIR__ . '/AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }

        $payload = json_encode([
            'phoneNumber' => $phoneNumber,
            'phone' => $phoneNumber,
            'payload' => $payloadData,
            'message' => $payloadData,
            'is_hsm' => $isTemplate,
            'isTemplate' => $isTemplate,
        ]);

        try {
            $stmt = $db->prepare("INSERT INTO jobs_queue (queue_name, property_id, payload_json) VALUES ('whatsapp', ?, ?)");
            $stmt->execute([$propertyId, $payload]);
            return ['ok' => true, 'queued' => true];
        } catch (\Exception $e) {
            error_log("Failed to queue whatsapp job: " . $e->getMessage());
            return ['ok' => false, 'error_message' => 'Failed to queue message'];
        }
    }

    /**
     * Sends a WhatsApp message synchronously and returns a structured array with ok status and message/error.
     */
    public static function sendWhatsAppSync(string $phoneNumber, array|string $payloadData, bool $isTemplate = true, ?int $propertyId = null): array {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();

        if ($propertyId === null) {
            require_once __DIR__ . '/AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }

        $stmt = $db->prepare("SELECT key_name, key_value FROM system_settings WHERE property_id = ? AND key_name IN ('WHATSAPP_TOKEN', 'WHATSAPP_WABA_ID', 'WHATSAPP_PHONE_NUMBER_ID')");
        $stmt->execute([$propertyId]);
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['key_name']] = $row['key_value'];
        }

        $waToken = $settings['WHATSAPP_TOKEN'] ?? (defined('WHATSAPP_TOKEN') ? WHATSAPP_TOKEN : null);
        $wabaId = $settings['WHATSAPP_WABA_ID'] ?? (defined('WHATSAPP_WABA_ID') ? WHATSAPP_WABA_ID : null);
        $phoneId = trim((string)($settings['WHATSAPP_PHONE_NUMBER_ID'] ?? (defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '')));
        if (in_array($phoneId, ['', 'your_phone_number_id'], true)) {
            $phoneId = '';
        }

        if (empty($waToken) || $waToken === 'your_whatsapp_token_here') {
            return ['ok' => false, 'error_message' => 'WhatsApp token is not configured'];
        }

        $baseUrl = !empty($wabaId) && str_starts_with($wabaId, 'http') 
                   ? rtrim($wabaId, '/')
                   : 'https://one.xpressbot.org/api/workspace/v1';

        // Always resolve to E.164 (digits only, with country code) via PhoneHelper
        $cleanPhone = PhoneHelper::toE164($phoneNumber);
        if ($cleanPhone === null) {
            // Fallback: strip non-digits and use as-is (supports non-Indian numbers)
            $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        }
        if (empty($cleanPhone)) {
            return ['ok' => false, 'error_message' => 'Invalid phone number format'];
        }

        if ($isTemplate) {
            $url = $baseUrl . '/whatsapp/templates/send';
            $templateName = is_array($payloadData) ? ($payloadData['name'] ?? '') : $payloadData;
            $languageCode = is_array($payloadData) ? ($payloadData['language']['code'] ?? 'en') : 'en';
            $vars = [];
            if (is_array($payloadData) && isset($payloadData['components'][0]['parameters'])) {
                foreach ($payloadData['components'][0]['parameters'] as $p) {
                    $vars[] = $p['text'] ?? '';
                }
            }
            
            $data = [
                'to' => $cleanPhone,
                'templateName' => $templateName,
                'languageCode' => $languageCode,
                'variables' => implode(',', $vars),
            ];
            if ($phoneId !== '') {
                $data['channelId'] = $phoneId;
            }
        } else {
            $url = $baseUrl . '/whatsapp/message/send';
            $data = [
                'to' => $cleanPhone,
                'type' => 'text',
                'body' => is_array($payloadData) ? ($payloadData['text']['body'] ?? '') : $payloadData
            ];
            if ($phoneId !== '') {
                $data['channelId'] = $phoneId;
            }
        }

        $res = self::makePostRequest($url, $data, $waToken);
        if ($res && isset($res['ok']) && $res['ok'] === true) {
            $msgId = "msg_" . uniqid();
            $payload = is_array($res['data'] ?? null) ? $res['data'] : [];
            $inner = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            if (isset($inner['messageId'])) $msgId = (string)$inner['messageId'];
            elseif (isset($inner['id'])) $msgId = (string)$inner['id'];
            elseif (isset($inner['wamid'])) $msgId = (string)$inner['wamid'];
            elseif (isset($inner['messages'][0]['id'])) $msgId = (string)$inner['messages'][0]['id'];
            return ['ok' => true, 'messageId' => $msgId];
        }
        
        return $res ?: ['ok' => false, 'error_message' => 'Unknown connection error'];
    }

    private static function makePostRequest(string $url, array $data, ?string $bearerToken = null): array|false {
        try {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }

            $headers = ['Content-Type: application/json'];
            if ($bearerToken !== null) {
                $headers[] = 'X-API-Key: ' . $bearerToken;
                $headers[] = 'Authorization: Bearer ' . $bearerToken;
            }

            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $caBundle = self::curlCaBundlePath();
            if ($caBundle !== null) {
                curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
            }

            $response = @curl_exec($ch);
            $curlErr = curl_errno($ch) ? curl_error($ch) : '';
            if ($curlErr !== '' && (stripos($curlErr, 'ssl') !== false || stripos($curlErr, 'certificate') !== false)) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $response = @curl_exec($ch);
                $curlErr = curl_errno($ch) ? curl_error($ch) : '';
            }

            if ($curlErr !== '') {
                curl_close($ch);
                error_log("PMS cURL Connection Error: " . $curlErr);
                return ['ok' => false, 'error_message' => 'cURL Error: ' . $curlErr];
            }

            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = json_decode((string)$response, true);
            $isDecodedArray = is_array($decoded);

            if ($isDecodedArray && array_key_exists('ok', $decoded) && (isset($decoded['result']) || isset($decoded['description']) || isset($decoded['error_code']))) {
                if (!empty($decoded['ok'])) {
                    return ['ok' => true, 'data' => $decoded];
                }
                return [
                    'ok' => false,
                    'error_code' => $decoded['error_code'] ?? $httpCode,
                    'error_message' => (string)($decoded['description'] ?? 'Telegram rejected the request'),
                    'description' => $decoded['description'] ?? null,
                    'data' => $decoded,
                ];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                if ($isDecodedArray && array_key_exists('success', $decoded) && $decoded['success'] === false) {
                    $errorMsg = is_string($decoded['message'] ?? null)
                        ? (string)$decoded['message']
                        : (is_string($decoded['error'] ?? null) ? (string)$decoded['error'] : 'XpressBot returned success=false');
                    return ['ok' => false, 'error_message' => $errorMsg, 'data' => $decoded];
                }
                return ['ok' => true, 'data' => $isDecodedArray ? $decoded : []];
            } else {
                // Log detailed error from the remote API
                $errorMsg = "HTTP {$httpCode} failed response";
                $errorCode = (string)$httpCode;
                if ($isDecodedArray) {
                    if (isset($decoded['error']['message'])) {
                        $errorCode = $decoded['error']['code'] ?? $httpCode;
                        $errorMsg = $decoded['error']['message'];
                    } elseif (is_string($decoded['error'] ?? null) || is_string($decoded['message'] ?? null)) {
                        $errorMsg = (string)($decoded['message'] ?? $decoded['error']);
                    } elseif (isset($decoded['description'])) { // Telegram API format
                        $errorCode = $decoded['error_code'] ?? $httpCode;
                        $errorMsg = $decoded['description'];
                    }
                }
                error_log("PMS API Call Failed to [{$url}]. Error: " . $errorMsg);
                
                // If it's a WhatsApp failure, send a Telegram notification about the delivery failure
                if ((strpos($url, 'graph.facebook.com') !== false || strpos($url, 'xpressbot.org') !== false) && defined('TELEGRAM_BOT_TOKEN') && !empty(TELEGRAM_BOT_TOKEN)) {
                    $recipient = $data['to'] ?? 'Unknown';
                    $tgAlert = "⚠️ <b>WhatsApp Delivery Failed</b>\n\n"
                             . "<b>To:</b> +{$recipient}\n"
                             . "<b>Error:</b> " . htmlspecialchars($errorMsg);
                    
                    // Call direct Telegram dispatch logic to avoid recursion
                    $tgUrl = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
                    
                    $chatIds = defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '';
                    $idList = array_filter(array_map('trim', explode(',', $chatIds)));
                    
                    foreach ($idList as $id) {
                        $tgData = [
                            'chat_id'    => $id,
                            'text'       => $tgAlert,
                            'parse_mode' => 'HTML'
                        ];
                        
                        $tgCh = curl_init($tgUrl);
                        if ($tgCh !== false) {
                            curl_setopt($tgCh, CURLOPT_POST, 1);
                            curl_setopt($tgCh, CURLOPT_POSTFIELDS, json_encode($tgData));
                            curl_setopt($tgCh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                            curl_setopt($tgCh, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($tgCh, CURLOPT_SSL_VERIFYPEER, true);
                            curl_setopt($tgCh, CURLOPT_TIMEOUT, 5);
                            curl_exec($tgCh);
                            curl_close($tgCh); // FIX: close handles properly in the failure path
                        }
                    }
                }
                
                return ['ok' => false, 'error_code' => $errorCode, 'error_message' => $errorMsg];
            }
            
        } catch (\Throwable $e) {
            if (isset($ch) && $ch !== false && (is_resource($ch) || $ch instanceof \CurlHandle)) {
                curl_close($ch);
            }
            error_log("PMS Exception in API request: " . $e->getMessage());
            return ['ok' => false, 'error_message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{ok:bool,error_message?:string,description?:string,data?:mixed}
     */
    public static function callTelegramBot(string $token, string $method, array $params = []): array
    {
        $token = trim($token);
        $method = trim($method, '/');
        if ($token === '' || $method === '') {
            return ['ok' => false, 'error_message' => 'Bot token or method is empty'];
        }
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        $res = self::makePostRequest($url, $params);
        if ($res === false) {
            return ['ok' => false, 'error_message' => 'Could not reach Telegram'];
        }
        return $res;
    }

    private static function curlCaBundlePath(): ?string {
        $candidates = [
            ini_get('curl.cainfo') ?: '',
            ini_get('openssl.cafile') ?: '',
            '/Applications/XAMPP/xamppfiles/share/curl/curl-ca-bundle.crt',
            '/Applications/XAMPP/xamppfiles/etc/ssl/certs/ca-bundle.crt',
            '/etc/ssl/certs/ca-certificates.crt',
        ];
        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    /** Load automation_rules only (SoT). Soft-deleted rows are ignored. */
    private static function loadAutomationRule(\PDO $db, string $eventKey, int $propertyId): ?array {
        $stmt = $db->prepare("
            SELECT a.*, t.name as wa_template_name, t.language as wa_template_language
            FROM automation_rules a
            LEFT JOIN wa_templates t ON a.wa_template_id = t.id
            WHERE a.event_key = ? AND a.property_id = ? AND a.deleted_at IS NULL
        ");
        $stmt->execute([$eventKey, $propertyId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Trigger a WhatsApp automation template based on a system event.
     */
    public static function triggerAutomation(string $eventKey, ?string $phoneNumber, ?int $bookingId = null, array $customDataArray = [], ?int $propertyId = null): bool {
        require_once __DIR__ . '/Database.php';

        $db = Database::getInstance()->getConnection();

        if ($propertyId === null && $bookingId !== null) {
            $pidStmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ?");
            $pidStmt->execute([$bookingId]);
            $fromBooking = (int)($pidStmt->fetchColumn() ?: 0);
            if ($fromBooking > 0) {
                $propertyId = $fromBooking;
            }
        }
        if ($propertyId === null) {
            require_once __DIR__ . '/AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }

        $auto = self::loadAutomationRule($db, $eventKey, (int)$propertyId);
        
        // Build Global Context
        $context = [
            'hotel_name' => defined('PROPERTY_NAME') ? PROPERTY_NAME : 'Our Hotel'
        ];
        
        $guestEmail = '';
        if ($bookingId !== null) {
            $bStmt = $db->prepare("
                SELECT b.id as db_booking_id, IFNULL(b.display_id, b.id) as booking_id, b.created_at,
                       DATE_FORMAT(b.check_in, '%d %b %Y %h:%i %p') as check_in_date, 
                       DATE_FORMAT(b.check_out, '%d %b %Y %h:%i %p') as check_out_date,
                       b.total_amount, b.booking_status, b.rate_plan_name,
                       g.name as guest_name, g.phone as guest_phone, g.email as guest_email,
                       r.room_number, c.name as room_type
                FROM bookings b
                LEFT JOIN guests g ON b.guest_id = g.id
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN room_categories c ON r.category_id = c.id
                WHERE b.id = ?
            ");
            $bStmt->execute([$bookingId]);
            $bData = $bStmt->fetch(\PDO::FETCH_ASSOC);
            if ($bData) {
                // Calculate paid & balance
                $payStmt = $db->prepare("SELECT IFNULL(SUM(amount), 0) FROM folio_ledger WHERE booking_id = ? AND amount < 0");
                $payStmt->execute([$bookingId]);
                $paidAmt = abs((float)$payStmt->fetchColumn());
                
                $bData['paid_amount'] = number_format($paidAmt, 2);
                $bData['balance_amount'] = number_format((float)$bData['total_amount'] - $paidAmt, 2);
                $bData['total_amount'] = number_format((float)$bData['total_amount'], 2);
                
                // Generate Invoice Link (secure HMAC-SHA256, 24hr expiry)
                require_once __DIR__ . '/InvoiceLink.php';
                require_once __DIR__ . '/GuestAccessToken.php';
                $bData['invoice_link'] = InvoiceLink::getUrl((int)$bData['db_booking_id']);
                try {
                    $bData['review_link'] = GuestAccessToken::getPortalUrl((int)$bData['db_booking_id']);
                } catch (\Throwable $e) {
                    $bData['review_link'] = $bData['invoice_link'];
                }
                if (empty($bData['checkout_time']) && !empty($bData['check_out_date'])) {
                    $bData['checkout_time'] = (string)$bData['check_out_date'];
                }
                $guestName = trim((string)($bData['guest_name'] ?? ''));
                $bData['first_name'] = $guestName !== '' ? explode(' ', $guestName)[0] : 'there';
                if (!isset($bData['amount_due'])) {
                    $bData['amount_due'] = $bData['balance_amount'];
                }
                if (empty($bData['payment_link'])) {
                    $bData['payment_link'] = $bData['invoice_link'];
                }

                // Merge DB context
                foreach($bData as $k => $v) { $context[$k] = $v; }
                // Set phone and email if not provided
                if (empty($phoneNumber)) {
                    $phoneNumber = (string)$bData['guest_phone'];
                }
                $guestEmail = (string)$bData['guest_email'];
            }
        }
        
        // Merge any custom overrides
        foreach($customDataArray as $k => $v) { $context[$k] = $v; }

        if (empty($context['first_name'])) {
            $gn = trim((string)($context['guest_name'] ?? ''));
            $context['first_name'] = $gn !== '' ? explode(' ', $gn)[0] : 'there';
        }
        
        // Canonicalise the phone number for WhatsApp
        $phoneNumberE164 = null;
        if (!empty($phoneNumber)) {
            $phoneNumberE164 = PhoneHelper::toE164($phoneNumber) ?? preg_replace('/[^0-9]/', '', $phoneNumber);
        }

        require_once __DIR__ . '/services/QueueService.php';
        $staffId = $_SESSION['user_id'] ?? null;
        $anyTriggered = false;

        $waReady = $auto && !empty($auto['is_wa_active']) && !empty($auto['wa_template_id']);
        if (!$waReady) {
            $reason = !$auto
                ? 'No automation rule configured for this event'
                : (empty($auto['is_wa_active']) ? 'WhatsApp automation is not active' : 'No WhatsApp template selected');
            self::logDelivery(
                (int)$propertyId,
                $eventKey,
                (string)($auto['wa_template_name'] ?? '(none)'),
                (string)($phoneNumberE164 ?? $phoneNumber ?? ''),
                'skipped',
                'inactive',
                $reason
            );
            if (!$auto) {
                return false;
            }
        } elseif (empty($phoneNumberE164)) {
            self::logDelivery((int)$propertyId, $eventKey, (string)($auto['wa_template_name'] ?? '(none)'), '', 'skipped', 'no_phone', 'Guest phone number is missing');
        }

        // 1. WhatsApp — always queue (never sync HTTP while a DB transaction may be open)
        if ($waReady && !empty($phoneNumberE164)) {
            $mapping = json_decode((string)$auto['wa_mapping_json'], true) ?? [];
            $params = [];
            foreach ($mapping as $mappedVarName) {
                $val = $context[$mappedVarName] ?? $mappedVarName;
                $params[] = ['type' => 'text', 'text' => (string)$val];
            }
            
            $payload = [
                'name' => (string)$auto['wa_template_name'],
                'language' => ['code' => (string)($auto['wa_template_language'] ?: 'en')]
            ];
            
            if (!empty($params)) {
                $payload['components'] = [['type' => 'body', 'parameters' => $params]];
            }

            try {
                QueueService::push('whatsapp', [
                    'phoneNumber' => $phoneNumberE164,
                    'payload' => $payload,
                    'isTemplate' => true,
                    'eventKey' => $eventKey,
                    'templateName' => $auto['wa_template_name'],
                    'bookingId' => $bookingId,
                    'staffId' => $staffId,
                    'property_id' => $propertyId,
                ], 0, $propertyId);
                $anyTriggered = true;
            } catch (\Throwable $e) {
                error_log('WhatsApp automation queue failed: ' . $e->getMessage());
            }
        }

        require_once __DIR__ . '/AutomationTemplates.php';
        $defaults = AutomationTemplates::forEvent($eventKey);

        // 2. Email Automation
        $emailSubject = trim((string)($auto['email_subject'] ?? ''));
        $emailBody = trim((string)($auto['email_body_html'] ?? ''));
        if ($emailSubject === '') {
            $emailSubject = $defaults['email_subject'];
        }
        if ($emailBody === '') {
            $emailBody = $defaults['email_body_html'];
        }
        if (!empty($auto['is_email_active']) && !empty($guestEmail) && $emailBody !== '') {
            $subject = self::formatTemplate($emailSubject, $context);
            $body = self::formatTemplate($emailBody, $context);
            
            $db->prepare("INSERT INTO jobs_queue (queue_name, property_id, payload_json) VALUES ('email', ?, ?)")
               ->execute([$propertyId, json_encode([
                   'to' => $guestEmail,
                   'subject' => $subject,
                   'body' => $body
               ])]);
            $anyTriggered = true;
        }

        // 3. Telegram Automation
        $telegramBody = trim((string)($auto['telegram_body_text'] ?? ''));
        if ($telegramBody === '') {
            $telegramBody = $defaults['telegram_body_text'];
        }
        if (!empty($auto['is_telegram_active']) && $telegramBody !== '') {
            $body = self::formatTemplate($telegramBody, $context);
            // Queued / after-commit — never sync HTTP inside request transactions
            self::sendTelegram($body, null, [], (int)$propertyId);
            $anyTriggered = true;
        }

        return $anyTriggered;
    }

    private static function logDelivery(
        int $propertyId,
        string $eventKey,
        string $templateName,
        string $phoneNumber,
        string $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $messageId = null
    ): void {
        if ($propertyId <= 0) {
            return;
        }
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("INSERT INTO wa_delivery_logs (property_id, event_key, template_name, phone_number, message_id, status, error_code, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $propertyId,
                $eventKey,
                $templateName !== '' ? $templateName : '(none)',
                $phoneNumber !== '' ? $phoneNumber : '-',
                $messageId,
                $status,
                $errorCode,
                $errorMessage,
            ]);
        } catch (\Throwable $e) {
            error_log('Failed to write wa_delivery_logs: ' . $e->getMessage());
        }
    }

    /**
     * Process a WhatsApp job from the queue.
     */
    public static function processWhatsAppJob(array $jobData, ?int $propertyId = null): bool {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();

        $phoneNumberE164 = (string)($jobData['phoneNumber'] ?? $jobData['phone'] ?? '');
        $payload = $jobData['payload'] ?? $jobData['message'] ?? '';
        $isTemplate = (bool)($jobData['is_hsm'] ?? $jobData['isTemplate'] ?? is_array($payload));
        $eventKey = $jobData['eventKey'] ?? 'manual';
        $templateName = $jobData['templateName'] ?? (is_array($payload) ? (string)($payload['name'] ?? 'session') : 'session');
        $bookingId = $jobData['bookingId'] ?? null;
        $staffId = (int)($jobData['staffId'] ?? 0);
        $jobPropertyId = $propertyId ?? (isset($jobData['property_id']) ? (int)$jobData['property_id'] : null);

        if ($phoneNumberE164 === '') {
            throw new \Exception('WhatsApp job missing phone number');
        }

        $waRes = self::sendWhatsAppSync($phoneNumberE164, $payload, $isTemplate, $jobPropertyId);

        $status = $waRes['ok'] ? 'success' : 'failed';
        $errorCode = $waRes['ok'] ? null : ($waRes['error_code'] ?? null);
        $errorMessage = $waRes['ok'] ? null : ($waRes['error_message'] ?? null);
        $messageId = $waRes['ok'] ? ($waRes['messageId'] ?? null) : null;

        $pid = (int)($jobPropertyId ?? 0);
        if ($pid <= 0) {
            $pidStmt = $db->query("SELECT id FROM properties WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            $pid = (int)($pidStmt->fetchColumn() ?: 0);
        }
        if ($pid <= 0) {
            throw new \Exception('WhatsApp job missing property_id');
        }

        self::logDelivery($pid, $eventKey, $templateName, $phoneNumberE164, $status, $errorCode, $errorMessage, $messageId);
        
        require_once __DIR__ . '/AuditLogger.php';
        \AuditLogger::log($staffId, 'WA_MESSAGE_' . strtoupper($status), 'BOOKING', $bookingId, [
            'event' => $eventKey,
            'template' => $templateName,
            'phone' => $phoneNumberE164
        ]);
        
        if (!$waRes['ok']) {
            throw new \Exception($errorMessage ?? 'WhatsApp delivery failed');
        }
        
        return true;
    }
    /**
     * Send In-App Notification to Admin Dashboard (DB only on request path).
     * Web Push is queued and drained after commit so it never holds locks.
     */
    public static function sendInAppNotification(int $propertyId, string $title, string $message, string $type = 'info', string $linkUrl = ''): bool {
        require_once __DIR__ . '/Database.php';
        require_once __DIR__ . '/DeferredSideEffects.php';
        require_once __DIR__ . '/services/QueueService.php';
        $db = Database::getInstance()->getConnection();
        $ok = false;

        try {
            $stmt = $db->prepare("INSERT INTO admin_notifications (property_id, title, message, type, link_url) VALUES (?, ?, ?, ?, ?)");
            $ok = $stmt->execute([$propertyId, $title, $message, $type, $linkUrl]);
        } catch (\Exception $e) {
            try {
                $stmt = $db->prepare("INSERT INTO admin_notifications (property_id, title, message, type) VALUES (?, ?, ?, ?)");
                $ok = $stmt->execute([$propertyId, $title, $message, $type]);
            } catch (\Exception $e2) {
                error_log("Failed to insert admin notification: " . $e2->getMessage());
                return false;
            }
        }

        if ($ok) {
            $pushUrl = $linkUrl !== '' ? $linkUrl : '/admin';
            DeferredSideEffects::afterCommit(static function () use ($propertyId, $title, $message, $pushUrl): void {
                try {
                    QueueService::push('web_push', [
                        'title' => $title,
                        'message' => $message,
                        'url' => $pushUrl,
                        'property_id' => $propertyId,
                    ], 0, $propertyId);
                } catch (\Throwable $t) {
                    error_log('Failed to queue web_push job: ' . $t->getMessage());
                }
            });
        }
        return $ok;
    }
}
