<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../NotificationRelay.php';
require_once __DIR__ . '/../AuditLogger.php';
require_once __DIR__ . '/CheckoutService.php';

/**
 * NightAudit — End-of-day audit process for MicroPMS.
 * 
 * Performs:
 * 1. Auto-checkout overdue guests (configurable hours past checkout time)
 * 2. Mark rooms as dirty after checkout
 * 3. Revenue reconciliation (collected vs pending)
 * 4. Room status summary
 * 5. Generate and send audit report via Telegram
 * 6. Log all actions to night_audit_log table
 */
class NightAudit {
    private \PDO $db;
    private int $propertyId;
    private array $actions = [];
    private array $errors = [];

    public function __construct(\PDO $db, int $propertyId = 1) {
        $this->db = $db;
        $this->propertyId = $propertyId;
    }

    /**
     * Run the night audit process.
     * 
     * @param string $runBy Who triggered the audit ('system' for cron, or username)
     * @return array Audit results
     */
    public function run(string $runBy = 'system'): array {
        $hour = (int)date('G');
        // If night audit runs between midnight and 6 AM, it's auditing the previous calendar day
        if ($hour < 6) {
            $today = date('Y-m-d', strtotime('-1 day'));
        } else {
            $today = date('Y-m-d');
        }
        
        // Prevent concurrent night audit runs using an advisory lock
        $lockName = "night_audit_{$this->propertyId}_{$today}";
        $lockStmt = $this->db->prepare("SELECT GET_LOCK(?, 0)");
        $lockStmt->execute([$lockName]);
        if (!$lockStmt->fetchColumn()) {
            return [
                'status' => 'skipped',
                'message' => 'Night audit is already running for today'
            ];
        }
        
        try {
            // Check if audit already ran today
            if ($this->alreadyRunToday($today)) {
                $stayover = $this->generateStayoverCleans($today);
                return [
                    'status' => 'skipped',
                    'message' => 'Night audit already completed for today',
                    'stayover_cleans' => $stayover,
                ];
            }

            $result = [
                'audit_date'         => $today,
                'run_by'             => $runBy,
                'status'             => 'success',
                'total_rooms'        => 0,
                'occupied_rooms'     => 0,
                'arrivals_today'     => 0,
                'departures_today'   => 0,
                'overdue_checkouts'  => 0,
                'auto_checkout_count'=> 0,
                'rooms_marked_dirty' => 0,
                'revenue_collected'  => 0.0,
                'revenue_pending'    => 0.0,
            ];

            // 1. Gather room statistics
            $result['total_rooms'] = $this->getTotalRooms();
            $result['occupied_rooms'] = $this->getOccupiedRooms($today);
            $result['arrivals_today'] = $this->getArrivalsToday($today);
            $result['departures_today'] = $this->getDeparturesToday($today);

            // 2. Process overdue checkouts
            $overdueResult = $this->processOverdueCheckouts($today);
            $result['overdue_checkouts'] = $overdueResult['overdue_count'];
            $result['auto_checkout_count'] = $overdueResult['checkout_count'];
            $result['rooms_marked_dirty'] = $overdueResult['dirty_count'];
            $result['stayover_cleans'] = $this->generateStayoverCleans($today);

            // 3. Revenue reconciliation
            $revenue = $this->calculateRevenue($today);
            $result['revenue_collected'] = $revenue['collected'];
            $result['revenue_pending'] = $revenue['pending'];

            // 4. Log the audit
            $this->logAudit($result, $runBy);

            // 5. Send report
            $this->sendReport($result);

        } catch (\Throwable $e) {
            $result['status'] = 'failed';
            $result['error_message'] = $e->getMessage();
            $this->errors[] = $e->getMessage();
            
            // Log the failed audit if we got far enough to have a result structure
            if (isset($result['audit_date'])) {
                $this->logAudit($result, $runBy);
            }
        } finally {
            $unlockStmt = $this->db->prepare("SELECT RELEASE_LOCK(?)");
            $unlockStmt->execute([$lockName]);
        }

        return $result ?? ['status' => 'failed', 'error_message' => 'Failed to initialize audit'];
    }

    /**
     * Check if audit already ran today.
     */
    private function alreadyRunToday(string $date): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM night_audit_log WHERE audit_date = ? AND property_id = ? AND status = 'success'");
        $stmt->execute([$date, $this->propertyId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get total rooms count.
     */
    private function getTotalRooms(): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ?");
        $stmt->execute([$this->propertyId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get occupied rooms count.
     */
    private function getOccupiedRooms(string $today): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT room_id) FROM bookings 
            WHERE property_id = :propId 
              AND (booking_status = 'checked_in'
               OR (booking_status = 'booked' AND DATE(check_in) <= :today1 AND DATE(check_out) > :today2))
        ");
        $stmt->execute(['today1' => $today, 'today2' => $today, 'propId' => $this->propertyId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get arrivals count for today.
     */
    private function getArrivalsToday(string $today): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE property_id = :propId AND booking_status IN ('booked', 'checked_in') AND DATE(check_in) = :today AND COALESCE(payment_status, '') != 'cancelled'
        ");
        $stmt->execute(['today' => $today, 'propId' => $this->propertyId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get departures count for today.
     */
    private function getDeparturesToday(string $today): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE property_id = :propId AND booking_status = 'checked_in' AND DATE(check_out) = :today AND payment_status != 'cancelled' AND booking_status != 'cancelled'
        ");
        $stmt->execute(['today' => $today, 'propId' => $this->propertyId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Process overdue checkouts based on settings.
     * 
     * Options:
     * - Auto-checkout enabled: Mark as checked_out, mark room dirty
     * - Auto-checkout disabled: Just send notification
     * - Grace period: Wait X hours past checkout before auto-checkout
     */
    private function processOverdueCheckouts(string $today): array {
        $result = ['overdue_count' => 0, 'checkout_count' => 0, 'dirty_count' => 0];
        
        $autoCheckout = $this->getSetting('night_audit_auto_checkout', 'true') === 'true';
        $graceHours = (int)$this->getSetting('night_audit_auto_checkout_hours', '2');
        $markDirty = $this->getSetting('night_audit_mark_dirty', 'true') === 'true';
        
        // Find overdue guests: checked_in with check_out in the past
        $stmt = $this->db->prepare("
            SELECT b.*, r.room_number, g.name as guest_name, g.phone as guest_phone
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            LEFT JOIN guests g ON b.guest_id = g.id
            WHERE b.property_id = :propId 
              AND b.booking_status = 'checked_in'
              AND b.check_out < NOW()
              AND COALESCE(b.payment_status, '') != 'cancelled'
        ");
        $stmt->execute(['propId' => $this->propertyId]);
        $overdueBookings = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $result['overdue_count'] = count($overdueBookings);
        
        foreach ($overdueBookings as $booking) {
            $checkoutTime = strtotime($booking['check_out']);
            $hoursPast = (time() - $checkoutTime) / 3600;
            
            // Calculate balance
            $balStmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM folio_ledger WHERE booking_id = :id");
            $balStmt->execute(['id' => $booking['id']]);
            $balance = round((float)$balStmt->fetchColumn(), 2);
            
            $issueType = null;
            $description = null;
            
            if ($balance > 0) {
                $issueType = 'overstay_with_dues';
                $description = "Guest has pending dues of ₹" . number_format($balance, 2);
            } elseif ($balance < 0) {
                $issueType = 'overstay_refund_due';
                $description = "Guest is owed a refund of ₹" . number_format(abs($balance), 2);
            } elseif (!$autoCheckout) {
                $issueType = 'overstay_zero_balance';
                $description = "Guest has not checked out. Auto-checkout is disabled.";
            }
            
            if ($issueType) {
                // Check if an action already exists
                $actCheck = $this->db->prepare("SELECT id FROM night_audit_actions WHERE booking_id = ? AND issue_type = ? AND status = 'pending'");
                $actCheck->execute([$booking['id'], $issueType]);
                if (!$actCheck->fetch()) {
                    $actInsert = $this->db->prepare("INSERT INTO night_audit_actions (property_id, booking_id, issue_type, amount, description) VALUES (?, ?, ?, ?, ?)");
                    $actInsert->execute([$this->propertyId, $booking['id'], $issueType, $balance, $description]);
                    $this->actions[] = "Flagged Room {$booking['room_number']} for {$issueType}";
                }
                
                // Do NOT auto-checkout, just notify
                $this->notifyOverdue($booking, $hoursPast, false);
                
            } else if ($autoCheckout && $hoursPast >= $graceHours) {
                try {
                    CheckoutService::performCheckout($this->db, (int)$booking['id'], $this->propertyId, [
                        'source' => 'night_audit',
                        'staff_id' => null,
                        'notify' => false,
                        'sync_sheets' => false,
                    ]);
                    $result['checkout_count']++;
                    $this->actions[] = "Auto-checked out Room {$booking['room_number']} (Guest: {$booking['guest_name']})";
                    if ($markDirty) {
                        $result['dirty_count']++;
                    }
                    $this->notifyOverdue($booking, $hoursPast, true);
                } catch (\Throwable $e) {
                    $this->errors[] = "Auto-checkout failed for Room {$booking['room_number']}: " . $e->getMessage();
                }
                
            } else {
                // Just notify (balance is 0, auto-checkout enabled but within grace period)
                $this->notifyOverdue($booking, $hoursPast, false);
            }
        }
        
        return $result;
    }

    /**
     * Send overdue notification via Telegram.
     */
    private function notifyOverdue(array $booking, float $hoursPast, bool $wasAutoCheckedOut): void {
        $roomNum = $booking['room_number'];
        $guestName = $booking['guest_name'] ?? 'Unknown';
        $hoursStr = round($hoursPast, 1);
        $title = $wasAutoCheckedOut ? 'Night Audit: Auto Checkout' : 'Overstay Alert';
        NotificationRelay::sendInAppNotification(
            $this->propertyId,
            $title,
            "{$guestName} in Room {$roomNum} — {$hoursStr}h overdue",
            'overstay',
            '/admin/folio?id=' . (int)$booking['id']
        );

        $notify = $this->getSetting('night_audit_notify_telegram', 'true') === 'true';
        if (!$notify) return;
        
        if ($wasAutoCheckedOut) {
            $msg = "🕛 <b>Night Audit: Auto Checkout</b>\n\n"
                 . "<b>Room:</b> {$roomNum}\n"
                 . "<b>Guest:</b> " . htmlspecialchars($guestName) . "\n"
                 . "<b>Overdue:</b> {$hoursStr} hours\n"
                 . "<b>Action:</b> Checked out, room marked dirty";
        } else {
            $msg = "⚠️ <b>Night Audit: Overstay Alert</b>\n\n"
                 . "<b>Room:</b> {$roomNum}\n"
                 . "<b>Guest:</b> " . htmlspecialchars($guestName) . "\n"
                 . "<b>Checkout was:</b> {$booking['check_out']}\n"
                 . "<b>Overdue:</b> {$hoursStr} hours";
        }
        
        NotificationRelay::sendTelegram($msg);
    }

    /**
     * Calculate revenue collected vs pending.
     */
    private function calculateRevenue(string $today): array {
        // Collected: negative amounts (payments) today
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ABS(amount)), 0) FROM folio_ledger 
            WHERE property_id = :propId AND amount < 0 AND DATE(recorded_at) = :today
        ");
        $stmt->execute(['today' => $today, 'propId' => $this->propertyId]);
        $collected = (float)$stmt->fetchColumn();
        
        // Pending: Sum positive net balances across active bookings
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(booking_balance), 0) FROM (
                SELECT SUM(fl.amount) as booking_balance
                FROM folio_ledger fl
                JOIN bookings b ON fl.booking_id = b.id
                WHERE b.property_id = :propId 
                  AND b.booking_status IN ('booked', 'checked_in')
                  AND b.payment_status != 'cancelled'
                GROUP BY fl.booking_id
                HAVING booking_balance > 0
            ) active_dues
        ");
        $stmt->execute(['propId' => $this->propertyId]);
        $pending = (float)$stmt->fetchColumn();
        
        return ['collected' => $collected, 'pending' => $pending];
    }

    /**
     * Log the audit run to the database.
     */
    private function logAudit(array $result, string $runBy): void {
        $stmt = $this->db->prepare("
            INSERT INTO night_audit_log 
            (property_id, audit_date, run_by, total_rooms, occupied_rooms, arrivals_today, departures_today, 
             overdue_checkouts, auto_checkout_count, rooms_marked_dirty, revenue_collected, revenue_pending, 
             actions_json, status, error_message)
            VALUES 
            (:propId, :audit_date, :run_by, :total_rooms, :occupied_rooms, :arrivals_today, :departures_today,
             :overdue_checkouts, :auto_checkout_count, :rooms_marked_dirty, :revenue_collected, :revenue_pending,
             :actions_json, :status, :error_message)
            ON DUPLICATE KEY UPDATE
             run_by = VALUES(run_by),
             total_rooms = VALUES(total_rooms),
             occupied_rooms = VALUES(occupied_rooms),
             arrivals_today = VALUES(arrivals_today),
             departures_today = VALUES(departures_today),
             overdue_checkouts = VALUES(overdue_checkouts),
             auto_checkout_count = VALUES(auto_checkout_count),
             rooms_marked_dirty = VALUES(rooms_marked_dirty),
             revenue_collected = VALUES(revenue_collected),
             revenue_pending = VALUES(revenue_pending),
             actions_json = VALUES(actions_json),
             status = VALUES(status),
             error_message = VALUES(error_message),
             run_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'propId' => $this->propertyId,
            'audit_date' => $result['audit_date'],
            'run_by' => $runBy,
            'total_rooms' => $result['total_rooms'],
            'occupied_rooms' => $result['occupied_rooms'],
            'arrivals_today' => $result['arrivals_today'],
            'departures_today' => $result['departures_today'],
            'overdue_checkouts' => $result['overdue_checkouts'],
            'auto_checkout_count' => $result['auto_checkout_count'],
            'rooms_marked_dirty' => $result['rooms_marked_dirty'],
            'revenue_collected' => $result['revenue_collected'],
            'revenue_pending' => $result['revenue_pending'],
            'actions_json' => json_encode($this->actions),
            'status' => $result['status'],
            'error_message' => $result['error_message'] ?? null,
        ]);
    }

    /**
     * Send the audit report via Telegram.
     */
    private function sendReport(array $result): void {
        $notify = $this->getSetting('night_audit_notify_telegram', 'true') === 'true';
        if (!$notify) return;
        
        $reportRevenue = $this->getSetting('night_audit_report_revenue', 'true') === 'true';
        $reportOccupancy = $this->getSetting('night_audit_report_occupancy', 'true') === 'true';
        $reportRoomStatus = $this->getSetting('night_audit_report_room_status', 'true') === 'true';
        $reportBookings = $this->getSetting('night_audit_report_bookings', 'true') === 'true';
        
        $hotelName = defined('PROPERTY_NAME') ? PROPERTY_NAME : 'Hotel';
        
        $lines = ["📊 <b>Night Audit Report — {$hotelName}</b>"];
        $lines[] = "<b>Date:</b> " . date('d M Y');
        $lines[] = "";
        
        if ($reportOccupancy) {
            $occPct = $result['total_rooms'] > 0 ? round(($result['occupied_rooms'] / $result['total_rooms']) * 100) : 0;
            $lines[] = "🏨 <b>Occupancy:</b> {$result['occupied_rooms']}/{$result['total_rooms']} ({$occPct}%)";
            $lines[] = "📅 <b>Arrivals:</b> {$result['arrivals_today']}";
            $lines[] = "🚪 <b>Departures:</b> {$result['departures_today']}";
        }
        
        if ($reportRevenue) {
            $lines[] = "💰 <b>Revenue Collected:</b> ₹" . number_format((float)$result['revenue_collected'], 2);
            $lines[] = "⏳ <b>Revenue Pending:</b> ₹" . number_format((float)$result['revenue_pending'], 2);
        }
        
        if ($reportRoomStatus) {
            $dirtyStmt = $this->db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ? AND state = 'dirty'");
            $dirtyStmt->execute([$this->propertyId]);
            $dirtyCount = (int)$dirtyStmt->fetchColumn();
            $lines[] = "🧹 <b>Dirty Rooms:</b> {$dirtyCount}";
        }
        
        if ($reportBookings && $result['overdue_checkouts'] > 0) {
            $lines[] = "";
            $lines[] = "⚠️ <b>Overdue Checkouts:</b> {$result['overdue_checkouts']}";
            if ($result['auto_checkout_count'] > 0) {
                $lines[] = "✅ <b>Auto Checked Out:</b> {$result['auto_checkout_count']}";
            }
        }
        
        if (!empty($this->actions)) {
            $lines[] = "";
            $lines[] = "📝 <b>Actions Taken:</b>";
            foreach (array_slice($this->actions, 0, 10) as $action) {
                $lines[] = "• {$action}";
            }
            if (count($this->actions) > 10) {
                $lines[] = "• ... and " . (count($this->actions) - 10) . " more";
            }
        }
        
        $lines[] = "";
        $runBy = $result['run_by'] ?? 'system';
        $lines[] = "<i>Run at: " . date('H:i:s') . " by {$runBy}</i>";
        
        $reportText = implode("\n", $lines);
        
        if ($notify) {
            NotificationRelay::sendTelegram($reportText);
        }
        
        $email = $this->getSetting('night_audit_notify_email', '');
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            require_once __DIR__ . '/../helpers/EmailHelper.php';
            $htmlReport = nl2br(strip_tags($reportText, '<b><i>'));
            EmailHelper::send($email, "Night Audit Report - {$hotelName}", $htmlReport, true);
        }
    }

    /**
     * Create a housekeeping ticket after N consecutive occupied nights.
     */
    private function generateStayoverCleans(string $today): int {
        if ($this->getSetting('STAYOVER_CLEAN_ENABLED', 'true') !== 'true') {
            return 0;
        }
        $nights = max(1, (int)$this->getSetting('STAYOVER_CLEAN_NIGHTS', '1'));
        try {
            $stmt = $this->db->prepare("
                SELECT b.id, b.room_id, r.room_number
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.property_id = ?
                  AND b.booking_status = 'checked_in'
                  AND DATEDIFF(?, DATE(b.check_in)) >= ?
                  AND COALESCE(r.dnd, 0) = 0
            ");
            $stmt->execute([$this->propertyId, $today, $nights]);
        } catch (\PDOException $e) {
            $stmt = $this->db->prepare("
                SELECT b.id, b.room_id, r.room_number
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.property_id = ?
                  AND b.booking_status = 'checked_in'
                  AND DATEDIFF(?, DATE(b.check_in)) >= ?
            ");
            $stmt->execute([$this->propertyId, $today, $nights]);
        }
        $created = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $dnd = $this->db->prepare("
                SELECT id FROM guest_service_requests
                WHERE booking_id = ? AND service_type = 'Do Not Disturb'
                  AND status IN ('pending', 'in_progress')
            ");
            $dnd->execute([(int)$row['id']]);
            if ($dnd->fetchColumn()) {
                continue;
            }
            $exists = $this->db->prepare("
                SELECT id FROM guest_service_requests
                WHERE booking_id = ? AND service_type = 'Stayover Clean'
                  AND status IN ('pending', 'in_progress')
            ");
            $exists->execute([(int)$row['id']]);
            if ($exists->fetchColumn()) {
                continue;
            }
            try {
                $ins = $this->db->prepare("
                    INSERT INTO guest_service_requests
                        (property_id, booking_id, service_type, status, source, category, room_id, notes)
                    VALUES (?, ?, 'Stayover Clean', 'pending', 'system', 'housekeeping', ?, ?)
                ");
                $ins->execute([
                    $this->propertyId,
                    (int)$row['id'],
                    (int)$row['room_id'],
                    "Auto stayover clean after {$nights} occupied nights",
                ]);
            } catch (\PDOException $e) {
                $ins = $this->db->prepare("
                    INSERT INTO guest_service_requests (property_id, booking_id, service_type, status)
                    VALUES (?, ?, 'Stayover Clean', 'pending')
                ");
                $ins->execute([$this->propertyId, (int)$row['id']]);
            }
            $this->db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ? AND property_id = ?")
                ->execute([(int)$row['room_id'], $this->propertyId]);
            $this->actions[] = "Stayover clean requested for Room {$row['room_number']}";
            $created++;
            NotificationRelay::sendInAppNotification(
                $this->propertyId,
                'Stayover Clean',
                "Room {$row['room_number']} needs a stayover clean",
                'housekeeping',
                '/admin/modules/housekeeping/service_requests'
            );
        }
        return $created;
    }

    /**
     * Get a system setting value.
     */
    private function getSetting(string $key, string $default = ''): string {
        $stmt = $this->db->prepare("SELECT key_value FROM system_settings WHERE key_name = ? AND property_id = ?");
        $stmt->execute([$key, $this->propertyId]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }

    /**
     * Get audit history for display.
     */
    public static function getHistory(\PDO $db, int $propertyId, int $limit = 30): array {
        $limit = max(1, min(365, $limit));
        $stmt = $db->prepare("SELECT * FROM night_audit_log WHERE property_id = ? ORDER BY audit_date DESC LIMIT {$limit}");
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get the last audit run.
     */
    public static function getLastAudit(\PDO $db, int $propertyId): ?array {
        $stmt = $db->prepare("SELECT * FROM night_audit_log WHERE property_id = ? ORDER BY audit_date DESC LIMIT 1");
        $stmt->execute([$propertyId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
