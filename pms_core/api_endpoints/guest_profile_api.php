<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';

try {
    $bookingId = $_GET['booking_id'] ?? '';
    $token = $_GET['token'] ?? '';
    
    if (empty($bookingId) || empty($token)) {
        throw new Exception("Missing parameters.");
    }

    GuestAccessToken::assert($bookingId, $token);

    $db = Database::getInstance()->getConnection();

    $bStmt = $db->prepare("SELECT id, property_id, guest_id, booking_status, check_out FROM bookings WHERE id = ?");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Reservation not found.");
    }
    GuestAccessToken::denyIfInaccessible($booking);

    $guestId = $booking['guest_id'];

    $docStmt = $db->prepare("SELECT document_type, file_path FROM guest_documents WHERE guest_id = ?");
    $docStmt->execute([$guestId]);
    $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

    $gStmt = $db->prepare("SELECT id_proof_front, id_proof_back, photo FROM guests WHERE id = ?");
    $gStmt->execute([$guestId]);
    $guest = $gStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $documents = [];
    foreach ($docs as $d) {
        $path = trim((string)$d['file_path']);
        if ($path === '') {
            continue;
        }
        if (str_starts_with($path, 'uploads/')) {
            $documents[$d['document_type']] = '/' . ltrim($path, '/');
        } else {
            $documents[$d['document_type']] = pms_document_url(basename($path)) ?: ('/' . ltrim($path, '/'));
        }
    }
    if (!empty($guest['id_proof_front'])) {
        $documents['id_proof_front'] = pms_document_url($guest['id_proof_front']);
    }
    if (!empty($guest['id_proof_back'])) {
        $documents['id_proof_back'] = pms_document_url($guest['id_proof_back']);
    }

    echo json_encode(['success' => true, 'data' => ['documents' => $documents]]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
