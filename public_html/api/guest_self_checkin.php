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

    $db = Database::getInstance()->getConnection();

    $computedToken = hash_hmac('sha256', (string)$bookingId, INVOICE_SECRET);
    if (!hash_equals($computedToken, $token)) {
        throw new Exception("Access Denied: Invalid secure token.");
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');

    if (empty($name) || empty($phone) || empty($city)) {
        throw new Exception("Please provide all required fields.");
    }

    $db = Database::getInstance()->getConnection();

    // Verify booking
    $bStmt = $db->prepare("SELECT id, property_id, guest_id, booking_status FROM bookings WHERE id = ?");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Reservation not found.");
    }
    if ($booking['booking_status'] !== 'booked') {
        throw new Exception("Check-in not allowed for this reservation status.");
    }

    // Handle File Uploads (stored in the same directory as Admin for consistency)
    $uploadDir = __DIR__ . '/../uploads/';
    
    // Create directory if not exists (should already exist but good for redundancy)
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $idFrontPath = null;
    $idBackPath = null;

    function handleUpload($fileArray, $prefix, $uploadDir) {
        if (!isset($fileArray['error']) || is_array($fileArray['error'])) {
            throw new Exception("Invalid file parameters.");
        }
        if ($fileArray['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($fileArray['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed with error code: " . $fileArray['error']);
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $ext = array_search(
            $finfo->file($fileArray['tmp_name']),
            array(
                'jpg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'pdf' => 'application/pdf',
                'heic' => 'image/heic'
            ),
            true
        );

        if ($ext === false) {
            throw new Exception("Invalid file format. Please upload JPG, PNG, GIF, HEIC or PDF.");
        }

        $filename = sprintf('%s_%s.%s', $prefix, bin2hex(random_bytes(8)), $ext);
        $filepath = $uploadDir . $filename;

        if (!move_uploaded_file($fileArray['tmp_name'], $filepath)) {
            throw new Exception("Failed to save uploaded file.");
        }
        
        // Return only the filename so it can be stored in the DB (the proxy script will read from the dir)
        return $filename;
    }

    if (!empty($_FILES['id_front']['name'])) {
        $idFrontPath = handleUpload($_FILES['id_front'], 'front_' . $bookingId, $uploadDir);
    }
    if (!empty($_FILES['id_back']['name'])) {
        $idBackPath = handleUpload($_FILES['id_back'], 'back_' . $bookingId, $uploadDir);
    }

    if (!$idFrontPath || !$idBackPath) {
        throw new Exception("Both front and back ID proofs are required.");
    }

    $db->beginTransaction();

    // Update Guest
    $gStmt = $db->prepare("
        UPDATE guests 
        SET name = ?, email = ?, phone = ?, city = ?, state = ?, 
            id_proof_front = COALESCE(?, id_proof_front), 
            id_proof_back = COALESCE(?, id_proof_back)
        WHERE id = ?
    ");
    $gStmt->execute([$name, $email, $phone, $city, $state, $idFrontPath, $idBackPath, $booking['guest_id']]);

    // Update Booking to Checked In
    $statusStmt = $db->prepare("UPDATE bookings SET booking_status = 'checked_in' WHERE id = ?");
    $statusStmt->execute([$bookingId]);

    // Log the event
    AuditLogger::log(0, 'PORTAL_SELF_CHECKIN', 'BOOKING', $bookingId, [
        'guest_name' => $name,
        'action' => 'completed_self_checkin'
    ], (int)$booking['property_id']);

    $db->commit();

    echo json_encode(['success' => true, 'message' => 'Check-in completed successfully.']);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
