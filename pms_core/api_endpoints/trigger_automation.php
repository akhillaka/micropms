<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_automations');

    $data = ApiHandler::getJsonInput();
    if (empty($data)) {
        $data = $_POST;
    }

    $eventKey  = $data['event'] ?? null;
    $bookingId = isset($data['booking_id']) ? (int)$data['booking_id'] : null;
    $phone     = $data['phone'] ?? null;

    if (!$eventKey) {
        throw new Exception("Missing event parameter");
    }

    if (!$bookingId && !$phone) {
        throw new Exception("Must provide either booking_id or phone");
    }

    $customData = [];
    if (!$bookingId) {
        $customData = [
            'guest_name' => 'Test Guest',
            'booking_id' => 'BKG-12345',
            'room_number' => '101',
            'room_type' => 'Deluxe Room',
            'rate_plan_name' => 'Standard Rate',
            'check_in_date' => date('d M Y h:i A'),
            'check_out_date' => date('d M Y h:i A', strtotime('+1 day')),
            'total_amount' => '1,000.00',
            'paid_amount' => '500.00',
            'balance_amount' => '500.00',
            'payment_link' => 'https://example.invalid/test'
        ];
    }

    $result = NotificationRelay::triggerAutomation($eventKey, $phone, $bookingId, $customData);

    if ($result) {
        ApiResponse::success(['message' => 'Automation triggered successfully']);
    }

    ApiResponse::success([
        'skipped' => true,
        'message' => 'WhatsApp was not sent. This is recorded in Delivery Logs (inactive, missing template, or missing guest phone).',
    ]);
}, true, true, false);
