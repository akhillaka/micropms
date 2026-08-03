<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('send_whatsapp');

    $data = json_decode(file_get_contents('php://input'), true);

    $conv_id     = isset($data['conversation_id']) ? (int)$data['conversation_id'] : 0;
    $message     = $data['message'] ?? '';
    $is_template = (bool)($data['is_template'] ?? false);

    if (!$conv_id || $message === '') {
        throw new \Exception('Missing conversation_id or message');
    }

    // Fetch conversation (phone_number + current status)
    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("SELECT id, phone_number, status FROM wa_conversations WHERE id = ? AND property_id = ?");
    $stmt->execute([$conv_id, $propertyId]);
    $conv = $stmt->fetch();

    if (!$conv) {
        throw new \Exception('Conversation not found');
    }

    // ── Send via WhatsApp Cloud API ───────────────────────────────────────────
    if ($is_template) {
        $payload = json_decode($message, true);
        if (!is_array($payload)) {
            throw new \Exception('Invalid template payload JSON');
        }
        $waRes = NotificationRelay::sendWhatsApp($conv['phone_number'], $payload, true);
        $resultMsgId = $waRes['ok'] ? ($waRes['messageId'] ?? null) : false;
        $logMessage  = '[Template] ' . ($payload['name'] ?? 'unknown');
    } else {
        $waRes = NotificationRelay::sendWhatsApp($conv['phone_number'], $message, false);
        $resultMsgId = $waRes['ok'] ? ($waRes['messageId'] ?? null) : false;
        $logMessage  = $message;
    }

    if ($resultMsgId === false) {
        // Log the failure but still return a meaningful error
        throw new \Exception('WhatsApp API rejected the message. Check your token, phone number ID, and 24-hour messaging window.');
    }

    // ── Persist outbound message ──────────────────────────────────────────────
    $msgStmt = $db->prepare(
        "INSERT INTO wa_messages (property_id, conversation_id, direction, message_text, status, message_id) VALUES (?, ?, 'outbound', ?, 'sent', ?)"
    );
    $msgStmt->execute([$propertyId, $conv_id, $logMessage, $resultMsgId]);
    $msgId = (int)$db->lastInsertId();

    // Reopen resolved conversations when staff actively replies
    $db->prepare(
        "UPDATE wa_conversations SET last_message_at = NOW(), status = 'open' WHERE id = ? AND property_id = ?"
    )->execute([$conv_id, $propertyId]);

    // Return the saved message so the UI can append it immediately (no extra reload needed)
    $msgRow = $db->prepare("SELECT * FROM wa_messages WHERE id = ? AND property_id = ?");
    $msgRow->execute([$msgId, $propertyId]);

    ApiResponse::success(['message' => $msgRow->fetch()]);

}, true, true, false);
