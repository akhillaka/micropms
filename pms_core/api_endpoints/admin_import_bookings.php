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
    $sheet = strtolower((string)($_GET['sheet'] ?? 'all'));
    $typeMap = [
        'booking' => 'booking', 'bookings' => 'booking',
        'payment' => 'payment', 'payments' => 'payment',
        'expense' => 'expense', 'expenses' => 'expense',
    ];
    if (isset($typeMap[$sheet])) {
        $type = $typeMap[$sheet];
        $csv = BookingImportService::templateCsv($type);
        $names = ['booking' => 'Bookings.csv', 'payment' => 'Payments.csv', 'expense' => 'Expenses.csv'];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $names[$type] . '"');
        header('Content-Length: ' . (string)strlen($csv));
        echo $csv;
        exit;
    }
    $zip = BookingImportService::templateZip();
    if (str_starts_with($zip, 'Booking ID') || str_starts_with($zip, '"Booking ID')) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Bookings.csv"');
    } else {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="google_sheets_import_template.zip"');
    }
    header('Content-Length: ' . (string)strlen($zip));
    echo $zip;
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
        ApiResponse::error('CSV or ZIP file is required');
    }
    if (($_FILES['file']['size'] ?? 0) > 4 * 1024 * 1024) {
        ApiResponse::error('File is larger than 4 MB');
    }

    $bundle = BookingImportService::parseUpload(
        $_FILES['file']['tmp_name'],
        (string)($_FILES['file']['name'] ?? '')
    );
    if (count($bundle['stays']) > BookingImportService::MAX_STAYS) {
        $bundle['stays'] = array_slice($bundle['stays'], 0, BookingImportService::MAX_STAYS);
    }
    $bundle = BookingImportService::validateBundle(
        $db,
        $propertyId,
        $bundle,
        AuthHelper::can('manage_finance')
    );

    $valid = 0;
    $err = 0;
    foreach (['stays', 'payments', 'expenses'] as $key) {
        foreach ($bundle[$key] as $row) {
            if (empty($row['error'])) {
                $valid++;
            } else {
                $err++;
            }
        }
    }

    if ($action === 'preview') {
        ApiResponse::success([
            'stays' => $bundle['stays'],
            'payments' => $bundle['payments'],
            'expenses' => $bundle['expenses'],
            'format' => $bundle['format'],
            'valid_count' => $valid,
            'error_count' => $err,
        ]);
    }

    $result = BookingImportService::commitBundle($db, $propertyId, $bundle);
    $created = $result['created_stays'] + $result['created_payments'] + $result['created_expenses'];
    $skipped = $result['skipped_stays'] + $result['skipped_payments'] + $result['skipped_expenses'];
    ApiResponse::success([
        'message' => 'Imported ' . $result['created_stays'] . ' stay(s), '
            . $result['created_payments'] . ' payment(s), '
            . $result['created_expenses'] . ' expense(s). Skipped ' . $skipped . '.',
        'created' => $created,
        'skipped' => $skipped,
        'created_stays' => $result['created_stays'],
        'created_payments' => $result['created_payments'],
        'created_expenses' => $result['created_expenses'],
        'errors' => $result['errors'],
    ]);
}, true, true, false);
