<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../../pms_core/PhoneHelper.php';

ApiHandler::run(function(\PDO $db) {
    // Session is checked by ApiHandler

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';
    $bookingId = (int)($data['booking_id'] ?? $_GET['booking_id'] ?? 0);

    if (!$bookingId) {
        ApiResponse::error('Booking ID is required');
    }

    // Fetch booking details
    $bStmt = $db->prepare("
        SELECT b.id, b.room_id, b.guest_id, b.check_in, b.check_out, b.booking_status, b.total_amount,
               r.room_number, c.name as category_name, g.name as guest_name, g.phone as guest_phone, g.photo as guest_photo
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_categories c ON r.category_id = c.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.id = :id AND b.payment_status != 'cancelled'
    ");
    $bStmt->execute(['id' => $bookingId]);
    $booking = $bStmt->fetch();

    if (!$booking) {
        ApiResponse::error('Booking not found');
    }

    // Action: Get Folio Breakdown Details
    if ($action === 'details') {
        $lStmt = $db->prepare("SELECT * FROM folio_ledger WHERE booking_id = :id ORDER BY recorded_at ASC");
        $lStmt->execute(['id' => $bookingId]);
        $ledgerEntries = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        $roomRent = 0.0;
        $restaurant = 0.0;
        $laundry = 0.0;
        $extraBed = 0.0;
        $taxes = 0.0;
        $incidentals = 0.0;
        $advancePaid = 0.0;
        $totalPaid = 0.0;

        foreach ($ledgerEntries as $entry) {
            $amount = (float)$entry['amount'];
            $desc = strtolower($entry['description'] ?? '');
            
            if ($amount > 0) {
                // Charge
                if ($entry['transaction_type'] === 'ROOM_CHARGE' || strpos($desc, 'room') !== false || strpos($desc, 'rent') !== false) {
                    $roomRent += $amount;
                } elseif (strpos($desc, 'extra bed') !== false || strpos($desc, 'bed') !== false) {
                    $extraBed += $amount;
                } elseif (strpos($desc, 'restaurant') !== false || strpos($desc, 'food') !== false || strpos($desc, 'meal') !== false || strpos($desc, 'dining') !== false) {
                    $restaurant += $amount;
                } elseif (strpos($desc, 'laundry') !== false || strpos($desc, 'dry clean') !== false) {
                    $laundry += $amount;
                } elseif (strpos($desc, 'tax') !== false || strpos($desc, 'gst') !== false || strpos($desc, 'vat') !== false) {
                    $taxes += $amount;
                } else {
                    $incidentals += $amount;
                }
            } else {
                // Payment (Stored as negative numbers in ledger)
                $absVal = abs($amount);
                $totalPaid += $absVal;
                
                if (strpos($desc, 'advance') !== false) {
                    $advancePaid += $absVal;
                }
            }
        }

        $totalCharges = $roomRent + $restaurant + $laundry + $extraBed + $taxes + $incidentals;
        $balance = $totalCharges - $totalPaid;

        ApiResponse::success([
            'booking' => [
                'id' => $booking['id'],
                'room_number' => $booking['room_number'],
                'category_name' => $booking['category_name'],
                'guest_name' => $booking['guest_name'],
                'guest_phone' => $booking['guest_phone'],
                'guest_photo' => $booking['guest_photo'],
                'booking_status' => $booking['booking_status'],
                'check_in' => date('d M Y, g:i A', strtotime($booking['check_in'])),
                'check_out' => date('d M Y, g:i A', strtotime($booking['check_out'])),
                'check_out_raw' => date('Y-m-d\TH:i', strtotime($booking['check_out']))
            ],
            'bill' => [
                'room_rent' => $roomRent,
                'restaurant' => $restaurant,
                'laundry' => $laundry,
                'extra_bed' => $extraBed,
                'taxes' => $taxes,
                'incidentals' => $incidentals,
                'total_charges' => $totalCharges,
                'advance_paid' => $advancePaid,
                'total_paid' => $totalPaid,
                'balance' => round($balance, 2)
            ],
            'ledger' => $ledgerEntries
        ]);
    }

    // Action: Execute Checkout
    elseif ($action === 'checkout') {
        if ($booking['booking_status'] !== 'checked_in') {
            ApiResponse::error('Can only checkout from Checked-In status');
        }

        // Calculate current outstanding balance
        $balStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM folio_ledger WHERE booking_id = :id");
        $balStmt->execute(['id' => $bookingId]);
        $balance = round((float)$balStmt->fetchColumn(), 2);

        if ($balance > 0) {
            ApiResponse::error("Cannot checkout: Guest has pending dues of ₹" . number_format($balance, 2) . ". Please settle the payment first.");
        }

        $db->beginTransaction();
        try {
            // Update booking status
            $stmt = $db->prepare("UPDATE bookings SET booking_status = 'checked_out' WHERE id = :id");
            $stmt->execute(['id' => $bookingId]);

            // Update room state to dirty for housekeeping
            $stmt2 = $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = :rid");
            $stmt2->execute(['rid' => $booking['room_id']]);

            // Log Audit trail
            AuditLogger::log($_SESSION['user_id'], 'CHECK_OUT', 'BOOKING', $bookingId, [
                'action' => 'assistant_check_out',
                'from_status' => 'checked_in',
                'to_status' => 'checked_out',
                'check_out_time' => date('Y-m-d H:i:s')
            ]);

            // Fetch total paid for notifications
            $paidStmt = $db->prepare("SELECT ABS(COALESCE(SUM(amount), 0)) FROM folio_ledger WHERE booking_id = ? AND amount < 0");
            $paidStmt->execute([$bookingId]);
            $paidAmount = (float)$paidStmt->fetchColumn();

            // Trigger Alerts & Messaging
            try {
                $tgMsg = "🚪 <b>Guest Checked Out</b>\n\nRoom: {$booking['room_number']}\nGuest: " . htmlspecialchars($booking['guest_name']) . "\nRoom is now dirty — needs cleaning.";
                $context = [
                    'guest_name' => $booking['guest_name'],
                    'room_number' => $booking['room_number'],
                    'paid_amount' => number_format($paidAmount, 2),
                    'balance_amount' => '0.00',
                    'total_amount' => number_format((float)$booking['total_amount'], 2)
                ];
                NotificationRelay::sendTelegram($tgMsg, 'check_out', $context);
                
                // WhatsApp
                NotificationRelay::triggerAutomation('guest_check_out', PhoneHelper::toE164($booking['guest_phone']), $bookingId);
            } catch (\Throwable $t) {
                // Ignore alert errors to prevent rollback
            }

            $db->commit();
            ApiResponse::success(['message' => 'Checkout processed successfully. Room marked dirty for cleaning.']);
        } catch (\Exception $ex) {
            $db->rollBack();
            ApiResponse::error('Checkout failed: ' . $ex->getMessage());
        }
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, true, false, false);
