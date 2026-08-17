<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/TenantScope.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('send_whatsapp');

    $data   = ApiHandler::getJsonInput();
    $action = $data['action'] ?? null;
    $propertyId = AuthHelper::getPropertyId();

    if ($action === 'status') {
        $conv_id = isset($data['conversation_id']) ? (int)$data['conversation_id'] : 0;
        $status  = $data['status'] ?? '';

        if (!$conv_id || !in_array($status, ['open', 'resolved', 'snoozed'], true)) {
            throw new \Exception('Invalid conversation ID or status');
        }

        TenantScope::conversation($db, $conv_id, $propertyId);

        $stmt = $db->prepare("UPDATE wa_conversations SET status = ? WHERE id = ? AND property_id = ?");
        $stmt->execute([$status, $conv_id, $propertyId]);

        if ($stmt->rowCount() === 0) {
            throw new \Exception('Conversation not found');
        }

        ApiResponse::success();
        return;
    }

    if ($action === 'new_chat') {
        $phone = $data['phone'] ?? '';
        if (!$phone) {
            throw new \Exception('Phone number is required');
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone;
        }
        if (strlen($cleanPhone) < 11 || strlen($cleanPhone) > 15) {
            throw new \Exception('Invalid phone number — include country code (e.g. 917702233496)');
        }

        $stmt = $db->prepare("SELECT id FROM wa_conversations WHERE phone_number = ? AND property_id = ?");
        $stmt->execute([$cleanPhone, $propertyId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            $last10 = substr($cleanPhone, -10);
            $stmt2  = $db->prepare("SELECT id FROM wa_conversations WHERE phone_number LIKE ? AND property_id = ? LIMIT 1");
            $stmt2->execute(['%' . $last10, $propertyId]);
            $existing = $stmt2->fetch();
            if ($existing) {
                $db->prepare("UPDATE wa_conversations SET phone_number = ? WHERE id = ? AND property_id = ?")
                   ->execute([$cleanPhone, $existing['id'], $propertyId]);
            }
        }

        if ($existing) {
            ApiResponse::success(['conversation_id' => (int)$existing['id']]);
            return;
        }

        $guestStmt = $db->prepare("SELECT id FROM guests WHERE phone LIKE ? AND property_id = ? LIMIT 1");
        $guestStmt->execute(['%' . substr($cleanPhone, -10), $propertyId]);
        $guest    = $guestStmt->fetch();
        $guest_id = $guest ? (int)$guest['id'] : null;

        $insert = $db->prepare(
            "INSERT INTO wa_conversations (guest_id, phone_number, status, last_message_at, property_id) VALUES (?, ?, 'open', NOW(), ?)"
        );
        $insert->execute([$guest_id, $cleanPhone, $propertyId]);
        $new_id = (int)$db->lastInsertId();

        ApiResponse::success(['conversation_id' => $new_id]);
        return;
    }

    if ($action === 'assign_guest') {
        $conv_id  = isset($data['conversation_id']) ? (int)$data['conversation_id'] : 0;
        $guest_id = isset($data['guest_id']) ? (int)$data['guest_id'] : null;

        if (!$conv_id) {
            throw new \Exception('conversation_id is required');
        }

        TenantScope::conversation($db, $conv_id, $propertyId);

        if ($guest_id !== null && $guest_id > 0) {
            TenantScope::guest($db, $guest_id, $propertyId);
        } else {
            $guest_id = null;
        }

        $db->prepare("UPDATE wa_conversations SET guest_id = ? WHERE id = ? AND property_id = ?")
           ->execute([$guest_id, $conv_id, $propertyId]);

        ApiResponse::success();
        return;
    }

    if ($action === 'delete') {
        $conv_id = isset($data['conversation_id']) ? (int)$data['conversation_id'] : 0;
        if (!$conv_id) {
            throw new \Exception('conversation_id is required');
        }

        TenantScope::conversation($db, $conv_id, $propertyId);
        $db->prepare("DELETE FROM wa_conversations WHERE id = ? AND property_id = ?")->execute([$conv_id, $propertyId]);
        ApiResponse::success();
        return;
    }

    throw new \Exception('Invalid action');

}, true, true, false);
