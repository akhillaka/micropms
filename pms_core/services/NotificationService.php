<?php
declare(strict_types=1);

require_once __DIR__ . '/../NotificationRelay.php';
require_once __DIR__ . '/../PhoneHelper.php';

/**
 * NotificationService - Centralized notification triggers for both Admin and Assistant.
 * Wraps NotificationRelay with business-context helpers.
 */
class NotificationService {

    /**
     * Send booking confirmation notifications (Telegram + WhatsApp automation + In-App).
     */
    public static function notifyBookingCreated(\PDO $db, int $bookingId): void {
        $booking = self::getBookingContext($db, $bookingId);
        if (!$booking) return;

        // In-App Notification
        NotificationRelay::sendInAppNotification((int)$booking['property_id'], "New Booking Confirmed", "Room {$booking['room_number']} booked for {$booking['guest_name']} (₹{$booking['total_amount']})", 'booking_confirmed');

        // Telegram
        $tgMsg = "New Booking: Room {$booking['room_number']}, Guest: {$booking['guest_name']}, Amount: ₹{$booking['total_amount']}";
        NotificationRelay::sendTelegram($tgMsg, 'booking_confirmed', $booking);

        // WhatsApp automation
        $phoneE164 = PhoneHelper::toE164($booking['guest_phone'] ?? '');
        if ($phoneE164) {
            NotificationRelay::triggerAutomation('booking_confirmed', $phoneE164, $bookingId);
        }
    }

    /**
     * Send check-in notifications.
     */
    public static function notifyCheckIn(\PDO $db, int $bookingId): void {
        $booking = self::getBookingContext($db, $bookingId);
        if (!$booking) return;

        // In-App Notification
        NotificationRelay::sendInAppNotification((int)$booking['property_id'], "Guest Checked In", "{$booking['guest_name']} checked into Room {$booking['room_number']}", 'check_in');

        $tgMsg = "Guest Checked In: {$booking['guest_name']} → Room {$booking['room_number']}";
        NotificationRelay::sendTelegram($tgMsg, 'check_in', $booking);

        $phoneE164 = PhoneHelper::toE164($booking['guest_phone'] ?? '');
        if ($phoneE164) {
            NotificationRelay::triggerAutomation('guest_check_in', $phoneE164, $bookingId);
        }
    }

    /**
     * Send check-out notifications and mark room dirty.
     */
    public static function notifyCheckOut(\PDO $db, int $bookingId): void {
        $booking = self::getBookingContext($db, $bookingId);
        if (!$booking) return;

        // Mark room dirty
        $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ?")->execute([$booking['room_id']]);

        // In-App Notification
        NotificationRelay::sendInAppNotification((int)$booking['property_id'], "Guest Checked Out", "{$booking['guest_name']} checked out of Room {$booking['room_number']}", 'check_out');

        $tgMsg = "Guest Checked Out: {$booking['guest_name']} from Room {$booking['room_number']}, Total Paid: ₹{$booking['paid_amount']}";
        NotificationRelay::sendTelegram($tgMsg, 'check_out', $booking);

        $phoneE164 = PhoneHelper::toE164($booking['guest_phone'] ?? '');
        if ($phoneE164) {
            NotificationRelay::triggerAutomation('guest_check_out', $phoneE164, $bookingId);
        }
    }

    /**
     * Send payment received notification.
     */
    public static function notifyPayment(\PDO $db, int $bookingId, float $amount, string $method): void {
        $booking = self::getBookingContext($db, $bookingId);
        if (!$booking) return;

        $booking['amount'] = number_format($amount, 2);
        $booking['method'] = $method;

        // In-App Notification
        NotificationRelay::sendInAppNotification((int)$booking['property_id'], "Payment Received", "₹{$booking['amount']} received via {$method} for Room {$booking['room_number']}", 'payment_received');

        $tgMsg = "Payment Received: ₹{$booking['amount']} ({$method}) for Room {$booking['room_number']}, Guest: {$booking['guest_name']}";
        NotificationRelay::sendTelegram($tgMsg, 'payment_received', $booking);
    }

    /**
     * Send overstay alert.
     */
    public static function notifyOverstay(\PDO $db, int $bookingId): void {
        $booking = self::getBookingContext($db, $bookingId);
        if (!$booking) return;

        // In-App Notification
        NotificationRelay::sendInAppNotification((int)$booking['property_id'], "Overstay Alert", "{$booking['guest_name']} in Room {$booking['room_number']} has overstayed", 'overstay');

        $tgMsg = "Overstay Alert: {$booking['guest_name']} in Room {$booking['room_number']}, Checkout was: {$booking['check_out']}";
        NotificationRelay::sendTelegram($tgMsg, 'overstay', $booking);
    }

    /**
     * Build booking context array for notification templates.
     */
    private static function getBookingContext(\PDO $db, int $bookingId): ?array {
        $stmt = $db->prepare("
            SELECT b.id as booking_id, b.check_in, b.check_out, b.total_amount, b.room_id, b.property_id,
                   r.room_number, c.name as room_type,
                   g.name as guest_name, g.phone as guest_phone
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            LEFT JOIN room_categories c ON r.category_id = c.id
            LEFT JOIN guests g ON b.guest_id = g.id
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$booking) return null;

        // Add computed fields
        $booking['total_amount'] = number_format((float)$booking['total_amount'], 2);
        $booking['paid_amount'] = number_format(self::getPaidAmount($db, $bookingId), 2);
        $booking['hotel_name'] = defined('PROPERTY_NAME') ? PROPERTY_NAME : 'Hotel';

        return $booking;
    }

    private static function getPaidAmount(\PDO $db, int $bookingId): float {
        $stmt = $db->prepare("SELECT ABS(COALESCE(SUM(amount), 0)) FROM folio_ledger WHERE booking_id = ? AND amount < 0");
        $stmt->execute([$bookingId]);
        return (float)$stmt->fetchColumn();
    }
}
