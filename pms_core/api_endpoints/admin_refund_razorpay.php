<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/services/RazorpayService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('refund_payment');
    $data     = ApiHandler::getJsonInput();
    $ledgerId = (int)($data['ledger_id'] ?? 0);
    if (!$ledgerId) {
        ApiResponse::error('Invalid ledger ID');
    }

    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("SELECT * FROM folio_ledger WHERE id = :id AND property_id = :pid");
    $stmt->execute(['id' => $ledgerId, 'pid' => $propertyId]);
    $ledger = $stmt->fetch();
    
    if (!$ledger || empty($ledger['transaction_ref']) || !str_starts_with($ledger['transaction_ref'], 'pay_')) {
        ApiResponse::error('Invalid or non-Razorpay transaction');
    }
    
    $paymentId = $ledger['transaction_ref'];
    $amountToRefund = abs((float)$ledger['amount']);
    $amountPaise = (int)round($amountToRefund * 100);

    $rz = RazorpayService::forProperty($db, $propertyId);
    if (!$rz) {
        ApiResponse::error('Razorpay is not configured for this property.');
    }

    $refund = $rz->refundPayment($paymentId, $amountPaise);
    $errorText = (string)($refund['error'] ?? '');
    if (empty($refund['success']) && stripos($errorText, 'status should be captured') !== false) {
        $rz->capturePayment($paymentId, $amountPaise);
        $refund = $rz->refundPayment($paymentId, $amountPaise);
    }

    if (empty($refund['success'])) {
        ApiResponse::error('Razorpay API Error', 400, ['details' => $refund['body'] ?? $refund]);
    }

    $refundStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, property_id, transaction_type, amount, transaction_ref, description, payment_method)
                          VALUES (:b, :pid, 'REFUND', :a, :r, :d, 'online_refund')");
    $refundStmt->execute([
        'b'   => $ledger['booking_id'],
        'pid' => (int)$ledger['property_id'],
        'a'   => $amountToRefund,
        'r'   => $refund['refund_id'] ?? ('refund_' . time()),
        'd'   => 'Refund for ' . $paymentId
    ]);

    AuditLogger::log($_SESSION['user_id'] ?? null, 'REFUND_PAYMENT', 'FOLIO', $ledger['booking_id'], [
        'amount' => $amountToRefund,
        'original_payment_id' => $paymentId,
        'refund_id' => $refund['refund_id'] ?? null
    ]);

    $tgMsg = "🔄 <b>Payment Refunded</b>\n\nBooking ID: {$ledger['booking_id']}\nAmount: ₹" . number_format($amountToRefund, 2) . "\nPayment ID: {$paymentId}";

    $context = [
        'booking_id' => $ledger['booking_id'],
        'guest_name' => 'N/A',
        'amount' => number_format($amountToRefund, 2),
        'ref' => $paymentId,
        'description' => "Razorpay payment refunded: " . $paymentId
    ];
    $bInfoStmt = $db->prepare("SELECT r.room_number, g.name as guest_name FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = :id AND b.property_id = :pid");
    $bInfoStmt->execute(['id' => $ledger['booking_id'], 'pid' => $propertyId]);
    $bInfo = $bInfoStmt->fetch();
    if ($bInfo) {
        $context['guest_name'] = $bInfo['guest_name'] ?? 'N/A';
        $context['room_number'] = $bInfo['room_number'];
    }

    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);

    ApiResponse::success();

}, true, true, true);
