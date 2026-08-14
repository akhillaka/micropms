<?php
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    http_response_code(405);
    die("Method Not Allowed - POST only");
}
require_once __DIR__ . '/../pms_core/config.php';
require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../pms_core/AuditLogger.php';
require_once __DIR__ . '/../pms_core/SequenceGenerator.php';

$webhookSecret = defined('RAZORPAY_WEBHOOK_SECRET') ? RAZORPAY_WEBHOOK_SECRET : 'your_webhook_secret';
$webhookSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

$payload = file_get_contents('php://input');

$expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

if (!hash_equals($expectedSignature, $webhookSignature)) {
    http_response_code(400);
    echo "Invalid signature";
    exit;
}

$data = json_decode($payload, true);

if (!is_array($data) || !isset($data['event'])) {
    http_response_code(200);
    echo "OK: Empty or invalid payload ignored";
    exit;
}

try {
    if ($data['event'] === 'payment.captured' || $data['event'] === 'order.paid') {
        $orderId = $data['payload']['payment']['entity']['order_id'] ?? null;
        $paymentId = $data['payload']['payment']['entity']['id'] ?? null;
        
        if ($orderId) {
            $db = Database::getInstance()->getConnection();
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT b.*, g.name as guest_name, g.phone as guest_phone FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id WHERE b.razorpay_order_id = :order_id FOR UPDATE");
            $stmt->execute(['order_id' => $orderId]);
            $booking = $stmt->fetch();
            
            // Replay Protection: check if payment transaction reference already exists
            if ($paymentId) {
                $checkRef = $db->prepare("SELECT COUNT(*) FROM folio_ledger WHERE transaction_ref = :ref");
                $checkRef->execute(['ref' => $paymentId]);
                if ((int)$checkRef->fetchColumn() > 0) {
                    $db->rollBack();
                    http_response_code(200);
                    echo "OK: Already processed";
                    exit;
                }
            }
            
            if ($booking) {
                $amountPaid = (float)($data['payload']['payment']['entity']['amount'] ?? 0) / 100;

                if ($booking['payment_status'] === 'pending_hold') {
                    $updateStmt = $db->prepare("UPDATE bookings SET payment_status = 'completed_paid' WHERE id = :id");
                    $updateStmt->execute(['id' => $booking['id']]);
                }

                $ledgerStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, property_id, transaction_type, amount, transaction_ref, description, payment_method) VALUES (:booking_id, :property_id, 'payment', :amount, :ref, 'Payment - ONLINE', 'online')");
                $ledgerStmt->execute([
                    'booking_id' => $booking['id'],
                    'property_id' => $booking['property_id'],
                    'amount' => -$amountPaid,
                    'ref' => $paymentId
                ]);
                $ledgerId = (int)$db->lastInsertId();
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', $ledgerId, 'SEQ_RECEIPT_FORMAT');

                // Fetch display ID
                $receiptStmt = $db->prepare("SELECT display_id FROM folio_ledger WHERE id = ?");
                $receiptStmt->execute([$ledgerId]);
                $receiptDisplayId = $receiptStmt->fetchColumn() ?: 'RCPT-' . $ledgerId;

                // Record finance transaction
                $financeStmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, booking_id, amount, description, payment_method, staff_id) VALUES (?, 'income', 'booking', ?, ?, ?, 'razorpay', NULL)");
                $desc = "Payment - Razorpay (Webhook Receipt {$receiptDisplayId})";
                $financeStmt->execute([(int)$booking['property_id'], $booking['id'], $amountPaid, $desc]);

                $financeId = (int)$db->lastInsertId();
                SequenceGenerator::assignDisplayId($db, 'finance_transactions', $financeId, 'SEQ_TRANSACTION_FORMAT');

                $db->commit();

                try {
                    AuditLogger::log(null, 'RECORD_PAYMENT', 'FOLIO', $booking['id'], [
                        'amount' => $amountPaid,
                        'method' => 'online',
                        'ref' => $paymentId,
                        'source' => 'webhook'
                    ], (int)$booking['property_id']);

                    // Retrieve room number for notification
                    $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = :id");
                    $roomStmt->execute(['id' => $booking['room_id']]);
                    $room = $roomStmt->fetch();
                    $roomNumber = $room ? $room['room_number'] : $booking['room_id'];

                    // Send WhatsApp Automation
                    NotificationRelay::triggerAutomation('booking_confirmed', null, $booking['id']);

                    // Send Telegram
                    $tgMsg = "✅ <b>Booking Confirmed</b>\n\nRoom: {$roomNumber}\nGuest: " . htmlspecialchars((string)($booking['guest_name'])) . "\nCheck-in: {$booking['check_in']}\nCheck-out: {$booking['check_out']}\nAmount: ₹" . number_format($amountPaid, 2);

                    $context = [
                        'guest_name' => $booking['guest_name'] ?? 'N/A',
                        'room_number' => $roomNumber,
                        'check_in_date' => $booking['check_in'],
                        'check_out_date' => $booking['check_out'],
                        'total_amount' => number_format($booking['total_amount'], 2),
                        'paid_amount' => number_format($amountPaid, 2)
                    ];
                    NotificationRelay::sendTelegram($tgMsg, 'booking_confirmed', $context);
                } catch (\Throwable $sideEffectError) {
                    error_log("Razorpay Webhook Side Effect Error: " . $sideEffectError->getMessage());
                }
            } else {
                $db->rollBack();
            }
        }
    } elseif ($data['event'] === 'payment.failed') {
        $orderId = $data['payload']['payment']['entity']['order_id'] ?? null;
        if ($orderId) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, payment_status FROM bookings WHERE razorpay_order_id = :order_id");
            $stmt->execute(['order_id' => $orderId]);
            $booking = $stmt->fetch();
            
            if ($booking && $booking['payment_status'] === 'pending_hold') {
                $db->beginTransaction();
                $updateStmt = $db->prepare("UPDATE bookings SET payment_status = 'cancelled', booking_status = 'cancelled' WHERE id = ?");
                $updateStmt->execute([$booking['id']]);
                $db->commit();
                AuditLogger::log(null, 'PAYMENT_FAILED', 'BOOKING', $booking['id'], [
                    'ref' => $orderId,
                    'source' => 'razorpay_webhook'
                ], (int)$booking['property_id']);
            }
        }
    }
} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Razorpay Webhook Fatal Error: " . $e->getMessage());
}

http_response_code(200);
echo "OK";
