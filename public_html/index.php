<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If this is the root directory, handle the root redirect logic
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($requestPath === '/' || $requestPath === '/index.php' || $requestPath === '') {
    if (isset($_SESSION['user_id'])) {
        header('Location: /admin/index.php');
    } else {
        header('Location: /login');
    }
    exit;
}

// For all other requests, pass control to the main router
require_once __DIR__ . '/router.php';
