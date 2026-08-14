<?php
declare(strict_types=1);

/**
 * Guest portal HMAC tokens (stay-scoped).
 * Existing links are hash_hmac(bookingId, INVOICE_SECRET).
 * Access ends after cancellation or 7 days past checkout.
 */
class GuestAccessToken {
    public static function generate(string|int $bookingId): string {
        $secret = defined('INVOICE_SECRET') ? (string)INVOICE_SECRET : '';
        if ($secret === '') {
            throw new \RuntimeException('INVOICE_SECRET is not configured');
        }
        return hash_hmac('sha256', (string)$bookingId, $secret);
    }

    public static function verify(string|int $bookingId, string $token): bool {
        if ($token === '' || (string)$bookingId === '') {
            return false;
        }
        return hash_equals(self::generate($bookingId), $token);
    }

    public static function assert(string|int $bookingId, string $token, bool $json = true): void {
        if (self::verify($bookingId, $token)) {
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
