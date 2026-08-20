<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../../pms_core/PricingEngine.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/services/GuestService.php';
require_once __DIR__ . '/../../pms_core/services/BookingService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('create_booking');

    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $guestName  = trim($data['guest_name'] ?? '');
    $guestPhone = PhoneHelper::toLocal($data['guest_phone'] ?? '');   // FIX: was PhoneHelper::clean() which doesn't exist
    $roomId     = (int)($data['room_id'] ?? 0);
    $durationHours = max(1, (int)($data['duration_hours'] ?? 2));
    $paymentMethod = trim($data['payment_method'] ?? 'Cash');
    $bookingSource = trim($data['booking_source'] ?? 'Walk-in');
    $ratePlanName  = trim($data['rate_plan_name'] ?? 'Standard');
    $priceOverride = isset($data['price_override']) && $data['price_override'] !== ''
        ? (float)$data['price_override'] : null;

    // Validate required fields
    if (empty($guestName)) {
        ApiResponse::error('Guest name is required.');
    }
    if ($guestPhone === null || !PhoneHelper::isValidIndian($guestPhone)) {
        ApiResponse::error('Invalid phone number. Enter a valid 10-digit Indian mobile number.');
    }
    if (!$roomId) {
        ApiResponse::error('Room ID is required.');
    }
    if ($durationHours < 1 || $durationHours > 168) {
        ApiResponse::error('Duration must be between 1 and 168 hours (7 days).');
    }

    // Compute check-in / check-out times
    $checkIn  = date('Y-m-d H:i:s');
    $checkOut = date('Y-m-d H:i:s', strtotime("+{$durationHours} hours"));

    // Availability check — must run inside the transaction lock
    $db->beginTransaction();

    try {
        $propertyId = AuthHelper::getPropertyId();
        $roomStmt = $db->prepare(
            "SELECT r.id, r.room_number, r.state, r.category_id, c.name AS category_name
             FROM rooms r
             JOIN room_categories c ON r.category_id = c.id
             WHERE r.id = :id AND r.property_id = :prop_id
             FOR UPDATE"
        );
        $roomStmt->execute(['id' => $roomId, 'prop_id' => $propertyId]);
        $room = $roomStmt->fetch();

        if (!$room) {
            throw new \Exception('Room not found.');
        }
        if ($room['state'] === 'out_of_order') {
            throw new \Exception('Room is out of order and cannot be booked.');
        }

        // Check for overlapping bookings
        if (!BookingService::isRoomAvailable($db, $roomId, $checkIn, $checkOut)) {
            throw new \Exception("Room {$room['room_number']} is not available for this timeframe.");
        }

        // Resolve or create guest
        $guestResult = GuestService::findOrCreate($db, $guestName, $guestPhone);
        $guestId = $guestResult['guest_id'];

        // Calculate price
        $totalAmount = 0.0;
        if ($priceOverride !== null) {
            $totalAmount = $priceOverride;
        } else {
            try {
                $totalAmount = PricingEngine::calculateTotalCost(
                    (int)$room['category_id'], $checkIn, $checkOut, $ratePlanName
                );
            } catch (\Exception $e) {
                // Fallback: use sliding_rates direct lookup
                if (empty($ratePlanName)) {
                    $ratePlanName = 'Base Rate';
                }
                $rateStmt = $db->prepare(
                    "SELECT price FROM sliding_rates
                     WHERE category_id = :cid AND rate_plan_name = :rp AND hours >= :h AND property_id = :prop_id
                     ORDER BY hours ASC LIMIT 1"
                );
                $rateStmt->execute(['cid' => $room['category_id'], 'rp' => $ratePlanName, 'h' => $durationHours, 'prop_id' => $propertyId]);
                $fallbackRate = (float)($rateStmt->fetchColumn() ?: 0);
                // If no exact match, use highest available rate for that plan
                if ($fallbackRate <= 0) {
                    $rateStmt2 = $db->prepare(
                        "SELECT price FROM sliding_rates WHERE category_id = :cid AND rate_plan_name = :rp AND property_id = :prop_id ORDER BY hours DESC LIMIT 1"
                    );
                    $rateStmt2->execute(['cid' => $room['category_id'], 'rp' => $ratePlanName, 'prop_id' => $propertyId]);
                    $fallbackRate = (float)($rateStmt2->fetchColumn() ?: 0);
                }
                
                // If STILL no match, just take any valid price for that category
                if ($fallbackRate <= 0) {
                    $rateStmt3 = $db->prepare(
                        "SELECT price FROM sliding_rates WHERE category_id = :cid AND property_id = :prop_id ORDER BY hours DESC LIMIT 1"
                    );
                    $rateStmt3->execute(['cid' => $room['category_id'], 'prop_id' => $propertyId]);
                    $fallbackRate = (float)($rateStmt3->fetchColumn() ?: 1000);
                }
                
                $totalAmount = $fallbackRate;
            }
        }

        if ($totalAmount <= 0) {
            throw new \Exception('Could not determine room rate. Please set up pricing for this category.');
        }

        $result = BookingService::createBooking($db, [
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'guest_id' => $guestId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'booking_status' => 'checked_in',
            'booking_source' => $bookingSource,
            'rate_plan_name' => $ratePlanName,
            'price_override' => $priceOverride !== null ? $priceOverride : $totalAmount,
            'guest_name' => $guestName,
            'payment_collected' => $totalAmount,
            'payment_method' => $paymentMethod,
        ]);
        $bookingId = (int)$result['booking_id'];
        $displayId = (string)$result['display_id'];
        $totalAmount = (float)$result['total_amount'];

        AuditLogger::log($_SESSION['user_id'] ?? null, 'EXPRESS_WALKIN', 'BOOKING', $bookingId, [
            'room_id'       => $roomId,
            'guest_id'      => $guestId,
            'duration_hours'=> $durationHours,
            'amount'        => $totalAmount,
            'method'        => $paymentMethod,
        ]);

        $db->commit();

        ApiResponse::success([
            'booking_id' => $bookingId,
            'display_id' => $displayId,
            'amount'     => $totalAmount,
            'check_in'   => $checkIn,
            'check_out'  => $checkOut,
            'message'    => "Express walk-in completed for {$guestName} — Room {$room['room_number']}",
        ]);

    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

}, true, true, false);
