<?php
declare(strict_types=1);
require_once __DIR__ . '/../../pms_core/ModuleHost.php';
ModuleHost::startSession();
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
try {
    require_once __DIR__ . '/../../pms_core/Database.php';
    $db = Database::getInstance()->getConnection();
    AuthHelper::revokeRememberTokens($db, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['saas_admin_id']) ? (int)$_SESSION['saas_admin_id'] : null));
} catch (\Throwable $e) {
    AuthHelper::clearRememberCookie();
}
$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
header('Location: /saas-admin/login');
exit;
