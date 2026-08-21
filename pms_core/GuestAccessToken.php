<?php
declare(strict_types=1);

/**
 * Guest portal HMAC tokens (stay-scoped).
 * Legacy: hash_hmac(bookingId, INVOICE_SECRET) — still accepted.
 * V2: hash_hmac("{bookingId}|{propertyId}", INVOICE_SECRET) — emitted for new links.
 * Access ends after cancellation or 7 days past checkout.
 */
class GuestAccessToken {
    public static function getPortalUrl(string|int $bookingId, ?int $propertyId = null): string {
        require_once __DIR__ . '/ModuleHost.php';
        $bid = (int)$bookingId;
        $pid = $propertyId !== null ? (int)$propertyId : 0;
        if ($pid <= 0 && $bid > 0) {
            try {
                require_once __DIR__ . '/Database.php';
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare('SELECT property_id FROM bookings WHERE id = ? LIMIT 1');
                $stmt->execute([$bid]);
                $pid = (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {
                $pid = 0;
            }
        }
        $token = $pid > 0 ? self::generateForBooking($bid, $pid) : self::generate($bid);
        return ModuleHost::url('guest', '/guest-portal?id=' . $bid . '&token=' . $token);
    }

    /** Legacy token (booking id only). Prefer generateForBooking for new links. */
    public static function generate(string|int $bookingId): string {
        $secret = defined('INVOICE_SECRET') ? (string)INVOICE_SECRET : '';
        if ($secret === '') {
            throw new \RuntimeException('INVOICE_SECRET is not configured');
        }
        return hash_hmac('sha256', (string)$bookingId, $secret);
    }

    public static function generateForBooking(int $bookingId, int $propertyId): string {
        $secret = defined('INVOICE_SECRET') ? (string)INVOICE_SECRET : '';
        if ($secret === '') {
            throw new \RuntimeException('INVOICE_SECRET is not configured');
        }
        if ($bookingId <= 0 || $propertyId <= 0) {
            throw new \InvalidArgumentException('bookingId and propertyId are required');
        }
        return hash_hmac('sha256', $bookingId . '|' . $propertyId, $secret);
    }

    /**
     * Accept v2 (when propertyId known) or legacy tokens.
     */
    public static function verify(string|int $bookingId, string $token, ?int $propertyId = null): bool {
        if ($token === '' || (string)$bookingId === '') {
            return false;
        }
        if ($propertyId !== null && $propertyId > 0) {
            if (hash_equals(self::generateForBooking((int)$bookingId, $propertyId), $token)) {
                return true;
            }
        }
        return hash_equals(self::generate($bookingId), $token);
    }

    public static function assert(string|int $bookingId, string $token, bool $json = true, ?int $propertyId = null): void {
        $pid = $propertyId !== null ? (int)$propertyId : 0;
        if ($pid <= 0 && (int)$bookingId > 0) {
            try {
                require_once __DIR__ . '/Database.php';
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare('SELECT property_id FROM bookings WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$bookingId]);
                $pid = (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {
                $pid = 0;
            }
        }
        if (self::verify($bookingId, $token, $pid > 0 ? $pid : null)) {
            return;
        }
        if ($json) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Access Denied: Invalid security token']);
            exit;
        }
        http_response_code(403);
        die('Access Denied: Invalid secure token.');
    }

    public static function bookingIsAccessible(?array $booking): bool {
        if (!$booking) {
            return false;
        }
        if (($booking['booking_status'] ?? '') === 'cancelled') {
            return false;
        }
        $checkout = strtotime((string)($booking['check_out'] ?? ''));
        if ($checkout !== false && $checkout < strtotime('-7 days')) {
            return false;
        }
        return true;
    }

    public static function denyIfInaccessible(?array $booking, bool $json = true): void {
        if (self::bookingIsAccessible($booking)) {
            return;
        }
        if ($json) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'This stay link has expired or the reservation is no longer accessible',
            ]);
            exit;
        }
        http_response_code(403);
        die('This stay link has expired or the reservation is no longer accessible.');
    }
}
