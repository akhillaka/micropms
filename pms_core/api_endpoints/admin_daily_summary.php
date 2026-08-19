<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/DailySummaryService.php';

$isCli = (php_sapi_name() === 'cli');

ApiHandler::run(function (\PDO $db) use ($isCli) {
    if (!$isCli) {
        AuthHelper::requirePermission('view_finance');
    }

    $propertyId = $isCli ? (isset($_SERVER['argv'][1]) ? (int)$_SERVER['argv'][1] : 0) : AuthHelper::getPropertyId();
    if ($propertyId <= 0) {
        if ($isCli) {
            echo "Usage: php admin_daily_summary.php <property_id>\n";
            return;
        }
        ApiResponse::error('No property selected', 400);
    }

    $result = DailySummaryService::send($db, $propertyId, date('Y-m-d'), !$isCli);

    if ($isCli) {
        echo ($result['message'] ?? 'Done') . "\n";
        return;
    }
    if (empty($result['ok'])) {
        ApiResponse::error((string)($result['message'] ?? 'Daily summary failed'), 400);
    }
    ApiResponse::success([
        'sent' => true,
        'telegram' => !empty($result['telegram']),
        'pdf' => !empty($result['pdf']),
        'message' => (string)$result['message'],
    ]);
}, !$isCli, !$isCli, false);
