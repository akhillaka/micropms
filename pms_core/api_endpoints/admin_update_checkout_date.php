<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/PricingEngine.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_booking');
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['booking_id']) || !isset($data['new_checkout_date'])) {
        ApiResponse::error('Missing parameters');
    }

    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("SELECT b.*, r.category_id, c.name as category_name FROM bookings b JOIN rooms r ON b.room_id = r.id JOIN room_categories c ON r.category_id = c.id WHERE b.id = :id AND b.property_id = :prop_id");
    $stmt->execute(['id' => $data['booking_id'], 'prop_id' => $propertyId]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        throw new Exception("Booking not found");
    }

    $newCheckOut = date('Y-m-d H:i:s', strtotime($data['new_checkout_date']));
    $isOverride = ($booking['price_override'] !== null);
    
    // Lock the room row to prevent race conditions
    $lockStmt = $db->prepare("SELECT id FROM rooms WHERE id = :room_id AND property_id = :prop_id FOR UPDATE");
    $lockStmt->execute(['room_id' => $booking['room_id'], 'prop_id' => $propertyId]);
    
    // Check for collisions (if they are actually moving checkout FORWARD, which shouldn't happen here usually, but just in case)
    if (strtotime($newCheckOut) > strtotime($booking['check_out'])) {
        $checkSql = "SELECT COUNT(*) FROM bookings 
                     WHERE room_id = :room_id 
                       AND id != :booking_id
                       AND check_in < :check_out 
                       AND check_out > :check_in
                       AND payment_status != 'cancelled'
                       AND property_id = :prop_id";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([
            'room_id' => $booking['room_id'],
            'booking_id' => $booking['id'],
            'check_in' => $booking['check_in'],
            'check_out' => $newCheckOut,
            'prop_id' => $propertyId
        ]);
        if ($checkStmt->fetchColumn() > 0) {
            throw new \Exception('Room is not available for this extended timeframe due to a conflicting booking.');
        }
    }
    
    $newTotal = 0.0;
    $breakdown = [];
    
    if ($isOverride) {
        // If price override was used, changing date is complicated. Let's just recalculate based on daily average
        $oldDays = max(1, (int)ceil((strtotime($booking['check_out']) - strtotime($booking['check_in'])) / 86400));
        $newDays = max(1, (int)ceil((strtotime($newCheckOut) - strtotime($booking['check_in'])) / 86400));
        $dailyRate = (float)$booking['total_amount'] / $oldDays;
        $newTotal = $dailyRate * $newDays;
    } else {
        // Standard flow: Recalculate total cost from check_in
        try {
            $newTotal = PricingEngine::calculateTotalCost($booking['category_id'], $booking['check_in'], $newCheckOut, $booking['rate_plan_name'] ?? null);
            $breakdown = PricingEngine::getCostBreakdown($booking['category_id'], $booking['check_in'], $newCheckOut, $booking['rate_plan_name'] ?? null);
        } catch (\Exception $e) {
            $days = max(1, (int)ceil((strtotime($newCheckOut) - strtotime($booking['check_in'])) / 86400));
            $newTotal = $days * 1000.00;
        }
    }
    
    $difference = $newTotal - (float)$booking['total_amount'];
    
    // Update Booking and replace ROOM_CHARGE entries
    if (!$isOverride) {
        $delStmt = $db->prepare("DELETE FROM folio_ledger WHERE booking_id = :id AND transaction_type = 'ROOM_CHARGE' AND property_id = :prop_id");
        $delStmt->execute(['id' => $booking['id'], 'prop_id' => $propertyId]);
        
        $ledgerStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, transaction_type, amount, description, transaction_ref, property_id) VALUES (:id, 'ROOM_CHARGE', :amount, :desc, 'MANUAL', :prop_id)");
        
        if (!empty($breakdown)) {
            foreach ($breakdown as $item) {
                $desc = "Day {$item['day']} - Room Charges - {$booking['category_name']} ({$item['duration']})";
                $ledgerStmt->execute([
                    'id' => $booking['id'],
                    'amount' => $item['cost'],
                    'desc' => $desc,
                    'prop_id' => $propertyId
                ]);
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
            }
        } else {
            $ledgerStmt->execute([
                'id' => $booking['id'],
                'amount' => $newTotal,
                'desc' => 'Room Charges - ' . $booking['category_name'],
                'prop_id' => $propertyId
            ]);
            SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
        }
    } else {
        // For overridden, just update the single charge if they only have one, or do a differential
        if (abs($difference) > 0.01) {
            $diffStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, transaction_type, amount, description, transaction_ref, property_id) VALUES (:id, 'ROOM_CHARGE', :amount, 'Checkout Date Adjustment', 'MANUAL', :prop_id)");
            $diffStmt->execute([
                'id' => $booking['id'],
                'amount' => $difference,
                'prop_id' => $propertyId
            ]);
            SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
        }
    }
    
    $updateStmt = $db->prepare("UPDATE bookings SET check_out = :co, total_amount = :ta WHERE id = :id AND property_id = :prop_id");
    $updateStmt->execute([
        'co' => $newCheckOut,
        'ta' => $newTotal,
        'id' => $booking['id'],
        'prop_id' => $propertyId
    ]);
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'UPDATE_CHECKOUT', 'BOOKING', $booking['id'], [
        'old_checkout' => $booking['check_out'],
        'new_checkout' => $newCheckOut,
        'new_total' => $newTotal
    ]);
    
    $guestStmt = $db->prepare("SELECT name FROM guests WHERE id = :id AND property_id = :prop_id");
    $guestStmt->execute(['id' => $booking['guest_id'], 'prop_id' => $propertyId]);
    $guest = $guestStmt->fetch();
    $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = :id AND property_id = :prop_id");
    $roomStmt->execute(['id' => $booking['room_id'], 'prop_id' => $propertyId]);
    $room = $roomStmt->fetch();
    
    $roomNum = $room ? $room['room_number'] : '?';
    $guestName = $guest ? $guest['name'] : '?';
    
    $tgMsg = "📅 <b>Checkout Date Changed</b>\n\nRoom: {$roomNum}\nGuest: " . htmlspecialchars($guestName) . "\nNew checkout: {$newCheckOut}\nDifference: ₹" . number_format($difference, 2);
    
    $context = [
        'guest_name' => $guestName,
        'room_number' => $roomNum,
        'check_out_date' => $newCheckOut,
        'amount' => number_format($difference, 2),
        'extra_charge' => number_format($difference, 2),
        'total_amount' => number_format($newTotal, 2),
        'description' => "Checkout changed to {$newCheckOut}"
    ];
    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);

    ApiResponse::success(['new_total' => $newTotal, 'new_checkout' => $newCheckOut, 'difference' => $difference]);
    
}, true, true, true);
