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
        if (!isset($context['hotel_name'])) {
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
     * Send Telegram message. Respects event preferences if $eventKey is provided.
     */
    public static function sendTelegram(string $fallbackMessage, ?string $eventKey = null, array $context = []): bool {
        // Ensure Database settings are loaded dynamically
        require_once __DIR__ . '/Database.php';
        Database::getInstance();

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

        $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';

        $chatIds = defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '';
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
                return ['ok' => false, 'error' => $e->getMessage() . " on chat ID $id"];
            }
        }
        return ['ok' => true];
    }

    /**
     * Sends a WhatsApp message (template or free text) and returns a structured array with ok status and message/error.
     */
    public static function sendWhatsApp(string $phoneNumber, array|string $payloadData, bool $isTemplate = true): array {
        // Ensure Database settings are loaded dynamically
        require_once __DIR__ . '/Database.php';
        Database::getInstance();

        if (!defined('WHATSAPP_TOKEN') || empty(WHATSAPP_TOKEN) || WHATSAPP_TOKEN === 'your_whatsapp_token_here') {
            return ['ok' => false, 'error_message' => 'WhatsApp token is not configured'];
        }

        $baseUrl = defined('WHATSAPP_WABA_ID') && str_starts_with(WHATSAPP_WABA_ID, 'http') 
                   ? rtrim(WHATSAPP_WABA_ID, '/')
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
                'channelId' => defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '',
                'to' => $cleanPhone,
                'templateName' => $templateName,
                'languageCode' => $languageCode,
                'variables' => $vars
            ];
        } else {
            $url = $baseUrl . '/whatsapp/message/send';
            $data = [
                'channelId' => defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '',
                'to' => $cleanPhone,
                'type' => 'text',
                'body' => is_array($payloadData) ? ($payloadData['text']['body'] ?? '') : $payloadData
            ];
        }

        $res = self::makePostRequest($url, $data, WHATSAPP_TOKEN);
        if ($res && isset($res['ok']) && $res['ok'] === true) {
            $msgId = "msg_" . uniqid();
            if (isset($res['data']['messageId'])) $msgId = (string)$res['data']['messageId'];
            elseif (isset($res['data']['id'])) $msgId = (string)$res['data']['id'];
            elseif (isset($res['data']['messages'][0]['id'])) $msgId = (string)$res['data']['messages'][0]['id'];
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
                error_log("PMS cURL Connection Error: " . $errStr);
                return ['ok' => false, 'error_message' => 'cURL Error: ' . $errStr];
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $decoded = json_decode((string)$response, true);
            $isDecodedArray = is_array($decoded);

            if ($httpCode >= 200 && $httpCode < 300) {
                return ['ok' => true, 'data' => $isDecodedArray ? $decoded : []];
            } else {
                // Log detailed error from the remote API
                $errorMsg = "HTTP {$httpCode} failed response";
                $errorCode = (string)$httpCode;
                if ($isDecodedArray) {
                    if (isset($decoded['error']['message'])) {
                        $errorCode = $decoded['error']['code'] ?? $httpCode;
                        $errorMsg = $decoded['error']['message'];
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
            error_log("PMS Exception in API request: " . $e->getMessage());
            return ['ok' => false, 'error_message' => $e->getMessage()];
        }
    }

    /**
     * Trigger a WhatsApp automation template based on a system event.
     */
    public static function triggerAutomation(string $eventKey, ?string $phoneNumber, ?int $bookingId = null, array $customDataArray = []): bool {
        require_once __DIR__ . '/Database.php';
        require_once __DIR__ . '/config.php';
        
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT a.variable_mapping_json, t.name, t.language, t.components_json 
            FROM wa_automations a 
            JOIN wa_templates t ON a.template_id = t.id 
            WHERE a.event_key = ? AND a.status = 'active'
        ");
        $stmt->execute([$eventKey]);
        $auto = $stmt->fetch();
        
        if (!$auto) {
            return false;
        }
        
        // Build Global Context
        $context = [
            'hotel_name' => defined('PROPERTY_NAME') ? PROPERTY_NAME : 'Our Hotel'
        ];
        
        if ($bookingId !== null) {
            $bStmt = $db->prepare("
                SELECT b.id as db_booking_id, IFNULL(b.display_id, b.id) as booking_id, b.created_at,
                       DATE_FORMAT(b.check_in, '%d %b %Y %h:%i %p') as check_in_date, 
                       DATE_FORMAT(b.check_out, '%d %b %Y %h:%i %p') as check_out_date,
                       b.total_amount, b.booking_status, b.rate_plan_name,
                       g.name as guest_name, g.phone as guest_phone,
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
                // Set phone number if not provided
                if (empty($phoneNumber)) {
                    $phoneNumber = (string)$bData['guest_phone'];
                }
            }
        }
        
        // Merge any custom overrides
        foreach($customDataArray as $k => $v) { $context[$k] = $v; }
        
        if (empty($phoneNumber)) {
            return false;
        }

        // Canonicalise the phone number for WhatsApp
        $phoneNumberE164 = PhoneHelper::toE164($phoneNumber);
        if ($phoneNumberE164 === null) {
            $phoneNumberE164 = preg_replace('/[^0-9]/', '', $phoneNumber);
        }
        if (empty($phoneNumberE164)) {
            return false;
        }

        $mapping = json_decode((string)$auto['variable_mapping_json'], true) ?? [];
        
        $params = [];
        foreach ($mapping as $varIndex => $mappedVarName) {
            $val = $context[$mappedVarName] ?? $mappedVarName;
            $params[] = ['type' => 'text', 'text' => (string)$val];
        }
        
        $payload = [
            'name' => (string)$auto['name'],
            'language' => ['code' => (string)$auto['language']]
        ];
        
        if (!empty($params)) {
            $payload['components'] = [['type' => 'body', 'parameters' => $params]];
        }
        
        $waRes = self::sendWhatsApp($phoneNumberE164, $payload, true);
        
        $status = $waRes['ok'] ? 'success' : 'failed';
        $errorCode = $waRes['ok'] ? null : ($waRes['error_code'] ?? null);
        $errorMessage = $waRes['ok'] ? null : ($waRes['error_message'] ?? null);
        $templateName = $auto['name'];
        $messageId = $waRes['ok'] ? ($waRes['messageId'] ?? null) : null;
        
        $insLog = $db->prepare("INSERT INTO wa_delivery_logs (event_key, template_name, phone_number, message_id, status, error_code, error_message) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insLog->execute([$eventKey, $templateName, $phoneNumberE164, $messageId, $status, $errorCode, $errorMessage]);
        
        // Also log to the central system Audit Logs
        require_once __DIR__ . '/AuditLogger.php';
        $staffId = $_SESSION['user_id'] ?? null;
        \AuditLogger::log($staffId, 'WA_MESSAGE_' . strtoupper($status), 'BOOKING', $bookingId, [
            'event' => $eventKey,
            'template' => $templateName,
            'phone' => $phoneNumberE164
        ]);
        
        return $waRes['ok'];
    }
}
