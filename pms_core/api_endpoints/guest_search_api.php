<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';

require_once __DIR__ . '/../../pms_core/RateLimiter.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$genericOtpMessage = 'If this number has a booking, a verification code has been sent.';

$ip = RateLimiter::clientIp();
if (!RateLimiter::allow('guest_search:' . $ip, 12, 600)) {
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.']);
    exit;
}

$now = time();
$attempts = array_values(array_filter(
    $_SESSION['guest_search_attempts'] ?? [],
    static fn($ts) => is_int($ts) && ($now - $ts) < 600
));
if (count($attempts) >= 8) {
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.']);
    exit;
}
$attempts[] = $now;
$_SESSION['guest_search_attempts'] = $attempts;

$data = json_decode(file_get_contents('php://input'), true);
$phone = trim($data['phone'] ?? '');
$propertyId = (int)($data['property_id'] ?? 0);

if (empty($phone) || $propertyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Phone number and Property ID are required']);
    exit;
}

$normalized = PhoneHelper::toLocal($phone);
if ($normalized === null || !PhoneHelper::isValidIndian($normalized)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid 10-digit mobile number']);
    exit;
}

$db = Database::getInstance()->getConnection();

$propStmt = $db->prepare("SELECT id FROM properties WHERE id = ? AND is_active = 1 LIMIT 1");
$propStmt->execute([$propertyId]);
if (!$propStmt->fetchColumn()) {
    echo json_encode(['success' => true, 'otp_required' => true, 'message' => $genericOtpMessage]);
    exit;
}

load_db_settings($db, $propertyId);

$stmt = $db->prepare("
    SELECT b.id, b.display_id, b.check_in, b.check_out, b.booking_status, b.property_id, g.name as guest_name 
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    WHERE b.property_id = ? 
      AND g.phone = ?
      AND b.booking_status IN ('booked', 'checked_in')
      AND (b.check_out IS NULL OR b.check_out >= DATE_SUB(NOW(), INTERVAL 7 DAY))
    ORDER BY b.check_in ASC
");
$stmt->execute([$propertyId, $normalized]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$otpEnabled = get_db_setting($db, 'GUEST_PORTAL_OTP_ENABLED', $propertyId, 'true') === 'true';

if ($otpEnabled) {
    if (!empty($bookings)) {
        $otp = (string)random_int(100000, 999999);
        $_SESSION['guest_otp_code'] = $otp;
        $_SESSION['guest_otp_phone'] = $phone;
        $_SESSION['guest_otp_property_id'] = $propertyId;
        $_SESSION['guest_otp_bookings'] = $bookings;
        $_SESSION['guest_otp_expiry'] = time() + 300;
        $_SESSION['guest_otp_attempts'] = 0;

        $message = "Your MicroPMS verification OTP is: *{$otp}*. It is valid for 5 minutes.";
        session_write_close();
        // Prefer queue; fall back to sync only if queue push is unavailable
        try {
            require_once __DIR__ . '/../../pms_core/services/QueueService.php';
            QueueService::push('whatsapp', [
                'phoneNumber' => PhoneHelper::toE164($phone) ?? $phone,
                'payload' => $message,
                'isTemplate' => false,
                'eventKey' => 'guest_otp',
                'templateName' => 'otp',
                'property_id' => $propertyId,
            ], 0, $propertyId);
        } catch (\Throwable $e) {
            NotificationRelay::sendWhatsAppSync($phone, $message, false, $propertyId);
        }
    }

    echo json_encode([
        'success' => true,
        'otp_required' => true,
        'message' => $genericOtpMessage
    ]);
    exit;
}

require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';
// OTP disabled: never hand out portal tokens on phone alone — guest must use PNR+identity login.
$resolved = [];
foreach ($bookings as $b) {
    if (!GuestAccessToken::bookingIsAccessible($b)) {
        continue;
    }
    $resolved[] = [
        'id' => $b['id'],
        'display_id' => $b['display_id'],
        'check_in' => $b['check_in'],
        'check_out' => $b['check_out'],
        'guest_name' => $b['guest_name'],
    ];
}

echo json_encode([
    'success' => true,
    'otp_required' => false,
    'require_pnr_login' => true,
    'bookings' => $resolved,
    'message' => $resolved === []
        ? 'No accessible stays found for this number.'
        : 'Open your stay link from the hotel, or sign in with booking reference and phone/email.'
]);
