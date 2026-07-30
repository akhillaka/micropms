<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';




ApiHandler::run(function(\PDO $db) {
AuthHelper::requirePermission('manage_guests');
$data = json_decode(file_get_contents('php://input'), true);
$bookingId  = $data['booking_id'] ?? 0;
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


    $guestStmt = $db->prepare("SELECT guest_id FROM bookings WHERE id = :id");
    $guestStmt->execute(['id' => $bookingId]);
    $guestId = $guestStmt->fetchColumn();
    
    if ($guestId) {
        $updateGuest = $db->prepare("UPDATE guests SET name = :name, phone = :phone, age = :age, city = :city, state = :state, country = :country, pincode = :pincode WHERE id = :id");
        $updateGuest->execute([
            'name'    => $guestName,
            'phone'   => $guestPhone,  // 10-digit local
            'age'     => $age,
            'city'    => $city    ?: null,
            'state'   => $state   ?: null,
            'country' => $country ?: 'India',
            'pincode' => $pincode ?: null,
            'id'      => $guestId
        ]);

        // Sync wa_conversations.phone_number to E.164
        $e164 = PhoneHelper::toE164($guestPhone);
        if ($e164) {
            $db->prepare("UPDATE wa_conversations SET phone_number = ? WHERE guest_id = ?")
               ->execute([$e164, $guestId]);
        }

        AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_GUEST', 'BOOKING', $bookingId, [
            'guest_id' => $guestId,
            'name'     => $guestName,
            'phone'    => $guestPhone
        ]);
    }

    ApiResponse::success();

}, true, true, false);

