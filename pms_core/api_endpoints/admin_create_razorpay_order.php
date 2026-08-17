<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/RazorpayService.php';

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

    $rz = RazorpayService::forProperty($db, $propertyId);
    if (!$rz) {
        ApiResponse::error('Razorpay keys not fully configured for this property in Settings.');
    }

    $receipt = 'bk_' . $bookingId . '_' . time();
    $result = $rz->createOrder((int)round($amount * 100), 'INR', $receipt, [
        'booking_id' => (string)$bookingId,
        'property_id' => (string)$propertyId,
    ]);

    if (empty($result['success'])) {
        ApiResponse::error($result['error'] ?? 'Failed to create Razorpay order');
    }

    $up = $db->prepare("UPDATE bookings SET razorpay_order_id = ? WHERE id = ? AND property_id = ?");
    $up->execute([$result['order_id'], $bookingId, $propertyId]);
    ApiResponse::success(['order_id' => $result['order_id'], 'key_id' => $rz->getKeyId()]);
}, true, true, false);
