<?php
declare(strict_types=1);

/**
 * Path-only hosting. Staff, guest, assistant, and SaaS share one host:
 * /login /admin /guest-login /assistant /saas-admin
 */
class ModuleHost {
    public const MODULES = ['guest', 'admin', 'assistant', 'saas'];

    public static function currentHost(): string {
        return self::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
    }

    public static function normalizeHost(string $host): string {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    public static function isLoopbackHost(string $host): bool {
        $host = self::normalizeHost($host);
        return $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    public static function isPreviewHost(string $host): bool {
        $host = self::normalizeHost($host);
        return $host === 'hostingersite.com' || str_ends_with($host, '.hostingersite.com');
    }

    public static function isPathMode(?string $host = null): bool {
        return true;
    }

    public static function baseDomain(?string $host = null): string {
        return '';
    }

    /**
     * @return 'path'|'apex'|'guest'|'admin'|'assistant'|'saas'
     */
    public static function detectModule(string $host, string $baseDomain = ''): string {
        return 'path';
    }

    public static function currentModule(): string {
        return 'path';
    }

    public static function applyHostPrefix(string $request, ?string $module = null): string {
        if ($request === '/index') {
            return '/';
        }
        return $request;
    }

    public static function isSharedPath(string $request): bool {
        foreach (['/api/', '/webhook', '/ical/', '/setup', '/css/', '/js/', '/uploads/', '/register', '/leads'] as $prefix) {
            if ($request === rtrim($prefix, '/') || str_starts_with($request, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Always a same-host path. Subdomains are not used.
     */
    public static function url(string $module, string $path = '/', ?string $host = null): string {
        if ($path === '') {
            $path = '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path;
    }

    /**
     * Staff URL on the admin module, keeping any current query string (e.g. hotelId).
     */
    public static function staffUrl(string $path, ?string $query = null): string {
        $url = self::url('admin', $path);
        $query = $query ?? (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($query === '') {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    public static function sessionCookieDomain(?string $module = null, ?string $baseDomain = null, ?string $host = null): string {
        return '';
    }

    public static function startSession(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $params = [
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::requestScheme() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $domain = self::sessionCookieDomain();
        if ($domain !== '') {
            $params['domain'] = $domain;
        }
        session_set_cookie_params($params);
        session_start();
        require_once __DIR__ . '/AuthHelper.php';
        AuthHelper::resumeRememberedSession();
    }

    public static function shouldKeepPhpInUrl(string $path): bool {
        $path = '/' . ltrim(strtolower($path), '/');
        if (str_starts_with($path, '/api/') || str_contains($path, '/api/')) {
            return true;
        }
        if (str_starts_with($path, '/webhook') || str_contains($path, 'webhook')) {
            return true;
        }
        if (str_starts_with($path, '/cron')) {
            return true;
        }
        return basename($path) === 'router.php';
    }

    public static function canonicalPublicPath(string $path): string {
        $path = '/' . trim($path, '/');
        if (str_ends_with(strtolower($path), '.php')) {
            $path = substr($path, 0, -4);
        }
        if ($path === '/index' || $path === '/') {
            return '/';
        }
        if (str_ends_with($path, '/index')) {
            $trimmed = substr($path, 0, -6);
            return $trimmed === '' ? '/' : $trimmed;
        }
        return $path;
    }

    public static function requestScheme(): string {
        $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded === 'https' || $forwarded === 'http') {
            return $forwarded;
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }
        return 'http';
    }

    public static function requestPortSuffix(?string $httpHost = null): string {
        $raw = (string)($httpHost ?? $_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/:(\d+)$/', $raw, $m)) {
            return '';
        }
        $port = $m[1];
        $scheme = self::requestScheme();
        if (($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443')) {
            return '';
        }
        return ':' . $port;
    }
}
