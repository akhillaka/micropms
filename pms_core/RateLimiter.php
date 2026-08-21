<?php
declare(strict_types=1);

/**
 * Simple durable rate limiter (file-backed) for guest/auth endpoints.
 * Keys are hashed; windows are sliding via timestamp lists.
 */
class RateLimiter {
    public static function allow(string $bucket, int $maxAttempts, int $windowSeconds): bool {
        $dir = sys_get_temp_dir() . '/micropms_rate';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/' . hash('sha256', $bucket) . '.json';
        $now = time();
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return true; // fail open if cannot lock file
        }
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $attempts = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $attempts = array_values(array_filter($decoded, static fn($t) => is_int($t) && ($now - $t) < $windowSeconds));
                }
            }
            if (count($attempts) >= $maxAttempts) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return false;
            }
            $attempts[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($attempts));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            try {
                flock($fp, LOCK_UN);
                fclose($fp);
            } catch (\Throwable $ignore) {
            }
            return true;
        }
    }

    public static function clientIp(): string {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        // Prefer REMOTE_ADDR only — do not trust X-Forwarded-For for lockout keys.
        return $ip;
    }
}
