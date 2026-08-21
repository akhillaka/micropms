<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/services/CityLedgerService.php';

ApiHandler::run(function (\PDO $db) {
    $data = ApiHandler::getJsonInput();
    if (!is_array($data) || $data === []) {
        $data = $_POST;
    }
    $action = (string)($data['action'] ?? $_GET['action'] ?? '');
    $propertyId = AuthHelper::getPropertyId();

    if ($action === 'list_companies') {
        AuthHelper::requirePermission('view_finance');
        $companies = CityLedgerService::getCompanies($db, $propertyId);
        $totalAr = 0.0;
        foreach ($companies as $c) {
            $totalAr += (float)($c['balance'] ?? 0);
        }
        ApiResponse::success([
            'companies' => $companies,
            'count' => count($companies),
            'total_ar' => $totalAr,
        ]);
    }

    if ($action === 'get_company') {
        AuthHelper::requirePermission('view_finance');
        $companyId = (int)($data['company_id'] ?? 0);
        $company = CityLedgerService::getCompany($db, $propertyId, $companyId);
        if (!$company) {
            ApiResponse::error('Company not found', 404);
        }
        $lines = CityLedgerService::getLedgerLines($db, $propertyId, $companyId);
        ApiResponse::success(['company' => $company, 'ledger' => $lines]);
    }

    if ($action === 'create_company') {
        AuthHelper::requirePermission('manage_finance');
        $id = CityLedgerService::createCompany($db, $propertyId, $data);
        AuditLogger::log($_SESSION['user_id'] ?? null, 'CREATE_COMPANY', 'COMPANY', $id, [
            'name' => $data['name'] ?? '',
        ], $propertyId);
        ApiResponse::success(['company_id' => $id, 'message' => 'Company created']);
    }

    if ($action === 'update_company') {
        AuthHelper::requirePermission('manage_finance');
        $companyId = (int)($data['company_id'] ?? 0);
        CityLedgerService::updateCompany($db, $propertyId, $companyId, $data);
        AuditLogger::log($_SESSION['user_id'] ?? null, 'UPDATE_COMPANY', 'COMPANY', $companyId, [
            'name' => $data['name'] ?? '',
        ], $propertyId);
        ApiResponse::success(['message' => 'Company updated']);
    }

    if ($action === 'delete_company') {
        AuthHelper::requirePermission('manage_finance');
        $companyId = (int)($data['company_id'] ?? 0);
        CityLedgerService::softDeleteCompany($db, $propertyId, $companyId);
        AuditLogger::log($_SESSION['user_id'] ?? null, 'DELETE_COMPANY', 'COMPANY', $companyId, [], $propertyId);
        ApiResponse::success(['message' => 'Company archived']);
    }

    if ($action === 'transfer_booking') {
        AuthHelper::requirePermission('manage_finance');
        $bookingId = (int)($data['booking_id'] ?? 0);
        $companyId = (int)($data['company_id'] ?? 0);
        $result = CityLedgerService::transferBookingToCityLedger($db, $bookingId, $companyId, $propertyId);
        AuditLogger::log($_SESSION['user_id'] ?? null, 'CITY_LEDGER_TRANSFER', 'BOOKING', $bookingId, $result, $propertyId);
        ApiResponse::success($result);
    }

    if ($action === 'record_payment') {
        AuthHelper::requirePermission('manage_finance');
        $companyId = (int)($data['company_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $ref = trim((string)($data['reference'] ?? 'MANUAL'));
        $result = CityLedgerService::recordCompanyPayment($db, $propertyId, $companyId, $amount, $ref);
        AuditLogger::log($_SESSION['user_id'] ?? null, 'CITY_LEDGER_PAYMENT', 'COMPANY', $companyId, [
            'amount' => $amount,
            'reference' => $ref,
        ], $propertyId);
        ApiResponse::success($result);
    }

    if ($action === 'link_booking_company') {
        if (!AuthHelper::can('edit_folio') && !AuthHelper::can('manage_finance')) {
            ApiResponse::error('Permission denied', 403);
        }
        $bookingId = (int)($data['booking_id'] ?? 0);
        $companyId = (int)($data['company_id'] ?? 0);
        CityLedgerService::linkBookingCompany($db, $propertyId, $bookingId, $companyId);
        AuditLogger::log($_SESSION['user_id'] ?? null, 'LINK_BOOKING_COMPANY', 'BOOKING', $bookingId, [
            'company_id' => $companyId,
        ], $propertyId);
        ApiResponse::success(['message' => 'Company linked to booking']);
    }

    ApiResponse::error('Unknown action', 400);
}, true, true, false);
