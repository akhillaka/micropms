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
        AuthHelper::requirePermission('run_night_audit');
        
        $username = $_SESSION['username'] ?? 'admin';
        
        // Push job to queue for async processing to ensure scalability
        $payload = json_encode([
            'job_type' => 'night_audit',
            'run_by' => $username,
            'property_id' => AuthHelper::getPropertyId() // ensure multi-tenancy context
        ]);
        
        $stmt = $db->prepare("INSERT INTO jobs_queue (queue_name, payload_json) VALUES ('night_audit', ?)");
        $stmt->execute([$payload]);
        
        ApiResponse::success([
            'message' => 'Night audit has been queued and will be processed shortly.',
            'job_id' => $db->lastInsertId()
        ]);
    }

    // Action: Get audit history
    elseif ($action === 'history') {
        AuthHelper::requireLogin();
        
        $limit = isset($data['limit']) ? (int)$data['limit'] : 30;
        $history = NightAudit::getHistory($db, $limit);
        ApiResponse::success(['history' => $history]);
    }

    // Action: Get audit exceptions
    elseif ($action === 'exceptions') {
        AuthHelper::requireLogin();
        $propertyId = AuthHelper::getPropertyId();
        
        // Find overdue checkouts
        $stmt = $db->prepare("
            SELECT b.id, b.room_id, r.room_number, b.guest_name, b.check_in, b.check_out, b.booking_status
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            WHERE b.property_id = ? AND b.booking_status = 'checked_in' AND b.check_out < NOW()
        ");
        $stmt->execute([$propertyId]);
        $exceptions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        ApiResponse::success(['exceptions' => $exceptions]);
    }

    // Action: Bulk resolve exceptions (Auto-checkout)
    elseif ($action === 'bulk_resolve') {
        AuthHelper::requirePermission('run_night_audit');
        $bookingIds = $data['booking_ids'] ?? [];
        if (empty($bookingIds) || !is_array($bookingIds)) {
            ApiResponse::error('No bookings selected');
        }
        
        $propertyId = AuthHelper::getPropertyId();
        $count = 0;
        foreach ($bookingIds as $bid) {
            // Verify booking belongs to property and is checked in
            $stmt = $db->prepare("SELECT room_id FROM bookings WHERE id = ? AND property_id = ? AND booking_status = 'checked_in'");
            $stmt->execute([$bid, $propertyId]);
            $roomId = $stmt->fetchColumn();
            
            if ($roomId) {
                // Check out
                $db->prepare("UPDATE bookings SET booking_status = 'checked_out' WHERE id = ?")->execute([$bid]);
                // Mark room dirty
                $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ?")->execute([$roomId]);
                $count++;
            }
        }
        
        ApiResponse::success(['message' => "$count exceptions resolved successfully."]);
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
            'night_audit_notify_telegram', 'night_audit_notify_whatsapp', 'night_audit_notify_email',
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
