<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';




ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('refund_payment');
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $ledgerId = (int)($data['ledger_id'] ?? 0);
    if (!$ledgerId) {
        ApiResponse::error('Invalid ledger ID');
    }


    // Get the ledger entry — scoped to this property to prevent cross-tenant refunds
    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("SELECT * FROM folio_ledger WHERE id = :id AND property_id = :pid");
    $stmt->execute(['id' => $ledgerId, 'pid' => $propertyId]);
    $ledger = $stmt->fetch();
    
    if (!$ledger || empty($ledger['transaction_ref']) || !str_starts_with($ledger['transaction_ref'], 'pay_')) {
        ApiResponse::error('Invalid or non-Razorpay transaction');
    }
    
    $paymentId = $ledger['transaction_ref'];
    $amountToRefund = abs($ledger['amount']);
    
    $keyId = defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '';
    $keySecret = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';
    
    if (empty($keyId) || empty($keySecret)) {
        ApiResponse::error('Razorpay keys not configured.');
    }
    
    // Call Razorpay Refund API
    $ch = curl_init("https://api.razorpay.com/v1/payments/{$paymentId}/refund");
    curl_setopt($ch, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'amount' => round($amountToRefund * 100),
        'speed'  => 'normal'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $res = json_decode((string)$response, true);
    
    // Fallback: If payment is only authorized, capture it first, then retry refund.
    if ($httpcode >= 400 && isset($res['error']['description']) && strpos($res['error']['description'], 'status should be captured') !== false) {
        // Capture the payment first
        $captureCh = curl_init("https://api.razorpay.com/v1/payments/{$paymentId}/capture");
        curl_setopt($captureCh, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
        curl_setopt($captureCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($captureCh, CURLOPT_POST, true);
        curl_setopt($captureCh, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($captureCh, CURLOPT_TIMEOUT, 30);
        curl_setopt($captureCh, CURLOPT_POSTFIELDS, json_encode([
            'amount'   => round($amountToRefund * 100),
            'currency' => 'INR'
        ]));
        curl_setopt($captureCh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($captureCh);
        curl_close($captureCh);

        // Retry Refund — fresh handle (old one is already closed)
        $retryCh = curl_init("https://api.razorpay.com/v1/payments/{$paymentId}/refund");
        curl_setopt($retryCh, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
        curl_setopt($retryCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($retryCh, CURLOPT_POST, true);
        curl_setopt($retryCh, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($retryCh, CURLOPT_TIMEOUT, 30);
        curl_setopt($retryCh, CURLOPT_POSTFIELDS, json_encode([
            'amount' => round($amountToRefund * 100),
            'speed'  => 'normal'
        ]));
        curl_setopt($retryCh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($retryCh);
        $httpcode = curl_getinfo($retryCh, CURLINFO_HTTP_CODE);
        curl_close($retryCh);
        $res = json_decode((string)$response, true);
    }
    
    if ($httpcode >= 200 && $httpcode < 300) {
        
        // Add a positive ledger entry (Debit) to cancel out the negative payment (Credit)
        // 'REFUND' is a valid ENUM value (migration 010 adds it); 'refund' lowercase would fail strict mode
        $refundStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, property_id, transaction_type, amount, transaction_ref, description, payment_method)
                              VALUES (:b, :pid, 'REFUND', :a, :r, :d, 'online_refund')");
        $refundStmt->execute([
            'b'   => $ledger['booking_id'],
            'pid' => (int)$ledger['property_id'],
            'a'   => $amountToRefund,
            'r'   => $res['id'] ?? ('refund_' . time()),
            'd'   => 'Refund for ' . $paymentId
        ]);
        
        // Log it
        AuditLogger::log($_SESSION['user_id'] ?? null, 'REFUND_PAYMENT', 'FOLIO', $ledger['booking_id'], [
            'amount' => $amountToRefund,
            'original_payment_id' => $paymentId,
            'refund_id' => $res['id'] ?? null
        ]);
        
        // Send Notification
        $tgMsg = "🔄 <b>Payment Refunded</b>\n\nBooking ID: {$ledger['booking_id']}\nAmount: ₹" . number_format($amountToRefund, 2) . "\nPayment ID: {$paymentId}";
        
        $context = [
            'booking_id' => $ledger['booking_id'],
            'guest_name' => 'N/A', // We can fallback or lookup name, but let's query room details in context if possible
            'amount' => number_format($amountToRefund, 2),
            'ref' => $paymentId,
            'description' => "Razorpay payment refunded: " . $paymentId
        ];
        // We also want to query the guest name for the context if available
        $bInfoStmt = $db->prepare("SELECT r.room_number, g.name as guest_name FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = :id");
        $bInfoStmt->execute(['id' => $ledger['booking_id']]);
        $bInfo = $bInfoStmt->fetch();
        if ($bInfo) {
            $context['guest_name'] = $bInfo['guest_name'] ?? 'N/A';
            $context['room_number'] = $bInfo['room_number'];
        }
        
        NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);
        
        ApiResponse::success();
    } else {
        ApiResponse::error('Razorpay API Error', 400, ['details' => $res]);
    }

}, true, true, true); // useTransaction=true — ensures atomic ledger entry creation
