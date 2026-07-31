<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/GoogleSheetService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_settings');

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $action = $data['action'] ?? 'bulk_sync';

    if ($action === 'test') {
        $url = $data['webhook_url'] ?? (defined('GOOGLE_SHEETS_WEBHOOK_URL') ? GOOGLE_SHEETS_WEBHOOK_URL : '');
        $res = GoogleSheetService::testConnection($url);
        if ($res['success']) {
            ApiResponse::success(['message' => $res['message']]);
        } else {
            ApiResponse::error($res['message']);
        }
    } elseif ($action === 'bulk_sync') {
        $type = $data['type'] ?? 'all';
        $res = GoogleSheetService::bulkSync($db, $type);
        if ($res['success']) {
            ApiResponse::success(['message' => $res['message'], 'count' => $res['count']]);
        } else {
            ApiResponse::error($res['message'] ?? 'Bulk sync failed.');
        }
    } else {
        ApiResponse::error('Invalid action');
    }

}, true, true, false);
