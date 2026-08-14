<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_settings');

    $data = json_decode(file_get_contents('php://input'), true);
    $propertyId = AuthHelper::getPropertyId();
    
    $settings = [
        'GUEST_PORTAL_UPSELL_ENABLED'        => ($data['upsell_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_POS_ENABLED'           => ($data['pos_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_SELF_CHECKOUT_ENABLED' => ($data['self_checkout_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_HOUSEKEEPING_ENABLED'  => ($data['housekeeping_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_EARLY_LATE_FEE'        => strval(floatval($data['early_late_fee'] ?? 0.00)),
        'GUEST_PORTAL_LOYALTY_ENABLED'       => ($data['loyalty_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_LOYALTY_GOLD'          => strval(intval($data['loyalty_gold'] ?? 5)),
        'GUEST_PORTAL_LOYALTY_PLATINUM'      => strval(intval($data['loyalty_platinum'] ?? 10)),
        'GUEST_PORTAL_PRE_ARRIVAL_ENABLED'   => ($data['pre_arrival_enabled'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_PRE_ARRIVAL_SIGNATURE' => ($data['pre_arrival_signature'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_PRE_ARRIVAL_DOC'       => ($data['pre_arrival_doc'] ?? false) ? 'true' : 'false',
        'GUEST_PORTAL_UPSELL_BREAKFAST_PRICE'=> strval(floatval($data['upsell_breakfast_price'] ?? 350.00)),
        'GUEST_PORTAL_UPSELL_TRANSFER_PRICE' => strval(floatval($data['upsell_transfer_price'] ?? 1200.00)),
        'GUEST_PORTAL_OTP_ENABLED'           => ($data['otp_enabled'] ?? false) ? 'true' : 'false',
        // New Guest Portal Info Settings
        'GUEST_PORTAL_WIFI_SSID'             => trim($data['wifi_ssid'] ?? ''),
        'GUEST_PORTAL_WIFI_PASSWORD'         => trim($data['wifi_password'] ?? ''),
        'GUEST_PORTAL_HELP_DESK_NO'          => trim($data['help_desk_no'] ?? ''),
        'GUEST_PORTAL_LOCAL_ATTRACTIONS'     => trim($data['local_attractions'] ?? ''),
    ];

    $stmt = $db->prepare("INSERT INTO system_settings (property_id, key_name, key_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    
    foreach ($settings as $key => $val) {
        $stmt->execute([$propertyId, $key, $val]);
    }

    AuditLogger::log($_SESSION['user_id'], 'UPDATE_GUEST_PORTAL_SETTINGS', 'SYSTEM', null, $settings);

    ApiResponse::success(['message' => 'Guest Portal settings updated successfully']);
}, true, true, false);
