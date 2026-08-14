<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    http_response_code(405);
    die("Method Not Allowed - POST only");
}

require_once __DIR__ . '/../pms_core/config.php';
require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../pms_core/AuditLogger.php';
require_once __DIR__ . '/../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../pms_core/services/PhonePeService.php';

$xVerify = $_SERVER['HTTP_X_VERIFY'] ?? '';
$rawBody = file_get_contents('php://input');

$data = json_decode($rawBody, true);
$base64 = $data['response'] ?? '';
if (empty($base64) || empty($xVerify)) {
    http_response_code(400);
    echo "Missing request payload or verification header";
    exit;
}

$decodedBody = json_decode(base64_decode($base64), true);
if (!$decodedBody) {
    http_response_code(400);
    echo "Malformed payload";
    exit;
}

$merchantTxnId = $decodedBody['data']['merchantTransactionId'] ?? '';
if (empty($merchantTxnId)) {
    http_response_code(400);
    echo "Missing transaction ID";
    exit;
}

$parts = explode('_', $merchantTxnId);
if (count($parts) < 2) {
    http_response_code(400);
    echo "Invalid transaction format";
    exit;
}
$bookingId = (int)$parts[1];

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("SELECT property_id, total_amount, payment_status, guest_id, room_id, check_in, check_out FROM bookings WHERE id = ?");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(400);
    echo "Booking not found";
    exit;
}

$propertyId = (int)$booking['property_id'];
$pp = PhonePeService::forProperty($db, $propertyId);

if (!$pp) {
    http_response_code(400);
    echo "PhonePe gateway not configured for property";
    exit;
}

if (!$pp->validateWebhook($rawBody, $xVerify)) {
    http_response_code(400);
    echo "Invalid checksum signature";
    exit;
}

$code = $decodedBody['code'] ?? '';
try {
    if ($code === 'PAYMENT_SUCCESS') {
        $db->beginTransaction();
        
        $checkRef = $db->prepare("SELECT COUNT(*) FROM folio_ledger WHERE transaction_ref = :ref");
        $checkRef->execute(['ref' => $merchantTxnId]);
        if ((int)$checkRef->fetchColumn() > 0) {
            $db->rollBack();
            http_response_code(200);
            echo "OK: Already processed";
            exit;
        }
        
        $amountPaid = (float)($decodedBody['data']['amount'] ?? 0) / 100;
        
        if ($booking['payment_status'] === 'pending_hold') {
            $updateStmt = $db->prepare("UPDATE bookings SET payment_status = 'completed_paid' WHERE id = ?");
            $updateStmt->execute([$bookingId]);
        }
        
        $ledgerStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, property_id, transaction_type, amount, transaction_ref, description, payment_method) VALUES (:booking_id, :property_id, 'payment', :amount, :ref, 'Payment - PHONEPE ONLINE', 'online')");
        $ledgerStmt->execute([
            'booking_id' => $bookingId,
            'property_id' => $propertyId,
            'amount' => -$amountPaid,
            'ref' => $merchantTxnId
        ]);
        $ledgerId = (int)$db->lastInsertId();
        SequenceGenerator::assignDisplayId($db, 'folio_ledger', $ledgerId, 'SEQ_RECEIPT_FORMAT');
        
        // Fetch display ID
        $receiptStmt = $db->prepare("SELECT display_id FROM folio_ledger WHERE id = ?");
        $receiptStmt->execute([$ledgerId]);
        $receiptDisplayId = $receiptStmt->fetchColumn() ?: 'RCPT-' . $ledgerId;

        // Record finance transaction
        $financeStmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, booking_id, amount, description, payment_method, staff_id) VALUES (?, 'income', 'booking', ?, ?, ?, 'phonepe', NULL)");
        $desc = "Payment - PhonePe (Webhook Receipt {$receiptDisplayId})";
        $financeStmt->execute([(int)$propertyId, $bookingId, $amountPaid, $desc]);

        $financeId = (int)$db->lastInsertId();
        SequenceGenerator::assignDisplayId($db, 'finance_transactions', $financeId, 'SEQ_TRANSACTION_FORMAT');
        
        $db->commit();
        
        try {
            AuditLogger::log(null, 'RECORD_PAYMENT', 'FOLIO', $bookingId, [
                'amount' => $amountPaid,
                'method' => 'online',
                'ref' => $merchantTxnId,
                'source' => 'phonepe_webhook'
            ], (int)$propertyId);
            
            $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = ?");
            $roomStmt->execute([$booking['room_id']]);
            $room = $roomStmt->fetch();
            $roomNumber = $room ? $room['room_number'] : $booking['room_id'];
            
            $guestStmt = $db->prepare("SELECT name FROM guests WHERE id = ?");
            $guestStmt->execute([$booking['guest_id']]);
            $guestName = $guestStmt->fetchColumn() ?: 'N/A';
            
            NotificationRelay::triggerAutomation('booking_confirmed', null, $bookingId);
            
            $tgMsg = "✅ <b>Booking Confirmed (PhonePe)</b>\n\nRoom: {$roomNumber}\nGuest: " . htmlspecialchars((string)($guestName)) . "\nCheck-in: {$booking['check_in']}\nCheck-out: {$booking['check_out']}\nAmount: ₹" . number_format($amountPaid, 2);
            
            $context = [
                'guest_name' => $guestName,
                'room_number' => $roomNumber,
                'check_in_date' => $booking['check_in'],
                'check_out_date' => $booking['check_out'],
                'total_amount' => number_format($amountPaid, 2),
                'paid_amount' => number_format($amountPaid, 2)
            ];
            NotificationRelay::sendTelegram($tgMsg, 'booking_confirmed', $context);
        } catch (\Throwable $sideEffectError) {
            error_log("PhonePe Webhook Side Effect Error: " . $sideEffectError->getMessage());
        }
    } elseif ($code === 'PAYMENT_ERROR') {
        if ($booking['payment_status'] === 'pending_hold') {
            $db->beginTransaction();
            $updateStmt = $db->prepare("UPDATE bookings SET payment_status = 'cancelled', booking_status = 'cancelled' WHERE id = ?");
            $updateStmt->execute([$bookingId]);
            $db->commit();
            AuditLogger::log(null, 'PAYMENT_FAILED', 'BOOKING', $bookingId, [
                'ref' => $merchantTxnId,
                'source' => 'phonepe_webhook'
            ]);
        }
    }
} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("PhonePe Webhook Fatal Error: " . $e->getMessage());
}

http_response_code(200);
echo "OK";
