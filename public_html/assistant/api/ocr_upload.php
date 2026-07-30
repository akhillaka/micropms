<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';

ApiHandler::run(function(\PDO $db) {
    // Session is checked by ApiHandler

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // Check if multipart/form-data or JSON upload
    $guestId = isset($_POST['guest_id']) ? (int)$_POST['guest_id'] : (isset($data['guest_id']) ? (int)$data['guest_id'] : 0);
    $idType = isset($_POST['id_type']) ? trim($_POST['id_type']) : (isset($data['id_type']) ? trim($data['id_type']) : ''); // id_proof_front, id_proof_back, photo
    
    if (!$guestId) {
        ApiResponse::error('Guest ID is required');
    }
    
    $validTypes = ['id_proof_front', 'id_proof_back', 'photo', 'guest_photo'];
    if (!in_array($idType, $validTypes, true)) {
        ApiResponse::error('Invalid upload document type');
    }
    
    // Normalize type to column name
    $dbCol = ($idType === 'guest_photo') ? 'photo' : $idType;

    $uploadDir = realpath(__DIR__ . '/../../uploads');
    if (!$uploadDir) {
        // Try creating it if missing
        $uploadDir = __DIR__ . '/../../uploads';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
    }
    
    if (!is_writable($uploadDir)) {
        ApiResponse::error('Server upload directory is not writable');
    }

    $savedFilename = '';

    // Case 1: Binary file upload via multipart/form-data
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $fileInfo = $_FILES['file'];
        $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
            ApiResponse::error('Unsupported file format. Use JPG, PNG, or PDF.');
        }

        $savedFilename = uniqid($idType . '_') . '.' . $ext;
        $dest = $uploadDir . '/' . $savedFilename;
        
        if (!move_uploaded_file($fileInfo['tmp_name'], $dest)) {
            ApiResponse::error('Failed to move uploaded file');
        }
    }
    
    // Case 2: Base64 data upload via JSON request (common in mobile camera canvas stream)
    elseif (!empty($data['image'])) {
        $base64Data = $data['image'];
        
        // Parse data URI: data:image/png;base64,iVBORw...
        if (preg_match('/^data:(image\/[a-z]+|application\/pdf);base64,(.*)$/i', $base64Data, $matches)) {
            $mimeType = $matches[1];
            $payload = base64_decode($matches[2]);
            
            $ext = 'jpg';
            if (strpos($mimeType, 'png') !== false) {
                $ext = 'png';
            } elseif (strpos($mimeType, 'pdf') !== false) {
                $ext = 'pdf';
            }
            
            $savedFilename = uniqid($idType . '_') . '.' . $ext;
            $dest = $uploadDir . '/' . $savedFilename;
            
            if (file_put_contents($dest, $payload) === false) {
                ApiResponse::error('Failed to write base64 image data to file');
            }
        } else {
            ApiResponse::error('Invalid base64 image format');
        }
    } 
    
    else {
        ApiResponse::error('No file or image data provided');
    }

    // Update guest profile in DB
    $stmt = $db->prepare("UPDATE guests SET `{$dbCol}` = :filename WHERE id = :id");
    $stmt->execute(['filename' => $savedFilename, 'id' => $guestId]);

    ApiResponse::success([
        'message' => 'Document uploaded and linked successfully',
        'filename' => $savedFilename,
        'url' => '/uploads/' . $savedFilename
    ]);

}, true, false, false);
