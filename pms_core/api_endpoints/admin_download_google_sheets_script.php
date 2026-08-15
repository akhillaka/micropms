<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';

AuthHelper::requireLogin();
AuthHelper::requirePermission('manage_settings');

$path = __DIR__ . '/../../pms_core/google_sheets/Code.gs';
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Apps Script file is missing.';
    exit;
}

$contents = file_get_contents($path);
header('Content-Type: application/javascript; charset=utf-8');
header('Content-Disposition: attachment; filename="MicroPMS_GoogleSheets.gs"');
header('Content-Length: ' . (string)strlen($contents));
echo $contents;
exit;
