<?php
declare(strict_types=1);

require_once __DIR__ . '/../ApiException.php';
require_once __DIR__ . '/../TenantScope.php';
require_once __DIR__ . '/../AuditLogger.php';
require_once __DIR__ . '/FolioService.php';

/**
 * Single checkout path for admin, assistant, guest portal, Telegram, and night audit.
 */
class CheckoutService {

    /**
     * @param array{source?: string, staff_id?: int|null, reason?: string, notify?: bool, sync_sheets?: bool} $opts
     * @return array{booking_id: int, room_id: int, guest_id: int, paid_amount: float, balance: float}
     */
    public static function performCheckout(\PDO $db, int $bookingId, int $propertyId, array $opts = []): array {
        $source = (string)($opts['source'] ?? 'admin');
        $staffId = $opts['staff_id'] ?? ($_SESSION['user_id'] ?? null);
        $reason = (string)($opts['reason'] ?? '');
        $notify = (bool)($opts['notify'] ?? true);
        $syncSheets = (bool)($opts['sync_sheets'] ?? true);

        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            $booking = TenantScope::booking($db, $bookingId, $propertyId, true);
            if ($booking['booking_status'] !== 'checked_in') {
                throw new ApiException('Can only check-out from checked-in status', 409, ['code' => 'INVALID_STATUS']);
            }

            $balance = round(FolioService::getBalance($db, $bookingId), 2);
            if ($balance > 0) {
                throw new ApiException(
                    'Cannot check-out: Guest has pending dues of ₹' . number_format($balance, 2) . '. Please settle the folio first.',
                    409,
                    ['code' => 'BALANCE_DUE', 'balance' => $balance]
                );
            }
            if ($balance < 0) {
                throw new ApiException(
                    'Cannot check-out: Guest is owed a refund of ₹' . number_format(abs($balance), 2) . '. Please process the refund first.',
                    409,
                    ['code' => 'REFUND_DUE', 'balance' => $balance]
                );
            }

            try {
                $db->prepare("UPDATE bookings SET booking_status = 'checked_out', payment_status = 'completed_paid', actual_checkout = NOW() WHERE id = ? AND property_id = ?")
                   ->execute([$bookingId, $propertyId]);
            } catch (\PDOException $e) {
                $db->prepare("UPDATE bookings SET booking_status = 'checked_out', payment_status = 'completed_paid' WHERE id = ? AND property_id = ?")
                   ->execute([$bookingId, $propertyId]);
            }

            $roomId = (int)$booking['room_id'];
            $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ? AND property_id = ?")->execute([$roomId, $propertyId]);

            try {
                $db->prepare("
                    UPDATE guest_service_requests
                    SET status = 'completed', resolved_at = NOW()
                    WHERE booking_id = ?
                      AND status NOT IN ('completed', 'rejected')
                      AND service_type NOT IN ('Housekeeping', 'Stayover Clean', 'Room Service', 'Extra Towels', 'Toiletries')
                ")->execute([$bookingId]);
            } catch (\PDOException $e) {
            }

            try {
                $db->prepare("UPDATE night_audit_actions SET status = 'resolved', resolved_at = NOW() WHERE booking_id = ? AND status = 'pending'")
                   ->execute([$bookingId]);
            } catch (\PDOException $e) {
            }

            $paidAmount = FolioService::getPaidAmount($db, $bookingId);

            $actionCode = match ($source) {
                'assistant' => 'CHECK_OUT',
                'guest_portal' => 'PORTAL_SELF_CHECKOUT',
                'night_audit' => 'NIGHT_AUDIT_AUTO_CHECKOUT',
                'telegram' => 'CHECK_OUT',
                default => 'CHECK_OUT',
            };
            AuditLogger::log($staffId, $actionCode, 'BOOKING', $bookingId, [
                'action' => $source . '_check_out',
                'from_status' => 'checked_in',
                'to_status' => 'checked_out',
                'reason' => $reason,
                'source' => $source,
                'check_out_time' => date('Y-m-d H:i:s'),
            ], $propertyId);

            if ($shouldCommit && $db->inTransaction()) {
                $db->commit();
            }

            if ($notify) {
                self::notifyCheckout($db, $booking, $paidAmount);
            }
            if ($syncSheets) {
                try {
                    require_once __DIR__ . '/../GoogleSheetService.php';
                    GoogleSheetService::syncBooking($db, $bookingId);
                } catch (\Throwable $t) {
                    error_log('Google Sheets sync error on checkout: ' . $t->getMessage());
                }
            }

            return [
                'booking_id' => $bookingId,
                'room_id' => $roomId,
                'guest_id' => (int)$booking['guest_id'],
                'paid_amount' => $paidAmount,
                'balance' => 0.0,
            ];
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function notifyCheckout(\PDO $db, array $booking, float $paidAmount): void {
        try {
            require_once __DIR__ . '/../NotificationRelay.php';
            require_once __DIR__ . '/../PhoneHelper.php';

            $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = ? AND property_id = ?");
            $roomStmt->execute([(int)$booking['room_id'], (int)$booking['property_id']]);
            $roomNum = (string)($roomStmt->fetchColumn() ?: $booking['room_id']);

            $guestStmt = $db->prepare("SELECT name, phone FROM guests WHERE id = ? AND property_id = ?");
            $guestStmt->execute([(int)$booking['guest_id'], (int)$booking['property_id']]);
            $guest = $guestStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $guestName = (string)($guest['name'] ?? 'N/A');

            $tgMsg = "🚪 <b>Guest Checked Out</b>\n\nRoom: {$roomNum}\nGuest: " . htmlspecialchars($guestName) . "\nRoom is now dirty — needs cleaning.";
            $context = [
                'guest_name' => $guestName,
                'room_number' => $roomNum,
                'paid_amount' => number_format($paidAmount, 2),
                'balance_amount' => '0.00',
                'total_amount' => number_format((float)$booking['total_amount'], 2),
            ];
            NotificationRelay::sendTelegram($tgMsg, 'check_out', $context);
            NotificationRelay::triggerAutomation('guest_check_out', PhoneHelper::toE164($guest['phone'] ?? ''), (int)$booking['id']);
            NotificationRelay::sendInAppNotification(
                (int)$booking['property_id'],
                'Guest Checked Out',
                "{$guestName} checked out of Room {$roomNum}",
                'check_out',
                '/admin/folio?id=' . (int)$booking['id']
            );
        } catch (\Throwable $t) {
        }
    }
}
