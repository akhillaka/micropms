<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';




ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_folio');
$data = json_decode(file_get_contents('php://input'), true);
$ledgerId = $data['ledger_id'] ?? 0;
if (!$ledgerId) {
    ApiResponse::error('Missing ledger ID');
    
}


    $bIdStmt = $db->prepare("SELECT l.booking_id, l.amount, l.description, l.display_id, r.room_number, g.name as guest_name, b.property_id
        FROM folio_ledger l 
        JOIN bookings b ON l.booking_id = b.id 
        JOIN rooms r ON b.room_id = r.id 
        LEFT JOIN guests g ON b.guest_id = g.id 
        WHERE l.id = :id");
    $bIdStmt->execute(['id' => $ledgerId]);
    $info = $bIdStmt->fetch();
    $bId = $info ? $info['booking_id'] : null;

    // Cross-tenant scope check: ensure the entry belongs to this staff's property
    $currentPropertyId = AuthHelper::getPropertyId();
    if ($info && (int)$info['property_id'] !== $currentPropertyId) {
        ApiResponse::error('Forbidden: Ledger entry does not belong to your property', 403);
    }

    $db->beginTransaction();

    if ($info) {
        $displayId = $info['display_id'] ?: 'RCPT-' . $ledgerId;
        $delFinStmt = $db->prepare("DELETE FROM finance_transactions WHERE booking_id = :bid AND description LIKE :desc");
        $delFinStmt->execute([
            'bid' => $bId,
            'desc' => "%{$displayId}%"
        ]);
    }

    $stmt = $db->prepare("DELETE FROM folio_ledger WHERE id = :id");
    $stmt->execute(['id' => $ledgerId]);
    
    $db->commit();
    
    $tgMsg = "🗑️ <b>Folio Entry Deleted</b>\n\nLedger #{$ledgerId} has been removed.";
    
    $context = [
        'guest_name' => $info ? ($info['guest_name'] ?? 'N/A') : 'N/A',
        'room_number' => $info ? ($info['room_number'] ?? 'N/A') : 'N/A',
        'description' => "Entry Deleted: " . ($info ? $info['description'] : ''),
        'amount' => $info ? number_format(abs((float)$info['amount']), 2) : '0.00'
    ];
    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'DELETE_LEDGER', 'FOLIO', $bId ?: $ledgerId, ['ledger_id' => $ledgerId]);
    
    ApiResponse::success();

}, true, true, false);

