<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    // Fix #2/#13: owner check inside callback so Content-Type header is set first
    AuthHelper::requirePermission('manage_settings');

    $data = ApiHandler::getJsonInput();

    if (!is_array($data) || empty($data)) {
        throw new \Exception('No data provided');
    }

    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("INSERT INTO system_settings (property_id, key_name, key_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    
    $secretKeys = [
        'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET', 'WHATSAPP_TOKEN',
        'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET', 'TELEGRAM_OPERATIONS_BOT_TOKEN',
        'SMTP_PASS', 'WA_APP_SECRET', 'INVOICE_SECRET', 'GOOGLE_VISION_API_KEY'
    ];
    foreach ($data as $key => $value) {
        if ($key === '_csrf_token') continue;
        if (in_array($key, $secretKeys, true) && trim((string)$value) === '') {
            continue;
        }

        if ($key === 'PROPERTY_LOGO_BASE64') {
            $value = normalize_property_logo_base64((string)$value);
        }
        
        // Intercept Email Report config
        if (in_array($key, ['EMAIL_REPORTS_ACTIVE', 'DAILY_AUDIT_EMAILS', 'WEEKLY_REVENUE_EMAILS'])) {
            try {
                $check = $db->prepare("SELECT property_id FROM email_report_config WHERE property_id = ?");
                $check->execute([$propertyId]);
                if (!$check->fetch()) {
                    $db->prepare("INSERT INTO email_report_config (property_id) VALUES (?)")->execute([$propertyId]);
                }
                if ($key === 'EMAIL_REPORTS_ACTIVE') {
                    $db->prepare("UPDATE email_report_config SET is_active = ? WHERE property_id = ?")->execute([$value, $propertyId]);
                } elseif ($key === 'DAILY_AUDIT_EMAILS') {
                    $db->prepare("UPDATE email_report_config SET daily_audit_emails = ? WHERE property_id = ?")->execute([$value, $propertyId]);
                } elseif ($key === 'WEEKLY_REVENUE_EMAILS') {
                    $db->prepare("UPDATE email_report_config SET weekly_revenue_emails = ? WHERE property_id = ?")->execute([$value, $propertyId]);
                }
            } catch (\PDOException $e) {
                // Ignore if table does not exist
            }
            continue;
        }

        $stmt->execute([$propertyId, $key, $value]);

        // Keep properties.whatsapp_phone_number_id in sync for webhook tenant routing.
        if ($key === 'WHATSAPP_PHONE_NUMBER_ID') {
            $waId = trim((string)$value);
            if ($waId === '' || strcasecmp($waId, 'your_phone_number_id') === 0) {
                $waId = null;
            }
            $db->prepare('UPDATE properties SET whatsapp_phone_number_id = ? WHERE id = ?')
               ->execute([$waId, $propertyId]);
        }
    }

    $rzKey = trim((string)($data['RAZORPAY_KEY_ID'] ?? ''));
    $rzSecret = trim((string)($data['RAZORPAY_KEY_SECRET'] ?? ''));
    if ($rzKey !== '') {
        upsert_payment_gateway_config($db, $propertyId, 'razorpay', $rzKey, $rzSecret, 1, 'live', null);
    }

    AuditLogger::log($_SESSION['user_id'], 'UPDATE_SETTINGS', 'SYSTEM', null, ['updated_keys' => array_keys($data)]);
    
    ApiResponse::success();

}, true, true, false);
