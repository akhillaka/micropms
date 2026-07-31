<?php
declare(strict_types=1);

class EmailHelper {
    /**
     * Send a plain text or HTML email using PHP's mail() function.
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email content
     * @param bool $isHtml Whether the body is HTML
     * @param string|null $from Sender email address (defaults to a generic no-reply if not provided)
     * @return bool True if mail() successfully accepted the email for delivery
     */
    public static function send(string $to, string $subject, string $body, bool $isHtml = false, ?string $from = null): bool {
        if (!$from) {
            $from = "no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'micropms.local');
        }

        $headers = [];
        $headers[] = "From: {$from}";
        $headers[] = "Reply-To: {$from}";
        $headers[] = "X-Mailer: PHP/" . phpversion();

        if ($isHtml) {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
        }

        $headerStr = implode("\r\n", $headers);

        try {
            return mail($to, $subject, $body, $headerStr);
        } catch (\Throwable $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }
}
