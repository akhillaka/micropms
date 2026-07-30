<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/config.php';
require_once __DIR__ . '/../../../pms_core/Database.php';
require_once __DIR__ . '/../../../pms_core/AuthHelper.php';

AuthHelper::requireLogin();

$filename = $_GET['file'] ?? '';

if (empty($filename) || strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
    die("Invalid file request.");
}

$uploadDir = __DIR__ . '/../../../../uploads/id_proofs/';
$filepath = $uploadDir . $filename;

if (!file_exists($filepath)) {
    die("File not found.");
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filepath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filepath));
// Do not force download, allow browser to display it
header('Content-Disposition: inline; filename="' . htmlspecialchars($filename) . '"');
header('Cache-Control: private, max-age=86400'); // Cache for 1 day securely

readfile($filepath);
exit;
