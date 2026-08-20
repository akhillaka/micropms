<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/services/BookingService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_booking');
    $data = ApiHandler::getJsonInput();
    if (!isset($data['booking_id']) || !isset($data['new_checkout_date'])) {
        ApiResponse::error('Missing parameters');
    }

    $propertyId = AuthHelper::getPropertyId();
    $bookingId = (int)$data['booking_id'];
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :id AND property_id = :prop_id");
    $stmt->execute(['id' => $bookingId, 'prop_id' => $propertyId]);
    $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$booking) {
        throw new Exception("Booking not found");
    }

    $newCheckOut = date('Y-m-d H:i:s', strtotime((string)$data['new_checkout_date']));
    $result = BookingService::extendStay($db, $bookingId, $newCheckOut, $propertyId);
    $difference = (float)$result['extra_cost'];
    $newTotal = (float)$result['new_total'];

    $guestStmt = $db->prepare("SELECT name FROM guests WHERE id = :id AND property_id = :prop_id");
    $guestStmt->execute(['id' => $booking['guest_id'], 'prop_id' => $propertyId]);
    $guestName = (string)($guestStmt->fetchColumn() ?: '?');
    $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = :id AND property_id = :prop_id");
    $roomStmt->execute(['id' => $booking['room_id'], 'prop_id' => $propertyId]);
    $roomNum = (string)($roomStmt->fetchColumn() ?: '?');

    $tgMsg = "📅 <b>Checkout Date Changed</b>\n\nRoom: {$roomNum}\nGuest: " . htmlspecialchars($guestName) . "\nNew checkout: {$newCheckOut}\nDifference: ₹" . number_format($difference, 2);
    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', [
        'guest_name' => $guestName,
        'room_number' => $roomNum,
        'check_out_date' => $newCheckOut,
        'amount' => number_format($difference, 2),
        'total_amount' => number_format($newTotal, 2),
        'description' => 'Checkout date updated',
    ], $propertyId);

    ApiResponse::success(['new_total' => $newTotal, 'new_checkout' => $newCheckOut]);
}, true, true, true);
