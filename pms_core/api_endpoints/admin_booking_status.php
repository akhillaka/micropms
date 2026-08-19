<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/GoogleSheetService.php';
require_once __DIR__ . '/../../pms_core/services/CheckoutService.php';

ApiHandler::run(function(\PDO $db) {

    $data = ApiHandler::getJsonInput();
    $action = $data['action'] ?? null;
    $bookingId = isset($data['booking_id']) ? (string)$data['booking_id'] : null;
    $reason = $data['reason'] ?? '';

    if (!$action || !$bookingId) {
        throw new Exception("Action and booking ID required");
    }

    if ($action === 'cancel') {
        AuthHelper::requirePermission('cancel_booking');
    } elseif (in_array($action, ['rollback_to_booked', 'rollback_to_checked_in'], true)) {
        AuthHelper::requirePermission('rollback_booking');
    } else {
        AuthHelper::requirePermission('check_in_out');
    }

    $propertyId = AuthHelper::getPropertyId();

    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :id AND property_id = :pid FOR UPDATE");
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
        // BUG-2 fix: scope UPDATE to property to prevent cross-tenant mutation
        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'checked_in' WHERE id = :id AND property_id = :pid");
        $stmt->execute(['id' => $bookingId, 'pid' => $propertyId]);

        // Removed buggy update rooms state to occupied
        AuditLogger::log($_SESSION['user_id'] ?? null, 'CHECK_IN', 'BOOKING', $bookingId, [
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

        $folioUrl = '/admin/folio?id=' . rawurlencode((string)($booking['display_id'] ?? $bookingId));
        NotificationRelay::sendInAppNotification($propertyId, 'Guest Checked In', "{$guestName} checked into Room {$roomNum}", 'check_in', $folioUrl);

        try {
            GoogleSheetService::syncBooking($db, (int)$bookingId);
        } catch (\Throwable $t) {
            error_log("Google Sheets sync error in booking_status: " . $t->getMessage());
        }

        ApiResponse::success(['message' => 'Guest checked in successfully']);

    } elseif ($action === 'check_out') {
        CheckoutService::performCheckout($db, (int)$bookingId, $propertyId, [
            'source' => 'admin',
            'reason' => $reason,
            'staff_id' => $_SESSION['user_id'] ?? null,
        ]);
        ApiResponse::success(['message' => 'Guest checked out successfully']);

    } elseif ($action === 'rollback_to_booked') {
        if ($currentStatus !== 'checked_in') {
            throw new Exception("Can only rollback from 'checked_in' status");
        }
        if (empty($reason)) {
            throw new Exception("Reason is required for rollback");
        }
        // BUG-2 fix: scope UPDATE to property
        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'booked' WHERE id = :id AND property_id = :pid");
        $stmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
        AuditLogger::log($_SESSION['user_id'] ?? null, 'ROLLBACK_TO_BOOKED', 'BOOKING', $bookingId, [
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

        // Check if room is already occupied by another booking
        $checkStmt = $db->prepare("SELECT id FROM bookings WHERE room_id = :room_id AND booking_status = 'checked_in' AND id != :id AND property_id = :pid");
        $checkStmt->execute(['room_id' => $booking['room_id'], 'id' => $bookingId, 'pid' => $propertyId]);
        if ($checkStmt->fetch()) {
            throw new Exception("Cannot rollback: Room is currently occupied by another active booking.");
        }

        // BUG-2 fix: scope UPDATE to property
        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'checked_in' WHERE id = :id AND property_id = :pid");
        $stmt->execute(['id' => $bookingId, 'pid' => $propertyId]);

        // Mark room as occupied again (Occupancy status is dynamic, no need to update rooms.state to invalid value)
        AuditLogger::log($_SESSION['user_id'] ?? null, 'ROLLBACK_TO_CHECKED_IN', 'BOOKING', $bookingId, [
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
        if ($currentStatus !== 'booked') {
            throw new Exception("Can only cancel a booking in 'booked' status. If the guest is checked-in, rollback the check-in first.");
        }
        if (empty($reason)) {
            throw new Exception("Reason is required for cancellation");
        }

        // Check if any payments were collected — we need to warn staff about refunds
        $pmtStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM folio_ledger WHERE booking_id = :id AND amount < 0");
        $pmtStmt->execute(['id' => $bookingId]);
        $totalPaid = abs((float)$pmtStmt->fetchColumn());

        // BUG-2 fix: scope UPDATE to property
        $stmt = $db->prepare("UPDATE bookings SET booking_status = 'cancelled', payment_status = 'cancelled' WHERE id = :id AND property_id = :pid");
        $stmt->execute(['id' => $bookingId, 'pid' => $propertyId]);

        // Clean up pending background jobs related to this booking
        $db->prepare("DELETE FROM jobs_queue WHERE property_id = :pid AND status = 'pending' AND JSON_EXTRACT(payload_json, '$.booking_id') = :id")
           ->execute(['pid' => $propertyId, 'id' => $bookingId]);

        // Void any unfulfilled POS orders and refund stock
        $posStmt = $db->prepare("SELECT id FROM pos_orders WHERE booking_id = :id AND status IN ('posted', 'pending') AND property_id = :pid");
        $posStmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
        $activePosOrders = $posStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($activePosOrders)) {
            $updatePos = $db->prepare("UPDATE pos_orders SET status = 'cancelled' WHERE id = ?");
            $restock = $db->prepare("UPDATE inventory_items ii JOIN pos_order_items poi ON ii.id = poi.item_id SET ii.stock_qty = ii.stock_qty + poi.quantity WHERE poi.order_id = ?");
            foreach ($activePosOrders as $oid) {
                $restock->execute([$oid]);
                $updatePos->execute([$oid]);
            }
        }

        // Do NOT delete folio entries — preserve the ledger trail for audit.
        // If payments were collected, staff must manually process refund.
        AuditLogger::log($_SESSION['user_id'] ?? null, 'CANCEL_BOOKING', 'BOOKING', $bookingId, [
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


