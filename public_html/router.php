<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Serve static assets directly if using PHP built-in development server
if (php_sapi_name() === 'cli-server') {
    $staticFile = __DIR__ . $requestPath;
    if (is_file($staticFile)) {
        return false;
    }
}

$request = '/' . trim($requestPath, '/');
if (str_ends_with(strtolower($request), '.php')) {
    $request = substr($request, 0, -4);
}

// ── API Router Interceptor ───────────────────────────────────────────────────
if (str_starts_with($request, '/api/')) {
    $apiRoutes = require __DIR__ . '/../pms_core/api_routes.php';
    $originalFile = array_search($request, $apiRoutes);
    if ($originalFile !== false) {
        require __DIR__ . '/../pms_core/api_endpoints/' . $originalFile;
        exit;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'API Endpoint Not Found']);
    exit;
}

// ── Setup Guard ─────────────────────────────────────────────────────────────
// If setup is not complete, redirect all non-setup requests to /setup
if (!str_starts_with($request, '/setup') && !str_starts_with($request, '/saas-admin')) {
    try {
        require_once __DIR__ . '/../pms_core/Database.php';
        $db = Database::getInstance()->getConnection();
        $setupDone = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'SETUP_COMPLETE'")->fetchColumn();
        if ($setupDone !== '1') {
            header('Location: /setup');
            exit;
        }
    } catch (\Throwable $e) {
        // DB not reachable — redirect to setup
        header('Location: /setup');
        exit;
    }
}

// Check hotelId override in URL query string (e.g. ?hotelId=30138)
if (isset($_GET['hotelId'])) {
    $targetId = (int)$_GET['hotelId'];
    if ($targetId > 0 && isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/../pms_core/Database.php';
        require_once __DIR__ . '/../pms_core/AuthHelper.php';
        $db = Database::getInstance()->getConnection();
        
        $isSuperAdmin = (($_SESSION['access_level'] ?? '') === 'superadmin' || ($_SESSION['role'] ?? '') === 'superadmin');
        $primaryPropId = (int)($_SESSION['primary_property_id'] ?? 0);
        
        $hasAccess = false;
        if ($isSuperAdmin || $primaryPropId === $targetId) {
            $hasAccess = true;
        } else {
            try {
                $stmt = $db->prepare("SELECT COUNT(*) FROM staff_properties WHERE staff_id = ? AND property_id = ?");
                $stmt->execute([$_SESSION['user_id'], $targetId]);
                $hasAccess = ((int)$stmt->fetchColumn() > 0);
            } catch (\PDOException $e) {}
        }

        if ($hasAccess) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            AuthHelper::setPropertyId($targetId);
        }
    }
}

// Router Mapping Table
switch ($request) {
    case '':
    case '/':
        header('Location: /login');
        exit;

    case '/login':
        require __DIR__ . '/admin/login.php';
        break;

    case '/setup':
        require __DIR__ . '/setup/index.php';
        break;

    case '/saas-admin':
    case '/saas-admin/login':
        require __DIR__ . '/saas-admin/login.php';
        break;

    case '/saas-admin/dashboard':
    case '/saas-admin/index':
        require __DIR__ . '/saas-admin/index.php';
        break;

    case '/saas-admin/logout':
        require __DIR__ . '/saas-admin/logout.php';
        break;

    case '/guest-search':
        require __DIR__ . '/guest_search.php';
        break;

    case '/guest-login':
        require __DIR__ . '/guest_login.php';
        break;

    case '/assistant':
    case '/assistant/':
    case '/assistant/index':
        require __DIR__ . '/assistant/index.html';
        break;

    case '/guest-portal':
        require __DIR__ . '/guest_portal.php';
        break;

    case '/group-dashboard':
        require __DIR__ . '/admin/group_dashboard.php';
        break;

    case '/admin':
    case '/admin/index':
    case '/property-dashboard':
    case '/property-configuration/index':
        require __DIR__ . '/admin/index.php';
        break;

    case '/logout':
    case '/admin/logout':
        require __DIR__ . '/admin/logout.php';
        break;

    case '/property-configuration/property-details':
        require __DIR__ . '/admin/settings.php';
        break;

    default:
        // Folio regex matcher: /folio/{booking_id}
        if (preg_match('#^/folio/([A-Za-z0-9_-]+)$#', $request, $matches)) {
            $_GET['id'] = $matches[1];
            require __DIR__ . '/admin/folio.php';
            break;
        }

        if (preg_match('#^/ical/([a-f0-9]{32})\.ics$#i', $request, $matches)) {
            $_GET['token'] = $matches[1];
            require __DIR__ . '/ical.php';
            break;
        }

        // Check if there is a matching file in root public_html (e.g. webhooks)
        $rootFile = __DIR__ . $request . '.php';
        if (file_exists($rootFile)) {
            require $rootFile;
            break;
        }

        // Check if there is a matching legacy file inside public_html/admin/
        $legacyFile = __DIR__ . '/admin' . $request . '.php';
        if (file_exists($legacyFile)) {
            require $legacyFile;
            break;
        }

        // 404 response
        http_response_code(404);
        echo "404 - Page Not Found (" . htmlspecialchars((string)($request)) . ")";
        break;
}
