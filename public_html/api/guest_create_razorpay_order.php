<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';

$data = json_decode(file_get_contents('php://input'), true);
$bookingId = (string)($data['booking_id'] ?? '');
$token = $data['token'] ?? '';
$amount = floatval($data['amount'] ?? 0);

if (empty($bookingId) || empty($token) || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid inputs']);
    exit;
}

// Initialize Database to load DB settings into constants
$db = Database::getInstance()->getConnection();

// Verify token
$computedToken = hash_hmac('sha256', $bookingId, INVOICE_SECRET);
if (!hash_equals($computedToken, $token)) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: Invalid security token']);
    exit;
}

require_once __DIR__ . '/../../pms_core/services/RazorpayService.php';

$stmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ?");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit;
}

$propertyId = $booking['property_id'];
$rz = RazorpayService::forProperty($db, $propertyId);

if (!$rz) {
    echo json_encode(['success' => false, 'message' => 'Online payments are currently unavailable for this property (Gateway not configured).']);
    exit;
}

$receipt = 'gst_' . $bookingId . '_' . time();
$result = $rz->createOrder(round($amount * 100), 'INR', $receipt);

if ($result['success']) {
    echo json_encode(['success' => true, 'order_id' => $result['order_id']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to create order', 'details' => $result['error']]);
}
