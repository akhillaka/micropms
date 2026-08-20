<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/services/BookingService.php';
require_once __DIR__ . '/../../pms_core/services/StayPolicy.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_booking');

    $data = ApiHandler::getJsonInput();
    if (!isset($data['booking_id'], $data['check_in'], $data['check_out'])) {
        throw new Exception("Missing parameters");
    }
    $bookingId = (int)$data['booking_id'];
    $propertyId = AuthHelper::getPropertyId();

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ? AND property_id = ?");
    $stmt->execute([$bookingId, $propertyId]);
    $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$booking) {
        throw new Exception("Booking not found");
    }

    $newRoomId = isset($data['room_id']) ? (int)$data['room_id'] : (int)$booking['room_id'];
    if ($newRoomId !== (int)$booking['room_id']) {
        AuthHelper::requirePermission('move_room');
    }

    $result = BookingService::reschedule($db, $bookingId, $propertyId, (string)$data['check_in'], (string)$data['check_out'], $newRoomId, [
        'rate_plan_name' => $data['rate_plan_name'] ?? $booking['rate_plan_name'],
        'tax_preference' => $data['tax_preference'] ?? ($booking['tax_preference'] ?? null),
    ]);

    $guestStmt = $db->prepare("SELECT name FROM guests WHERE id = :id AND property_id = :prop_id");
    $guestStmt->execute(['id' => $booking['guest_id'], 'prop_id' => $propertyId]);
    $guestName = (string)($guestStmt->fetchColumn() ?: '?');
    $changes = "Stay {$result['check_in']} → {$result['check_out']}";
    $tgMsg = "📝 <b>Booking Edited</b>\n\nGuest: " . htmlspecialchars($guestName) . "\nRoom: {$result['room_number']}\n{$changes}";
    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', [
        'guest_name' => $guestName,
        'room_number' => $result['room_number'],
        'description' => $changes,
        'amount' => number_format((float)$result['new_total'], 2),
    ], $propertyId);

    ApiResponse::success(['new_total' => $result['new_total']]);
}, true, true, true);
