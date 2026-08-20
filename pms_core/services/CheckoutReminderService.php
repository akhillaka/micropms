<?php
declare(strict_types=1);

require_once __DIR__ . '/../NotificationRelay.php';
require_once __DIR__ . '/../PhoneHelper.php';

/**
 * Staff/guest checkout reminders: once at 30 minutes and once at 15 minutes.
 */
class CheckoutReminderService {

    public const WINDOWS = [30, 15];
    public const TOLERANCE_MINUTES = 2;

    public static function ensureTable(\PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS notification_milestones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                property_id INT NOT NULL,
                entity_type VARCHAR(32) NOT NULL DEFAULT 'booking',
                entity_id INT NOT NULL,
                milestone VARCHAR(64) NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_milestone (entity_type, entity_id, milestone),
                KEY idx_property (property_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public static function minutesUntil(string $checkOut, ?int $nowTs = null): int {
        $nowTs = $nowTs ?? time();
        $out = strtotime($checkOut);
        if ($out === false) {
            return PHP_INT_MAX;
        }
        return (int) round(($out - $nowTs) / 60);
    }

    /**
     * Returns 30 or 15 when remaining minutes fall in that window, else null.
     */
    public static function matchingWindow(int $minutesLeft): ?int {
        foreach (self::WINDOWS as $window) {
            if (abs($minutesLeft - $window) <= self::TOLERANCE_MINUTES) {
                return $window;
            }
        }
        return null;
    }

    public static function alertId(int $bookingId, int $window): string {
        return 'upcoming_checkout_' . $bookingId . '_' . $window;
    }

    /**
     * @return true if this process should send (first claim wins)
     */
    public static function claim(\PDO $db, int $propertyId, string $entityType, int $entityId, string $milestone): bool {
        self::ensureTable($db);
        try {
            $stmt = $db->prepare("
                INSERT INTO notification_milestones (property_id, entity_type, entity_id, milestone)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$propertyId, $entityType, $entityId, $milestone]);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Cron: send 30- and 15-minute checkout reminders once per stay.
     */
    public static function dispatchDue(\PDO $db): int {
        self::ensureTable($db);
        $sent = 0;
        $stmt = $db->query("
            SELECT b.id, b.property_id, b.check_out, r.room_number, g.name AS guest_name, g.phone AS guest_phone
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            LEFT JOIN guests g ON b.guest_id = g.id
            WHERE b.booking_status = 'checked_in'
              AND b.payment_status != 'cancelled'
              AND b.check_out > NOW()
              AND b.check_out <= DATE_ADD(NOW(), INTERVAL 32 MINUTE)
        ");
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            $minutes = self::minutesUntil((string)$row['check_out']);
            $window = self::matchingWindow($minutes);
            if ($window === null) {
                continue;
            }
            $bookingId = (int)$row['id'];
            $propertyId = (int)$row['property_id'];
            $milestone = 'checkout_' . $window . 'm';
            if (!self::claim($db, $propertyId, 'booking', $bookingId, $milestone)) {
                continue;
            }
            $room = (string)$row['room_number'];
            $guest = (string)($row['guest_name'] ?? 'Guest');
            $when = date('g:i A', strtotime((string)$row['check_out']));
            $title = $window === 30 ? 'Checkout in 30 minutes' : 'Checkout in 15 minutes';
            $message = "{$guest} (Room {$room}) is due at {$when}";
            NotificationRelay::sendInAppNotification(
                $propertyId,
                $title,
                $message,
                'checkout_soon',
                '/admin/folio?id=' . $bookingId
            );
            NotificationRelay::sendTelegram(
                "⏰ <b>{$title}</b>\n\nRoom: {$room}\nGuest: " . htmlspecialchars($guest) . "\nDue: {$when}",
                'pre_departure',
                [
                    'guest_name' => $guest,
                    'room_number' => $room,
                    'check_out_date' => $row['check_out'],
                ],
                $propertyId
            );
            $phoneE164 = PhoneHelper::toE164((string)($row['guest_phone'] ?? ''));
            if ($phoneE164) {
                NotificationRelay::triggerAutomation('pre_departure', $phoneE164, $bookingId, [
                    'checkout_time' => $when,
                ], $propertyId);
            }
            $sent++;
        }
        return $sent;
    }
}
