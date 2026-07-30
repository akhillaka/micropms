<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';


require_once __DIR__ . '/../../pms_core/PricingEngine.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_booking');

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['booking_id'], $data['check_in'], $data['check_out'])) {
        throw new Exception("Missing parameters");
    }

    if (strtotime($data['check_out']) <= strtotime($data['check_in'])) {
        throw new Exception("Check-out date and time must be after check-in.");
    }

    // We must lock the table for bookings overlapping this room to prevent double booking.
    $stmt = $db->prepare("SELECT b.*, r.category_id, c.name as category_name FROM bookings b JOIN rooms r ON b.room_id = r.id JOIN room_categories c ON r.category_id = c.id WHERE b.id = :id FOR UPDATE");
    $stmt->execute(['id' => $data['booking_id']]);
    $booking = $stmt->fetch();
    
    if (!$booking) throw new Exception("Booking not found");
    
    $newRoomId = isset($data['room_id']) ? $data['room_id'] : $booking['room_id'];
    $newRatePlan = isset($data['rate_plan_name']) ? $data['rate_plan_name'] : $booking['rate_plan_name'];

    // Get new room's category and lock the room to prevent race conditions
    $newRoomStmt = $db->prepare("SELECT r.room_number, r.category_id, c.name as category_name FROM rooms r JOIN room_categories c ON r.category_id = c.id WHERE r.id = :id FOR UPDATE");
    $newRoomStmt->execute(['id' => $newRoomId]);
    $newRoom = $newRoomStmt->fetch();
    if (!$newRoom) throw new Exception("New room not found");
    $newRoomNumber = $newRoom['room_number'];

    // Check for concurrency overlap
    $overlapStmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = :room_id AND id != :booking_id AND check_in < :new_out AND check_out > :new_in AND payment_status != 'cancelled'");
    $overlapStmt->execute([
        'room_id' => $newRoomId,
        'booking_id' => $booking['id'],
        'new_in' => $data['check_in'],
        'new_out' => $data['check_out']
    ]);
    
    if ($overlapStmt->fetchColumn() > 0) {
        throw new \Exception('Room is already booked during this new timeframe by another guest.');
    }
    
    // Calculate new price
    try {
        $newTotal = PricingEngine::calculateTotalCost($newRoom['category_id'], $data['check_in'], $data['check_out'], $newRatePlan);
    } catch (\Exception $e) {
        $days = max(1, (int)ceil((strtotime($data['check_out']) - strtotime($data['check_in'])) / 86400));
        $newTotal = $days * 1000.00;
    }
    
    $newTaxPref = isset($data['tax_preference']) ? $data['tax_preference'] : $booking['tax_preference'];
    
    $updateStmt = $db->prepare("UPDATE bookings SET room_id = :room_id, check_in = :in, check_out = :out, total_amount = :total, rate_plan_name = :rate_plan_name, tax_preference = :tax_preference WHERE id = :id");
    $updateStmt->execute([
        'room_id' => $newRoomId,
        'in' => $data['check_in'],
        'out' => $data['check_out'],
        'total' => $newTotal,
        'rate_plan_name' => $newRatePlan,
        'tax_preference' => $newTaxPref,
        'id' => $booking['id']
    ]);
    
    // Manage physical room states if room changed
    if ($newRoomId != $booking['room_id']) {
        $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = :id")->execute(['id' => $booking['room_id']]);
        // The new room becomes occupied dynamically based on the booking check-in status.
    }
    
    if ($booking['check_in'] !== $data['check_in'] || $booking['check_out'] !== $data['check_out'] || $booking['room_id'] != $newRoomId || $booking['rate_plan_name'] !== $newRatePlan) {
        $delStmt = $db->prepare("DELETE FROM folio_ledger WHERE booking_id = :id AND transaction_type = 'ROOM_CHARGE'");
        $delStmt->execute(['id' => $booking['id']]);
        
        try {
            $breakdown = PricingEngine::getCostBreakdown($newRoom['category_id'], $data['check_in'], $data['check_out'], $newRatePlan);
        } catch (\Exception $e) {
            $breakdown = [];
        }
        $ledgerStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, transaction_type, amount, description) VALUES (:id, 'ROOM_CHARGE', :amount, :desc)");
        
        if (!empty($breakdown)) {
            foreach ($breakdown as $item) {
                $ledgerStmt->execute([
                    'id' => $booking['id'],
                    'amount' => $item['cost'],
                    'desc' => "Day {$item['day']} - Room Charges - {$newRoom['category_name']} ({$item['duration']})"
                ]);
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
            }
        } else {
            $ledgerStmt->execute([
                'id' => $booking['id'],
                'amount' => $newTotal,
                'desc' => "Room Charges - {$newRoom['category_name']}"
            ]);
            SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
        }
    }
    
    AuditLogger::log($_SESSION['user_id'], 'EDIT_BOOKING_DATES', 'BOOKING', $booking['id'], [
        'old_in' => $booking['check_in'], 'old_out' => $booking['check_out'], 'old_total' => $booking['total_amount'],
        'new_in' => $data['check_in'], 'new_out' => $data['check_out'], 'new_total' => $newTotal
    ]);
    
    $changes = [];
    if ($booking['room_id'] != $newRoomId) {
        $oldRoomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = :id");
        $oldRoomStmt->execute(['id' => $booking['room_id']]);
        $oldRoom = $oldRoomStmt->fetch();
        $changes[] = "Room: " . ($oldRoom ? $oldRoom['room_number'] : '?') . " → " . ($newRoomNumber ?? $newRoomId);
    }
    if ($booking['check_in'] !== $data['check_in'] || $booking['check_out'] !== $data['check_out']) {
        $changes[] = "Dates: {$booking['check_in']} → {$data['check_in']}";
    }
    if ($booking['total_amount'] != $newTotal) {
        $changes[] = "Total: ₹" . number_format((float)$booking['total_amount'], 2) . " → ₹" . number_format((float)$newTotal, 2);
    }

    $guestStmt = $db->prepare("SELECT name FROM guests WHERE id = :id");
    $guestStmt->execute(['id' => $booking['guest_id']]);
    $guest = $guestStmt->fetch();
    
    // Use new room number for Telegram context (reflects the final state)
    $roomNum = $newRoomNumber ?? '?';
    $guestName = $guest ? $guest['name'] : '?';
    
    $tgMsg = "📝 <b>Booking Edited</b>\n\nGuest: " . htmlspecialchars($guestName) . "\nFolio #" . $booking['id'] . "\n" . implode("\n", $changes);
    
    $context = [
        'guest_name' => $guestName,
        'room_number' => $roomNum,
        'description' => implode(", ", $changes),
        'amount' => number_format((float)$newTotal, 2)
    ];
    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);

    ApiResponse::success(['new_total' => $newTotal]);

}, true, true, true);
