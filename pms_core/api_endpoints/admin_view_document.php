<?php
declare(strict_types=1);

ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../AuthHelper.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['guest_id'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

require_once __DIR__ . '/../config.php';

$file = basename(str_replace('\\', '/', (string)($_GET['file'] ?? '')));
if (!pms_is_safe_upload_filename($file)) {
    http_response_code(400);
    echo "Invalid file parameter";
    exit;
}

require_once __DIR__ . '/../Database.php';
$db = Database::getInstance()->getConnection();

$prefixed = 'uploads/' . $file;
$stmt = $db->prepare(
    "SELECT id, property_id FROM guests
     WHERE id_proof_front IN (:f1, :p1)
        OR id_proof_back IN (:f2, :p2)
        OR photo IN (:f3, :p3)
     LIMIT 1"
);
$stmt->execute([
    'f1' => $file, 'p1' => $prefixed,
    'f2' => $file, 'p2' => $prefixed,
    'f3' => $file, 'p3' => $prefixed,
]);
$fileRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileRow) {
    $docStmt = $db->prepare(
        "SELECT g.id, g.property_id
         FROM guest_documents d
         JOIN guests g ON g.id = d.guest_id
         WHERE d.file_path IN (:f1, :p1) OR d.file_path LIKE :like
         LIMIT 1"
    );
    $docStmt->execute(['f1' => $file, 'p1' => $prefixed, 'like' => '%/' . $file]);
    $fileRow = $docStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$fileRow) {
    http_response_code(404);
    echo "File not found";
    exit;
}

if (isset($_SESSION['user_id'])) {
    try {
        $userPropId = AuthHelper::getPropertyId();
    } catch (\Throwable $e) {
        http_response_code(403);
        echo "Unauthorized";
        exit;
    }
    if ((int)$fileRow['property_id'] !== $userPropId) {
        http_response_code(403);
        echo "Unauthorized: Document belongs to a different property.";
        exit;
    }
} elseif (isset($_SESSION['guest_id']) && (int)$_SESSION['guest_id'] !== (int)$fileRow['id']) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

$searchDirs = [
    realpath(__DIR__ . '/../uploads'),
    realpath(__DIR__ . '/../../public_html/uploads'),
];
$filePath = '';
foreach ($searchDirs as $dir) {
    if ($dir === false) {
        continue;
    }
    $candidate = $dir . DIRECTORY_SEPARATOR . $file;
    $real = realpath($candidate);
    if ($real !== false && is_readable($real) && str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
        $filePath = $real;
        break;
    }
}

if ($filePath === '') {
    http_response_code(404);
    echo "File not found";
    exit;
}

$mime = (new \finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'application/octet-stream';
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
if (!in_array($mime, $allowed, true)) {
    $mime = 'application/octet-stream';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
