<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * ErrorTracker — Structured error logging with business context.
 *
 * Every API exception is automatically logged here via ApiHandler.
 * Critical errors also fire an instant Telegram alert to the owner.
 *
 * Categories: payment | whatsapp | database | auth | booking | system
 * Severities:  info | warning | error | critical
 */
class ErrorTracker {

    /**
     * Log a structured error entry to the error_logs table.
     *
     * @param string $severity  'info'|'warning'|'error'|'critical'
     * @param string $category  'payment'|'whatsapp'|'database'|'auth'|'booking'|'system'
     * @param string $message   Human-readable description
     * @param array  $context   Business context: booking_id, guest_name, amount, ref, etc.
     * @return int              Inserted error_log ID (0 on failure)
     */
    public static function log(
        string $severity,
        string $category,
        string $message,
        array  $context = []
    ): int {
        // Enrich context with request metadata
        $context['_uri']    = $_SERVER['REQUEST_URI']  ?? null;
        $context['_method'] = $_SERVER['REQUEST_METHOD'] ?? null;
        $context['_ip']     = self::getClientIp();
        $context['_staff']  = $_SESSION['user_id'] ?? null;

        try {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "INSERT INTO error_logs
                    (severity, category, message, context, staff_id, request_uri, ip_address)
                 VALUES
                    (:sev, :cat, :msg, :ctx, :sid, :uri, :ip)"
            );
            $stmt->execute([
                'sev' => $severity,
                'cat' => $category,
                'msg' => $message,
                'ctx' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sid' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
                'uri' => $_SERVER['REQUEST_URI'] ?? null,
                'ip'  => self::getClientIp(),
            ]);
            return (int)$db->lastInsertId();
        } catch (\Throwable $e) {
            // If the DB is down, fall back to PHP error_log — never throw from here
            error_log("[ErrorTracker] Failed to write to error_logs: " . $e->getMessage());
            error_log("[ErrorTracker] Original: [$severity][$category] $message");
            return 0;
        }
    }

    /**
     * Log a critical error AND immediately send a Telegram alert to the owner.
     */
    public static function critical(
        string $category,
        string $message,
        array  $context = []
    ): void {
        $errorId = self::log('critical', $category, $message, $context);

        // Build Telegram alert
        $icons = [
            'payment'   => '💳',
            'whatsapp'  => '📱',
            'database'  => '🗄️',
            'auth'      => '🔐',
            'booking'   => '🛏️',
            'system'    => '⚙️',
        ];
        $icon = $icons[$category] ?? '🚨';

        $tgLines = [
            "🚨 <b>CRITICAL ERROR</b>",
            "",
            "{$icon} <b>Category:</b> " . ucfirst($category),
            "<b>Message:</b> " . htmlspecialchars($message),
        ];

        // Add business context lines (booking, guest, amount, IP)
        if (!empty($context['booking_id'])) {
            $tgLines[] = "<b>Booking:</b> #" . $context['booking_id'];
        }
        if (!empty($context['guest_name'])) {
            $tgLines[] = "<b>Guest:</b> " . htmlspecialchars((string)$context['guest_name']);
        }
        if (!empty($context['amount'])) {
            $tgLines[] = "<b>Amount:</b> ₹" . $context['amount'];
        }
        if (!empty($context['username'])) {
            $tgLines[] = "<b>Username:</b> " . htmlspecialchars((string)$context['username']);
        }
        if (!empty($context['_ip'])) {
            $tgLines[] = "<b>IP:</b> " . $context['_ip'];
        }
        if (!empty($context['_uri'])) {
            $tgLines[] = "<b>Endpoint:</b> " . htmlspecialchars((string)$context['_uri']);
        }

        $tgLines[] = "";
        $tgLines[] = "<b>Time:</b> " . date('d M Y H:i:s');

        if ($errorId > 0) {
            $tgLines[] = "<b>Error Log ID:</b> #" . $errorId;
        }

        $tgMsg = implode("\n", $tgLines);

        // Send via NotificationRelay (already handles token lookup)
        try {
            if (!class_exists('NotificationRelay')) {
                $relay = __DIR__ . '/NotificationRelay.php';
                if (file_exists($relay)) {
                    require_once $relay;
                }
            }
            if (class_exists('NotificationRelay')) {
                NotificationRelay::sendTelegram($tgMsg);
            }
        } catch (\Throwable $e) {
            error_log("[ErrorTracker] Telegram alert failed: " . $e->getMessage());
        }
    }

    /**
     * Classify and log a Throwable from an API endpoint catch block.
     * Called automatically by ApiHandler::run().
     */
    public static function fromException(\Throwable $e, string $category = 'system'): void {
        // Classify severity
        if ($e instanceof \PDOException) {
            $severity = 'critical';
            $category = 'database';
        } elseif (is_numeric($e->getCode()) && (int)$e->getCode() >= 400 && (int)$e->getCode() < 500) {
            $severity = 'warning'; // 4xx = client/validation errors, not our bug
        } else {
            $severity = 'error';
        }

        // Sanitize context — never log passwords, tokens, raw credentials
        $rawBody = '';
        try {
            if (class_exists('CsrfToken') && method_exists('CsrfToken', 'getInputBody')) {
                // Reflection to access cached input body if private or via file_get_contents fallback
                $ref = new \ReflectionClass('CsrfToken');
                if ($ref->hasMethod('getInputBody')) {
                    $method = $ref->getMethod('getInputBody');
                    if (PHP_VERSION_ID < 80100) {
                        $method->setAccessible(true);
                    }
                    $rawBody = (string)$method->invoke(null);
                }
            }
            if (empty($rawBody)) {
                $rawBody = (string)file_get_contents('php://input');
            }
        } catch (\Throwable) {}

        $bodyData = [];
        if (!empty($rawBody)) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                // Strip sensitive keys
                $sensitiveKeys = ['password', 'password_hash', 'token', 'secret', 'key', '_csrf_token', 'api_key'];
                foreach ($sensitiveKeys as $sk) {
                    unset($decoded[$sk]);
                }
                $bodyData = $decoded;
            }
        }

        $context = [
            'exception_class' => get_class($e),
            'file'            => $e->getFile() . ':' . $e->getLine(),
            'request_body'    => $bodyData ?: null,
        ];

        $message = $e->getMessage();

        if ($severity === 'critical') {
            self::critical($category, $message, $context);
        } else {
            self::log($severity, $category, $message, $context);
        }
    }

    /**
     * Mark an error log entry as resolved.
     */
    public static function resolve(int $errorId, int $staffId, ?int $propertyId = null): bool {
        try {
            $db   = Database::getInstance()->getConnection();
            $pid = $propertyId;
            if ($pid === null && class_exists('AuthHelper') && !empty($_SESSION['property_id'])) {
                try {
                    $pid = AuthHelper::getPropertyId();
                } catch (\Throwable $e) {
                    $pid = null;
                }
            }
            if ($pid !== null && $pid > 0) {
                $stmt = $db->prepare(
                    "UPDATE error_logs
                        SET resolved = 1, resolved_at = NOW(), resolved_by = :sid
                      WHERE id = :id AND resolved = 0 AND property_id = :pid"
                );
                $stmt->execute(['sid' => $staffId, 'id' => $errorId, 'pid' => $pid]);
            } else {
                $stmt = $db->prepare(
                    "UPDATE error_logs
                        SET resolved = 1, resolved_at = NOW(), resolved_by = :sid
                      WHERE id = :id AND resolved = 0"
                );
                $stmt->execute(['sid' => $staffId, 'id' => $errorId]);
            }
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log("[ErrorTracker] resolve() failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Bulk-resolve all unresolved errors in a category.
     */
    public static function bulkResolve(string $category, int $staffId, ?int $propertyId = null): int {
        try {
            $db   = Database::getInstance()->getConnection();
            $pid = $propertyId;
            if ($pid === null && class_exists('AuthHelper') && !empty($_SESSION['property_id'])) {
                try {
                    $pid = AuthHelper::getPropertyId();
                } catch (\Throwable $e) {
                    $pid = null;
                }
            }
            if ($pid !== null && $pid > 0) {
                $stmt = $db->prepare(
                    "UPDATE error_logs
                        SET resolved = 1, resolved_at = NOW(), resolved_by = :sid
                      WHERE category = :cat AND resolved = 0 AND property_id = :pid"
                );
                $stmt->execute(['sid' => $staffId, 'cat' => $category, 'pid' => $pid]);
            } else {
                $stmt = $db->prepare(
                    "UPDATE error_logs
                        SET resolved = 1, resolved_at = NOW(), resolved_by = :sid
                      WHERE category = :cat AND resolved = 0"
                );
                $stmt->execute(['sid' => $staffId, 'cat' => $category]);
            }
            return (int)$stmt->rowCount();
        } catch (\Throwable $e) {
            error_log("[ErrorTracker] bulkResolve() failed: " . $e->getMessage());
            return 0;
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private static function getClientIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                // X-Forwarded-For can be a comma-separated list
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
