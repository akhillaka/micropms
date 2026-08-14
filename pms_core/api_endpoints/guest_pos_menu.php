<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';

$bookingId = $_GET['id'] ?? $_GET['booking_id'] ?? '';
$token = $_GET['token'] ?? '';

if ($bookingId === '' || $token === '') {
    echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
    exit;
}

$db = Database::getInstance()->getConnection();

GuestAccessToken::assert($bookingId, $token);

try {
    $bkStmt = $db->prepare("SELECT property_id, booking_status, check_out FROM bookings WHERE id = ?");
    $bkStmt->execute([$bookingId]);
    $booking = $bkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Booking context not resolved.");
    }
    GuestAccessToken::denyIfInaccessible($booking);
    if (($booking['booking_status'] ?? '') !== 'checked_in') {
        throw new Exception("You must be checked in to order.");
    }

    $propertyId = (int)$booking['property_id'];
    if ($propertyId <= 0) {
        throw new Exception("Booking context not resolved.");
    }

    $st = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = 'GUEST_PORTAL_POS_ENABLED'");
    $st->execute([$propertyId]);
    if ($st->fetchColumn() !== 'true') {
        throw new Exception("POS feature is disabled for this property.");
    }

    $oStmt = $db->prepare("SELECT id, name FROM pos_outlets WHERE property_id = ? ORDER BY name ASC");
    $oStmt->execute([$propertyId]);
    $outlets = $oStmt->fetchAll(PDO::FETCH_ASSOC);

    $iStmt = $db->prepare("
        SELECT ii.id, ii.outlet_id, ii.name, ii.sku, ii.selling_price, ii.image_url,
               COALESCE(pi.stock_quantity, ii.stock_qty, 0) AS stock_qty
        FROM inventory_items ii
        LEFT JOIN pos_inventory pi ON pi.item_id = ii.id AND pi.property_id = ii.property_id AND pi.deleted_at IS NULL
        WHERE ii.property_id = ?
          AND ii.selling_price > 0
          AND COALESCE(pi.stock_quantity, ii.stock_qty, 0) > 0
        ORDER BY ii.name ASC
    ");
    $iStmt->execute([$propertyId]);
    $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'outlets' => $outlets,
        'items' => $items
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
