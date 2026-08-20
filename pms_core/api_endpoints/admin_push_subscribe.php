<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/WebPushService.php';

$needCsrf = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requireLogin();
    $staffId = (int)($_SESSION['user_id'] ?? 0);
    if ($staffId <= 0) {
        ApiResponse::error('Not logged in', 401);
    }
    $propertyId = AuthHelper::getPropertyId();

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        WebPushService::ensureKeys();
        ApiResponse::success(['publicKey' => WebPushService::publicKey()]);
    }

    $data = ApiHandler::getJsonInput();
    $action = (string)($data['action'] ?? 'subscribe');
    $endpoint = trim((string)($data['endpoint'] ?? ''));
    if ($endpoint === '') {
        ApiResponse::error('Missing endpoint');
    }

    if ($action === 'unsubscribe') {
        WebPushService::deleteSubscription($db, $endpoint);
        ApiResponse::success(['message' => 'Unsubscribed']);
    }

    $p256dh = trim((string)($data['keys']['p256dh'] ?? $data['p256dh'] ?? ''));
    $auth = trim((string)($data['keys']['auth'] ?? $data['auth'] ?? ''));
    if ($p256dh === '' || $auth === '') {
        ApiResponse::error('Missing subscription keys');
    }
    $client = trim((string)($data['client'] ?? 'admin'));
    WebPushService::saveSubscription($db, $staffId, (int)$propertyId, $endpoint, $p256dh, $auth, $client);
    ApiResponse::success(['message' => 'Subscribed']);
}, true, $needCsrf, false);
