<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/NightAudit.php';

ApiHandler::run(function(\PDO $db) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    // Action: Run night audit manually
    if ($action === 'run') {
        AuthHelper::requirePermission('manage_settings');
        
        $username = $_SESSION['username'] ?? 'admin';
        $audit = new NightAudit($db);
        $result = $audit->run($username);
        
        ApiResponse::success(['result' => $result]);
    }

    // Action: Get audit history
    elseif ($action === 'history') {
        AuthHelper::requireLogin();
        
        $limit = isset($data['limit']) ? (int)$data['limit'] : 30;
        $history = NightAudit::getHistory($db, $limit);
        ApiResponse::success(['history' => $history]);
    }

    // Action: Get last audit
    elseif ($action === 'last') {
        AuthHelper::requireLogin();
        
        $last = NightAudit::getLastAudit($db);
        ApiResponse::success(['last_audit' => $last]);
    }

    // Action: Get audit settings
    elseif ($action === 'settings') {
        AuthHelper::requireLogin();
        
        $settings = [];
        $keys = [
            'night_audit_enabled', 'night_audit_time', 'night_audit_auto_checkout',
            'night_audit_auto_checkout_hours', 'night_audit_mark_dirty',
            'night_audit_notify_telegram', 'night_audit_notify_whatsapp',
            'night_audit_report_revenue', 'night_audit_report_occupancy',
            'night_audit_report_room_status', 'night_audit_report_bookings'
        ];
        
        $stmt = $db->prepare("SELECT key_name, key_value FROM system_settings WHERE key_name IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
        $stmt->execute($keys);
        while ($row = $stmt->fetch()) {
            $settings[$row['key_name']] = $row['key_value'];
        }
        
        ApiResponse::success(['settings' => $settings]);
    }

    // Action: Save audit settings
    elseif ($action === 'save_settings') {
        AuthHelper::requirePermission('manage_settings');
        
        $settings = $data['settings'] ?? [];
        if (empty($settings)) {
            ApiResponse::error('No settings provided');
        }
        
        $stmt = $db->prepare("
            INSERT INTO system_settings (key_name, key_value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = NOW()
        ");
        
        foreach ($settings as $key => $value) {
            if (str_starts_with($key, 'night_audit_')) {
                $stmt->execute([$key, $value]);
            }
        }
        
        ApiResponse::success(['message' => 'Night audit settings saved']);
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, true, true, false);
