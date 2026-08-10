<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas-admin/login');
    exit;
}
$_SESSION = [];
session_destroy();
header('Location: /saas-admin/login');
exit;
