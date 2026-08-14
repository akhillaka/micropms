<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/GoogleSheetService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_finance');

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['type'], $data['category'], $data['amount'], $data['description'])) {
        throw new Exception("Missing required fields");
    }
    
    $amount = (float)$data['amount'];
    if ($amount <= 0) throw new Exception("Amount must be greater than 0");
    if (!in_array($data['type'], ['income', 'expense'])) throw new Exception("Invalid transaction type");

    $payment_method = isset($data['payment_method']) ? $data['payment_method'] : 'cash';

    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, amount, description, payment_method, staff_id) VALUES (:pid, :type, :cat, :amt, :desc, :pm, :staff_id)");
    $stmt->execute([
        'pid' => $propertyId,
        'type' => $data['type'],
        'cat' => $data['category'],
        'amt' => $amount,
        'desc' => $data['description'],
        'pm' => $payment_method,
        'staff_id' => $_SESSION['user_id'] ?? null
    ]);
    
    $id = (int)$db->lastInsertId();
    SequenceGenerator::assignDisplayId($db, 'finance_transactions', $id, 'SEQ_TRANSACTION_FORMAT');
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'ADD_FINANCE', 'SYSTEM', $id, $data);

    if ($data['type'] === 'expense') {
        try {
            GoogleSheetService::syncExpense($db, $id);
        } catch (\Throwable $t) {
            error_log("Google Sheets expense sync error: " . $t->getMessage());
        }
    }
    
    ApiResponse::success();

}, true, true, false);

