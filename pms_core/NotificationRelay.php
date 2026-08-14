<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/PhoneHelper.php';

class NotificationRelay {

    /**
     * Check if a specific notification event is enabled.
     */
    public static function isEnabled(string $eventKey): bool {
        if (!defined('NOTIFY_EVENTS')) {
            return false;
        }
        $events = json_decode(NOTIFY_EVENTS, true) ?? [];
        return isset($events[$eventKey]) && $events[$eventKey] === true;
    }

    /**
     * Replace placeholders with context data.
     */
    public static function formatTemplate(string $template, array $context): string {
        foreach (['guest_name', 'action_url', 'first_name', 'hotel_name', 'room_number'] as $fallbackKey) {
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
     * Send Telegram message by queueing it to jobs_queue.
     */
    public static function sendTelegram(string $fallbackMessage, ?string $eventKey = null, array $context = [], ?int $propertyId = null): bool {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();

        if ($propertyId === null) {
            require_once __DIR__ . '/AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }

        if (!defined('TELEGRAM_BOT_TOKEN') || empty(TELEGRAM_BOT_TOKEN) || TELEGRAM_BOT_TOKEN === 'your_telegram_bot_token') {
            return false;
        }
        if ($eventKey !== null && !self::isEnabled($eventKey)) {
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
        $payload = json_encode(['message' => $formatted]);

        try {
            $stmt = $db->prepare("INSERT INTO jobs_queue (queue_name, property_id, payload_json) VALUES ('telegram', ?, ?)");
            return $stmt->execute([$propertyId, $payload]);
        } catch (\Exception $e) {
            error_log("Failed to queue telegram job: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send Telegram message synchronously.
     */
    public static function sendTelegramSync(string $fallbackMessage, ?string $eventKey = null, array $context = [], ?int $propertyId = null): bool {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();

        if ($propertyId === null) {
            require_once __DIR__ . '/AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }

        $stmt = $db->prepare("SELECT key_name, key_value FROM system_settings WHERE property_id = ? AND key_name IN ('TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID')");
        $stmt->execute([$propertyId]);
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['key_name']] = $row['key_value'];
        }

        $token = $settings['TELEGRAM_BOT_TOKEN'] ?? (defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : null);
        $chatIds = $settings['TELEGRAM_CHAT_ID'] ?? (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '');

        if (empty($token) || $token === 'your_telegram_bot_token') {
            return false;
        }
        if ($eventKey !== null && !self::isEnabled($eventKey)) {
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

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

        $idList = array_filter(array_map('trim', explode(',', $chatIds)));
        
        if (empty($idList)) {
            return false;
        }

        $allSuccess = true;
        foreach ($idList as $id) {
            $data = [
                'chat_id'    => $id,
                'text'       => $formatted,
                'parse_mode' => 'HTML'
            ];
            $res = self::makePostRequest($url, $data);
            if ($res === false) {
                $allSuccess = false;
            }
        }
        return $allSuccess;
    }

    /**
     * Send a test message to verify Telegram integration.
     */
    public static function sendTestTelegram(): array {
        // Ensure Database settings are loaded dynamically
        require_once __DIR__ . '/Database.php';
        Database::getInstance();

        if (!defined('TELEGRAM_BOT_TOKEN') || empty(TELEGRAM_BOT_TOKEN) || TELEGRAM_BOT_TOKEN === 'your_telegram_bot_token') {
            return ['ok' => false, 'error' => 'Bot token not configured'];
        }

        $msg = "✅ <b>MicroPMS Telegram Test</b>\n\n"
             . "Bot is connected and working!\n"
             . "Time: " . date('d M Y, h:i A') . "\n"
             . "Server: " . gethostname();

        $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
        
        $chatIds = defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '';
        $idList = array_filter(array_map('trim', explode(',', $chatIds)));
        
        if (empty($idList)) {
            return ['ok' => false, 'error' => 'Chat ID not configured'];
        }
        
        $results = [];
        foreach ($idList as $id) {
            $data = [
                'chat_id'    => $id,
                'text'       => $msg,
                'parse_mode' => 'HTML'
            ];

            try {
                $ch = curl_init($url);
                if ($ch === false) {
                    throw new \RuntimeException('Failed to initialize cURL');
                }
                
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                
                $response = curl_exec($ch);

                if (curl_errno($ch)) {
                    throw new \RuntimeException("cURL Error: " . curl_error($ch));
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch); // FIX: Close handle
                $body = is_string($response) ? json_decode($response, true) : [];

                $success = match (true) {
                    isset($body['ok']) && $body['ok'] === true => true,
                    $httpCode >= 200 && $httpCode < 300 => true,
                    default => false
                };
                
                if (!$success) {
                    return ['ok' => false, 'error' => $body['description'] ?? "HTTP $httpCode on chat ID $id"];
                }
            } catch (\Exception $e) {
                if (isset($ch) && $ch !== false && (is_resource($ch) || $ch instanceof \CurlHandle)) {
                    curl_close($ch);
                }
                return ['ok' => false, 'error' => $e->getMessage() . " on chat ID $id"];
            }
        }
        return ['ok' => true];
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                $errStr = curl_error($ch);
                curl_close($ch);
                error_log("PMS cURL Connection Error: " . $errStr);
                return ['ok' => false, 'error_message' => 'cURL Error: ' . $errStr];
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch); // FIX: Close handle after reading response

            $decoded = json_decode((string)$response, true);
            $isDecodedArray = is_array($decoded);

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
            
        } catch (\Exception $e) {
            if (isset($ch) && $ch !== false && (is_resource($ch) || $ch instanceof \CurlHandle)) {
                curl_close($ch);
            }
            error_log("PMS Exception in API request: " . $e->getMessage());
            return ['ok' => false, 'error_message' => $e->getMessage()];
        }
    }

    /**
     * WhatsApp rules may live in automation_rules (Automations page) or wa_automations
     * (WhatsApp Automations module). Merge so enabling either UI actually sends.
     */
    private static function loadAutomationRule(\PDO $db, string $eventKey, int $propertyId): ?array {
        $stmt = $db->prepare("
            SELECT a.*, t.name as wa_template_name, t.language as wa_template_language
            FROM automation_rules a
            LEFT JOIN wa_templates t ON a.wa_template_id = t.id
            WHERE a.event_key = ? AND a.property_id = ?
        ");
        $stmt->execute([$eventKey, $propertyId]);
        $auto = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $waActive = $auto && !empty($auto['is_wa_active']) && !empty($auto['wa_template_id']);
        if ($waActive) {
            return $auto;
        }

        $waStmt = $db->prepare("
            SELECT a.property_id, a.event_key, a.template_id, a.variable_mapping_json, a.status,
                   t.name as wa_template_name, t.language as wa_template_language
            FROM wa_automations a
            LEFT JOIN wa_templates t ON a.template_id = t.id
            WHERE a.event_key = ? AND a.property_id = ? AND a.status = 'active'
        ");
        $waStmt->execute([$eventKey, $propertyId]);
        $wa = $waStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$wa) {
            return $auto;
        }

        if (!$auto) {
            $auto = [
                'is_email_active' => 0,
                'is_telegram_active' => 0,
                'email_subject' => '',
                'email_body_html' => '',
                'telegram_body_text' => '',
            ];
        }
        $auto['is_wa_active'] = 1;
        $auto['wa_template_id'] = $wa['template_id'];
        $auto['wa_mapping_json'] = $wa['variable_mapping_json'];
        $auto['wa_template_name'] = $wa['wa_template_name'];
        $auto['wa_template_language'] = $wa['wa_template_language'];
        return $auto;
    }

    /**
     * Trigger a WhatsApp automation template based on a system event.
     */
    public static function triggerAutomation(string $eventKey, ?string $phoneNumber, ?int $bookingId = null, array $customDataArray = [], ?int $propertyId = null): bool {
        require_once __DIR__ . '/Database.php';
        require_once __DIR__ . '/config.php';
        
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
                $bData['invoice_link'] = InvoiceLink::getUrl((int)$bData['db_booking_id']);
                
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

        // 1. WhatsApp Automation — send now so it does not depend on cron_worker
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
                self::processWhatsAppJob([
                    'phoneNumber' => $phoneNumberE164,
                    'payload' => $payload,
                    'isTemplate' => true,
                    'eventKey' => $eventKey,
                    'templateName' => $auto['wa_template_name'],
                    'bookingId' => $bookingId,
                    'staffId' => $staffId,
                    'property_id' => $propertyId,
                ], (int)$propertyId);
                $anyTriggered = true;
            } catch (\Throwable $e) {
                error_log('WhatsApp automation send failed: ' . $e->getMessage());
                QueueService::push('whatsapp', [
                    'phoneNumber' => $phoneNumberE164,
                    'payload' => $payload,
                    'isTemplate' => true,
                    'eventKey' => $eventKey,
                    'templateName' => $auto['wa_template_name'],
                    'bookingId' => $bookingId,
                    'staffId' => $staffId,
                ], 0, $propertyId);
            }
        }

        // 2. Email Automation
        if (!empty($auto['is_email_active']) && !empty($guestEmail) && !empty($auto['email_body_html'])) {
            $subject = self::formatTemplate((string)$auto['email_subject'], $context);
            $body = self::formatTemplate((string)$auto['email_body_html'], $context);
            
            $db->prepare("INSERT INTO jobs_queue (queue_name, property_id, payload_json) VALUES ('email', ?, ?)")
               ->execute([$propertyId, json_encode([
                   'to' => $guestEmail,
                   'subject' => $subject,
                   'body' => $body
               ])]);
            $anyTriggered = true;
        }

        // 3. Telegram Automation
        if (!empty($auto['is_telegram_active']) && !empty($auto['telegram_body_text'])) {
            $body = self::formatTemplate((string)$auto['telegram_body_text'], $context);
            
            $db->prepare("INSERT INTO jobs_queue (queue_name, property_id, payload_json) VALUES ('telegram', ?, ?)")
               ->execute([$propertyId, json_encode([
                   'message' => $body
               ])]);
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
     * Send In-App Notification to Admin Dashboard
     */
    public static function sendInAppNotification(int $propertyId, string $title, string $message, string $type = 'info'): bool {
        require_once __DIR__ . '/Database.php';
        $db = Database::getInstance()->getConnection();
        
        try {
            $stmt = $db->prepare("INSERT INTO admin_notifications (property_id, title, message, type) VALUES (?, ?, ?, ?)");
            return $stmt->execute([$propertyId, $title, $message, $type]);
        } catch (\Exception $e) {
            error_log("Failed to insert admin notification: " . $e->getMessage());
            return false;
        }
    }
}
