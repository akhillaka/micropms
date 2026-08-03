<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$data = json_decode(file_get_contents('php://input'), true);
$otp = trim($data['otp'] ?? '');

if (empty($otp)) {
    echo json_encode(['success' => false, 'message' => 'Verification code is required']);
    exit;
}

$sessionOtp = $_SESSION['guest_otp_code'] ?? '';
$expiry = $_SESSION['guest_otp_expiry'] ?? 0;

if (empty($sessionOtp) || time() > $expiry) {
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit;
}

if ($otp !== $sessionOtp) {
    echo json_encode(['success' => false, 'message' => 'Incorrect verification code. Please try again.']);
    exit;
}

// Success! Resolve the secure tokens for the guest's active bookings
$bookings = $_SESSION['guest_otp_bookings'] ?? [];
$resolvedBookings = [];

foreach ($bookings as $b) {
    $bookingId = (string)$b['id'];
    $computedToken = hash_hmac('sha256', $bookingId, INVOICE_SECRET);
    
    $resolvedBookings[] = [
        'id' => $b['id'],
        'display_id' => $b['display_id'],
        'check_in' => $b['check_in'],
        'check_out' => $b['check_out'],
        'guest_name' => $b['guest_name'],
        'token' => $computedToken
    ];
}

// Clear OTP session variables
unset($_SESSION['guest_otp_code']);
unset($_SESSION['guest_otp_expiry']);

echo json_encode([
    'success' => true,
    'bookings' => $resolvedBookings
]);
