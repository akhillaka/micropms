<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';

try {
    $db = Database::getInstance()->getConnection();
    echo "Connected successfully to " . DB_NAME;
} catch (Throwable $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
    echo "Socket used: " . (defined('DB_SOCKET') ? DB_SOCKET : 'Not defined') . "\n";
    echo "Host used: " . (defined('DB_HOST') ? DB_HOST : 'Not defined') . "\n";
}
