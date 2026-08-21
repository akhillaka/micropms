<?php
declare(strict_types=1);
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    http_response_code(405);
    die("Method Not Allowed - POST only");
}
require_once __DIR__ . '/../pms_core/config.php';
require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../pms_core/AuditLogger.php';
require_once __DIR__ . '/../pms_core/services/FolioService.php';
require_once __DIR__ . '/../pms_core/services/RazorpayService.php';

$db = Database::getInstance()->getConnection();
$webhookSignature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

if (!is_array($data) || !isset($data['event'])) {
    http_response_code(200);
    echo "OK: Empty or invalid payload ignored";
    exit;
}

$paymentEntity = $data['payload']['payment']['entity'] ?? [];
$orderEntity = $data['payload']['order']['entity'] ?? [];
$orderId = $paymentEntity['order_id'] ?? $orderEntity['id'] ?? null;
if (!$orderId && ($data['event'] === 'payment.failed')) {
    $orderId = $data['payload']['payment']['entity']['order_id'] ?? null;
}
$subEntity = $data['payload']['subscription']['entity'] ?? [];
$propertyId = 0;

if ($orderId) {
    $peek = $db->prepare("SELECT property_id FROM bookings WHERE razorpay_order_id = ? LIMIT 1");
    $peek->execute([$orderId]);
    $propertyId = (int)$peek->fetchColumn();
}
if ($propertyId <= 0 && str_starts_with((string)$data['event'], 'subscription.')) {
    $propertyId = (int)($subEntity['notes']['property_id'] ?? 0);
    if ($propertyId <= 0 && !empty($subEntity['id'])) {
        $find = $db->prepare("SELECT property_id FROM saas_subscriptions WHERE gateway_sub_id = ? LIMIT 1");
        $find->execute([(string)$subEntity['id']]);
        $propertyId = (int)$find->fetchColumn();
    }
}

$webhookSecret = RazorpayService::webhookSecretForProperty($db, $propertyId);
$placeholderSecrets = ['', 'your_webhook_secret', 'rzp_secret_placeholder'];
if (in_array($webhookSecret, $placeholderSecrets, true)) {
    http_response_code(500);
    echo "Webhook secret is not configured";
    exit;
}

$expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
if (!hash_equals($expectedSignature, $webhookSignature)) {
    // Last chance: try global secret when property secret mismatched (migration / misconfig)
    $global = RazorpayService::globalWebhookSecret();
    if ($global !== $webhookSecret && !in_array($global, $placeholderSecrets, true)) {
        $expectedSignature = hash_hmac('sha256', $payload, $global);
    }
    if (!hash_equals($expectedSignature, $webhookSignature)) {
        http_response_code(400);
        echo "Invalid signature";
        exit;
    }
}

try {
    if ($data['event'] === 'payment.captured' || $data['event'] === 'order.paid') {
        $paymentId = $paymentEntity['id'] ?? null;
        
        if ($orderId) {
            $db->beginTransaction();
            
            $stmt = $db->prepare("SELECT b.*, g.name as guest_name, g.phone as guest_phone FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id WHERE b.razorpay_order_id = :order_id FOR UPDATE");
            $stmt->execute(['order_id' => $orderId]);
            $booking = $stmt->fetch();
            
            // Replay Protection: check if payment transaction reference already exists
            if ($paymentId) {
                try {
                    $db->prepare("INSERT INTO processed_webhook_events (provider, event_id) VALUES ('razorpay', ?)")->execute([$paymentId]);
                } catch (\PDOException $e) {
                    $db->rollBack();
                    require_once __DIR__ . '/../pms_core/DeferredSideEffects.php';
                    DeferredSideEffects::discard();
                    http_response_code(200);
                    echo "OK: Already processed";
                    exit;
                }
                if ($booking) {
                    $checkRef = $db->prepare("SELECT COUNT(*) FROM folio_ledger WHERE transaction_ref = :ref AND property_id = :pid");
                    $checkRef->execute(['ref' => $paymentId, 'pid' => (int)$booking['property_id']]);
                } else {
                    $checkRef = $db->prepare("SELECT COUNT(*) FROM folio_ledger WHERE transaction_ref = :ref");
                    $checkRef->execute(['ref' => $paymentId]);
                }
                if ((int)$checkRef->fetchColumn() > 0) {
                    $db->rollBack();
                    require_once __DIR__ . '/../pms_core/DeferredSideEffects.php';
                    DeferredSideEffects::discard();
                    http_response_code(200);
                    echo "OK: Already processed";
                    exit;
                }
            }
            
            if ($booking) {
                $amountPaid = (float)($paymentEntity['amount'] ?? $orderEntity['amount'] ?? 0) / 100;
                $bookingPropertyId = (int)$booking['property_id'];

                if ($booking['payment_status'] === 'pending_hold') {
                    $updateStmt = $db->prepare("UPDATE bookings SET payment_status = 'completed_paid' WHERE id = :id AND property_id = :pid");
                    $updateStmt->execute(['id' => $booking['id'], 'pid' => $bookingPropertyId]);
                }

                FolioService::recordPayment($db, (int)$booking['id'], $amountPaid, 'Razorpay', (string)$paymentId, 'webhook');

                $db->commit();

                try {
                    AuditLogger::log(null, 'RECORD_PAYMENT', 'FOLIO', $booking['id'], [
                        'amount' => $amountPaid,
                        'method' => 'online',
                        'ref' => $paymentId,
                        'source' => 'webhook'
                    ], $bookingPropertyId);

                    // Retrieve room number for notification
                    $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = :id AND property_id = :pid");
                    $roomStmt->execute(['id' => $booking['room_id'], 'pid' => $bookingPropertyId]);
                    $room = $roomStmt->fetch();
                    $roomNumber = $room ? $room['room_number'] : $booking['room_id'];

                    // Send WhatsApp Automation
                    NotificationRelay::triggerAutomation('booking_confirmed', null, $booking['id']);

                    // Send Telegram
                    $tgMsg = "✅ <b>Booking Confirmed</b>\n\nRoom: {$roomNumber}\nGuest: " . htmlspecialchars((string)($booking['guest_name'])) . "\nCheck-in: {$booking['check_in']}\nCheck-out: {$booking['check_out']}\nAmount: ₹" . number_format($amountPaid, 2);

                    $context = [
                        'guest_name' => $booking['guest_name'] ?? 'N/A',
                        'room_number' => $roomNumber,
                        'check_in_date' => $booking['check_in'],
                        'check_out_date' => $booking['check_out'],
                        'total_amount' => number_format((float)$booking['total_amount'], 2),
                        'paid_amount' => number_format($amountPaid, 2)
                    ];
                    NotificationRelay::sendTelegram($tgMsg, 'booking_confirmed', $context, $bookingPropertyId);
                } catch (\Throwable $sideEffectError) {
                    error_log("Razorpay Webhook Side Effect Error: " . $sideEffectError->getMessage());
                }
                require_once __DIR__ . '/../pms_core/DeferredSideEffects.php';
                DeferredSideEffects::flushAndDrain(3, 600);
            } else {
                $db->rollBack();
                require_once __DIR__ . '/../pms_core/DeferredSideEffects.php';
                DeferredSideEffects::discard();
            }
        }
    } elseif ($data['event'] === 'payment.failed') {
        $orderId = $data['payload']['payment']['entity']['order_id'] ?? null;
        if ($orderId) {
            $stmt = $db->prepare("SELECT id, payment_status, property_id FROM bookings WHERE razorpay_order_id = :order_id");
            $stmt->execute(['order_id' => $orderId]);
            $booking = $stmt->fetch();
            
            if ($booking && $booking['payment_status'] === 'pending_hold') {
                $db->beginTransaction();
                $updateStmt = $db->prepare("UPDATE bookings SET payment_status = 'cancelled', booking_status = 'cancelled' WHERE id = ? AND property_id = ?");
                $updateStmt->execute([$booking['id'], (int)$booking['property_id']]);
                $db->commit();
                AuditLogger::log(null, 'PAYMENT_FAILED', 'BOOKING', $booking['id'], [
                    'ref' => $orderId,
                    'source' => 'razorpay_webhook'
                ], (int)$booking['property_id']);
            }
        }
    } elseif (str_starts_with((string)$data['event'], 'subscription.')) {
        $sub = $data['payload']['subscription']['entity'] ?? [];
        $subId = (string)($sub['id'] ?? '');
        $eventId = (string)$data['event'] . ':' . $subId . ':' . (string)($data['created_at'] ?? time());
        if ($subId !== '') {
            try {
                $db->prepare("INSERT INTO processed_webhook_events (provider, event_id) VALUES ('razorpay', ?)")->execute([$eventId]);
            } catch (\PDOException $e) {
                http_response_code(200);
                echo "OK: Already processed";
                exit;
            }

            $propertyId = (int)($sub['notes']['property_id'] ?? 0);
            if ($propertyId <= 0) {
                $find = $db->prepare("SELECT property_id FROM saas_subscriptions WHERE gateway_sub_id = ? LIMIT 1");
                $find->execute([$subId]);
                $propertyId = (int)$find->fetchColumn();
            }
            if ($propertyId > 0) {
                $statusMap = [
                    'subscription.activated' => 'active',
                    'subscription.charged' => 'active',
                    'subscription.authenticated' => 'active',
                    'subscription.updated' => 'active',
                    'subscription.pending' => 'past_due',
                    'subscription.halted' => 'past_due',
                    'subscription.cancelled' => 'cancelled',
                    'subscription.completed' => 'cancelled',
                ];
                $status = $statusMap[$data['event']] ?? 'manual';
                $endsAt = !empty($sub['current_end'])
                    ? date('Y-m-d H:i:s', (int)$sub['current_end'])
                    : date('Y-m-d H:i:s', strtotime('+30 days'));
                $plan = (string)($sub['notes']['plan'] ?? $sub['plan_id'] ?? 'starter');
                $amountPaise = (int)($data['payload']['payment']['entity']['amount'] ?? 0);
                $amount = $amountPaise > 0 ? round($amountPaise / 100, 2) : 0.0;

                $exists = $db->prepare("SELECT id FROM saas_subscriptions WHERE gateway_sub_id = ? LIMIT 1");
                $exists->execute([$subId]);
                $subRowId = $exists->fetchColumn();
                if ($subRowId) {
                    $db->prepare("
                        UPDATE saas_subscriptions
                        SET status = ?, ends_at = ?, plan = ?, amount = IF(? > 0, ?, amount)
                        WHERE id = ?
                    ")->execute([$status, $endsAt, $plan, $amount, $amount, $subRowId]);
                } else {
                    $db->prepare("
                        INSERT INTO saas_subscriptions
                            (property_id, gateway, gateway_sub_id, plan, amount, currency, status, starts_at, ends_at)
                        VALUES (?, 'razorpay', ?, ?, ?, 'INR', ?, NOW(), ?)
                    ")->execute([$propertyId, $subId, $plan, $amount, $status, $endsAt]);
                }

                $db->prepare("
                    UPDATE properties
                    SET subscription_status = ?, valid_until = ?, is_active = IF(? = 'cancelled', is_active, 1)
                    WHERE id = ?
                ")->execute([$status, $endsAt, $status, $propertyId]);

                AuditLogger::log(null, 'SAAS_SUBSCRIPTION_' . strtoupper((string)$data['event']), 'PROPERTY', $propertyId, [
                    'gateway_sub_id' => $subId,
                    'status' => $status,
                    'valid_until' => $endsAt,
                ], $propertyId);
            }
        }
    }
} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Razorpay Webhook Fatal Error: " . $e->getMessage());
}

http_response_code(200);
echo "OK";
