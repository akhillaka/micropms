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
$amount = floatval($data['amount'] ?? 0);
$desc = trim($data['description'] ?? '');
$method = trim($data['payment_method'] ?? '');
if (!$ledgerId || !$desc) {
    ApiResponse::error('Missing fields');
    
}


    // Fetch original amount to preserve sign logic
    $origStmt = $db->prepare("SELECT amount FROM folio_ledger WHERE id = :id");
    $origStmt->execute(['id' => $ledgerId]);
    $origAmt = (float)$origStmt->fetchColumn();
    
    // Ensure we keep the same mathematical sign (payments remain negative, charges remain positive)
    $amount = abs($amount);
    if ($origAmt < 0) {
        $amount = -$amount;
    }

    // Only update method if it was provided
    if ($method !== '') {
        $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc, payment_method = :pm WHERE id = :id");
        $stmt->execute(['amt' => $amount, 'desc' => $desc, 'pm' => $method, 'id' => $ledgerId]);
    } else {
        $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc WHERE id = :id");
        $stmt->execute(['amt' => $amount, 'desc' => $desc, 'id' => $ledgerId]);
    }

    $tgMsg = "✏️ <b>Folio Entry Edited</b>\n\nLedger #{$ledgerId}\nDescription: " . htmlspecialchars($desc) . "\nNew Amount: ₹" . number_format($amount, 2);
    
    // Attempt to get booking_id and details
    $bIdStmt = $db->prepare("SELECT l.booking_id, r.room_number, g.name as guest_name FROM folio_ledger l JOIN bookings b ON l.booking_id = b.id JOIN rooms r ON b.room_id = r.id LEFT JOIN guests g ON b.guest_id = g.id WHERE l.id = :id");
    $bIdStmt->execute(['id' => $ledgerId]);
    $info = $bIdStmt->fetch();
    
    $context = [
        'guest_name' => $info ? ($info['guest_name'] ?? 'N/A') : 'N/A',
        'room_number' => $info ? ($info['room_number'] ?? 'N/A') : 'N/A',
        'description' => "Entry Edited: " . $desc,
        'amount' => number_format($amount, 2)
    ];
    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);
    
    $bId = $info ? $info['booking_id'] : null;
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_LEDGER', 'FOLIO', $bId ?: $ledgerId, [
        'ledger_id' => $ledgerId,
        'amount' => $amount,
        'description' => $desc,
        'payment_method' => $method
    ]);
    
    ApiResponse::success();

}, true, true, false);

