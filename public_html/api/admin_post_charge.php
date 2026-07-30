<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';




require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';


ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_folio');
$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['booking_id']) || !isset($data['item_name']) || !isset($data['amount'])) {
    ApiResponse::error('Missing parameters');
    
}


    $amount = (float)$data['amount'];
    if ($amount <= 0) {
        throw new Exception("Amount must be greater than zero");
    }

    // NOTE: Do NOT update bookings.total_amount here — the folio ledger is the
    // single source of truth for balance. Updating total_amount separately causes
    // it to diverge from SUM(folio_ledger.amount) over time.

    // Insert into folio ledger
    $folioStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, transaction_type, amount, description) VALUES (:id, 'INCIDENTAL', :amount, :desc)");
    $folioStmt->execute([
        'id' => $data['booking_id'],
        'amount' => $amount, // Charges are positive
        'desc' => $data['item_name']
    ]);
    
    SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
    
    AuditLogger::log($_SESSION['user_id'], 'POST_CHARGE', 'FOLIO', $data['booking_id'], ['item' => $data['item_name'], 'amount' => $amount]);
    
    $bStmt = $db->prepare("SELECT r.room_number, g.name as guest_name, b.total_amount FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = :id");
    $bStmt->execute(['id' => $data['booking_id']]);
    $info = $bStmt->fetch();
    if ($info) {
        $tgMsg = "📎 <b>Charge Added</b>\n\nRoom: {$info['room_number']}\nGuest: " . htmlspecialchars($info['guest_name']) . "\nItem: " . htmlspecialchars($data['item_name']) . "\nAmount: ₹" . number_format($amount, 2);
        
        $context = [
            'guest_name' => $info['guest_name'] ?? 'N/A',
            'room_number' => $info['room_number'],
            'description' => "Charge added: " . $data['item_name'],
            'amount' => number_format($amount, 2),
            'total_amount' => number_format((float)$info['total_amount'], 2)
        ];
        NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);
    }

    ApiResponse::success();
    

}, true, true, true);
