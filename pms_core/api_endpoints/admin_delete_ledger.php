<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('void_folio_item');
$data = ApiHandler::getJsonInput();
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

    $role = AuthHelper::getRole();
    $canDelete = in_array($role, ['superadmin', 'owner', 'admin'], true);

    $db->beginTransaction();

    if ($info) {
        $displayId = $info['display_id'] ?: 'RCPT-' . $ledgerId;
        
        if ($canDelete) {
            // Hard delete for owner/admin
            $delFinStmt = $db->prepare("DELETE FROM finance_transactions WHERE booking_id = :bid AND description LIKE :desc AND property_id = :pid");
            $delFinStmt->execute([
                'bid' => $bId,
                'desc' => "%{$displayId}%",
                'pid' => $currentPropertyId
            ]);
            
            $stmt = $db->prepare("DELETE FROM folio_ledger WHERE id = :id AND property_id = :pid");
            $stmt->execute(['id' => $ledgerId, 'pid' => $currentPropertyId]);
        } else {
            // Role based immutability - Void (Zero Out) the entry instead of deleting
            $voidFinStmt = $db->prepare("UPDATE finance_transactions SET amount = 0, description = CONCAT('[VOID] ', description) WHERE booking_id = :bid AND description LIKE :desc AND property_id = :pid");
            $voidFinStmt->execute([
                'bid' => $bId,
                'desc' => "%{$displayId}%",
                'pid' => $currentPropertyId
            ]);

            $voidStmt = $db->prepare("UPDATE folio_ledger SET amount = 0, cgst_amount = 0, sgst_amount = 0, description = CONCAT('[VOID] ', description), deleted_at = NOW() WHERE id = :id AND property_id = :pid");
            $voidStmt->execute(['id' => $ledgerId, 'pid' => $currentPropertyId]);
        }
    }

    $db->commit();
    
    if ($canDelete) {
        $tgMsg = "🗑️ <b>Folio Entry Deleted</b>\n\nLedger #{$ledgerId} has been removed.";
    } else {
        $tgMsg = "↩️ <b>Folio Entry Voided</b>\n\nLedger #{$ledgerId} has been voided (amount zeroed).";
    }
    
    $context = [
        'guest_name' => $info ? ($info['guest_name'] ?? 'N/A') : 'N/A',
        'room_number' => $info ? ($info['room_number'] ?? 'N/A') : 'N/A',
        'description' => ($canDelete ? "Entry Deleted: " : "Entry Rebated: ") . ($info ? $info['description'] : ''),
        'amount' => $info ? number_format(abs((float)$info['amount']), 2) : '0.00'
    ];
    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);
    
    AuditLogger::log($_SESSION['user_id'] ?? null, $canDelete ? 'DELETE_LEDGER' : 'REBATE_LEDGER', 'FOLIO', $bId ?: $ledgerId, ['ledger_id' => $ledgerId]);
    
    ApiResponse::success();

}, true, true, false);

