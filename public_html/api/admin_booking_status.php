<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/GoogleSheetService.php';

ApiHandler::run(function(\PDO $db) {

    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? null;
    $bookingId = $data['booking_id'] ?? null;
    $reason = $data['reason'] ?? '';

    if (!$action || !$bookingId) {
        throw new Exception("Action and booking ID required");
    }

    if ($action === 'cancel') {
        AuthHelper::requirePermission('cancel_booking');
    } else {
        AuthHelper::requirePermission('check_in_out');
    }

    $propertyId = AuthHelper::getPropertyId();

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :id AND property_id = :pid");
    $stmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        throw new Exception("Booking not found or access denied");
    }

    $currentStatus = $booking['booking_status'];

    if ($action === 'check_in') {
        if ($currentStatus !== 'booked') {
            throw new Exception("Can only check-in from 'booked' status");
        }
        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'checked_in' WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);
        AuditLogger::log($_SESSION['user_id'], 'CHECK_IN', 'BOOKING', $bookingId, [
            'action' => 'check_in',
            'from_status' => $currentStatus,
            'to_status' => 'checked_in',
            'reason' => $reason,
            'check_in_time' => date('Y-m-d H:i:s')
        ]);

        // Telegram notification
        $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = :id");
        $roomStmt->execute(['id' => $booking['room_id']]);
        $room = $roomStmt->fetch();
        $guestStmt = $db->prepare("SELECT name FROM guests WHERE id = :id");
        $guestStmt->execute(['id' => $booking['guest_id']]);
        $guest = $guestStmt->fetch();
        
        $roomNum = $room ? $room['room_number'] : $booking['room_id'];
        $guestName = $guest ? $guest['name'] : 'N/A';
        
        $tgMsg = "🏨 <b>Guest Checked In</b>\n\nRoom: {$roomNum}\nGuest: " . htmlspecialchars($guestName) . "\nCheckout: {$booking['check_out']}";
        
        $context = [
            'guest_name' => $guestName,
            'room_number' => $roomNum,
            'check_out_date' => $booking['check_out'],
            'total_amount' => number_format((float)$booking['total_amount'], 2)
        ];
        NotificationRelay::sendTelegram($tgMsg, 'check_in', $context);

        // Trigger WhatsApp automation
        NotificationRelay::triggerAutomation('guest_check_in', null, (int)$bookingId);

        try {
            GoogleSheetService::syncBooking($db, (int)$bookingId);
        } catch (\Throwable $t) {
            error_log("Google Sheets sync error in booking_status: " . $t->getMessage());
        }

        ApiResponse::success(['message' => 'Guest checked in successfully']);

    } elseif ($action === 'check_out') {
        if ($currentStatus !== 'checked_in') {
            throw new Exception("Can only check-out from 'checked_in' status");
        }
        
        $balStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM folio_ledger WHERE booking_id = :id");
        $balStmt->execute(['id' => $bookingId]);
        $balance = round((float)$balStmt->fetchColumn(), 2);
        
        if ($balance > 0) {
            throw new Exception("Cannot check-out: Guest has pending dues of ₹" . number_format($balance, 2) . ". Please settle the folio first.");
        }

        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'checked_out' WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);
        
        $stmt2 = $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = :rid");
        $stmt2->execute(['rid' => $booking['room_id']]);
        AuditLogger::log($_SESSION['user_id'], 'CHECK_OUT', 'BOOKING', $bookingId, [
            'action' => 'check_out',
            'from_status' => $currentStatus,
            'to_status' => 'checked_out',
            'reason' => $reason,
            'check_out_time' => date('Y-m-d H:i:s')
        ]);

        // Telegram notification
        $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = :id");
        $roomStmt->execute(['id' => $booking['room_id']]);
        $room = $roomStmt->fetch();
        $guestStmt = $db->prepare("SELECT name FROM guests WHERE id = :id");
        $guestStmt->execute(['id' => $booking['guest_id']]);
        $guest = $guestStmt->fetch();
        
        $roomNum = $room ? $room['room_number'] : $booking['room_id'];
        $guestName = $guest ? $guest['name'] : 'N/A';
        
        $paidStmt = $db->prepare("SELECT IFNULL(SUM(amount), 0) FROM folio_ledger WHERE booking_id = ? AND amount < 0");
        $paidStmt->execute([$bookingId]);
        $paidAmount = abs((float)$paidStmt->fetchColumn());
        
        $tgMsg = "🚪 <b>Guest Checked Out</b>\n\nRoom: {$roomNum}\nGuest: " . htmlspecialchars($guestName) . "\nRoom is now dirty — needs cleaning.";
        
        $context = [
            'guest_name' => $guestName,
            'room_number' => $roomNum,
            'paid_amount' => number_format($paidAmount, 2),
            'balance_amount' => number_format((float)$booking['total_amount'] - $paidAmount, 2),
            'total_amount' => number_format((float)$booking['total_amount'], 2)
        ];
        NotificationRelay::sendTelegram($tgMsg, 'check_out', $context);

        // Trigger WhatsApp automation
        NotificationRelay::triggerAutomation('guest_check_out', null, (int)$bookingId);

        try {
            GoogleSheetService::syncBooking($db, (int)$bookingId);
        } catch (\Throwable $t) {
            error_log("Google Sheets sync error in booking_status: " . $t->getMessage());
        }

        ApiResponse::success(['message' => 'Guest checked out successfully']);

    } elseif ($action === 'rollback_to_booked') {
        if ($currentStatus !== 'checked_in') {
            throw new Exception("Can only rollback from 'checked_in' status");
        }
        if (empty($reason)) {
            throw new Exception("Reason is required for rollback");
        }
        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'booked' WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);
        AuditLogger::log($_SESSION['user_id'], 'ROLLBACK_TO_BOOKED', 'BOOKING', $bookingId, [
            'action' => 'rollback_to_booked',
            'from_status' => $currentStatus,
            'to_status' => 'booked',
            'reason' => $reason
        ]);

        try {
            GoogleSheetService::syncBooking($db, (int)$bookingId);
        } catch (\Throwable $t) {
            error_log("Google Sheets sync error in booking_status: " . $t->getMessage());
        }

        ApiResponse::success(['message' => 'Rolled back to booked status']);

    } elseif ($action === 'rollback_to_checked_in') {
        if ($currentStatus !== 'checked_out') {
            throw new Exception("Can only rollback from 'checked_out' status");
        }
        if (empty($reason)) {
            throw new Exception("Reason is required for rollback");
        }
        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'checked_in' WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);
        AuditLogger::log($_SESSION['user_id'], 'ROLLBACK_TO_CHECKED_IN', 'BOOKING', $bookingId, [
            'action' => 'rollback_to_checked_in',
            'from_status' => $currentStatus,
            'to_status' => 'checked_in',
            'reason' => $reason
        ]);

        try {
            GoogleSheetService::syncBooking($db, (int)$bookingId);
        } catch (\Throwable $t) {
            error_log("Google Sheets sync error in booking_status: " . $t->getMessage());
        }

        ApiResponse::success(['message' => 'Rolled back to checked-in status']);

    } elseif ($action === 'cancel') {
        if (!in_array($currentStatus, ['booked', 'checked_in'])) {
            throw new Exception("Can only cancel from 'booked' or 'checked_in' status");
        }
        if (empty($reason)) {
            throw new Exception("Reason is required for cancellation");
        }

        // Check if any payments were collected — we need to warn staff about refunds
        $pmtStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM folio_ledger WHERE booking_id = :id AND amount < 0");
        $pmtStmt->execute(['id' => $bookingId]);
        $totalPaid = abs((float)$pmtStmt->fetchColumn());

        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'cancelled', payment_status = 'cancelled' WHERE id = :id");
        $stmt->execute(['id' => $bookingId]);

        // Do NOT delete folio entries — preserve the ledger trail for audit.
        // If payments were collected, staff must manually process refund.
        AuditLogger::log($_SESSION['user_id'], 'CANCEL_BOOKING', 'BOOKING', $bookingId, [
            'action'       => 'cancel',
            'from_status'  => $currentStatus,
            'to_status'    => 'cancelled',
            'reason'       => $reason,
            'total_paid'   => $totalPaid,
            'refund_needed'=> $totalPaid > 0,
        ]);

        // Trigger WhatsApp automation
        NotificationRelay::triggerAutomation('booking_cancelled', null, (int)$bookingId);

        try {
            GoogleSheetService::syncBooking($db, (int)$bookingId);
        } catch (\Throwable $t) {
            error_log("Google Sheets sync error in booking_status: " . $t->getMessage());
        }

        $responseData = ['message' => 'Booking cancelled'];
        if ($totalPaid > 0) {
            $responseData['refund_alert'] = true;
            $responseData['refund_amount'] = $totalPaid;
            $responseData['message'] = 'Booking cancelled. Guest paid ₹' . number_format($totalPaid, 2) . ' — please process a refund.';
        }
        ApiResponse::success($responseData);

    } else {
        throw new Exception("Unknown action");
    }

}, true, true, true);


