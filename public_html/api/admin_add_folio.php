<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_folio');

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['booking_id'], $data['amount'], $data['type'])) {
        throw new Exception("Missing parameters");
    }

    $stmt = $db->prepare("INSERT INTO folio_ledger (booking_id, transaction_type, amount, transaction_ref) VALUES (:id, :type, :amount, :ref)");
    $stmt->execute([
        'id' => $data['booking_id'],
        'type' => $data['type'],
        'amount' => $data['amount'],
        'ref' => 'MANUAL_ENTRY'
    ]);
    
    SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
    
    AuditLogger::log($_SESSION['user_id'], 'ADD_FOLIO_PAYMENT', 'BOOKING', $data['booking_id'], ['amount' => $data['amount'], 'type' => $data['type']]);
    
    ApiResponse::success();

}, true, true, false);

