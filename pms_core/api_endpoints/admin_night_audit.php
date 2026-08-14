<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/NightAudit.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';

ApiHandler::run(function(\PDO $db) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    // Action: Run night audit manually (synchronous — jobs worker may be disabled)
    if ($action === 'run') {
        AuthHelper::requirePermission('run_night_audit');

        $username = $_SESSION['username'] ?? 'admin';
        $propertyId = AuthHelper::getPropertyId();
        $audit = new NightAudit($db, $propertyId);
        $result = $audit->run($username);

        ApiResponse::success([
            'message' => $result['message'] ?? ($result['status'] === 'success'
                ? 'Night audit completed.'
                : ($result['status'] === 'skipped' ? ($result['message'] ?? 'Night audit skipped.') : 'Night audit finished with errors.')),
            'result' => $result
        ]);
    }

    // Action: Get audit history
    elseif ($action === 'history') {
        AuthHelper::requirePermission('run_night_audit');
        $propertyId = AuthHelper::getPropertyId();

        $limit = isset($data['limit']) ? (int)$data['limit'] : 30;
        $history = NightAudit::getHistory($db, $propertyId, $limit);
        ApiResponse::success(['history' => $history]);
    }

    // Action: Get audit exceptions
    elseif ($action === 'exceptions') {
        AuthHelper::requirePermission('run_night_audit');
        $propertyId = AuthHelper::getPropertyId();

        $stmt = $db->prepare("
            SELECT b.id, b.room_id, r.room_number, g.name as guest_name, b.check_in, b.check_out, b.booking_status
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            LEFT JOIN guests g ON b.guest_id = g.id
            WHERE b.property_id = ? AND b.booking_status = 'checked_in' AND b.check_out < NOW()
        ");
        $stmt->execute([$propertyId]);
        $exceptions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        ApiResponse::success(['exceptions' => $exceptions]);
    }

    // Action: Bulk resolve exceptions (Auto-checkout zero-balance stays only)
    elseif ($action === 'bulk_resolve') {
        AuthHelper::requirePermission('run_night_audit');
        $bookingIds = $data['booking_ids'] ?? [];
        if (empty($bookingIds) || !is_array($bookingIds)) {
            ApiResponse::error('No bookings selected');
        }

        $propertyId = AuthHelper::getPropertyId();
        $count = 0;
        $skipped = 0;
        foreach ($bookingIds as $bid) {
            $bid = (int)$bid;
            $stmt = $db->prepare("SELECT room_id FROM bookings WHERE id = ? AND property_id = ? AND booking_status = 'checked_in'");
            $stmt->execute([$bid, $propertyId]);
            $roomId = $stmt->fetchColumn();

            if (!$roomId) {
                continue;
            }

            $balance = round(FolioService::getBalance($db, $bid), 2);
            if ($balance != 0.0) {
                $skipped++;
                continue;
            }

            $db->prepare("UPDATE bookings SET booking_status = 'checked_out' WHERE id = ? AND property_id = ?")->execute([$bid, $propertyId]);
            $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ? AND property_id = ?")->execute([$roomId, $propertyId]);
            $count++;
        }

        $msg = "$count exceptions resolved successfully.";
        if ($skipped > 0) {
            $msg .= " $skipped skipped because the folio still has a balance.";
        }
        ApiResponse::success(['message' => $msg, 'resolved' => $count, 'skipped' => $skipped]);
    }

    // Action: Get last audit
    elseif ($action === 'last') {
        AuthHelper::requirePermission('run_night_audit');
        $propertyId = AuthHelper::getPropertyId();

        $last = NightAudit::getLastAudit($db, $propertyId);
        ApiResponse::success(['last_audit' => $last]);
    }

    // Action: Get audit settings
    elseif ($action === 'settings') {
        AuthHelper::requirePermission('run_night_audit');
        $propertyId = AuthHelper::getPropertyId();

        $settings = [];
        $keys = [
            'night_audit_enabled', 'night_audit_time', 'night_audit_auto_checkout',
            'night_audit_auto_checkout_hours', 'night_audit_mark_dirty',
            'night_audit_notify_telegram', 'night_audit_notify_whatsapp', 'night_audit_notify_email',
            'night_audit_report_revenue', 'night_audit_report_occupancy',
            'night_audit_report_room_status', 'night_audit_report_bookings'
        ];

        $stmt = $db->prepare("SELECT key_name, key_value FROM system_settings WHERE property_id = ? AND key_name IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
        $stmt->execute(array_merge([$propertyId], $keys));
        while ($row = $stmt->fetch()) {
            $settings[$row['key_name']] = $row['key_value'];
        }

        ApiResponse::success(['settings' => $settings]);
    }

    // Action: Save audit settings
    elseif ($action === 'save_settings') {
        AuthHelper::requirePermission('manage_settings');
        $propertyId = AuthHelper::getPropertyId();

        $settings = $data['settings'] ?? [];
        if (empty($settings)) {
            ApiResponse::error('No settings provided');
        }

        $stmt = $db->prepare("
            INSERT INTO system_settings (property_id, key_name, key_value, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = NOW()
        ");

        foreach ($settings as $key => $value) {
            if (str_starts_with($key, 'night_audit_')) {
                $stmt->execute([$propertyId, $key, $value]);
            }
        }

        ApiResponse::success(['message' => 'Night audit settings saved']);
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, true, true, false);
