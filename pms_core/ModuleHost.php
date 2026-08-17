<?php
declare(strict_types=1);

/**
 * Maps Hostinger module hostnames onto app surfaces.
 * Loopback (localhost / 127.0.0.1) stays path-based: /admin, /assistant, /saas-admin.
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

    public static function isPathMode(?string $host = null): bool {
        return self::isLoopbackHost($host ?? self::currentHost());
    }

    public static function baseDomain(?string $host = null): string {
        $env = strtolower(trim((string)(getenv('APP_BASE_DOMAIN') ?: ($_ENV['APP_BASE_DOMAIN'] ?? ''))));
        if ($env !== '') {
            return self::normalizeHost($env);
        }
        $host = self::normalizeHost($host ?? self::currentHost());
        if ($host === '' || self::isLoopbackHost($host)) {
            return '';
        }
        $parts = explode('.', $host);
        if (isset($parts[0]) && in_array($parts[0], self::MODULES, true) && count($parts) >= 2) {
            return implode('.', array_slice($parts, 1));
        }
        return $host;
    }

    /**
     * @return 'path'|'apex'|'guest'|'admin'|'assistant'|'saas'
     */
    public static function detectModule(string $host, string $baseDomain = ''): string {
        $host = self::normalizeHost($host);
        if (self::isLoopbackHost($host)) {
            return 'path';
        }
        $baseDomain = $baseDomain !== '' ? self::normalizeHost($baseDomain) : self::baseDomain($host);
        if ($baseDomain !== '' && $host === $baseDomain) {
            return 'apex';
        }
        $parts = explode('.', $host);
        $first = $parts[0] ?? '';
        if (in_array($first, self::MODULES, true)) {
            if ($baseDomain === '' || $host === $first . '.' . $baseDomain || str_ends_with($host, '.' . $baseDomain)) {
                return $first;
            }
        }
        return 'apex';
    }

    public static function currentModule(): string {
        return self::detectModule(self::currentHost(), self::baseDomain());
    }

    public static function applyHostPrefix(string $request, ?string $module = null): string {
        $module = $module ?? self::currentModule();
        if ($request === '/index') {
            $request = '/';
        }
        if ($module === 'path' || $module === 'apex') {
            return $request;
        }
        if (self::isSharedPath($request)) {
            return $request;
        }
        if ($module === 'guest') {
            if ($request === '/' || $request === '/login' || $request === '/admin') {
                return '/guest-login';
            }
            return $request;
        }
        if ($module === 'admin') {
            if ($request === '/') {
                return '/admin';
            }
            return $request;
        }
        if ($module === 'assistant') {
            if ($request === '/' || $request === '/login') {
                return '/assistant';
            }
            return $request;
        }
        if ($module === 'saas') {
            if ($request === '/' || $request === '/login') {
                return '/saas-admin';
            }
            return $request;
        }
        return $request;
    }

    public static function isSharedPath(string $request): bool {
        foreach (['/api/', '/webhook', '/ical/', '/setup', '/css/', '/js/', '/uploads/', '/register'] as $prefix) {
            if ($request === rtrim($prefix, '/') || str_starts_with($request, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Absolute URL for a module, or a relative path on localhost.
     */
    public static function url(string $module, string $path = '/', ?string $host = null): string {
        if ($path === '') {
            $path = '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $rawHost = $host ?? (string)($_SERVER['HTTP_HOST'] ?? '');
        $host = $host ?? self::currentHost();
        if (self::isPathMode($host)) {
            return $path;
        }
        $base = self::baseDomain($host);
        if ($base === '') {
            return $path;
        }
        $scheme = self::requestScheme();
        $port = self::requestPortSuffix($rawHost);
        if ($module === 'apex' || $module === 'path') {
            return $scheme . '://' . $base . $port . $path;
        }
        if (!in_array($module, self::MODULES, true)) {
            return $scheme . '://' . $base . $port . $path;
        }
        return $scheme . '://' . $module . '.' . $base . $port . $path;
    }

    public static function sessionCookieDomain(?string $module = null, ?string $baseDomain = null, ?string $host = null): string {
        $host = self::normalizeHost($host ?? self::currentHost());
        if (self::isLoopbackHost($host)) {
            return '';
        }
        $module = $module ?? self::detectModule($host, $baseDomain ?? self::baseDomain($host));
        if ($module === 'guest' || $module === 'path') {
            return '';
        }
        $baseDomain = $baseDomain ?? self::baseDomain($host);
        if ($baseDomain === '' || !str_contains($baseDomain, '.')) {
            return '';
        }
        return '.' . $baseDomain;
    }

    public static function startSession(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $params = [
            'lifetime' => 86400,
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
