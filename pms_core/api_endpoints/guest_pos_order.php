<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

$data = json_decode(file_get_contents('php://input'), true);
$bookingId = (string)($data['booking_id'] ?? '');
$token = $data['token'] ?? '';
$outletId = isset($data['outlet_id']) ? (int)$data['outlet_id'] : null;
$cartItems = $data['items'] ?? [];

if (empty($bookingId) || empty($token) || empty($cartItems)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Verify token authenticity
$computedToken = hash_hmac('sha256', $bookingId, INVOICE_SECRET);
if (!hash_equals($computedToken, $token)) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: Invalid security token.']);
    exit;
}

try {
    $db->beginTransaction();

    // Fetch booking details & property context
    $bkStmt = $db->prepare("
        SELECT b.*, r.room_number, g.name as guest_name 
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN guests g ON b.guest_id = g.id
        WHERE b.id = ? AND b.booking_status = 'checked_in'
    ");
    $bkStmt->execute([$bookingId]);
    $booking = $bkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("You must be actively checked-in to place orders.");
    }

    $propertyId = (int)$booking['property_id'];

    $st = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = 'GUEST_PORTAL_POS_ENABLED'");
    $st->execute([$propertyId]);
    $posEnabled = ($st->fetchColumn() === 'true');
    if (!$posEnabled) {
        throw new Exception("POS feature is disabled for this property.");
    }

    // Validate items, prices, and stock
    $totalAmount = 0.0;
    $validatedItems = [];

    foreach ($cartItems as $cartItem) {
        $itemId = (int)$cartItem['id'];
        $qty = (int)$cartItem['quantity'];

        $stmt = $db->prepare("SELECT * FROM inventory_items WHERE id = ? AND property_id = ? FOR UPDATE");
        $stmt->execute([$itemId, $propertyId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception("Product ID {$itemId} not found.");
        }

        if ($product['stock_qty'] < $qty) {
            throw new Exception("Product '{$product['name']}' only has {$product['stock_qty']} units left in stock.");
        }

        $pricePerUnit = (float)$product['selling_price'];
        $totalAmount += $pricePerUnit * $qty;

        $validatedItems[] = [
            'id' => $itemId,
            'name' => $product['name'],
            'qty' => $qty,
            'price_per_unit' => $pricePerUnit
        ];
    }

    // Insert guest portal order
    $insOrder = $db->prepare("
        INSERT INTO pos_orders (property_id, outlet_id, booking_id, total_amount, payment_method, status, source, delivery_status)
        VALUES (?, ?, ?, ?, 'room_charge', 'posted', 'guest_portal', 'pending')
    ");
    $insOrder->execute([$propertyId, $outletId, (int)$bookingId, $totalAmount]);
    $orderId = (int)$db->lastInsertId();
    require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
    SequenceGenerator::assignDisplayId($db, 'pos_orders', $orderId, 'SEQ_POS_ORDER_FORMAT');

    // Deduct stock and record items
    $insLine = $db->prepare("
        INSERT INTO pos_order_items (order_id, item_id, quantity, price_per_unit)
        VALUES (?, ?, ?, ?)
    ");
    $deductStock = $db->prepare("UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ? AND property_id = ?");

    foreach ($validatedItems as $vi) {
        $insLine->execute([$orderId, $vi['id'], $vi['qty'], $vi['price_per_unit']]);
        $deductStock->execute([$vi['qty'], $vi['id'], $propertyId]);
    }

    // Charge room folio (uses positive amount for charges)
    $itemSummaries = [];
    foreach ($validatedItems as $vi) {
        $itemSummaries[] = "{$vi['name']} x{$vi['qty']}";
    }
    $description = "Room Service: " . implode(', ', $itemSummaries);
    FolioService::postCharge($db, (int)$bookingId, $totalAmount, $description, 'pos_order');

    // Audit log
    AuditLogger::log(0, 'PORTAL_POS_ORDER', 'POS_ORDER', $orderId, [
        'total' => $totalAmount,
        'room' => $booking['room_number'],
        'property_id' => $propertyId
    ]);

    // Send Telegram Notification to hotel staff
    $tgMsg = "🛎️ <b>New Guest Room Service Order</b>\n\nRoom: {$booking['room_number']}\nGuest: " . htmlspecialchars($booking['guest_name']) . "\nItems: " . implode(', ', $itemSummaries) . "\nTotal Charge: ₹" . number_format($totalAmount, 2) . "\nSource: Guest Portal";
    
    NotificationRelay::sendTelegram($tgMsg, 'room_service_order', [
        'room' => $booking['room_number'],
        'guest' => $booking['guest_name'],
        'total' => number_format($totalAmount, 2)
    ], $propertyId);

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Order placed successfully! It will be delivered to your room soon.']);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
