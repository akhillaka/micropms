<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../../pms_core/TenantScope.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_guests');
    $data = ApiHandler::getJsonInput();
    $bookingId  = (int)($data['booking_id'] ?? 0);
    $guestName  = trim($data['guest_name'] ?? '');
    $guestPhone = PhoneHelper::toLocal(trim($data['guest_phone'] ?? ''));
    $age        = !empty($data['age']) ? (int)$data['age'] : null;
    $city       = trim($data['city'] ?? '');
    $state      = trim($data['state'] ?? '');
    $country    = trim($data['country'] ?? 'India');
    $pincode    = trim($data['pincode'] ?? '');

    if (!$bookingId || !$guestName || !$guestPhone) {
        ApiResponse::error('Missing or invalid fields — check name and phone');
    }

    $propertyId = AuthHelper::getPropertyId();
    $booking = TenantScope::booking($db, $bookingId, $propertyId);
    $guestId = (int)$booking['guest_id'];
    if ($guestId <= 0) {
        ApiResponse::error('Booking has no guest assigned');
    }
    TenantScope::guest($db, $guestId, $propertyId);

    $updateGuest = $db->prepare("UPDATE guests SET name = :name, phone = :phone, age = :age, city = :city, state = :state, country = :country, pincode = :pincode WHERE id = :id AND property_id = :pid");
    $updateGuest->execute([
        'name'    => $guestName,
        'phone'   => $guestPhone,
        'age'     => $age,
        'city'    => $city    ?: null,
        'state'   => $state   ?: null,
        'country' => $country ?: 'India',
        'pincode' => $pincode ?: null,
        'id'      => $guestId,
        'pid'     => $propertyId,
    ]);

    $e164 = PhoneHelper::toE164($guestPhone);
    if ($e164) {
        $db->prepare("UPDATE wa_conversations SET phone_number = ? WHERE guest_id = ? AND property_id = ?")
           ->execute([$e164, $guestId, $propertyId]);
    }

    AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_GUEST', 'BOOKING', $bookingId, [
        'guest_id' => $guestId,
        'name'     => $guestName,
        'phone'    => $guestPhone
    ]);

    ApiResponse::success();

}, true, true, false);
