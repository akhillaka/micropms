<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../pms_core/AuthHelper.php';

// Auth checks - must be logged in as staff or guest with access
// For simplicity, we just check if user_id or guest_id is set
if (!isset($_SESSION['user_id']) && !isset($_SESSION['guest_id'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

$file = $_GET['file'] ?? '';
if (empty($file) || strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
    http_response_code(400);
    echo "Invalid file parameter";
    exit;
}

$uploadDir = realpath(__DIR__ . '/../../pms_core/uploads');
$filePath = $uploadDir . DIRECTORY_SEPARATOR . $file;

if (!file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    echo "File not found";
    exit;
}

// Basic mime type detection
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $filePath);
finfo_close($finfo);

// Force safe content types
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
// Add cache control if needed
header('Cache-Control: private, max-age=86400');

readfile($filePath);
exit;
