<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers/EmailHelper.php';

class EmailService {
    public static function send(string $to, string $subject, string $htmlBody, string $fromEmail = 'noreply@yourdomain.com', string $fromName = 'PMS System'): bool {
        $from = EmailHelper::sanitizeAddress($fromEmail);
        if ($from === '') {
            $from = null;
        }
        return EmailHelper::send($to, $subject, $htmlBody, true, $from);
    }
}
