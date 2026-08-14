<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../../pms_core/services/FolioService.php';
require_once __DIR__ . '/../../../pms_core/PhoneHelper.php';

ApiHandler::run(function(\PDO $db) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $bookingId = (int)($data['booking_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0.0);
    $paymentMethod = trim($data['payment_method'] ?? 'Cash');
    $paymentRef = trim($data['payment_ref'] ?? '');

    if (!$bookingId || $amount <= 0) {
        ApiResponse::error('Booking ID and valid positive amount are required');
    }

    // Fetch booking details
    $bStmt = $db->prepare("
        SELECT b.id, b.room_id, b.guest_id, b.total_amount,
               r.room_number, g.name as guest_name, g.phone as guest_phone
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.id = :id AND b.payment_status != 'cancelled' AND b.property_id = :pid
    ");
    $bStmt->execute(['id' => $bookingId, 'pid' => AuthHelper::getPropertyId()]);
    $booking = $bStmt->fetch();

    if (!$booking) {
        ApiResponse::error('Booking not found');
    }

    $db->beginTransaction();
    try {
        // Use shared FolioService for standardized recording
        $receiptId = FolioService::recordPayment($db, $bookingId, $amount, $paymentMethod, $paymentRef, 'assistant');

        // Fetch receipt display_id
        $recStmt = $db->prepare("SELECT display_id FROM folio_ledger WHERE id = ?");
        $recStmt->execute([$receiptId]);
        $receiptDisplayId = $recStmt->fetchColumn() ?: 'RCPT-' . $receiptId;

        // Note: FolioService::recordPayment() above already syncs to finance_transactions.

        // Send Telegram notification
        try {
            $tgMsg = "💰 <b>Payment Recorded</b>\n\n<b>Guest:</b> {$booking['guest_name']}\n<b>Room:</b> {$booking['room_number']}\n<b>Amount:</b> ₹" . number_format($amount, 2) . "\n<b>Method:</b> " . ucfirst($paymentMethod);
            $context = [
                'guest_name' => $booking['guest_name'],
                'room_number' => $booking['room_number'],
                'amount' => number_format($amount, 2),
                'method' => ucfirst($paymentMethod),
                'ref' => $paymentRef ?: 'N/A'
            ];
            NotificationRelay::sendTelegram($tgMsg, 'payment_received', $context);
        } catch (\Throwable $t) {
            // Ignore alert errors
        }

        $db->commit();
        ApiResponse::success([
            'message' => 'Payment recorded successfully',
            'receipt_id' => $receiptDisplayId,
            'amount' => $amount
        ]);

    } catch (\Exception $ex) {
        $db->rollBack();
        ApiResponse::error('Recording payment failed: ' . $ex->getMessage());
    }

}, true, true, false);
