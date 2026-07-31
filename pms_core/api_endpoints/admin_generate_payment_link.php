<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('generate_payment_link');

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['booking_id'])) {
        throw new Exception("Missing booking ID");
    }

    $stmt = $db->prepare("SELECT b.*, g.name as guest_name, g.phone as guest_phone FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = :id");
    $stmt->execute(['id' => $data['booking_id']]);
    $booking = $stmt->fetch();
    
    if (!$booking) throw new Exception("Booking not found");

    $ledgerStmt = $db->prepare("SELECT SUM(amount) as balance_due FROM folio_ledger WHERE booking_id = :id");
    $ledgerStmt->execute(['id' => $booking['id']]);
    $balance = (float)$ledgerStmt->fetchColumn();
    
    if ($balance <= 0) throw new Exception("Balance is fully paid or in credit");
    
    $keyId = defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '';
    $keySecret = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';
    
    $paymentLink = '';
    if (!empty($keyId) && !empty($keySecret) && $keyId !== 'rzp_test_placeholder') {
        $ch = curl_init('https://api.razorpay.com/v1/payment_links');
        curl_setopt($ch, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        $callbackUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . '/index.php?booking_id=' . $booking['id'];
        
        // Razorpay expects contact with country code (E.164 without +)
        $razorpayPhone = PhoneHelper::toE164($booking['guest_phone'] ?? '') ?? $booking['guest_phone'];

        $payload = [
            'amount'         => round($balance * 100),
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
        ];
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpcode >= 200 && $httpcode < 300) {
            $res = json_decode($response, true);
            $paymentLink = $res['short_url'] ?? '';
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
            $convStmt = $db->prepare("SELECT id FROM wa_conversations WHERE phone_number = ?");
            $convStmt->execute([$waPhone]);
            $convId = $convStmt->fetchColumn();
            
            if (!$convId) {
                $guestId = !empty($booking['guest_id']) ? (int)$booking['guest_id'] : null;
                $insConv = $db->prepare("INSERT INTO wa_conversations (guest_id, phone_number, last_message_at, status) VALUES (?, ?, NOW(), 'open')");
                $insConv->execute([$guestId, $waPhone]);
                $convId = (int)$db->lastInsertId();
            } else {
                $db->prepare("UPDATE wa_conversations SET last_message_at = NOW(), status = 'open' WHERE id = ?")->execute([$convId]);
            }
            
            // Log the free-text message to conversations
            $insMsg = $db->prepare("INSERT INTO wa_messages (conversation_id, direction, message_text, status, message_id) VALUES (?, 'outbound', ?, 'sent', ?)");
            $insMsg->execute([$convId, $message, $realWaMsgId]);
        }
    }
    
    AuditLogger::log($_SESSION['user_id'], 'GENERATE_PAYMENT_LINK', 'BOOKING', $booking['id'], ['amount' => $balance, 'link' => $paymentLink]);
    
    ApiResponse::success(['link' => $paymentLink]);

}, true, true, false);

