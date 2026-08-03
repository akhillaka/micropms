<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    // Fix #2/#13: owner check inside callback so Content-Type header is set first
    AuthHelper::requirePermission('manage_settings');

    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data) || empty($data)) {
        throw new \Exception('No data provided');
    }

    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("INSERT INTO system_settings (property_id, key_name, key_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    
    foreach ($data as $key => $value) {
        if ($key === '_csrf_token') continue;
        
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
    }

    AuditLogger::log($_SESSION['user_id'], 'UPDATE_SETTINGS', 'SYSTEM', null, ['updated_keys' => array_keys($data)]);
    
    ApiResponse::success();

}, false, true, false);
