<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method.");
    }

    $bookingId = $_POST['booking_id'] ?? '';
    $token = $_POST['token'] ?? '';
    
    if (empty($bookingId) || empty($token)) {
        throw new Exception("Missing parameters.");
    }

    GuestAccessToken::assert($bookingId, $token);

    $db = Database::getInstance()->getConnection();

    // Verify booking
    $bStmt = $db->prepare("SELECT id, property_id, guest_id, booking_status, check_out FROM bookings WHERE id = ?");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Reservation not found.");
    }
    GuestAccessToken::denyIfInaccessible($booking);

    $uploadDir = realpath(__DIR__ . '/../uploads');
    if (!$uploadDir) {
        $uploadDir = __DIR__ . '/../uploads';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new Exception('Upload directory is not writable');
        }
    }

    $uploadedFiles = [];
    $guestId = $booking['guest_id'];
    
    if (!empty($_FILES['id_file']['tmp_name'])) {
        $fileTmp = $_FILES['id_file']['tmp_name'];
        $ext = strtolower(pathinfo((string)($_FILES['id_file']['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true)) {
            throw new Exception('Unsupported file format. Use JPG, PNG, or PDF.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($fileTmp);
        $allowedMimes = [
            'image/jpeg' => true,
            'image/png' => true,
            'application/pdf' => true,
        ];
        if (!isset($allowedMimes[$mime])) {
            throw new Exception('File content type is not allowed.');
        }
        if ($mime === 'image/jpeg' && !in_array($ext, ['jpg', 'jpeg'], true)) {
            $ext = 'jpg';
        } elseif ($mime === 'image/png') {
            $ext = 'png';
        } elseif ($mime === 'application/pdf') {
            $ext = 'pdf';
        }

        $docType = $_POST['document_type'] ?? 'id_proof';
        if (!in_array($docType, ['id_proof_front', 'id_proof_back', 'id_proof'], true)) {
            $docType = 'id_proof';
        }

        $savedFilename = uniqid($docType . '_') . '.' . $ext;
        $destPath = rtrim($uploadDir, '/') . '/' . $savedFilename;

        if (move_uploaded_file($fileTmp, $destPath)) {
            $guestCol = $docType === 'id_proof_back' ? 'id_proof_back' : 'id_proof_front';
            $gUpd = $db->prepare("UPDATE guests SET `{$guestCol}` = ? WHERE id = ? AND property_id = ?");
            $gUpd->execute([$savedFilename, $guestId, (int)$booking['property_id']]);

            try {
                $chkStmt = $db->prepare("SELECT id FROM guest_documents WHERE guest_id = ? AND document_type = ?");
                $chkStmt->execute([$guestId, $docType]);
                $existing = $chkStmt->fetchColumn();

                if ($existing) {
                    $updStmt = $db->prepare("UPDATE guest_documents SET file_path = ?, uploaded_at = NOW() WHERE id = ?");
                    $updStmt->execute([$savedFilename, $existing]);
                } else {
                    $docStmt = $db->prepare("INSERT INTO guest_documents (guest_id, document_type, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
                    $docStmt->execute([$guestId, $docType, $savedFilename]);
                }
            } catch (\PDOException $e) {
                // guests columns already hold the file; guest_documents is optional history
            }
            $uploadedFiles[] = $savedFilename;
        } else {
            throw new Exception("Failed to upload ID document.");
        }
    } else {
        throw new Exception("No file was uploaded.");
    }

    AuditLogger::log(null, 'UPLOAD_DOC', 'GUEST', $guestId, ['booking_id' => $bookingId, 'message' => 'Guest uploaded ID proof via portal']);

    echo json_encode(['success' => true, 'message' => 'ID Proof uploaded successfully.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
