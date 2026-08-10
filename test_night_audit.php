<?php
session_start();
$_SESSION['user_id'] = 2; // Assuming staff
$_SESSION['property_id'] = 1;
$_SESSION['role'] = 'admin';

// Spoof request
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/admin/night_audit?action=settings';
$_GET['action'] = 'settings';

try {
    require_once __DIR__ . '/pms_core/api_endpoints/admin_night_audit.php';
} catch (Throwable $e) {
    echo "FATAL THROWABLE: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
