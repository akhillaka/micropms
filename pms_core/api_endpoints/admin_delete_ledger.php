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

    $role = AuthHelper::getRole();
    $canDelete = in_array($role, ['superadmin', 'owner', 'admin'], true);

    $db->beginTransaction();

    if ($info) {
        $displayId = $info['display_id'] ?: 'RCPT-' . $ledgerId;
        
        if ($canDelete) {
            // Hard delete
            $delFinStmt = $db->prepare("DELETE FROM finance_transactions WHERE booking_id = :bid AND description LIKE :desc AND property_id = :pid");
            $delFinStmt->execute([
                'bid' => $bId,
                'desc' => "%{$displayId}%",
                'pid' => $currentPropertyId
            ]);
            
            $stmt = $db->prepare("DELETE FROM folio_ledger WHERE id = :id AND property_id = :pid");
            $stmt->execute(['id' => $ledgerId, 'pid' => $currentPropertyId]);
        } else {
            // Role based immutability - post a rebate instead
            $rebateAmount = -(float)$info['amount'];
            
            // Insert rebate into folio_ledger
            $rebateStmt = $db->prepare("
                INSERT INTO folio_ledger (property_id, booking_id, amount, description, type, recorded_by, created_at, cgst_amount, sgst_amount)
                VALUES (:pid, :bid, :amt, :desc, :type, :uid, NOW(), 0, 0)
            ");
            $rebateStmt->execute([
                'pid' => $currentPropertyId,
                'bid' => $bId,
                'amt' => $rebateAmount,
                'desc' => "Rebate for: " . $info['description'] . " (Ref: {$displayId})",
                'type' => 'REBATE',
                'uid' => $_SESSION['user_id'] ?? null
            ]);
            $newLedgerId = $db->lastInsertId();
            SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$newLedgerId, 'SEQ_RECEIPT_FORMAT', 'display_id');
            
            // Insert rebate into finance_transactions if it was a payment
            if ($rebateAmount > 0) { // meaning original was negative (payment)
                $finStmt = $db->prepare("
                    INSERT INTO finance_transactions (property_id, booking_id, type, category, amount, payment_method, description, recorded_by, created_at)
                    VALUES (:pid, :bid, 'EXPENSE', 'REFUND', :amt, 'CASH', :desc, :uid, NOW())
                ");
                $finStmt->execute([
                    'pid' => $currentPropertyId,
                    'bid' => $bId,
                    'amt' => $rebateAmount, // Rebate payment is an expense (giving money back)
                    'desc' => "Refund for: " . $info['description'],
                    'uid' => $_SESSION['user_id'] ?? null
                ]);
            }
        }
    }

    $db->commit();
    
    if ($canDelete) {
        $tgMsg = "🗑️ <b>Folio Entry Deleted</b>\n\nLedger #{$ledgerId} has been removed.";
    } else {
        $tgMsg = "↩️ <b>Folio Entry Rebated</b>\n\nLedger #{$ledgerId} has been voided via Rebate.";
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

