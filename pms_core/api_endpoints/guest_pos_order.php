<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$bookingId = (string)($data['booking_id'] ?? $data['id'] ?? '');
$token = $data['token'] ?? '';
$outletId = isset($data['outlet_id']) ? (int)$data['outlet_id'] : null;
$cartItems = $data['items'] ?? [];

if ($bookingId === '' || $token === '' || empty($cartItems) || !is_array($cartItems)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

$db = Database::getInstance()->getConnection();

GuestAccessToken::assert($bookingId, $token);

try {
    $db->beginTransaction();

    $bkStmt = $db->prepare("
        SELECT b.*, r.room_number, g.name as guest_name
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.id = ? AND b.booking_status = 'checked_in'
    ");
    $bkStmt->execute([$bookingId]);
    $booking = $bkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("You must be actively checked-in to place orders.");
    }
    if (!GuestAccessToken::bookingIsAccessible($booking)) {
        throw new Exception("This stay link has expired or the reservation is no longer accessible");
    }

    $propertyId = (int)$booking['property_id'];

    $st = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = 'GUEST_PORTAL_POS_ENABLED'");
    $st->execute([$propertyId]);
    if ($st->fetchColumn() !== 'true') {
        throw new Exception("POS feature is disabled for this property.");
    }

    $totalAmount = 0.0;
    $validatedItems = [];

    usort($cartItems, function ($a, $b) {
        $aid = (int)($a['item_id'] ?? $a['id'] ?? 0);
        $bid = (int)($b['item_id'] ?? $b['id'] ?? 0);
        return $aid <=> $bid;
    });

    foreach ($cartItems as $cartItem) {
        $itemId = (int)($cartItem['item_id'] ?? $cartItem['id'] ?? 0);
        $qty = (int)($cartItem['quantity'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            throw new Exception("Invalid item or quantity.");
        }

        $stmt = $db->prepare("
            SELECT ii.id, ii.name, ii.selling_price, ii.stock_qty, pi.id AS pi_id, pi.stock_quantity
            FROM inventory_items ii
            LEFT JOIN pos_inventory pi ON pi.item_id = ii.id AND pi.property_id = ii.property_id AND pi.deleted_at IS NULL
            WHERE ii.id = ? AND ii.property_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$itemId, $propertyId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new Exception("Product not found.");
        }

        $available = $product['pi_id'] !== null ? (int)$product['stock_quantity'] : (int)$product['stock_qty'];
        if ($available < $qty) {
            throw new Exception("Product '{$product['name']}' only has {$available} units left in stock.");
        }

        $pricePerUnit = (float)$product['selling_price'];
        if ($pricePerUnit <= 0) {
            throw new Exception("Product '{$product['name']}' is not available for sale.");
        }
        $totalAmount += $pricePerUnit * $qty;

        $validatedItems[] = [
            'id' => $itemId,
            'name' => $product['name'],
            'qty' => $qty,
            'price_per_unit' => $pricePerUnit,
            'pi_id' => $product['pi_id']
        ];
    }

    $insOrder = $db->prepare("
        INSERT INTO pos_orders (property_id, outlet_id, booking_id, total_amount, payment_method, status, source, delivery_status)
        VALUES (?, ?, ?, ?, 'room_charge', 'posted', 'guest_portal', 'pending')
    ");
    $insOrder->execute([$propertyId, $outletId ?: null, (int)$bookingId, $totalAmount]);
    $orderId = (int)$db->lastInsertId();
    SequenceGenerator::assignDisplayId($db, 'pos_orders', $orderId, 'SEQ_POS_ORDER_FORMAT');

    $dispStmt = $db->prepare("SELECT display_id FROM pos_orders WHERE id = ?");
    $dispStmt->execute([$orderId]);
    $orderDisplayId = $dispStmt->fetchColumn() ?: ('POS-' . $orderId);

    $insLine = $db->prepare("
        INSERT INTO pos_order_items (order_id, item_id, quantity, price_per_unit)
        VALUES (?, ?, ?, ?)
    ");
    $deductInv = $db->prepare("UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ? AND property_id = ?");
    $deductPos = $db->prepare("UPDATE pos_inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");

    foreach ($validatedItems as $vi) {
        $insLine->execute([$orderId, $vi['id'], $vi['qty'], $vi['price_per_unit']]);
        if (!empty($vi['pi_id'])) {
            $deductPos->execute([$vi['qty'], $vi['pi_id']]);
        } else {
            $deductInv->execute([$vi['qty'], $vi['id'], $propertyId]);
        }
    }

    $itemSummaries = [];
    foreach ($validatedItems as $vi) {
        $itemSummaries[] = "{$vi['name']} x{$vi['qty']}";
    }
    $description = "POS Charge ({$orderDisplayId}): " . mb_substr(implode(', ', $itemSummaries), 0, 180);
    FolioService::postCharge($db, (int)$bookingId, $totalAmount, $description, 'F&B');

    $roomNum = $booking['room_number'] ?? '?';
    $db->prepare("INSERT INTO admin_notifications (property_id, type, title, message) VALUES (?, 'pos_order', ?, ?)")
       ->execute([$propertyId, 'Guest dining order', "Room {$roomNum} ordered {$orderDisplayId} (₹" . number_format($totalAmount, 2) . ")"]);

    AuditLogger::log(0, 'PORTAL_POS_ORDER', 'POS_ORDER', $orderId, [
        'total' => $totalAmount,
        'room' => $roomNum,
        'property_id' => $propertyId
    ]);

    $tgMsg = "🛎️ <b>New Guest Room Service Order</b>\n\nRoom: {$roomNum}\nGuest: " . htmlspecialchars((string)($booking['guest_name'] ?? 'Guest')) . "\nItems: " . implode(', ', $itemSummaries) . "\nTotal Charge: ₹" . number_format($totalAmount, 2) . "\nSource: Guest Portal";

    NotificationRelay::sendTelegram($tgMsg, 'room_service_order', [
        'room' => $roomNum,
        'guest' => $booking['guest_name'] ?? 'Guest',
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
