<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
require_once __DIR__ . '/../../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

/**
 * XpressBot WhatsApp API Webhook
 *
 * Handles two types of incoming requests:
 *   GET  — Hub verification (subscribe challenge) if applicable
 *   POST — Inbound messages + delivery/read status callbacks
 *
 * CRITICAL: Always return HTTP 200, otherwise the provider will retry indefinitely.
 */

$_raw_body = file_get_contents('php://input');

// ── GET: Hub Verification ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify_token = defined('WA_WEBHOOK_VERIFY_TOKEN')
        ? WA_WEBHOOK_VERIFY_TOKEN
        : (getenv('WA_WEBHOOK_VERIFY_TOKEN') ?: '');

    if ($verify_token === '' || $verify_token === 'micropms_wa_secure_token_123') {
        http_response_code(403);
        exit;
    }

    if (
        isset($_GET['hub_mode'], $_GET['hub_verify_token']) &&
        $_GET['hub_mode'] === 'subscribe' &&
        $_GET['hub_verify_token'] === $verify_token
    ) {
        echo $_GET['hub_challenge'] ?? '';
    } else {
        http_response_code(403);
    }
    exit;
}

// ── POST: Webhook Events ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$appSecret = getenv('WA_APP_SECRET') ?: ($_ENV['WA_APP_SECRET'] ?? '');
if (empty($appSecret) && defined('WA_APP_SECRET')) {
    $appSecret = (string)WA_APP_SECRET;
}
if (empty($appSecret)) {
    http_response_code(403);
    echo json_encode(['error' => 'WhatsApp webhook secret is not configured']);
    exit;
}
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $_raw_body, $appSecret);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$input = $_raw_body; // reuse already-read body
$data  = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'invalid_json']);
    exit;
}


$db = Database::getInstance()->getConnection();

// Extract XpressBot Fields
// Message ID
$message_id = $data['message']['id'] ?? $data['messageId'] ?? $data['id'] ?? null;

// Is this a status update?
$new_status = $data['status'] ?? null;
if (!$new_status && isset($data['message']['status'])) {
    $new_status = $data['message']['status'];
}

// Inbound Message Data
$raw_phone = $data['contact']['phoneNumber'] ?? $data['from'] ?? $data['phone'] ?? null;
$message_text = $data['message']['body'] ?? $data['body'] ?? $data['text'] ?? null;
$msg_type = $data['message']['type'] ?? $data['type'] ?? 'text';

// ── B. Delivery / Read Status Callbacks ───────────────────────────────
if ($message_id && $new_status) {
    $allowed = ['sent', 'delivered', 'read', 'failed'];
    if (in_array($new_status, $allowed, true)) {
        $db->prepare("UPDATE wa_messages SET status = ? WHERE message_id = ? AND direction = 'outbound'")
           ->execute([$new_status, $message_id]);
    }
} 
// ── A. Inbound message ────────────────────────────────────────────────
elseif ($raw_phone && $message_id) {
    // Normalise phone to E.164
    $phone_number = PhoneHelper::toE164($raw_phone);
    if ($phone_number === null) {
        $phone_number = preg_replace('/[^0-9]/', '', (string)$raw_phone);
    }
    
    if (!empty($phone_number)) {
        // Determine message text for media
        if (empty($message_text)) {
            if ($msg_type === 'image') $message_text = '[📷 Image]';
            elseif ($msg_type === 'video') $message_text = '[🎥 Video]';
            elseif ($msg_type === 'audio') $message_text = '[🎙 Audio]';
            elseif ($msg_type === 'document') $message_text = '[📎 Document]';
            elseif ($msg_type === 'location') $message_text = '[📍 Location]';
            elseif ($msg_type === 'sticker') $message_text = '[🎭 Sticker]';
            elseif ($msg_type === 'contacts') $message_text = '[👤 Contact Card]';
            else $message_text = '[' . ucfirst($msg_type) . ' Message]';
        }

        // ── Find or create conversation ───────────────────────────────────
        $stmt = $db->prepare("SELECT id, guest_id, property_id FROM wa_conversations WHERE phone_number = ?");
        $stmt->execute([$phone_number]);
        $conv = $stmt->fetch();

        if (!$conv) {
            // Fallback: last-10-digit match
            $last10 = substr($phone_number, -10);
            $stmt2  = $db->prepare("SELECT id, guest_id, property_id FROM wa_conversations WHERE phone_number = ? LIMIT 1");
            $stmt2->execute([$phone_number]);
            $conv = $stmt2->fetch();

            if ($conv) {
                $db->prepare("UPDATE wa_conversations SET phone_number = ? WHERE id = ?")
                   ->execute([$phone_number, $conv['id']]);
            }
        }

        if ($conv) {
            $conv_id = (int)$conv['id'];
            if (empty($conv['guest_id'])) {
                $last10    = substr($phone_number, -10);
                $guestStmt = $db->prepare("SELECT id FROM guests WHERE phone = ? OR phone = ? LIMIT 1");
                $guestStmt->execute([$phone_number, $last10]);
                if ($guest = $guestStmt->fetch()) {
                    $db->prepare("UPDATE wa_conversations SET guest_id = ? WHERE id = ?")
                       ->execute([$guest['id'], $conv_id]);
                }
            }
            $db->prepare("UPDATE wa_conversations SET last_message_at = NOW(), status = 'open' WHERE id = ?")
               ->execute([$conv_id]);
        } else {
            $last10    = substr($phone_number, -10);
            $guestStmt = $db->prepare("SELECT id, property_id FROM guests WHERE phone = ? OR phone = ? LIMIT 1");
            $guestStmt->execute([$phone_number, $last10]);
            $guest    = $guestStmt->fetch();
            $guest_id = $guest ? (int)$guest['id'] : null;
            $propertyId = $guest ? (int)$guest['property_id'] : 0;
            if ($propertyId <= 0) {
                $propertyId = (int)$db->query("SELECT id FROM properties WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
            }

            $insertStmt = $db->prepare("INSERT INTO wa_conversations (property_id, guest_id, phone_number, last_message_at, status) VALUES (?, ?, ?, NOW(), 'open')");
            $insertStmt->execute([$propertyId, $guest_id, $phone_number]);
            $conv_id = (int)$db->lastInsertId();
            $conv = ['id' => $conv_id, 'guest_id' => $guest_id, 'property_id' => $propertyId];
        }

        $waPropertyId = (int)($conv['property_id'] ?? 0);
        if ($waPropertyId <= 0) {
            $pidStmt = $db->prepare("SELECT property_id FROM wa_conversations WHERE id = ?");
            $pidStmt->execute([$conv_id]);
            $waPropertyId = (int)$pidStmt->fetchColumn();
        }

        // ── Idempotency: ignore duplicates using UNIQUE constraint ──────
        $msgStmt = $db->prepare("INSERT IGNORE INTO wa_messages (conversation_id, direction, message_text, status, message_id) VALUES (?, 'inbound', ?, 'received', ?)");
        $msgStmt->execute([$conv_id, $message_text, $message_id]);
        
        if ($msgStmt->rowCount() === 0) {
            // Duplicate message, already processed concurrently
            http_response_code(200);
            echo json_encode(['status' => 'ignored', 'reason' => 'duplicate']);
            exit;
        }

            // ── AI Guest Concierge Automated Responder ─────────────────────────
            $txtLower = strtolower($message_text);
            $aiReply = '';

            if (preg_match('/wifi|wi-fi|internet|password/i', $txtLower)) {
                $wifiName = defined('PROPERTY_WIFI_NAME') ? PROPERTY_WIFI_NAME : 'Hotel_Guest_WiFi';
            $wifiPass = defined('PROPERTY_WIFI_PASS') ? PROPERTY_WIFI_PASS : '';
            $aiReply = "📶 *Wi-Fi Network:* {$wifiName}\n🔑 *Password:* " . ($wifiPass !== '' ? $wifiPass : 'Ask the front desk');
            } elseif (preg_match('/checkout|check-out|check out|timing/i', $txtLower)) {
                $aiReply = "⏰ *Standard Checkout:* Please refer to your booking summary. Need extra time? Reply *EXTEND* to receive a 1-click extension link.";
            } elseif (preg_match('/extend|extension|more hours/i', $txtLower)) {
                $aiReply = "⏳ *Extend Stay:* You can extend your room directly! Front desk link: " . (defined('PROPERTY_URL') ? PROPERTY_URL : 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')) . "/index.php";
            } elseif (preg_match('/location|address|map|where/i', $txtLower)) {
                $hotelName = defined('PROPERTY_NAME') ? PROPERTY_NAME : 'MicroPMS Hotel';
                $hotelAddr = defined('PROPERTY_ADDRESS') ? PROPERTY_ADDRESS : 'Main Station Road, City Center';
                $aiReply = "📍 *{$hotelName}*\nAddress: {$hotelAddr}";
            }

            if (!empty($aiReply)) {
                $sendResult = NotificationRelay::sendWhatsApp($phone_number, $aiReply, false, $waPropertyId ?: null);
                $msgStatus = (isset($sendResult['ok']) && $sendResult['ok'] === true) ? 'sent' : 'failed';
                $insReply = $db->prepare("INSERT INTO wa_messages (conversation_id, direction, message_text, status) VALUES (?, 'outbound', ?, ?)");
                $insReply->execute([$conv_id, "🤖 [AI Concierge] " . $aiReply, $msgStatus]);
            }

            require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

            $gName = '+' . $phone_number;
            $guestNameQuery = $db->prepare("SELECT g.name FROM wa_conversations c JOIN guests g ON c.guest_id = g.id WHERE c.id = ?");
            $guestNameQuery->execute([$conv_id]);
            if ($fetchedName = $guestNameQuery->fetchColumn()) {
                $gName = $fetchedName;
            }

            $tgMsg = "💬 <b>New WhatsApp Message</b>\n\n"
                   . "<b>From:</b> " . htmlspecialchars($gName) . " (+" . htmlspecialchars($phone_number) . ")\n"
                   . "<b>Message:</b> " . htmlspecialchars($message_text);
            NotificationRelay::sendTelegram($tgMsg, null, [], $waPropertyId ?: null);
    }
}

// Always 200 OK
http_response_code(200);
echo json_encode(['status' => 'success']);
