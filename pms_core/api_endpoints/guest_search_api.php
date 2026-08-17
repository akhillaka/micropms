<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$genericOtpMessage = 'If this number has a booking, a verification code has been sent.';

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
    SELECT b.id, b.display_id, b.check_in, b.check_out, b.booking_status, g.name as guest_name 
    FROM bookings b
    JOIN guests g ON b.guest_id = g.id
    WHERE b.property_id = ? 
      AND g.phone = ?
      AND b.booking_status IN ('confirmed', 'checked_in', 'booked')
      AND (b.check_out IS NULL OR b.check_out >= DATE_SUB(NOW(), INTERVAL 7 DAY))
    ORDER BY b.check_in ASC
");
$stmt->execute([$propertyId, $normalized]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$otpEnabled = defined('GUEST_PORTAL_OTP_ENABLED') && GUEST_PORTAL_OTP_ENABLED === 'true';

if (!$otpEnabled) {
    echo json_encode([
        'success' => false,
        'otp_required' => true,
        'message' => 'Guest portal lookup requires OTP verification. Enable GUEST_PORTAL_OTP_ENABLED.'
    ]);
    exit;
}

if (!empty($bookings)) {
    $otp = (string)random_int(100000, 999999);
    $_SESSION['guest_otp_code'] = $otp;
    $_SESSION['guest_otp_phone'] = $phone;
    $_SESSION['guest_otp_property_id'] = $propertyId;
    $_SESSION['guest_otp_bookings'] = $bookings;
    $_SESSION['guest_otp_expiry'] = time() + 300;

    $message = "Your MicroPMS verification OTP is: *{$otp}*. It is valid for 5 minutes.";
    NotificationRelay::sendWhatsAppSync($phone, $message, false);
}

echo json_encode([
    'success' => true,
    'otp_required' => true,
    'message' => $genericOtpMessage
]);
