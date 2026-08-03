<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

$data = json_decode(file_get_contents('php://input'), true);
$bookingId = (string)($data['booking_id'] ?? '');
$token = $data['token'] ?? '';
$description = trim($data['description'] ?? '');
$amount = floatval($data['amount'] ?? 0);

if (empty($bookingId) || empty($token) || empty($description) || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid inputs']);
    exit;
}

$db = Database::getInstance()->getConnection();

$computedToken = hash_hmac('sha256', $bookingId, INVOICE_SECRET);
if (!hash_equals($computedToken, $token)) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: Invalid secure token']);
    exit;
}

$stmt = $db->prepare("SELECT property_id, booking_status FROM bookings WHERE id = ?");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit;
}

$propertyId = (int)$booking['property_id'];

$ins = $db->prepare("
    INSERT INTO folio_ledger (booking_id, property_id, transaction_type, amount, description, payment_method, recorded_at) 
    VALUES (:b_id, :p_id, 'charge', :amount, :desc, 'other', NOW())
");
$ins->execute([
    'b_id' => $bookingId,
    'p_id' => $propertyId,
    'amount' => $amount,
    'desc' => $description
]);
SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');

AuditLogger::log(null, 'ADD_FOLIO_CHARGE', 'FOLIO', $bookingId, [
    'amount' => $amount,
    'description' => $description,
    'source' => 'guest_portal_upsell'
]);

echo json_encode(['success' => true, 'message' => 'Add-on successfully posted to folio']);
