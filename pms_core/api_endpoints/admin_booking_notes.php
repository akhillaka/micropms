<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function (\PDO $db) {
    $data = ApiHandler::getJsonInput();
    if (!is_array($data) || $data === []) {
        $data = array_merge($_GET, $_POST);
    }
    $action = (string)($data['action'] ?? $_GET['action'] ?? 'list');
    $propertyId = AuthHelper::getPropertyId();
    $bookingId = (int)($data['booking_id'] ?? $_GET['booking_id'] ?? 0);
    if ($bookingId <= 0) {
        ApiResponse::error('booking_id is required');
    }

    $bStmt = $db->prepare('SELECT id FROM bookings WHERE id = ? AND property_id = ? LIMIT 1');
    $bStmt->execute([$bookingId, $propertyId]);
    if (!$bStmt->fetchColumn()) {
        ApiResponse::error('Booking not found', 404);
    }

    if ($action === 'list') {
        AuthHelper::requirePermission('view_folio');
        $stmt = $db->prepare("
            SELECT bn.id, bn.note, bn.created_at, bn.staff_id, COALESCE(s.username, 'Staff') AS staff_name
            FROM booking_notes bn
            LEFT JOIN staff_users s ON s.id = bn.staff_id
            WHERE bn.booking_id = ?
            ORDER BY bn.created_at DESC, bn.id DESC
        ");
        $stmt->execute([$bookingId]);
        ApiResponse::success(['notes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'create') {
        AuthHelper::requirePermission('edit_folio');
        $note = trim((string)($data['note'] ?? ''));
        if ($note === '') {
            ApiResponse::error('Note text is required');
        }
        if (mb_strlen($note) > 5000) {
            ApiResponse::error('Note is too long');
        }
        $staffId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $ins = $db->prepare('INSERT INTO booking_notes (booking_id, staff_id, note) VALUES (?, ?, ?)');
        $ins->execute([$bookingId, $staffId, $note]);
        AuditLogger::log($staffId, 'ADD_BOOKING_NOTE', 'BOOKING', $bookingId, [
            'note_preview' => mb_substr($note, 0, 120),
        ], $propertyId);
        ApiResponse::success(['id' => (int)$db->lastInsertId(), 'message' => 'Note added']);
    }

    ApiResponse::error('Unknown action', 400);
}, true, true, false);
