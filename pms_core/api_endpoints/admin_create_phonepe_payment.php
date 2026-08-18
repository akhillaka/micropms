<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/PhonePeService.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('generate_payment_link');
    $data = ApiHandler::getJsonInput();
    $amount = floatval($data['amount'] ?? 0);
    $bookingId = (int)($data['booking_id'] ?? 0);

    if ($amount <= 0 || !$bookingId) {
        ApiResponse::error('Invalid input');
    }

    $propertyId = AuthHelper::getPropertyId();
    TenantScope::booking($db, $bookingId, $propertyId);

    $pp = PhonePeService::forProperty($db, $propertyId);
    if (!$pp) {
        ApiResponse::error('PhonePe is not configured for this property in Settings → Payments.');
    }

    $guestPhone = '';
    $gStmt = $db->prepare("SELECT g.phone FROM bookings b LEFT JOIN guests g ON g.id = b.guest_id WHERE b.id = ? AND b.property_id = ?");
    $gStmt->execute([$bookingId, $propertyId]);
    $guestPhone = (string)($gStmt->fetchColumn() ?: '');

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $redirectUrl = $scheme . '://' . $host . '/admin/folio?id=' . $bookingId;
    $callbackUrl = $scheme . '://' . $host . '/webhook_phonepe';
    $merchantTxnId = 'pay_' . $bookingId . '_' . time();

    $result = $pp->initiatePayment((int)round($amount * 100), $merchantTxnId, $callbackUrl, $redirectUrl, $guestPhone);
    if (empty($result['success'])) {
        ApiResponse::error($result['error'] ?? 'Failed to start PhonePe payment');
    }

    ApiResponse::success([
        'redirect_url' => $result['redirect_url'],
        'transaction_id' => $result['transaction_id'] ?? $merchantTxnId,
    ]);
}, true, true, false);
