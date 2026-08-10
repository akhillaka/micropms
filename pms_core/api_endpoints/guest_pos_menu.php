<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';

$bookingId = $_GET['id'] ?? '';
$token = $_GET['token'] ?? '';

if (empty($bookingId) || empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Verify token authenticity
$computedToken = hash_hmac('sha256', (string)$bookingId, INVOICE_SECRET);
if (!hash_equals($computedToken, $token)) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: Invalid security token.']);
    exit;
}

try {
    // Get booking to know property context
    $bkStmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ?");
    $bkStmt->execute([$bookingId]);
    $propertyId = (int)$bkStmt->fetchColumn();

    if ($propertyId <= 0) {
        throw new Exception("Booking context not resolved.");
    }

    $st = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = 'GUEST_PORTAL_POS_ENABLED'");
    $st->execute([$propertyId]);
    $posEnabled = ($st->fetchColumn() === 'true');
    if (!$posEnabled) {
        throw new Exception("POS feature is disabled for this property.");
    }

    // Fetch outlets
    $oStmt = $db->prepare("SELECT * FROM pos_outlets WHERE property_id = ? ORDER BY name ASC");
    $oStmt->execute([$propertyId]);
    $outlets = $oStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch items with positive stock
    $iStmt = $db->prepare("
        SELECT id, outlet_id, name, sku, stock_qty, selling_price, image_url 
        FROM inventory_items 
        WHERE property_id = ? AND stock_qty > 0 
        ORDER BY name ASC
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
