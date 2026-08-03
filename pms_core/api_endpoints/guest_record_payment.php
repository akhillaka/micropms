<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';

$data = json_decode(file_get_contents('php://input'), true);
$bookingId = (string)($data['booking_id'] ?? '');
$token = $data['token'] ?? '';
$amount = floatval($data['amount'] ?? 0);
$ref = $data['payment_ref'] ?? $data['ref'] ?? '';

if (empty($bookingId) || empty($token) || $amount <= 0 || empty($ref)) {
    echo json_encode(['success' => false, 'message' => 'Invalid inputs']);
    exit;
}

$db = Database::getInstance()->getConnection();

// Verify token
$computedToken = hash_hmac('sha256', $bookingId, INVOICE_SECRET);
if (!hash_equals($computedToken, $token)) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: Invalid security token']);
    exit;
}

try {
    $db->beginTransaction();

    // Validate booking exists
    $bStmt = $db->prepare("SELECT b.*, r.room_number, g.name as guest_name FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = ?");
    $bStmt->execute([$bookingId]);
    $booking = $bStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Booking not found");
    }

    // Auto-capture Razorpay payment
    $keyId = defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '';
    $keySecret = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';
    
    if (!empty($keyId) && !empty($keySecret)) {
        $ch = curl_init("https://api.razorpay.com/v1/payments/{$ref}/capture");
        curl_setopt($ch, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'amount' => round($amount * 100),
            'currency' => 'INR'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpcode < 200 || $httpcode >= 300) {
            // Already captured or verification error (continue anyway but log it)
            AuditLogger::log(0, 'PORTAL_RAZORPAY_CAPTURE_FAILED', 'FOLIO', $bookingId, [
                'http_code' => $httpcode,
                'response' => $response
            ], (int)$booking['property_id']);
        }
    }

    // Record payment via FolioService (uses negative amount in ledger)
    $entryId = FolioService::recordPayment($db, (int)$bookingId, $amount, 'Razorpay', $ref, 'guest_portal');

    // Fetch display ID
    $receiptStmt = $db->prepare("SELECT display_id FROM folio_ledger WHERE id = ?");
    $receiptStmt->execute([$entryId]);
    $receiptDisplayId = $receiptStmt->fetchColumn() ?: 'RCPT-' . $entryId;

    // Record finance transaction
    $financeStmt = $db->prepare("INSERT INTO finance_transactions (type, category, booking_id, amount, description, payment_method, staff_id) VALUES ('income', 'booking', ?, ?, ?, 'razorpay', NULL)");
    $desc = "Payment - Razorpay (Receipt {$receiptDisplayId})";
    $financeStmt->execute([$bookingId, $amount, $desc]);
    
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
    NotificationRelay::sendTelegram($tgMsg, 'payment_received', $context);

    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully!']);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
