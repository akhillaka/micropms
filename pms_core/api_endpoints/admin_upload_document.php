<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

AuthHelper::requireLogin();

if (!isset($_POST['doc_type'])) {
    echo json_encode(['success' => false, 'message' => 'Missing params']);
    exit;
}

CsrfToken::requireValid();

$bookingId = $_POST['booking_id'] ?? null;
$guestId = $_POST['guest_id'] ?? null;
$docType = $_POST['doc_type'];

if (!$bookingId && !$guestId) {
    echo json_encode(['success' => false, 'message' => 'booking_id or guest_id required']);
    exit;
}

$bookingDocMap = ['id_proof_front', 'id_proof_back', 'guest_photo'];
$guestDocMap = ['id_proof_front', 'id_proof_back', 'photo'];
if (!in_array($docType, array_merge($bookingDocMap, $guestDocMap))) {
    echo json_encode(['success' => false, 'message' => 'Invalid doc type']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = $_FILES['file']['error'] ?? 'no_file';
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
    ];
    $errorMsg = $errorMessages[$errorCode] ?? "Unknown error (code: $errorCode)";
    echo json_encode(['success' => false, 'message' => "File upload error: $errorMsg"]);
    exit;
}

$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'pdf'];
if (!in_array($ext, $allowed)) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);
    finfo_close($finfo);
    
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf'
    ];
    
    if (isset($mimeToExt[$mimeType])) {
        $ext = $mimeToExt[$mimeType];
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid file type: ' . $mimeType]);
        exit;
    }
}

$filename = uniqid($docType . '_') . '.' . $ext;
$uploadDir = realpath(__DIR__ . '/../uploads');
if (!$uploadDir || !is_writable($uploadDir)) {
    echo json_encode(['success' => false, 'message' => 'Upload directory not found or not writable: ' . __DIR__ . '/../uploads']);
    exit;
}
$dest = $uploadDir . '/' . $filename;

if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
        $maxDim = 1200;
        $quality = 80;
        
        $info = getimagesize($dest);
        $width = $info[0];
        $height = $info[1];
        $mime = $info['mime'];
        
        if ($width > $maxDim || $height > $maxDim) {
            $ratio = min($maxDim / $width, $maxDim / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);
            
            $src = match($mime) {
                'image/jpeg' => imagecreatefromjpeg($dest),
                'image/png' => imagecreatefrompng($dest),
                default => null
            };
            
            if ($src) {
                $dst = imagecreatetruecolor($newWidth, $newHeight);
                
                if ($mime === 'image/png') {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                }
                
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                
                if ($mime === 'image/jpeg') {
                    imagejpeg($dst, $dest, $quality);
                } else {
                    imagepng($dst, $dest, 6);
                }
                
                if (is_resource($src)) { imagedestroy($src); }
                if (is_resource($dst)) { imagedestroy($dst); }
            }
        } elseif ($mime === 'image/jpeg') {
            $src = imagecreatefromjpeg($dest);
            if ($src) {
                imagejpeg($src, $dest, $quality);
                if (is_resource($src)) { imagedestroy($src); }
            }
        }
    }
    
    $db = Database::getInstance()->getConnection();
    
    if ($bookingId) {
        $guestStmt = $db->prepare("SELECT guest_id FROM bookings WHERE id = :id");
        $guestStmt->execute(['id' => $bookingId]);
        $linkedGuestId = $guestStmt->fetchColumn();
        
        if ($linkedGuestId) {
            // Fix #9: use explicit allow-list map to prevent column-name injection
            $guestColMap = [
                'id_proof_front' => 'id_proof_front',
                'id_proof_back'  => 'id_proof_back',
                'guest_photo'    => 'photo',
                'photo'          => 'photo',
            ];
            $guestCol = $guestColMap[$docType] ?? null;
            if ($guestCol) {
                $syncStmt = $db->prepare("UPDATE guests SET `{$guestCol}` = :f WHERE id = :id");
                $syncStmt->execute(['f' => $filename, 'id' => $linkedGuestId]);
            }
        }
    }
    
    if ($guestId) {
        // Fix #9: use explicit allow-list map to prevent column-name injection
        $guestColMap = [
            'id_proof_front' => 'id_proof_front',
            'id_proof_back'  => 'id_proof_back',
            'guest_photo'    => 'photo',
            'photo'          => 'photo',
        ];
        $guestCol = $guestColMap[$docType] ?? null;
        if ($guestCol) {
            $stmt = $db->prepare("UPDATE guests SET `{$guestCol}` = :f WHERE id = :id");
            $stmt->execute(['f' => $filename, 'id' => $guestId]);
        }
    }
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'UPLOAD_DOCUMENT', $bookingId ? 'BOOKING' : 'SYSTEM', $bookingId ?: $guestId, [
        'doc_type' => $docType,
        'filename' => $filename,
        'guest_id' => $guestId
    ]);
    
    echo json_encode(['success' => true, 'filename' => $filename]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to move file']);
}
