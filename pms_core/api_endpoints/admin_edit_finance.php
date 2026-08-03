<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/GoogleSheetService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_finance');
$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['id']) || !isset($data['amount']) || !isset($data['description']) || !isset($data['category'])) {
    ApiResponse::error('Missing parameters');
    
}


    $amount = (float)$data['amount'];
    if ($amount <= 0) {
        throw new Exception("Amount must be greater than zero");
    }

    $payment_method = isset($data['payment_method']) ? $data['payment_method'] : 'cash';

    $propertyId = AuthHelper::getPropertyId();
    $stmt = $db->prepare("UPDATE finance_transactions SET amount = :amt, description = :desc, category = :cat, payment_method = :pm WHERE id = :id AND property_id = :prop_id");
    $stmt->execute([
        'amt' => $amount,
        'desc' => $data['description'],
        'cat' => $data['category'],
        'pm' => $payment_method,
        'id' => $data['id'],
        'prop_id' => $propertyId
    ]);
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_FINANCE', 'SYSTEM', $data['id'], [
        'amount' => $amount,
        'description' => $data['description'],
        'category' => $data['category'],
        'payment_method' => $payment_method
    ]);

    try {
        GoogleSheetService::syncExpense($db, (int)$data['id']);
    } catch (\Throwable $t) {
        error_log("Google Sheets expense edit sync error: " . $t->getMessage());
    }
    
    ApiResponse::success();

}, false, true, false);

