<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/services/PhonePeService.php';

$data = json_decode(file_get_contents('php://input'), true);
$bookingId = (string)($data['booking_id'] ?? '');
$token = $data['token'] ?? '';
$amount = floatval($data['amount'] ?? 0);

if (empty($bookingId) || empty($token) || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid inputs']);
    exit;
}

$db = Database::getInstance()->getConnection();

$computedToken = hash_hmac('sha256', $bookingId, INVOICE_SECRET);
if (!hash_equals($computedToken, $token)) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: Invalid secure token']);
    exit;
}

$stmt = $db->prepare("SELECT property_id, guest_id FROM bookings WHERE id = ?");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit;
}

$propertyId = (int)$booking['property_id'];
$pp = PhonePeService::forProperty($db, $propertyId);

if (!$pp) {
    echo json_encode(['success' => false, 'message' => 'PhonePe online payments are currently unavailable (not configured).']);
    exit;
}

$guestPhone = '';
if ($booking['guest_id']) {
    $gStmt = $db->prepare("SELECT phone FROM guests WHERE id = ?");
    $gStmt->execute([$booking['guest_id']]);
    $guestPhone = $gStmt->fetchColumn() ?: '';
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$redirectUrl = "{$protocol}{$host}/guest-portal?id={$bookingId}&token={$token}";
$callbackUrl = "{$protocol}{$host}/webhook_phonepe";

$merchantTxnId = 'pay_' . $bookingId . '_' . time();
$amountPaise = (int)round($amount * 100);

$result = $pp->initiatePayment($amountPaise, $merchantTxnId, $callbackUrl, $redirectUrl, $guestPhone);

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'redirect_url' => $result['redirect_url']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to initiate PhonePe payment',
        'details' => $result['error'] ?? ''
    ]);
}
