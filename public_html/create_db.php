<?php
require_once __DIR__ . '/../pms_core/config.php';

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;
$socket = defined('DB_SOCKET') ? DB_SOCKET : (getenv('DB_SOCKET') ?: '');

try {
    if ($socket && file_exists($socket)) {
        $dsn = "mysql:unix_socket={$socket};charset=utf8mb4";
    } else {
        $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
        $dsn  = "mysql:host={$host};port={$port};charset=utf8mb4";
    }
    
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS pms_db");
    echo "Database created successfully!";
} catch (Exception $e) {
    echo "Failed: " . $e->getMessage();
}
