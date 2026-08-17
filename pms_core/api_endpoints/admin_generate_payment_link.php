<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../../pms_core/services/RazorpayService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('generate_payment_link');

    $data = ApiHandler::getJsonInput();
    
    if (!isset($data['booking_id'])) {
        throw new Exception("Missing booking ID");
    }

    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("SELECT b.*, g.name as guest_name, g.phone as guest_phone FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = :id AND b.property_id = :pid");
    $stmt->execute(['id' => $data['booking_id'], 'pid' => $propertyId]);
    $booking = $stmt->fetch();
    
    if (!$booking) throw new Exception("Booking not found");

    $ledgerStmt = $db->prepare("SELECT SUM(amount) as balance_due FROM folio_ledger WHERE booking_id = :id");
    $ledgerStmt->execute(['id' => $booking['id']]);
    $balance = (float)$ledgerStmt->fetchColumn();
    
    if ($balance <= 0) throw new Exception("Balance is fully paid or in credit");
    
    $paymentLink = '';
    $rz = RazorpayService::forProperty($db, $propertyId);
    if ($rz) {
        $callbackUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . '/index.php?booking_id=' . $booking['id'];
        $razorpayPhone = PhoneHelper::toE164($booking['guest_phone'] ?? '') ?? $booking['guest_phone'];
        $linkRes = $rz->createPaymentLink([
            'amount'         => (int)round($balance * 100),
            'currency'       => 'INR',
            'accept_partial' => false,
            'description'    => 'Payment for Booking #' . $booking['id'] . ' at ' . (defined('PROPERTY_NAME') ? PROPERTY_NAME : 'MicroPMS Hotel'),
            'customer'       => [
                'name'    => $booking['guest_name'],
                'contact' => $razorpayPhone
            ],
            'notify' => [
                'sms' => false,
                'email' => false
            ],
            'reminder_enable' => false,
            'callback_url' => $callbackUrl,
            'callback_method' => 'get'
        ]);
        if (!empty($linkRes['success'])) {
            $paymentLink = $linkRes['short_url'] ?? '';
        }
    }
    
    if (empty($paymentLink)) {
        // Fallback to local guest payment / portal page link
        $paymentLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . '/index.php?booking_id=' . $booking['id'];
    }
    
    $message = "Hi {$booking['guest_name']}, you have an outstanding balance of Rs.{$balance} for your stay. Please pay using this link: {$paymentLink}";
    // Use E.164 phone so NotificationRelay doesn't have to guess format
    $waPhone = PhoneHelper::toE164($booking['guest_phone'] ?? '') ?? $booking['guest_phone'];
    
    // Attempt to trigger the WhatsApp automation first (for 'payment_link' template)
    $autoTriggered = NotificationRelay::triggerAutomation('payment_link', $waPhone, (int)$booking['id'], [
        'payment_link' => $paymentLink,
        'balance_amount' => number_format($balance, 2)
    ]);
    
    // If the template isn't mapped or fails, fall back to the raw free-text message
    if (!$autoTriggered) {
        $waRes = NotificationRelay::sendWhatsApp($waPhone, $message, false);
        
        if (is_array($waRes) && !empty($waRes['ok'])) {
            $realWaMsgId = $waRes['messageId'] ?? null;
            
            // Find or create conversation
            $convStmt = $db->prepare("SELECT id FROM wa_conversations WHERE phone_number = ? AND property_id = ?");
            $convStmt->execute([$waPhone, $propertyId]);
            $convId = $convStmt->fetchColumn();
            
            if (!$convId) {
                $guestId = !empty($booking['guest_id']) ? (int)$booking['guest_id'] : null;
                $insConv = $db->prepare("INSERT INTO wa_conversations (guest_id, phone_number, last_message_at, status, property_id) VALUES (?, ?, NOW(), 'open', ?)");
                $insConv->execute([$guestId, $waPhone, $propertyId]);
                $convId = (int)$db->lastInsertId();
            } else {
                $db->prepare("UPDATE wa_conversations SET last_message_at = NOW(), status = 'open' WHERE id = ? AND property_id = ?")->execute([$convId, $propertyId]);
            }
            
            // Log the free-text message to conversations
            $insMsg = $db->prepare("INSERT INTO wa_messages (conversation_id, direction, message_text, status, message_id) VALUES (?, 'outbound', ?, 'sent', ?)");
            $insMsg->execute([$convId, $message, $realWaMsgId]);
        }
    }
    
    AuditLogger::log($_SESSION['user_id'], 'GENERATE_PAYMENT_LINK', 'BOOKING', $booking['id'], ['amount' => $balance, 'link' => $paymentLink]);
    
    ApiResponse::success(['link' => $paymentLink]);

}, true, true, false);

