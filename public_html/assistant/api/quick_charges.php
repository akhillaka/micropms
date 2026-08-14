<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/services/FolioService.php';

ApiHandler::run(function(\PDO $db) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    // Action: Get quick charge presets
    if ($action === 'presets') {
        $presets = FolioService::getQuickChargePresets($db);
        ApiResponse::success(['presets' => $presets]);
    }

    // Action: Post a quick charge
    elseif ($action === 'add') {
        $bookingId = (int)($data['booking_id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $amount = (float)($data['amount'] ?? 0);

        if (!$bookingId || !$name || $amount <= 0) {
            ApiResponse::error('Missing booking ID, charge name, or amount');
        }

        $entryId = FolioService::postCharge($db, $bookingId, $amount, $name);
        ApiResponse::success([
            'message' => "Charge added: {$name} ₹" . number_format($amount, 2),
            'entry_id' => $entryId
        ]);
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, true, true, false);
