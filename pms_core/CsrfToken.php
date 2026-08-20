<?php
declare(strict_types=1);


class CsrfToken {
    private const TOKEN_NAME    = 'csrf_token';
    private const TOKEN_LENGTH  = 32;
    private const TIMEOUT_KEY   = 'last_activity';
    private const TIMEOUT_SECONDS = 1800; // 30 minutes

    /** Cache for php://input so it can only be read once per request */
    private static ?string $inputBodyCache = null;

    /** Read php://input exactly once and return the cached result */
    private static function getInputBody(): string {
        if (self::$inputBodyCache === null) {
            self::$inputBodyCache = (string)file_get_contents('php://input');
        }
        return self::$inputBodyCache;
    }

    private static function hydrateSession(bool $forWrite = false): void {
        if ($forWrite) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!empty($_SESSION[self::TOKEN_NAME]) || !empty($_SESSION['user_id'])) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function generate(): string {
        self::hydrateSession(!empty($_SESSION[self::TOKEN_NAME]) ? false : true);
        if (!empty($_SESSION[self::TOKEN_NAME])) {
            return $_SESSION[self::TOKEN_NAME];
        }
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_NAME] = $token;
        return $token;
    }

    public static function field(): string {
        $token = self::generate();
        return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function meta(): string {
        $token = self::generate();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token = null): bool {
        self::hydrateSession(false);

        if (empty($_SESSION[self::TOKEN_NAME])) {
            return false;
        }
        
        $sessionToken = $_SESSION[self::TOKEN_NAME];
        $candidates = [];

        if ($token !== null) {
            $candidates[] = $token;
        }

        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $headerTokens = explode(',', $_SERVER['HTTP_X_CSRF_TOKEN']);
            foreach ($headerTokens as $t) {
                $candidates[] = trim($t);
            }
        }

        if (!empty($_POST['_csrf_token'])) {
            $candidates[] = $_POST['_csrf_token'];
        }

        $body = self::getInputBody(); // FIX: use cached body — php://input cannot be read twice
        if ($body) {
            $json = json_decode($body, true);
            if (is_array($json) && !empty($json['_csrf_token'])) {
                $candidates[] = $json['_csrf_token'];
            }
        }

        foreach ($candidates as $candidate) {
            if (hash_equals($sessionToken, (string)$candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function getJsonPayload(): array {
        $json = json_decode(self::getInputBody(), true);
        return is_array($json) ? $json : [];
    }

    public static function requireValid(): void {
        if (!self::validate()) {
            http_response_code(403);
            header('Content-Type: application/json');
            
            // Fix #10: Never expose CSRF tokens or raw request bodies in production.
            // Debug details are only included in development mode.
            $isDev = (getenv('APP_ENV') === 'development' || getenv('APP_ENV') === 'local');
            $debugInfo = [];
            if ($isDev) {
                $tokenFromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
                $tokenFromPost   = $_POST['_csrf_token'] ?? null;
                $body = self::getInputBody(); // FIX: use cached body
                $tokenFromJson   = null;
                if ($body) {
                    $json = json_decode($body, true);
                    if (is_array($json) && isset($json['_csrf_token'])) {
                        $tokenFromJson = $json['_csrf_token'];
                    }
                }
                $debugInfo = [
                    'header_token_present' => !empty($tokenFromHeader),
                    'post_token_present'   => !empty($tokenFromPost),
                    'json_token_present'   => !empty($tokenFromJson),
                ];
            }
            
            $payload = ['success' => false, 'message' => 'Invalid CSRF token'];
            if ($isDev) {
                $payload['debug'] = $debugInfo;
            }
            echo json_encode($payload, JSON_THROW_ON_ERROR);
            exit;
        }
    }

    public static function checkTimeout(): void {
        self::hydrateSession(true);
        if (isset($_SESSION[self::TIMEOUT_KEY])) {
            $inactive = time() - (int)$_SESSION[self::TIMEOUT_KEY];
            if ($inactive > self::TIMEOUT_SECONDS) {
                session_unset();
                session_destroy();
                header('Location: /login');
                exit;
            }
        }
        $_SESSION[self::TIMEOUT_KEY] = time();
    }
}
