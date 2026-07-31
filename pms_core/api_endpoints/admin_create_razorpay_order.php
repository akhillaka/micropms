<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
$db = Database::getInstance()->getConnection();

AuthHelper::requireLogin();

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
$rz = RazorpayService::forProperty($db, $propertyId);

if (!$rz) {
    echo json_encode(['success' => false, 'message' => 'Razorpay keys not fully configured for this property in Settings.']);
    exit;
}

$receipt = 'bk_' . $bookingId . '_' . time();
$result = $rz->createOrder(round($amount * 100), 'INR', $receipt);

if ($result['success']) {
    echo json_encode(['success' => true, 'order_id' => $result['order_id']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to create order', 'details' => $result['error']]);
}

