<?php
/**
 * Cron Script: Checkout Warnings
 * 
 * Run via cron every 5 minutes:
 *   * /5 * * * * php /path/to/scripts/checkout_warnings.php
 */

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/NotificationRelay.php';

try {
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->query("
        SELECT b.*, r.room_number, g.name as guest_name, g.phone as guest_phone
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.check_out > NOW() 
          AND b.check_out <= DATE_ADD(NOW(), INTERVAL 30 MINUTE)
          AND b.checkout_warning_sent = FALSE
          AND b.payment_status != 'cancelled'
          AND b.booking_status IN ('booked', 'checked_in')
    ");
    
    $imminentCheckouts = $stmt->fetchAll();
    
    if (empty($imminentCheckouts)) {
        echo "[" . date('Y-m-d H:i:s') . "] No impending checkouts to notify.\n";
        exit;
    }
    
    $updateStmt = $db->prepare("UPDATE bookings SET checkout_warning_sent = TRUE WHERE id = :id");
    
    foreach ($imminentCheckouts as $b) {
        $guestName = $b['guest_name'];
        $guestPhone = $b['guest_phone'];
        $roomNumber = $b['room_number'];
        $timeStr = date('g:i A', strtotime($b['check_out']));
        
        // WhatsApp to guest
        $message = "Hi $guestName, this is a friendly reminder from MicroPMS that check-out for Room $roomNumber is scheduled for $timeStr. If you need a late checkout, please contact the front desk. Thank you!";
        $sent = NotificationRelay::sendWhatsApp($guestPhone, $message);
        
        // Telegram to owner (pre-departure event)
        $tgMsg = "⏰ <b>Pre-Departure Reminder</b>\n\nRoom: {$roomNumber}\nGuest: " . htmlspecialchars($guestName) . "\nCheckout at: {$timeStr}";
        
        $context = [
            'guest_name' => $guestName,
            'room_number' => $roomNumber,
            'check_out_date' => $timeStr
        ];
        NotificationRelay::sendTelegram($tgMsg, 'pre_departure', $context);
        
        if ($sent) {
            echo "[" . date('Y-m-d H:i:s') . "] Sent warning to $guestName ($guestPhone) for Room $roomNumber.\n";
        } else {
            echo "[" . date('Y-m-d H:i:s') . "] FAILED sending warning to $guestName ($guestPhone) for Room $roomNumber.\n";
        }
        
        $updateStmt->execute(['id' => $b['id']]);
    }

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
}
