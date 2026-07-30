<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$user = 'root';
$pass = '';

echo "Testing connections...\n\n";

// 1. Try TCP
try {
    $dsnTCP = "mysql:host=127.0.0.1;port=3306;charset=utf8mb4";
    $pdo1 = new PDO($dsnTCP, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✅ TCP (127.0.0.1:3306) SUCCESS!\n";
} catch (Exception $e) {
    echo "❌ TCP FAILED: " . $e->getMessage() . "\n";
}

// 2. Try Standard XAMPP Mac Socket
try {
    $sock1 = "/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock";
    $dsnSock1 = "mysql:unix_socket={$sock1};charset=utf8mb4";
    $pdo2 = new PDO($dsnSock1, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✅ SOCKET ($sock1) SUCCESS!\n";
} catch (Exception $e) {
    echo "❌ SOCKET 1 FAILED: " . $e->getMessage() . "\n";
}

// 3. Try tmp mysql.sock
try {
    $sock2 = "/tmp/mysql.sock";
    $dsnSock2 = "mysql:unix_socket={$sock2};charset=utf8mb4";
    $pdo3 = new PDO($dsnSock2, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✅ SOCKET ($sock2) SUCCESS!\n";
} catch (Exception $e) {
    echo "❌ SOCKET 2 FAILED: " . $e->getMessage() . "\n";
}

// 4. Try localhost
try {
    $dsnLocal = "mysql:host=localhost;charset=utf8mb4";
    $pdo4 = new PDO($dsnLocal, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✅ LOCALHOST SUCCESS!\n";
} catch (Exception $e) {
    echo "❌ LOCALHOST FAILED: " . $e->getMessage() . "\n";
}
