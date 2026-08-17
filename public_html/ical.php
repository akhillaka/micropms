<?php
declare(strict_types=1);

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/services/IcalService.php';

$token = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['token'] ?? ''));
if (strlen($token) < 32) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Calendar not found';
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $ics = IcalService::exportCalendar($db, $token);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="room.ics"');
    header('Cache-Control: no-cache');
    echo $ics;
} catch (\Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Calendar not found';
}
