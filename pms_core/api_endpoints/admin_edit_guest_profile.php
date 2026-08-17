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
    $guestId   = (int)($data['guest_id'] ?? 0);
    $name      = trim($data['name'] ?? '');
    $phoneRaw  = trim($data['phone'] ?? '');
    $phone     = PhoneHelper::toLocal($phoneRaw);
    $email     = trim($data['email'] ?? '');
    $age       = !empty($data['age']) ? (int)$data['age'] : null;
    $city      = trim($data['city'] ?? '');
    $state     = trim($data['state'] ?? '');
    $country   = trim($data['country'] ?? 'India');
    $pincode   = trim($data['pincode'] ?? '');

    if (!$guestId || !$name || !$phone) {
        ApiResponse::error('Name and a valid phone number are required');
    }

    $propertyId = AuthHelper::getPropertyId();
    TenantScope::guest($db, $guestId, $propertyId);

    $stmt = $db->prepare("UPDATE guests SET name = :name, phone = :phone, email = :email, age = :age, city = :city, state = :state, country = :country, pincode = :pincode WHERE id = :id AND property_id = :pid");
    $stmt->execute([
        'name'    => $name,
        'phone'   => $phone,
        'email'   => $email   ?: null,
        'age'     => $age,
        'city'    => $city    ?: null,
        'state'   => $state   ?: null,
        'country' => $country ?: 'India',
        'pincode' => $pincode ?: null,
        'id'      => $guestId,
        'pid'     => $propertyId,
    ]);

    $e164 = PhoneHelper::toE164($phone);
    if ($e164) {
        $db->prepare(
            "UPDATE wa_conversations SET phone_number = ? WHERE guest_id = ? AND property_id = ?"
        )->execute([$e164, $guestId, $propertyId]);
    }

    AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_GUEST_PROFILE', 'SYSTEM', $guestId, [
        'name'  => $name,
        'phone' => $phone
    ]);

    ApiResponse::success();

}, true, true, false);
