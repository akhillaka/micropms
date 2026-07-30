<?php
declare(strict_types=1);

/**
 * InvoiceLink — Secure invoice link generation and validation.
 * 
 * Uses HMAC-SHA256 with a server-side secret key.
 * Links expire after 24 hours.
 * 
 * Format: {booking_id}:{timestamp}:{hmac}
 */
class InvoiceLink {
    private const EXPIRY_SECONDS = 86400; // 24 hours

    /**
     * Generate a secure invoice link token.
     * 
     * @param int $bookingId
     * @return string Token to append to invoice URL
     */
    public static function generate(int $bookingId): string {
        $timestamp = time();
        $payload = "{$bookingId}:{$timestamp}";
        $hmac = self::computeHmac($payload);
        
        return base64_encode("{$payload}:{$hmac}");
    }

    /**
     * Validate an invoice link token.
     * 
     * @param string $token Base64-encoded token from URL
     * @param int $expectedBookingId The booking ID from the URL query param
     * @return bool True if valid and not expired
     */
    public static function validate(string $token, int $expectedBookingId): bool {
        $decoded = base64_decode($token, true);
        if ($decoded === false) return false;

        $parts = explode(':', $decoded);
        if (count($parts) !== 3) return false;

        [$bookingId, $timestamp, $hmac] = $parts;

        // Verify booking ID matches
        if ((int)$bookingId !== $expectedBookingId) return false;

        // Verify timestamp is numeric
        if (!is_numeric($timestamp)) return false;

        // Verify HMAC
        $payload = "{$bookingId}:{$timestamp}";
        $expectedHmac = self::computeHmac($payload);
        
        if (!hash_equals($expectedHmac, $hmac)) return false;

        // Check expiry (24 hours)
        if ((time() - (int)$timestamp) > self::EXPIRY_SECONDS) return false;

        return true;
    }

    /**
     * Compute HMAC-SHA256 using the server secret.
     */
    private static function computeHmac(string $data): string {
        $secret = defined('INVOICE_SECRET') ? INVOICE_SECRET : 'fallback-secret-change-me';
        return hash_hmac('sha256', $data, $secret);
    }

    /**
     * Get the full invoice URL with secure token.
     * 
     * @param int $bookingId
     * @return string Full URL like https://host/guest_invoice.php?id=123&token=xxx
     */
    public static function getUrl(int $bookingId): string {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $token = self::generate($bookingId);
        
        return "{$protocol}://{$host}/guest_invoice.php?id={$bookingId}&token={$token}";
    }
}
