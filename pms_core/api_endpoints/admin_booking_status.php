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
        require_once __DIR__ . '/../../pms_core/services/BookingService.php';
        BookingService::checkIn($db, (int)$bookingId, $propertyId, [
            'source' => 'admin',
            'reason' => $reason,
            'staff_id' => $_SESSION['user_id'] ?? null,
        ]);
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
        require_once __DIR__ . '/../../pms_core/services/BookingService.php';
        $responseData = BookingService::cancelBooking($db, (int)$bookingId, $propertyId, (string)$reason, [
            'source' => 'admin',
            'staff_id' => $_SESSION['user_id'] ?? null,
        ]);
        ApiResponse::success($responseData);

    } else {
        throw new Exception("Unknown action");
    }

}, true, true, true);


