<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
$db = Database::getInstance()->getConnection();

AuthHelper::requirePermission('generate_payment_link');

CsrfToken::requireValid();

$data = json_decode(file_get_contents('php://input'), true);
$amount = floatval($data['amount'] ?? 0);
$bookingId = $data['booking_id'] ?? 0;

if ($amount <= 0 || !$bookingId) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

require_once __DIR__ . '/../../pms_core/services/RazorpayService.php';
$propertyId = AuthHelper::getPropertyId();

$bk = $db->prepare("SELECT id FROM bookings WHERE id = ? AND property_id = ?");
$bk->execute([(int)$bookingId, $propertyId]);
if (!$bk->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit;
}

$rz = RazorpayService::forProperty($db, $propertyId);

if (!$rz) {
    echo json_encode(['success' => false, 'message' => 'Razorpay keys not fully configured for this property in Settings.']);
    exit;
}

$receipt = 'bk_' . $bookingId . '_' . time();
$result = $rz->createOrder(round($amount * 100), 'INR', $receipt, [
    'booking_id' => (string)$bookingId,
    'property_id' => (string)$propertyId,
]);

if ($result['success']) {
    $up = $db->prepare("UPDATE bookings SET razorpay_order_id = ? WHERE id = ? AND property_id = ?");
    $up->execute([$result['order_id'], (int)$bookingId, $propertyId]);
    echo json_encode(['success' => true, 'order_id' => $result['order_id'], 'key_id' => $rz->getKeyId()]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to create order', 'details' => $result['error']]);
}

