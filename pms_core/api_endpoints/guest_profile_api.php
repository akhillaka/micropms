<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';

try {
    $bookingId = $_GET['booking_id'] ?? '';
    $token = $_GET['token'] ?? '';
    
    if (empty($bookingId) || empty($token)) {
        throw new Exception("Missing parameters.");
    }

    $computedToken = hash_hmac('sha256', (string)$bookingId, INVOICE_SECRET);
    if (!hash_equals($computedToken, $token)) {
        throw new Exception("Access Denied: Invalid secure token.");
    }

    $db = Database::getInstance()->getConnection();

    $bStmt = $db->prepare("SELECT id, property_id, guest_id FROM bookings WHERE id = ?");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Reservation not found.");
    }

    $guestId = $booking['guest_id'];

    $docStmt = $db->prepare("SELECT document_type, file_path FROM guest_documents WHERE guest_id = ?");
    $docStmt->execute([$guestId]);
    $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

    $documents = [];
    foreach ($docs as $d) {
        $documents[$d['document_type']] = $d['file_path'];
    }

    echo json_encode(['success' => true, 'data' => ['documents' => $documents]]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
