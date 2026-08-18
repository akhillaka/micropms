<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';

require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';


ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_folio');

    $data = ApiHandler::getJsonInput();
    if (!isset($data['booking_id']) || !isset($data['item_name']) || !isset($data['amount'])) {
        ApiResponse::error('Missing parameters');
    }

    $amount = (float)$data['amount'];
    if ($amount <= 0) {
        throw new Exception("Amount must be greater than zero");
    }

    $propertyId = AuthHelper::getPropertyId();
    $checkStmt = $db->prepare("SELECT id FROM bookings WHERE id = ? AND property_id = ?");
    $checkStmt->execute([$data['booking_id'], $propertyId]);
    if (!$checkStmt->fetchColumn()) {
        ApiResponse::error('Booking not found', 404);
    }

    // Insert into folio ledger (tax breakdown stored separately if needed, not embedded in amount)
    FolioService::postCharge($db, (int)$data['booking_id'], $amount, (string)$data['item_name'], (string)($data['category'] ?? 'other'));

    AuditLogger::log($_SESSION['user_id'] ?? null, 'POST_CHARGE', 'FOLIO', $data['booking_id'], ['item' => $data['item_name'], 'amount' => $amount, 'category' => $data['category'] ?? 'other']);

    $bStmt = $db->prepare("SELECT r.room_number, g.name as guest_name, b.total_amount FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = :id AND b.property_id = :pid");
    $bStmt->execute(['id' => $data['booking_id'], 'pid' => $propertyId]);
    $info = $bStmt->fetch();
    if ($info) {
        $tgMsg = "📎 <b>Charge Added</b>\n\nRoom: {$info['room_number']}\nGuest: " . htmlspecialchars($info['guest_name']) . "\nItem: " . htmlspecialchars($data['item_name']) . "\nAmount: ₹" . number_format($amount, 2);

        $context = [
            'guest_name'   => $info['guest_name'] ?? 'N/A',
            'room_number'  => $info['room_number'],
            'description'  => "Charge added: " . $data['item_name'],
            'amount'       => number_format($amount, 2),
            'total_amount' => number_format((float)$info['total_amount'], 2)
        ];
        NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);
    }

    ApiResponse::success();

}, true, true, true);
