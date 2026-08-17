<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/RoomHoldService.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('create_booking');
    $data = !empty($_POST) ? $_POST : ApiHandler::getJsonInput();

    $roomIds = [];
    if (isset($data['room_ids'])) {
        $raw = $data['room_ids'];
        $roomIds = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    } elseif (isset($data['room_id'])) {
        $roomIds = [(int)$data['room_id']];
    }

    $checkIn = (string)($data['check_in'] ?? '');
    $checkOut = (string)($data['check_out'] ?? '');
    $token = trim((string)($data['hold_token'] ?? ''));
    $propertyId = AuthHelper::getPropertyId();
    $staffId = (int)($_SESSION['user_id'] ?? 0);

    if ($checkIn === '' || $checkOut === '') {
        ApiResponse::error('Missing check-in or check-out');
    }

    $hold = RoomHoldService::place($db, $propertyId, $roomIds, $checkIn, $checkOut, $staffId, $token !== '' ? $token : null);
    ApiResponse::success([
        'hold_token' => $hold['token'],
        'expires_at' => $hold['expires_at'],
        'expires_in' => $hold['expires_in'],
    ]);
}, true, true, true);
