<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_finance');
$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['id'])) {
    ApiResponse::error('Missing parameters');
    
}


    $stmt = $db->prepare("DELETE FROM finance_transactions WHERE id = :id");
    $stmt->execute(['id' => $data['id']]);
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'DELETE_FINANCE', 'SYSTEM', $data['id']);
    
    ApiResponse::success();

}, false, true, false);

