<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';

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

GuestAccessToken::assert($bookingId, $token);

$stmt = $db->prepare("SELECT property_id, booking_status, check_out FROM bookings WHERE id = ?");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit;
}
GuestAccessToken::denyIfInaccessible($booking);
if (($booking['booking_status'] ?? '') !== 'checked_in') {
    echo json_encode(['success' => false, 'message' => 'Add-ons are available after check-in']);
    exit;
}

$propertyId = (int)$booking['property_id'];
load_db_settings($db, $propertyId);
$upsellEnabled = get_db_setting($db, 'GUEST_PORTAL_UPSELL_ENABLED', $propertyId, 'false') === 'true';
if (!$upsellEnabled) {
    echo json_encode(['success' => false, 'message' => 'Guest upsell is disabled']);
    exit;
}

$description = trim(strip_tags($description));
$breakfastPrice = round(floatval(get_db_setting($db, 'GUEST_PORTAL_UPSELL_BREAKFAST_PRICE', $propertyId, '350.00')), 2);
$transferPrice = round(floatval(get_db_setting($db, 'GUEST_PORTAL_UPSELL_TRANSFER_PRICE', $propertyId, '1200.00')), 2);
$catalog = [];
if ($breakfastPrice > 0) {
    $catalog['Breakfast Buffet'] = $breakfastPrice;
}
if ($transferPrice > 0) {
    $catalog['Airport Cab Transfer'] = $transferPrice;
}
if (!isset($catalog[$description])) {
    echo json_encode(['success' => false, 'message' => 'This add-on is not available']);
    exit;
}
$amount = $catalog[$description];

FolioService::postCharge($db, (int)$bookingId, $amount, $description, 'other');

AuditLogger::log(null, 'ADD_FOLIO_CHARGE', 'FOLIO', $bookingId, [
    'amount' => $amount,
    'description' => $description,
    'source' => 'guest_portal_upsell'
]);

echo json_encode(['success' => true, 'message' => 'Add-on successfully posted to folio']);
