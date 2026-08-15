<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/services/BookingImportService.php';
require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (($_GET['action'] ?? '') === 'template')) {
    AuthHelper::requireLogin();
    AuthHelper::requirePermission('create_booking');
    $csv = BookingImportService::templateCsv();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="booking_import_template.csv"');
    header('Content-Length: ' . (string)strlen($csv));
    echo $csv;
    exit;
}

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('create_booking');
    $action = $_POST['action'] ?? '';
    $propertyId = AuthHelper::getPropertyId();

    if (!in_array($action, ['preview', 'commit'], true)) {
        ApiResponse::error('Invalid action');
    }
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        ApiResponse::error('CSV file is required');
    }
    if (($_FILES['file']['size'] ?? 0) > 2 * 1024 * 1024) {
        ApiResponse::error('File is larger than 2 MB');
    }

    $stays = BookingImportService::parseFile($_FILES['file']['tmp_name']);
    if (count($stays) > BookingImportService::MAX_STAYS) {
        $stays = array_slice($stays, 0, BookingImportService::MAX_STAYS);
    }
    $stays = BookingImportService::validateStays($db, $propertyId, $stays);

    $valid = 0;
    $err = 0;
    foreach ($stays as $s) {
        if (empty($s['error'])) {
            $valid++;
        } else {
            $err++;
        }
    }

    if ($action === 'preview') {
        ApiResponse::success([
            'stays' => $stays,
            'valid_count' => $valid,
            'error_count' => $err,
        ]);
    }

    $result = BookingImportService::commit($db, $propertyId, $stays);
    ApiResponse::success([
        'message' => 'Imported ' . $result['created'] . ' stay(s). Skipped ' . $result['skipped'] . '.',
        'created' => $result['created'],
        'skipped' => $result['skipped'],
        'errors' => $result['errors'],
    ]);
}, true, true, false);
