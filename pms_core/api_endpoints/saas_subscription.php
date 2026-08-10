<?php
declare(strict_types=1);
require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/services/RazorpayService.php';

ApiHandler::run(function(\PDO $db) {
    if (AuthHelper::getRole() !== 'owner') {
        ApiResponse::error("Only property owners can manage SaaS subscriptions", 403);
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST ?? [];
    $propertyId = AuthHelper::getPropertyId();
    $action = $data['action'] ?? $_GET['action'] ?? '';

    // The SaaS razorpay credentials (global)
    $keyId = defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '';
    $keySecret = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';
    $isLive = defined('RAZORPAY_LIVE_MODE') && RAZORPAY_LIVE_MODE === 'true';

    if (empty($keyId) || empty($keySecret)) {
        ApiResponse::error("SaaS Payments are not configured globally");
    }

    $rz = new RazorpayService($keyId, $keySecret, $isLive);

    if ($action === 'create_subscription') {
        $planId = trim($data['plan_id'] ?? '');
        if (!$planId) ApiResponse::error("Plan ID required");

        $notes = ['property_id' => $propertyId];
        $result = $rz->createSubscription($planId, 12, $notes);
        
        if ($result['success']) {
            // Store it as 'pending'
            $stmt = $db->prepare("
                INSERT INTO saas_subscriptions 
                (property_id, gateway, gateway_sub_id, plan, amount, currency, status, starts_at, ends_at) 
                VALUES (?, 'razorpay', ?, ?, ?, 'INR', 'manual', NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR))
            ");
            $stmt->execute([
                $propertyId,
                $result['subscription_id'],
                $planId, // you can fetch amount from DB if you map it
                0 // Or proper amount
            ]);

            ApiResponse::success([
                'subscription_id' => $result['subscription_id'],
                'short_url' => $result['short_url']
            ]);
        }

        ApiResponse::error($result['error'] ?? "Failed to create subscription");
    }
    
    ApiResponse::error("Unknown action: " . htmlspecialchars($action));
});
