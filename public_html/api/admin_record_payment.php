<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('record_payment');
    
    $data = json_decode(file_get_contents('php://input'), true);
    $bookingId = (int)($data['booking_id'] ?? 0);
    $amount = floatval($data['amount'] ?? 0);
    // Accept both 'method' and 'payment_method' for backward compatibility
    $method = $data['payment_method'] ?? $data['method'] ?? 'cash';
    $ref = $data['payment_ref'] ?? $data['ref'] ?? '';
    
    if (!$bookingId || $amount == 0) {
        ApiResponse::error('Invalid input');
    }

    // Validate booking exists and is not cancelled
    $bStmt = $db->prepare("SELECT b.*, r.room_number, g.name as guest_name 
        FROM bookings b 
        JOIN rooms r ON b.room_id = r.id 
        LEFT JOIN guests g ON b.guest_id = g.id 
        WHERE b.id = :id AND b.payment_status != 'cancelled'");
    $bStmt->execute(['id' => $bookingId]);
    $booking = $bStmt->fetch();
    
    if (!$booking) {
        ApiResponse::error('Booking not found or cancelled');
    }

    // Auto-capture Razorpay payment if applicable
    if ($method === 'online' && str_starts_with($ref, 'pay_')) {
        require_once __DIR__ . '/../../pms_core/config.php';
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
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // Log Razorpay capture attempt
            if ($httpcode < 200 || $httpcode >= 300) {
                AuditLogger::log($_SESSION['user_id'], 'RAZORPAY_CAPTURE_FAILED', 'FOLIO', $bookingId, [
                    'http_code' => $httpcode,
                    'response' => $response,
                    'error' => $curlError
                ]);
            }
        }
    }
    
    // Use shared FolioService for standardized recording
    $entryId = FolioService::recordPayment($db, $bookingId, $amount, $method, $ref, 'admin');

    // Record finance transaction (matching assistant behavior)
    $receiptStmt = $db->prepare("SELECT display_id FROM folio_ledger WHERE id = ?");
    $receiptStmt->execute([$entryId]);
    $receiptDisplayId = $receiptStmt->fetchColumn() ?: 'RCPT-' . $entryId;
    
    $financeStmt = $db->prepare("INSERT INTO finance_transactions (type, category, booking_id, amount, description, payment_method, staff_id) VALUES ('income', 'booking', :bid, :amount, :desc, :method, :staff)");
    $financeStmt->execute([
        'bid' => $bookingId,
        'amount' => $amount,
        'desc' => "Payment - " . ucfirst($method) . " (Receipt {$receiptDisplayId})",
        'method' => strtolower($method),
        'staff' => $_SESSION['user_id'] ?? null
    ]);
    SequenceGenerator::assignDisplayId($db, 'finance_transactions', (int)$db->lastInsertId(), 'SEQ_TRANSACTION_FORMAT');

    // Telegram notification
    $tgMsg = "💰 <b>Payment Received</b>\n\nRoom: {$booking['room_number']}\nGuest: " . htmlspecialchars($booking['guest_name']) . "\nAmount: ₹" . number_format($amount, 2) . "\nMethod: " . ucfirst($method);
    $context = [
        'guest_name' => $booking['guest_name'] ?? 'N/A',
        'room_number' => $booking['room_number'],
        'amount' => number_format($amount, 2),
        'method' => ucfirst($method),
        'ref' => $ref ?: 'N/A'
    ];
    NotificationRelay::sendTelegram($tgMsg, 'payment_received', $context);
    
    ApiResponse::success(['entry_id' => $entryId, 'receipt_id' => $receiptDisplayId]);

}, true, true, true); // requireAdmin=true, requireCsrf=true, useTransaction=true
