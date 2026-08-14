<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception("Invalid JSON payload.");
    }

    $pnr = trim($data['pnr'] ?? '');
    $identity = trim($data['identity'] ?? '');

    if (empty($pnr) || empty($identity)) {
        throw new Exception("Please provide both Booking Reference and Phone/Email.");
    }

    $db = Database::getInstance()->getConnection();

    // Find the booking by display_id (PNR)
    $stmt = $db->prepare("
        SELECT b.id, b.display_id, b.booking_status, b.check_out, g.phone, g.email 
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        WHERE b.display_id = ?
    ");
    $stmt->execute([$pnr]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Reservation not found. Check your PNR.");
    }
    if (!GuestAccessToken::bookingIsAccessible($booking)) {
        throw new Exception("This reservation is no longer accessible.");
    }

    // Verify identity (either phone or email matches)
    $match = false;
    $identityLower = strtolower($identity);
    
    // Normalize phone (strip spaces/symbols for basic comparison)
    $dbPhone = preg_replace('/[^0-9+]/', '', $booking['phone'] ?? '');
    $inputPhone = preg_replace('/[^0-9+]/', '', $identity);

    if (!empty($dbPhone) && strlen($inputPhone) >= 10) {
        $inputLast10 = substr($inputPhone, -10);
        $dbLast10 = substr($dbPhone, -10);
        if ($inputLast10 === $dbLast10) {
            $match = true;
        }
    }
    if (!empty($booking['email']) && strtolower(trim($booking['email'])) === $identityLower) {
        $match = true;
    }

    if (!$match) {
        throw new Exception("Verification failed. The phone or email does not match the reservation.");
    }

    // Generate secure token for guest portal access
    // Uses the same hashing logic expected by guest_portal.php
    $token = GuestAccessToken::generate((string)$booking['id']);

    echo json_encode([
        'success' => true,
        'booking_id' => $booking['id'],
        'token' => $token,
        'message' => 'Login successful'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
