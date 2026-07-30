<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('send_whatsapp');

    $data   = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? null;

    // ── 1. Update conversation status ─────────────────────────────────────────
    if ($action === 'status') {
        $conv_id = isset($data['conversation_id']) ? (int)$data['conversation_id'] : 0;
        $status  = $data['status'] ?? '';

        if (!$conv_id || !in_array($status, ['open', 'resolved', 'snoozed'], true)) {
            throw new \Exception('Invalid conversation ID or status');
        }

        $stmt = $db->prepare("UPDATE wa_conversations SET status = ? WHERE id = ?");
        $stmt->execute([$status, $conv_id]);

        if ($stmt->rowCount() === 0) {
            throw new \Exception('Conversation not found');
        }

        ApiResponse::success();
        return;
    }

    // ── 2. Start new outbound chat ────────────────────────────────────────────
    if ($action === 'new_chat') {
        $phone = $data['phone'] ?? '';
        if (!$phone) {
            throw new \Exception('Phone number is required');
        }

        // Normalise — same logic as webhook
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone;
        }
        if (strlen($cleanPhone) < 11 || strlen($cleanPhone) > 15) {
            throw new \Exception('Invalid phone number — include country code (e.g. 917702233496)');
        }

        // Return existing conversation if present (last-10-digit fallback)
        $stmt = $db->prepare("SELECT id FROM wa_conversations WHERE phone_number = ?");
        $stmt->execute([$cleanPhone]);
        $existing = $stmt->fetch();

        if (!$existing) {
            // Try last-10-digit fallback for legacy short-form records
            $last10 = substr($cleanPhone, -10);
            $stmt2  = $db->prepare("SELECT id FROM wa_conversations WHERE phone_number LIKE ? LIMIT 1");
            $stmt2->execute(['%' . $last10]);
            $existing = $stmt2->fetch();
            if ($existing) {
                // Canonicalise the stored number
                $db->prepare("UPDATE wa_conversations SET phone_number = ? WHERE id = ?")
                   ->execute([$cleanPhone, $existing['id']]);
            }
        }

        if ($existing) {
            ApiResponse::success(['conversation_id' => (int)$existing['id']]);
            return;
        }

        // Link to a guest if phone matches
        $guestStmt = $db->prepare("SELECT id FROM guests WHERE phone LIKE ? LIMIT 1");
        $guestStmt->execute(['%' . substr($cleanPhone, -10)]);
        $guest    = $guestStmt->fetch();
        $guest_id = $guest ? (int)$guest['id'] : null;

        $insert = $db->prepare(
            "INSERT INTO wa_conversations (guest_id, phone_number, status, last_message_at) VALUES (?, ?, 'open', NOW())"
        );
        $insert->execute([$guest_id, $cleanPhone]);
        $new_id = (int)$db->lastInsertId();

        ApiResponse::success(['conversation_id' => $new_id]);
        return;
    }

    // ── 3. Assign / unassign a guest to a conversation ───────────────────────
    if ($action === 'assign_guest') {
        $conv_id  = isset($data['conversation_id']) ? (int)$data['conversation_id'] : 0;
        $guest_id = isset($data['guest_id']) ? (int)$data['guest_id'] : null;

        if (!$conv_id) {
            throw new \Exception('conversation_id is required');
        }

        // Verify guest exists when assigning
        if ($guest_id !== null) {
            $gCheck = $db->prepare("SELECT id FROM guests WHERE id = ?");
            $gCheck->execute([$guest_id]);
            if (!$gCheck->fetch()) {
                throw new \Exception('Guest not found');
            }
        }

        $db->prepare("UPDATE wa_conversations SET guest_id = ? WHERE id = ?")
           ->execute([$guest_id, $conv_id]);

        ApiResponse::success();
        return;
    }

    // ── 4. Delete a conversation (and its messages, via CASCADE) ─────────────
    if ($action === 'delete') {
        $conv_id = isset($data['conversation_id']) ? (int)$data['conversation_id'] : 0;
        if (!$conv_id) {
            throw new \Exception('conversation_id is required');
        }

        $db->prepare("DELETE FROM wa_conversations WHERE id = ?")->execute([$conv_id]);
        ApiResponse::success();
        return;
    }

    throw new \Exception('Invalid action');

}, true, true, false);
