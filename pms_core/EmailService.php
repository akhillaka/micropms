<?php
declare(strict_types=1);

class EmailService {

    /**
     * Send an HTML email using standard PHP mail()
     * Requires the server to have sendmail or Postfix configured.
     */
    public static function send(string $to, string $subject, string $htmlBody, string $fromEmail = 'noreply@yourdomain.com', string $fromName = 'PMS System'): bool {
        
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Standard mail function
        return @mail($to, $subject, $htmlBody, $headers);
    }
}
