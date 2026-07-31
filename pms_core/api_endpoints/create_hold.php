<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/BookingService.php';
require_once __DIR__ . '/../../pms_core/services/GuestService.php';

ApiHandler::run(function(\PDO $db) {

    // Read from POST if set, otherwise fallback to JSON input
    if (!empty($_POST)) {
        $data = $_POST;
    } else {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    
    // Support both single room_id (legacy) and room_ids array (multi-room)
    $roomIds = [];
    if (isset($data['room_ids'])) {
        $roomIds = json_decode($data['room_ids'], true);
        if (!is_array($roomIds) || empty($roomIds)) {
            ApiResponse::error('room_ids must be a non-empty JSON array');
        }
    } elseif (isset($data['room_id'])) {
        $roomIds = [(int)$data['room_id']];
    } else {
        ApiResponse::error('Missing room_id or room_ids parameter');
    }

    if (!isset($data['check_in'], $data['check_out'], $data['guest_name'], $data['guest_phone'])) {
        ApiResponse::error('Missing check_in, check_out, guest_name, or guest_phone');
    }

    // Normalize phone
    $guestPhone = PhoneHelper::toLocal($data['guest_phone']);
    if ($guestPhone === null) {
        ApiResponse::error('Invalid phone number. Enter a valid 10-digit Indian mobile number.');
    }
    if (!PhoneHelper::isValidIndian($guestPhone)) {
        ApiResponse::error('Phone number must be a valid Indian mobile (starting with 6-9).');
    }

    // Find or create guest
    $guestResult = GuestService::findOrCreate($db, $data['guest_name'], $guestPhone);
    $guestId = $guestResult['guest_id'];

    // Process document uploads
    $uploadDir = realpath(__DIR__ . '/../uploads');
    if ($uploadDir && is_writable($uploadDir)) {
        foreach (['id_proof_front', 'id_proof_back', 'guest_photo'] as $key) {
            $dbCol = ($key === 'guest_photo') ? 'photo' : $key;
            if (isset($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                    $filename = uniqid($key . '_') . '.' . $ext;
                    $dest = $uploadDir . '/' . $filename;
                    if (move_uploaded_file($_FILES[$key]['tmp_name'], $dest)) {
                        $db->prepare("UPDATE guests SET `{$dbCol}` = :val WHERE id = :id")->execute(['val' => $filename, 'id' => $guestId]);
                    }
                }
            }
        }
    }

    // Create bookings for each room.
    // Transaction is managed by ApiHandler (useTransaction=true on line 112) —
    // do NOT call beginTransaction/commit/rollBack here.
    $bookingIds = [];
    $totalAmount = 0;
    $roomCount = count($roomIds);
    $priceOverride = isset($data['price_override']) && $data['price_override'] !== '' ? (float)$data['price_override'] : null;
    $perRoomOverride = ($priceOverride !== null) ? round($priceOverride / $roomCount, 2) : null;
    
    // Parse individual room overrides if sent
    $roomOverrides = [];
    if (isset($data['room_overrides'])) {
        $roomOverrides = json_decode($data['room_overrides'], true);
        if (!is_array($roomOverrides)) {
            $roomOverrides = [];
        }
    }

    foreach ($roomIds as $roomId) {
        // Use individual room override if present, otherwise fallback to divided override
        $specificOverride = isset($roomOverrides[$roomId]) ? (float)$roomOverrides[$roomId] : $perRoomOverride;

        $bookingParams = [
            'room_id'           => (int)$roomId,
            'guest_id'          => $guestId,
            'check_in'          => $data['check_in'],
            'check_out'         => $data['check_out'],
            'rate_plan_name'    => $data['rate_plan_name'] ?? null,
            'booking_source'    => $data['booking_source'] ?? 'Walk-in',
            'offline_folio_id'  => $data['offline_folio_id'] ?? null,
            'price_override'    => $specificOverride,
            'payment_collected' => $data['payment_collected'] ?? 0,
            'payment_method'    => $data['payment_method'] ?? 'Cash',
        ];

        $result = BookingService::createBooking($db, $bookingParams);
        $bookingIds[] = $result['booking_id'];
        $totalAmount += $result['total_amount'];
    }

    $response = [
        'success' => true,
        'booking_ids' => $bookingIds,
        'booking_id' => $bookingIds[0], // Backward compatibility
        'amount' => $totalAmount,
        'rooms_booked' => count($bookingIds),
    ];

    ApiResponse::success($response);


}, false, false, true);
