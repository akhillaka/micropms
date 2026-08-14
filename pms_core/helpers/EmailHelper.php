<?php
declare(strict_types=1);

class EmailHelper {
    public static function send(string $to, string $subject, string $body, bool $isHtml = false, ?string $from = null): bool {
        $to = self::sanitizeAddress($to);
        if ($to === '') {
            error_log('Email sending failed: invalid recipient');
            return false;
        }

        if (!$from) {
            $from = defined('SMTP_FROM') && SMTP_FROM !== ''
                ? SMTP_FROM
                : ('no-reply@' . preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'micropms.local')));
        }
        $from = self::sanitizeAddress($from);
        if ($from === '') {
            return false;
        }

        $subject = str_replace(["\r", "\n"], '', $subject);

        $headers = [];
        $headers[] = "From: {$from}";
        $headers[] = "Reply-To: {$from}";
        $headers[] = "X-Mailer: MicroPMS";
        if ($isHtml) {
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
        }
        $headerStr = implode("\r\n", $headers);

        $smtpHost = defined('SMTP_HOST') ? trim((string)SMTP_HOST) : '';
        if ($smtpHost !== '') {
            $ok = self::sendSmtp($to, $from, $subject, $body, $headerStr);
            if ($ok) {
                return true;
            }
            error_log('SMTP send failed; falling back to mail()');
        }

        try {
            return mail($to, $subject, $body, $headerStr);
        } catch (\Throwable $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }

    public static function sanitizeAddress(string $address): string {
        $address = trim(str_replace(["\r", "\n", ",", ";"], '', $address));
        if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return '';
        }
        return $address;
    }

    private static function sendSmtp(string $to, string $from, string $subject, string $body, string $headers): bool {
        $host = (string)SMTP_HOST;
        $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $user = defined('SMTP_USER') ? (string)SMTP_USER : '';
        $pass = defined('SMTP_PASS') ? (string)SMTP_PASS : '';
        $enc = defined('SMTP_ENCRYPTION') ? strtolower((string)SMTP_ENCRYPTION) : 'tls';

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$fp) {
            error_log("SMTP connect failed: {$errstr}");
            return false;
        }
        stream_set_timeout($fp, 15);
        $read = function () use ($fp) { return fgets($fp, 2048); };
        $write = function (string $line) use ($fp) { fwrite($fp, $line . "\r\n"); };

        $banner = $read();
        if ($banner === false || strpos($banner, '220') !== 0) {
            fclose($fp);
            return false;
        }
        $write('EHLO micropms');
        while (($line = $read()) !== false) {
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        if ($enc === 'tls') {
            $write('STARTTLS');
            $tls = $read();
            if ($tls === false || strpos($tls, '220') !== 0) {
                fclose($fp);
                return false;
            }
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return false;
            }
            $write('EHLO micropms');
            while (($line = $read()) !== false) {
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
        }
        if ($user !== '') {
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode($user));
            $read();
            $write(base64_encode($pass));
            $auth = $read();
            if ($auth === false || strpos($auth, '235') !== 0) {
                fclose($fp);
                return false;
            }
        }
        $write('MAIL FROM:<' . $from . '>');
        if (strpos((string)$read(), '250') !== 0) { fclose($fp); return false; }
        $write('RCPT TO:<' . $to . '>');
        if (strpos((string)$read(), '250') !== 0) { fclose($fp); return false; }
        $write('DATA');
        if (strpos((string)$read(), '354') !== 0) { fclose($fp); return false; }
        $write('Subject: ' . $subject);
        $write($headers);
        $write('');
        $write(str_replace("\n.", "\n..", $body));
        $write('.');
        $ok = strpos((string)$read(), '250') === 0;
        $write('QUIT');
        fclose($fp);
        return $ok;
    }
}
