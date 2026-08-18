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

        // Insert booking directly (already in transaction — don't let BookingService start a nested one)
        $insertStmt = $db->prepare("
            INSERT INTO bookings
                (room_id, guest_id, check_in, check_out, payment_status, booking_status,
                 total_amount, booking_source, price_override, adults, children, extra_bed, rate_plan_name, property_id)
            VALUES
                (:room_id, :guest_id, :check_in, :check_out, 'completed_paid', 'checked_in',
                 :total_amount, :booking_source, :price_override, 1, 0, 0, :rate_plan_name, :prop_id)
        ");
        $insertStmt->execute([
            'room_id'        => $roomId,
            'guest_id'       => $guestId,
            'check_in'       => $checkIn,
            'check_out'      => $checkOut,
            'total_amount'   => $totalAmount,
            'booking_source' => $bookingSource,
            'price_override' => $priceOverride,
            'rate_plan_name' => $ratePlanName,
            'prop_id'        => $propertyId
        ]);
        $bookingId = (int)$db->lastInsertId();

        // Assign sequence display IDs
        SequenceGenerator::assignDisplayId($db, 'bookings', $bookingId, 'SEQ_BOOKING_FORMAT', 'display_id');
        SequenceGenerator::assignDisplayId($db, 'bookings', $bookingId, 'SEQ_FOLIO_FORMAT', 'offline_folio_id');

        // BUG-13 fix: Occupancy status is dynamic, no need to update rooms.state to invalid value
        $dispStmt = $db->prepare("SELECT display_id FROM bookings WHERE id = ? AND property_id = ?");
        $dispStmt->execute([$bookingId, $propertyId]);
        $displayId = $dispStmt->fetchColumn() ?: ('BKG-' . $bookingId);

        // Post room charge to folio (separate INSERT statements — no multi-value with reused params)
        $chargeStmt = $db->prepare("
            INSERT INTO folio_ledger (booking_id, transaction_type, amount, transaction_ref, description, property_id)
            VALUES (:bid, 'ROOM_CHARGE', :amt, :ref, :desc, :prop_id)
        ");
        $chargeStmt->execute([
            'bid'  => $bookingId,
            'amt'  => $totalAmount,
            'ref'  => FolioService::uniqueRef('RC'),
            'desc' => "Room Tariff - {$room['category_name']} ({$durationHours}H Walk-in)",
            'prop_id' => $propertyId
        ]);
        SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');

        // Post payment entry (separate statement — fixes duplicate named param bug)
        $paymentStmt = $db->prepare("
            INSERT INTO folio_ledger (booking_id, transaction_type, amount, transaction_ref, description, payment_method, property_id)
            VALUES (:bid, 'payment', :amt, :ref, :desc, :method, :prop_id)
        ");
        $paymentStmt->execute([
            'bid'    => $bookingId,
            'amt'    => -$totalAmount,
            'ref'    => FolioService::uniqueRef('PAY'),
            'desc'   => 'Express Walk-in Payment - ' . ucfirst(strtolower($paymentMethod)),
            'method' => strtolower($paymentMethod),
            'prop_id' => $propertyId
        ]);
        SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');

        // Finance transaction record
        $finStmt = $db->prepare("
            INSERT INTO finance_transactions (type, category, booking_id, amount, description, payment_method, staff_id, property_id)
            VALUES ('income', 'booking', :bid, :amt, :desc, :method, :staff, :prop_id)
        ");
        $finStmt->execute([
            'bid'    => $bookingId,
            'amt'    => $totalAmount,
            'desc'   => "Express Walk-in - {$displayId}",
            'method' => strtolower($paymentMethod),
            'staff'  => $_SESSION['user_id'] ?? null,
            'prop_id' => $propertyId
        ]);
        SequenceGenerator::assignDisplayId($db, 'finance_transactions', (int)$db->lastInsertId(), 'SEQ_TRANSACTION_FORMAT');

        // Audit log
        AuditLogger::log($_SESSION['user_id'] ?? null, 'EXPRESS_WALKIN', 'BOOKING', $bookingId, [
            'room_id'       => $roomId,
            'guest_id'      => $guestId,
            'duration_hours'=> $durationHours,
            'amount'        => $totalAmount,
            'method'        => $paymentMethod,
        ]);

        $db->commit();

        // Post-commit: notifications (non-blocking)
        try {
            NotificationRelay::triggerAutomation('booking_confirmed', null, $bookingId);
            NotificationRelay::triggerAutomation('guest_check_in', null, $bookingId);
        } catch (\Throwable $t) {
            // ignore
        }

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
