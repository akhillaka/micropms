<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLogin();
require_once __DIR__ . '/../../pms_core/Database.php';

header('Content-Type: application/json');

$db     = Database::getInstance()->getConnection();
$action = $_GET['action'] ?? 'list';

// ── List conversations ────────────────────────────────────────────────────────
if ($action === 'list') {
    $filter = $_GET['filter'] ?? 'all';
    $search = trim($_GET['search'] ?? '');

    $propertyId = AuthHelper::getPropertyId();
    $where  = ["c.property_id = ?"];
    $params = [$propertyId];

    if ($filter === 'open') {
        $where[] = "c.status = 'open'";
    } elseif ($filter === 'resolved') {
        $where[] = "c.status = 'resolved'";
    } elseif ($filter === 'unregistered') {
        $where[] = "c.guest_id IS NULL";
    }

    if ($search !== '') {
        $where[]  = "(LOWER(g.name) LIKE LOWER(?) OR c.phone_number LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $db->prepare("
        SELECT
            c.id,
            c.phone_number,
            c.status,
            c.last_message_at,
            c.guest_id,
            g.name   AS guest_name,
            (SELECT message_text FROM wa_messages
             WHERE  conversation_id = c.id
             ORDER  BY created_at DESC LIMIT 1)  AS last_message,
            (SELECT direction FROM wa_messages
             WHERE  conversation_id = c.id
             ORDER  BY created_at DESC LIMIT 1)  AS last_direction,
            (SELECT COUNT(*) FROM wa_messages
             WHERE  conversation_id = c.id
               AND  direction = 'inbound'
               AND  status    = 'received') AS unread_count
        FROM  wa_conversations c
        LEFT  JOIN guests g ON c.guest_id = g.id
        $whereSql
        ORDER BY c.last_message_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $chats = $stmt->fetchAll();

    echo json_encode(['success' => true, 'chats' => $chats]);
    exit;
}

// ── Load messages for a conversation ─────────────────────────────────────────
if ($action === 'messages') {
    $conv_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$conv_id) {
        echo json_encode(['success' => false, 'error' => 'No conversation ID provided']);
        exit;
    }

    $propertyId = AuthHelper::getPropertyId();

    // Mark inbound messages as read
    $db->prepare(
        "UPDATE wa_messages SET status = 'read'
         WHERE  conversation_id = ? AND property_id = ? AND direction = 'inbound' AND status = 'received'"
    )->execute([$conv_id, $propertyId]);

    // Fetch all messages
    $stmt = $db->prepare("SELECT * FROM wa_messages WHERE conversation_id = ? AND property_id = ? ORDER BY created_at ASC");
    $stmt->execute([$conv_id, $propertyId]);
    $messages = $stmt->fetchAll();

    // Fetch conversation + guest header info
    $cStmt = $db->prepare("
        SELECT c.phone_number, c.guest_id, c.status AS conv_status,
               g.name AS guest_name, g.city AS guest_city, g.phone AS guest_phone
        FROM   wa_conversations c
        LEFT   JOIN guests g ON c.guest_id = g.id
        WHERE  c.id = ? AND c.property_id = ?
    ");
    $cStmt->execute([$conv_id, $propertyId]);
    $conv = $cStmt->fetch();

    // Fetch the most relevant booking for this guest
    $bookingInfo = null;
    if ($conv) {
        $localPhone = substr((string)$conv['phone_number'], -10);

        if (!empty($conv['guest_id'])) {
            $bStmt = $db->prepare("
                SELECT b.id, b.booking_status AS status,
                       b.check_in, b.check_out, b.total_amount, b.rate_plan_name,
                       r.room_number
                FROM   bookings b
                LEFT   JOIN rooms r ON b.room_id = r.id
                WHERE  b.guest_id = ? AND b.property_id = ?
                  AND  b.booking_status != 'cancelled'
                ORDER  BY FIELD(b.booking_status,'checked_in','booked','confirmed','checked_out') ASC,
                           b.id DESC
                LIMIT 1
            ");
            $bStmt->execute([$conv['guest_id'], $propertyId]);
            $bookingInfo = $bStmt->fetch();
        }

        if (!$bookingInfo) {
            $bStmt = $db->prepare("
                SELECT b.id, b.booking_status AS status,
                       b.check_in, b.check_out, b.total_amount, b.rate_plan_name,
                       r.room_number
                FROM   bookings b
                LEFT   JOIN rooms  r ON b.room_id  = r.id
                LEFT   JOIN guests g ON b.guest_id = g.id
                WHERE  (g.phone = ? OR g.phone LIKE ?) AND b.property_id = ?
                  AND  b.booking_status != 'cancelled'
                ORDER  BY FIELD(b.booking_status,'checked_in','booked','confirmed','checked_out') ASC,
                           b.id DESC
                LIMIT 1
            ");
            $bStmt->execute([$conv['phone_number'], '%' . $localPhone, $propertyId]);
            $bookingInfo = $bStmt->fetch();
        }

        if ($bookingInfo) {
            // Paid amount (negative entries = payments)
            $payStmt = $db->prepare(
                "SELECT IFNULL(SUM(amount), 0) FROM folio_ledger WHERE booking_id = ? AND property_id = ? AND amount < 0"
            );
            $payStmt->execute([$bookingInfo['id'], $propertyId]);
            $bookingInfo['paid_amount'] = abs((float)$payStmt->fetchColumn());

            // Net balance (positive = guest owes money)
            $balStmt = $db->prepare("SELECT IFNULL(SUM(amount), 0) FROM folio_ledger WHERE booking_id = ? AND property_id = ?");
            $balStmt->execute([$bookingInfo['id'], $propertyId]);
            $bookingInfo['balance_due'] = (float)$balStmt->fetchColumn();
        }

        $conv['booking'] = $bookingInfo;
    }

    echo json_encode(['success' => true, 'messages' => $messages, 'info' => $conv]);
    exit;
}

// ── Stats: unread count across all conversations ──────────────────────────────
if ($action === 'unread_count') {
    $propertyId = AuthHelper::getPropertyId();
    $sql = "SELECT COUNT(*) FROM wa_messages m JOIN wa_conversations c ON m.conversation_id = c.id WHERE m.direction = 'inbound' AND m.status = 'received' AND c.property_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$propertyId]);
    echo json_encode(['success' => true, 'unread' => (int)$stmt->fetchColumn()]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
