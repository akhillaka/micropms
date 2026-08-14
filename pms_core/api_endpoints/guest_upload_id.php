<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method.");
    }

    $bookingId = $_POST['booking_id'] ?? '';
    $token = $_POST['token'] ?? '';
    
    if (empty($bookingId) || empty($token)) {
        throw new Exception("Missing parameters.");
    }

    $computedToken = hash_hmac('sha256', (string)$bookingId, INVOICE_SECRET);
    if (!hash_equals($computedToken, $token)) {
        throw new Exception("Access Denied: Invalid secure token.");
    }

    $db = Database::getInstance()->getConnection();

    // Verify booking
    $bStmt = $db->prepare("SELECT id, property_id, guest_id, booking_status FROM bookings WHERE id = ?");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Reservation not found.");
    }

    $uploadDir = __DIR__ . '/../../public_html/uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $uploadedFiles = [];
    $guestId = $booking['guest_id'];
    
    // Check if files were uploaded
    if (!empty($_FILES['id_file']['tmp_name'])) {
        $fileTmp = $_FILES['id_file']['tmp_name'];
        $fileName = preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($_FILES['id_file']['name']));
        $uniqueName = 'guest_' . $guestId . '_id_' . time() . '_' . $fileName;
        $destPath = $uploadDir . $uniqueName;

        $docType = $_POST['document_type'] ?? 'id_proof';
        if (!in_array($docType, ['id_proof_front', 'id_proof_back', 'id_proof'])) {
            $docType = 'id_proof';
        }

        if (move_uploaded_file($fileTmp, $destPath)) {
            // Check if document record already exists for this guest
            $chkStmt = $db->prepare("SELECT id FROM guest_documents WHERE guest_id = ? AND document_type = ?");
            $chkStmt->execute([$guestId, $docType]);
            $existing = $chkStmt->fetchColumn();

            if ($existing) {
                // Update
                $updStmt = $db->prepare("UPDATE guest_documents SET file_path = ?, uploaded_at = NOW() WHERE id = ?");
                $updStmt->execute(['uploads/' . $uniqueName, $existing]);
            } else {
                // Insert new
                $docStmt = $db->prepare("INSERT INTO guest_documents (guest_id, document_type, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
                $docStmt->execute([$guestId, $docType, 'uploads/' . $uniqueName]);
            }
            $uploadedFiles[] = 'uploads/' . $uniqueName;
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
