<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';
require_once __DIR__ . '/../../pms_core/services/RazorpayService.php';

$data = json_decode(file_get_contents('php://input'), true);
$bookingId = (string)($data['booking_id'] ?? '');
$token = $data['token'] ?? '';
$amount = floatval($data['amount'] ?? 0);
$ref = $data['razorpay_payment_id'] ?? $data['payment_ref'] ?? $data['ref'] ?? '';
$orderId = (string)($data['razorpay_order_id'] ?? '');
$signature = (string)($data['razorpay_signature'] ?? '');

if (empty($bookingId) || empty($token) || empty($ref)) {
    echo json_encode(['success' => false, 'message' => 'Invalid inputs']);
    exit;
}

$db = Database::getInstance()->getConnection();

GuestAccessToken::assert($bookingId, $token);

try {
    $db->beginTransaction();

    $bStmt = $db->prepare("SELECT b.*, r.room_number, g.name as guest_name FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = ? FOR UPDATE");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Booking not found");
    }
    if (!GuestAccessToken::bookingIsAccessible($booking)) {
        throw new Exception("This stay link has expired or the reservation is no longer accessible");
    }

    $dupStmt = $db->prepare("SELECT id FROM folio_ledger WHERE booking_id = ? AND transaction_ref = ? LIMIT 1");
    $dupStmt->execute([(int)$bookingId, $ref]);
    if ($dupStmt->fetchColumn()) {
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Payment already recorded']);
        exit;
    }

    // Auto-capture Razorpay payment — do not post the folio if capture fails
    $propertyId = (int)$booking['property_id'];
    $rz = RazorpayService::forProperty($db, $propertyId);
    if (!$rz) {
        throw new Exception("Payment gateway is not configured");
    }

    $storedOrder = (string)($booking['razorpay_order_id'] ?? '');
    if ($orderId === '' || $signature === '' || $storedOrder === '' || !hash_equals($storedOrder, $orderId)) {
        throw new Exception("Payment signature missing or order mismatch.");
    }
    if (!$rz->verifySignature($orderId, $ref, $signature)) {
        throw new Exception("Invalid payment signature.");
    }

    $fetched = $rz->fetchPayment($ref);
    if (empty($fetched['success'])) {
        throw new Exception("Could not verify payment with gateway.");
    }
    $amountPaise = (int)($fetched['amount'] ?? 0);
    $amount = $amountPaise > 0 ? ($amountPaise / 100) : $amount;
    if ($amount <= 0) {
        throw new Exception("Invalid captured amount.");
    }

    $capture = $rz->capturePayment($ref, $amountPaise > 0 ? $amountPaise : (int)round($amount * 100));
    $alreadyCaptured = !$capture['success'] && isset($capture['error']) && stripos((string)$capture['error'], 'already captured') !== false;
    if (!$capture['success'] && !$alreadyCaptured) {
        $status = strtolower((string)($fetched['status'] ?? ''));
        if (!in_array($status, ['captured', 'authorized'], true) && $status !== 'captured') {
            AuditLogger::log(0, 'PORTAL_RAZORPAY_CAPTURE_FAILED', 'FOLIO', $bookingId, [
                'error' => $capture['error'] ?? 'unknown'
            ], $propertyId);
            throw new Exception("Payment capture failed. Folio was not updated.");
        }
    }

    // Record payment via FolioService (uses negative amount in ledger)
    $entryId = FolioService::recordPayment($db, (int)$bookingId, $amount, 'Razorpay', $ref, 'guest_portal');

    // Fetch display ID
    $receiptStmt = $db->prepare("SELECT display_id FROM folio_ledger WHERE id = ?");
    $receiptStmt->execute([$entryId]);
    $receiptDisplayId = $receiptStmt->fetchColumn() ?: 'RCPT-' . $entryId;

    // Record finance transaction
    $financeStmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, booking_id, amount, description, payment_method, staff_id) VALUES (?, 'income', 'booking', ?, ?, ?, 'razorpay', NULL)");
    $desc = "Payment - Razorpay (Receipt {$receiptDisplayId})";
    $financeStmt->execute([(int)$booking['property_id'], $bookingId, $amount, $desc]);
    
    $financeId = (int)$db->lastInsertId();
    SequenceGenerator::assignDisplayId($db, 'finance_transactions', $financeId, 'SEQ_TRANSACTION_FORMAT');

    $txnStmt = $db->prepare("SELECT display_id FROM finance_transactions WHERE id = ?");
    $txnStmt->execute([$financeId]);
    $txnDisplayId = $txnStmt->fetchColumn();
    if ($txnDisplayId) {
        $db->prepare("UPDATE folio_ledger SET transaction_ref = ? WHERE id = ? AND (transaction_ref = 'MANUAL' OR transaction_ref = '' OR transaction_ref IS NULL)")->execute([$txnDisplayId, $entryId]);
    }

    // Audit log
    AuditLogger::log(0, 'PORTAL_PAYMENT_RECORDED', 'FOLIO', $bookingId, [
        'amount' => $amount,
        'ref' => $ref
    ], (int)$booking['property_id']);

    // Send Telegram Notification
    $tgMsg = "💰 <b>Payment Received (Guest Portal)</b>\n\nRoom: {$booking['room_number']}\nGuest: " . htmlspecialchars($booking['guest_name']) . "\nAmount: ₹" . number_format($amount, 2) . "\nMethod: Razorpay\nRef: {$ref}";
    $context = [
        'guest_name' => $booking['guest_name'] ?? 'N/A',
        'room_number' => $booking['room_number'],
        'amount' => number_format($amount, 2),
        'method' => 'Razorpay',
        'ref' => $ref
    ];
    NotificationRelay::sendTelegram($tgMsg, 'payment_received', $context, (int)$booking['property_id']);

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully!']);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
