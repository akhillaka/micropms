<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';




ApiHandler::run(function(\PDO $db) {
AuthHelper::requirePermission('manage_guests');
$data = json_decode(file_get_contents('php://input'), true);
$guestId   = $data['guest_id'] ?? 0;
$name      = trim($data['name'] ?? '');
$phoneRaw  = trim($data['phone'] ?? '');
$phone     = PhoneHelper::toLocal($phoneRaw);  // Normalise to 10-digit
$email     = trim($data['email'] ?? '');
$age       = !empty($data['age']) ? (int)$data['age'] : null;
$city      = trim($data['city'] ?? '');
$state     = trim($data['state'] ?? '');
$country   = trim($data['country'] ?? 'India');
$pincode   = trim($data['pincode'] ?? '');

if (!$guestId || !$name || !$phone) {
    ApiResponse::error('Name and a valid phone number are required');
}


    $stmt = $db->prepare("UPDATE guests SET name = :name, phone = :phone, email = :email, age = :age, city = :city, state = :state, country = :country, pincode = :pincode WHERE id = :id");
    $stmt->execute([
        'name'    => $name,
        'phone'   => $phone,  // stored as 10-digit
        'email'   => $email   ?: null,
        'age'     => $age,
        'city'    => $city    ?: null,
        'state'   => $state   ?: null,
        'country' => $country ?: 'India',
        'pincode' => $pincode ?: null,
        'id'      => $guestId
    ]);

    // Keep wa_conversations.phone_number in sync (E.164 format)
    $e164 = PhoneHelper::toE164($phone);
    if ($e164) {
        $db->prepare(
            "UPDATE wa_conversations SET phone_number = ? WHERE guest_id = ?"
        )->execute([$e164, $guestId]);
    }

    AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_GUEST_PROFILE', 'SYSTEM', $guestId, [
        'name'  => $name,
        'phone' => $phone
    ]);

    ApiResponse::success();

}, true, true, false);

