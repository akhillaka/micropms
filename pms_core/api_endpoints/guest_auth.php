<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';
require_once __DIR__ . '/../../pms_core/RateLimiter.php';

try {
    $ip = RateLimiter::clientIp();
    if (!RateLimiter::allow('guest_auth:' . $ip, 10, 600)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many attempts. Please wait and try again.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception("Invalid JSON payload.");
    }

    $pnr = trim($data['pnr'] ?? '');
    $identity = trim($data['identity'] ?? '');
    $hotelId = (int)($data['hotelId'] ?? $data['property_id'] ?? ($_GET['hotelId'] ?? 0));

    if (empty($pnr) || empty($identity)) {
        throw new Exception("Please provide both Booking Reference and Phone/Email.");
    }

    // Per-identity throttle (slows credential stuffing against a known PNR)
    $idKey = 'guest_auth:id:' . hash('sha256', strtolower($pnr . '|' . $identity));
    if (!RateLimiter::allow($idKey, 8, 900)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many attempts for this reservation. Try again later.']);
        exit;
    }

    $db = Database::getInstance()->getConnection();

    // display_id sequences are per-property, so the same PNR can exist at two hotels.
    // Prefer hotelId when provided; otherwise require identity to uniquely match.
    $sql = "
        SELECT b.id, b.display_id, b.booking_status, b.check_out, b.property_id, g.phone, g.email
        FROM bookings b
        JOIN guests g ON b.guest_id = g.id
        WHERE b.display_id = ?
    ";
    $params = [$pnr];
    if ($hotelId > 0) {
        $sql .= " AND b.property_id = ?";
        $params[] = $hotelId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$candidates) {
        throw new Exception("Reservation not found. Check your PNR.");
    }

    $identityLower = strtolower($identity);
    $inputPhone = preg_replace('/[^0-9+]/', '', $identity);
    $matches = [];

    foreach ($candidates as $row) {
        if (!GuestAccessToken::bookingIsAccessible($row)) {
            continue;
        }
        $ok = false;
        $dbPhone = preg_replace('/[^0-9+]/', '', $row['phone'] ?? '');
        if (!empty($dbPhone) && strlen($inputPhone) >= 10) {
            if (substr($inputPhone, -10) === substr($dbPhone, -10)) {
                $ok = true;
            }
        }
        if (!empty($row['email']) && strtolower(trim((string)$row['email'])) === $identityLower) {
            $ok = true;
        }
        if ($ok) {
            $matches[] = $row;
        }
    }

    if (count($matches) === 0) {
        throw new Exception("Verification failed. The phone or email does not match the reservation.");
    }
    if (count($matches) > 1) {
        throw new Exception("Multiple reservations match this reference. Open the link from your hotel or include hotelId.");
    }

    $booking = $matches[0];
    $token = GuestAccessToken::generateForBooking((int)$booking['id'], (int)$booking['property_id']);

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
