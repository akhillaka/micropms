<?php
declare(strict_types=1);

require_once __DIR__ . '/../PricingEngine.php';
require_once __DIR__ . '/../PhoneHelper.php';
require_once __DIR__ . '/../SequenceGenerator.php';
require_once __DIR__ . '/../NotificationRelay.php';
require_once __DIR__ . '/../AuditLogger.php';
require_once __DIR__ . '/../GoogleSheetService.php';

/**
 * BookingService - Shared booking logic for both Admin API and Assistant PWA.
 * Eliminates duplication between api/create_hold.php and assistant/api/bookings.php
 */
class BookingService {

    /**
     * Create a new booking with folio entries.
     *
     * @param PDO $db Database connection (must be within a transaction if caller wants atomicity)
     * @param array $params Booking parameters
     * @return array ['booking_id' => int, 'display_id' => string, 'total_amount' => float]
     * @throws Exception on validation or availability failure
     */
    public static function createBooking(\PDO $db, array $params): array {
        $roomId        = (int)($params['room_id'] ?? 0);
        $guestId       = (int)($params['guest_id'] ?? 0);
        $checkIn       = $params['check_in'] ?? '';
        $checkOut      = $params['check_out'] ?? '';
        $ratePlanName  = $params['rate_plan_name'] ?? null;
        $bookingSource = $params['booking_source'] ?? 'Walk-in';
        $priceOverride = isset($params['price_override']) && $params['price_override'] !== '' ? (float)$params['price_override'] : null;
        $adults        = isset($params['adults']) ? (int)$params['adults'] : 2;
        $children      = isset($params['children']) ? (int)$params['children'] : 0;
        $extraBed      = isset($params['extra_bed']) && (int)$params['extra_bed'] === 1 ? 1 : 0;
        $bookingStatus = $params['booking_status'] ?? 'booked';

        // Validation
        if (!$roomId || !$guestId || !$checkIn || !$checkOut) {
            throw new \Exception('Missing room ID, guest ID, or stay dates');
        }
        if (strtotime($checkOut) <= strtotime($checkIn)) {
            throw new \Exception('Check-out date must be after check-in');
        }

        $idempotencyKey = $params['idempotency_key'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            try {
                $stmt = $db->prepare("SELECT response_body FROM idempotency_keys WHERE idempotency_key = ?");
                $stmt->execute([$idempotencyKey]);
                $cached = $stmt->fetchColumn();
                if ($cached !== false) {
                    return json_decode($cached, true);
                }
            } catch (\PDOException $e) {
                // If table doesn't exist yet, proceed
            }
        }

        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            // Lock room and get category
            $stmt = $db->prepare("SELECT r.category_id, r.property_id, c.name as category_name FROM rooms r JOIN room_categories c ON r.category_id = c.id WHERE r.id = :room_id FOR UPDATE");
            $stmt->execute(['room_id' => $roomId]);
            $room = $stmt->fetch();
            if (!$room) {
                throw new \Exception('Invalid room selected');
            }

            // Check availability
            if (!self::isRoomAvailable($db, $roomId, $checkIn, $checkOut)) {
                throw new \Exception('Room is no longer available for this timeframe');
            }

            // Calculate pricing
            $categoryId = (int)$room['category_id'];
            $totalAmount = 0.0;
            $extraBedCost = 0.0;

            if ($priceOverride !== null) {
                $totalAmount = $priceOverride;
            } else {
                $totalAmount = PricingEngine::calculateTotalCost($categoryId, $checkIn, $checkOut, $ratePlanName);
            }

            // Extra bed charges
            if ($extraBed === 1) {
                $days = self::calculateDays($checkIn, $checkOut);
                $extraBedCost = $days * 500.00;
                if ($priceOverride === null) {
                    $totalAmount += $extraBedCost;
                }
            }

            $offlineFolioId = $params['offline_folio_id'] ?? null;
            if ($offlineFolioId === '') $offlineFolioId = null;

            $propertyId = (int)($room['property_id'] ?? 1);

            // Insert booking
            $insertStmt = $db->prepare("
                INSERT INTO bookings (property_id, room_id, guest_id, check_in, check_out, payment_status, booking_status, total_amount, rate_plan_name, booking_source, price_override, adults, children, extra_bed, offline_folio_id)
                VALUES (:property_id, :room_id, :guest_id, :check_in, :check_out, 'completed_paid', :booking_status, :total_amount, :rate_plan_name, :booking_source, :price_override, :adults, :children, :extra_bed, :offline_folio_id)
            ");
            $insertStmt->execute([
                'property_id'     => $propertyId,
                'room_id'         => $roomId,
                'guest_id'        => $guestId,
                'check_in'        => $checkIn,
                'check_out'       => $checkOut,
                'booking_status'  => $bookingStatus,
                'total_amount'    => $totalAmount,
                'rate_plan_name'  => $ratePlanName,
                'booking_source'  => $bookingSource,
                'price_override'  => $priceOverride,
                'adults'          => $adults,
                'children'        => $children,
                'extra_bed'       => $extraBed,
                'offline_folio_id'=> $offlineFolioId,
            ]);

            $bookingId = (int)$db->lastInsertId();
            SequenceGenerator::assignDisplayId($db, 'bookings', $bookingId, 'SEQ_BOOKING_FORMAT', 'display_id');
            
            if ($offlineFolioId === null) {
                SequenceGenerator::assignDisplayId($db, 'bookings', $bookingId, 'SEQ_FOLIO_FORMAT', 'offline_folio_id');
            }

            // Fetch display ID
            $dispStmt = $db->prepare("SELECT display_id FROM bookings WHERE id = ?");
            $dispStmt->execute([$bookingId]);
            $bookingDisplayId = $dispStmt->fetchColumn() ?: 'BKG-' . $bookingId;

            // Post room charges to folio
            self::postRoomCharges($db, $bookingId, $categoryId, $room['category_name'], $checkIn, $checkOut, $ratePlanName, $priceOverride);

            // Post extra bed charges if not using a fixed price override
            if ($extraBedCost > 0 && $priceOverride === null) {
                $days = self::calculateDays($checkIn, $checkOut);
                self::postFolioEntry($db, $bookingId, 'ROOM_CHARGE', $extraBedCost, "Extra Bed Charge ({$days} night" . ($days > 1 ? 's' : '') . ")");
            }

            // Record advance payment if collected
            $paymentCollected = isset($params['payment_collected']) ? (float)$params['payment_collected'] : 0.0;
            $paymentMethod = $params['payment_method'] ?? 'Cash';
            $paymentRef = $params['payment_ref'] ?? '';

            if ($paymentCollected > 0) {
                self::recordPayment($db, $bookingId, $paymentCollected, $paymentMethod, $paymentRef ?: 'MANUAL', 'Booking Advance Payment');
                
                // Record finance transaction
                $staffId = $_SESSION['user_id'] ?? null;
                $financeStmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, booking_id, amount, description, payment_method, staff_id) VALUES (:pid, 'income', 'booking', :bid, :amount, :desc, :method, :staff)");
                $financeStmt->execute([
                    'pid'    => $propertyId,
                    'bid'    => $bookingId,
                    'amount'  => $paymentCollected,
                    'desc'    => "Advance Payment - Booking " . $bookingDisplayId,
                    'method'  => $paymentMethod,
                    'staff'   => $staffId,
                ]);
                SequenceGenerator::assignDisplayId($db, 'finance_transactions', (int)$db->lastInsertId(), 'SEQ_TRANSACTION_FORMAT');
            }

            // Trigger WhatsApp automation
            try {
                $phoneStmt = $db->prepare("SELECT phone FROM guests WHERE id = ?");
                $phoneStmt->execute([$guestId]);
                $guestPhone = $phoneStmt->fetchColumn();
                if ($guestPhone) {
                    NotificationRelay::triggerAutomation('booking_confirmed', PhoneHelper::toE164($guestPhone), $bookingId);
                    if ($bookingStatus === 'checked_in') {
                        NotificationRelay::triggerAutomation('guest_check_in', PhoneHelper::toE164($guestPhone), $bookingId);
                    }
                }
            } catch (\Throwable $t) {
                // Ignore notification errors
            }

            // Audit log
            $staffId = $_SESSION['user_id'] ?? null;
            AuditLogger::log($staffId, 'CREATE_BOOKING', 'BOOKING', $bookingId, [
                'room_id' => $roomId, 'guest_id' => $guestId, 'total' => $totalAmount
            ]);

            $result = [
                'booking_id'   => $bookingId,
                'display_id'   => $bookingDisplayId,
                'total_amount' => $totalAmount,
            ];

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                try {
                    $insertIdemp = $db->prepare("INSERT IGNORE INTO idempotency_keys (idempotency_key, response_body) VALUES (?, ?)");
                    $insertIdemp->execute([$idempotencyKey, json_encode($result)]);
                } catch (\PDOException $e) {
                    // If table doesn't exist yet, proceed
                }
            }

            if ($shouldCommit) {
                $db->commit();
            }

            // Sync booking to Google Sheets
            try {
                GoogleSheetService::syncBooking($db, $bookingId);
            } catch (\Throwable $t) {
                // Non-blocking sync failure logger
                error_log("Google Sheets sync failed for booking $bookingId: " . $t->getMessage());
            }

            return $result;
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Check if a room is available for the given timeframe.
     */
    public static function isRoomAvailable(\PDO $db, int $roomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null, ?int $propertyId = null): bool {
        // Normalize date-only strings to full DATETIME
        if (strlen($checkIn) === 10) $checkIn .= ' 00:00:00';
        if (strlen($checkOut) === 10) $checkOut .= ' 23:59:59';

        $propId = $propertyId ?? (class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1);

        $sql = "SELECT COUNT(*) FROM bookings
                WHERE room_id = :room_id
                  AND property_id = :property_id
                  AND payment_status != 'cancelled'
                  AND check_in < :check_out
                  AND check_out > :check_in";
        $params = [
            'room_id'     => $roomId,
            'property_id' => $propId,
            'check_in'    => $checkIn,
            'check_out'   => $checkOut,
        ];
        if ($excludeBookingId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeBookingId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() === 0;
    }

    /**
     * Check for duplicate booking by guest phone.
     */
    public static function checkDuplicate(\PDO $db, string $phoneRaw, string $checkIn, string $checkOut): ?array {
        $phone = PhoneHelper::toLocal($phoneRaw);
        if ($phone === null) return null;

        $propertyId = class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1;

        $stmt = $db->prepare("
            SELECT b.id, b.check_in, b.check_out, r.room_number, g.name as guest_name 
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN guests g ON b.guest_id = g.id 
            WHERE g.phone = :phone 
              AND b.property_id = :property_id
              AND b.booking_status IN ('booked', 'checked_in') 
              AND b.payment_status != 'cancelled'
              AND b.check_in < :check_out 
              AND b.check_out > :check_in
        ");
        $stmt->execute(['phone' => $phone, 'property_id' => $propertyId, 'check_in' => $checkIn, 'check_out' => $checkOut]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Extend a booking's checkout date and post extra charges.
     */
    public static function extendStay(\PDO $db, int $bookingId, string $newCheckOut): array {
        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            $stmt = $db->prepare("
                SELECT b.*, r.category_id, c.name as category_name
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                JOIN room_categories c ON r.category_id = c.id
                WHERE b.id = :id
            ");
            $stmt->execute(['id' => $bookingId]);
            $booking = $stmt->fetch();
            if (!$booking) {
                throw new \Exception('Booking not found');
            }
            if ($booking['booking_status'] === 'checked_out') {
                throw new \Exception('Cannot extend stay for a booking that is already checked out');
            }
            if ($booking['payment_status'] === 'cancelled') {
                throw new \Exception('Cannot extend stay for a cancelled booking');
            }

            $oldCheckOut = $booking['check_out'];
            if (strtotime($newCheckOut) <= strtotime($oldCheckOut)) {
                throw new \Exception('New checkout must be after current checkout');
            }

            // Check availability for extended period
            if (!self::isRoomAvailable($db, (int)$booking['room_id'], $oldCheckOut, $newCheckOut, $bookingId)) {
                throw new \Exception('Room not available for extended timeframe');
            }

            $isOverride = ($booking['price_override'] !== null);
            $newTotal = 0.0;
            $difference = 0.0;
            $breakdown = [];

            if ($isOverride) {
                try {
                    $difference = PricingEngine::calculateTotalCost($booking['category_id'], $oldCheckOut, $newCheckOut, $booking['rate_plan_name']);
                    $newTotal = (float)$booking['total_amount'] + $difference;
                    $breakdown = PricingEngine::getCostBreakdown($booking['category_id'], $oldCheckOut, $newCheckOut, $booking['rate_plan_name']);
                } catch (\Exception $e) {
                    $days = self::calculateDays($oldCheckOut, $newCheckOut);
                    $difference = $days * 1000.00;
                    $newTotal = (float)$booking['total_amount'] + $difference;
                }
            } else {
                try {
                    $newTotal = PricingEngine::calculateTotalCost($booking['category_id'], $booking['check_in'], $newCheckOut, $booking['rate_plan_name']);
                    $difference = $newTotal - (float)$booking['total_amount'];
                    $breakdown = PricingEngine::getCostBreakdown($booking['category_id'], $booking['check_in'], $newCheckOut, $booking['rate_plan_name']);
                } catch (\Exception $e) {
                    $days = self::calculateDays($booking['check_in'], $newCheckOut);
                    $newTotal = $days * 1000.00;
                    $difference = $newTotal - (float)$booking['total_amount'];
                }
            }

            // Replace room charges if not override
            if (!$isOverride) {
                $db->prepare("DELETE FROM folio_ledger WHERE booking_id = :id AND transaction_type = 'ROOM_CHARGE'")->execute(['id' => $bookingId]);
            }

            // Post new charges
            $ledgerStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, transaction_type, amount, transaction_ref, description) VALUES (:id, 'ROOM_CHARGE', :amount, 'MANUAL', :desc)");
            if (!empty($breakdown)) {
                foreach ($breakdown as $item) {
                    $desc = $isOverride 
                        ? "Stay Extension (Day {$item['day']}) - {$booking['category_name']} ({$item['duration']})"
                        : "Day {$item['day']} - Room Charges - {$booking['category_name']} ({$item['duration']})";
                    $ledgerStmt->execute(['id' => $bookingId, 'amount' => $item['cost'], 'desc' => $desc]);
                    SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
                }
            } else {
                $ledgerStmt->execute(['id' => $bookingId, 'amount' => $difference, 'desc' => "Extension - {$booking['category_name']}"]);
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
            }

            // Update booking
            $db->prepare("UPDATE bookings SET check_out = :co, total_amount = :total WHERE id = :id")
               ->execute(['co' => $newCheckOut, 'total' => $newTotal, 'id' => $bookingId]);

            // Audit
            $staffId = $_SESSION['user_id'] ?? null;
            AuditLogger::log($staffId, 'EXTEND_STAY', 'BOOKING', $bookingId, [
                'old_checkout' => $oldCheckOut, 'new_checkout' => $newCheckOut, 'extra_cost' => $difference
            ]);

            if ($shouldCommit) {
                $db->commit();
            }

            return ['extra_cost' => $difference, 'new_total' => $newTotal];
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * List bookings with filters and balance info.
     */
    public static function listBookings(\PDO $db, string $filter = 'today', string $search = '', int $limit = 50): array {
        $today = date('Y-m-d');
        $propId = class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1;

        $where = "b.payment_status != 'cancelled' AND b.property_id = :property_id";
        $params = ['property_id' => $propId];

        // If a search term is provided, bypass date filter so search checks all bookings in history
        if (strlen($search) === 0) {
            if ($filter === 'today') {
                $where .= " AND (DATE(b.check_in) = :t1 OR DATE(b.check_out) = :t2 OR b.booking_status = 'checked_in')";
                $params['t1'] = $today;
                $params['t2'] = $today;
            } elseif ($filter === 'yesterday') {
                $where .= " AND (DATE(b.check_in) = :y1 OR DATE(b.check_out) = :y2)";
                $params['y1'] = date('Y-m-d', strtotime('-1 day'));
                $params['y2'] = date('Y-m-d', strtotime('-1 day'));
            } elseif ($filter === 'week') {
                $where .= " AND b.check_in >= :ws";
                $params['ws'] = date('Y-m-d H:i:s', strtotime('-7 days'));
            } elseif ($filter === 'month') {
                $where .= " AND b.check_in >= :ms";
                $params['ms'] = date('Y-m-d H:i:s', strtotime('-30 days'));
            }
        }

        if (strlen($search) > 0) {
            $numericId = 0;
            if (preg_match_all('/(\d+)/', $search, $matches) && !empty($matches[1])) {
                $numericId = (int)end($matches[1]);
            }

            $where .= " AND (g.name LIKE :q1 OR g.phone LIKE :q2 OR r.room_number LIKE :q3 OR b.display_id LIKE :q4 OR b.id = :q5)";
            $params['q1'] = "%$search%";
            $params['q2'] = "%$search%";
            $params['q3'] = "%$search%";
            $params['q4'] = "%$search%";
            $params['q5'] = $numericId;
        }

        // Single optimised query — balance and advance_paid via aggregated LEFT JOIN (eliminates N+1)
        $sql = "
            SELECT b.id, b.room_id, b.guest_id, b.check_in, b.check_out,
                   b.booking_status, b.payment_status, b.total_amount, b.created_at, b.display_id, b.offline_folio_id,
                   b.rate_plan_name, b.price_override, b.booking_source,
                   r.room_number, r.state as room_state,
                   c.name as category_name,
                   g.name as guest_name, g.phone as guest_phone, g.photo as guest_photo,
                   g.id_proof_front, g.id_proof_back,
                   COALESCE(fl_agg.balance, 0)       as balance,
                   COALESCE(fl_agg.advance_paid, 0)  as advance_paid
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            JOIN room_categories c ON r.category_id = c.id
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN (
                SELECT booking_id,
                       SUM(amount) as balance,
                       ABS(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END)) as advance_paid
                FROM folio_ledger
                GROUP BY booking_id
            ) fl_agg ON fl_agg.booking_id = b.id
            WHERE {$where}
            ORDER BY b.check_in DESC
            LIMIT :limit
        ";

        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":$k", $v);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $bookings = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($bookings as &$b) {
            $b['display_check_in']  = date('d M Y, g:i A', strtotime($b['check_in']));
            $b['display_check_out'] = date('d M Y, g:i A', strtotime($b['check_out']));
        }

        return $bookings;
    }


    // ─── Private Helpers ──────────────────────────────────────────────────

    private static function postRoomCharges(\PDO $db, int $bookingId, int $categoryId, string $categoryName, string $checkIn, string $checkOut, ?string $ratePlanName, ?float $priceOverride): void {
        $pStmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ?");
        $pStmt->execute([$bookingId]);
        $propertyId = (int)$pStmt->fetchColumn() ?: 1;
        $ledgerStmt = $db->prepare("INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, transaction_ref, description) VALUES (:pid, :bid, 'ROOM_CHARGE', :amount, 'MANUAL', :desc)");

        if ($priceOverride !== null) {
            $ledgerStmt->execute([
                'pid'    => $propertyId,
                'bid'    => $bookingId,
                'amount' => $priceOverride,
                'desc'   => "Room Charges - {$categoryName} (Manual Override)",
            ]);
            SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
        } else {
            $breakdown = PricingEngine::getCostBreakdown($categoryId, $checkIn, $checkOut, $ratePlanName);
            foreach ($breakdown as $item) {
                $ledgerStmt->execute([
                    'pid'    => $propertyId,
                    'bid'    => $bookingId,
                    'amount' => $item['cost'],
                    'desc'   => "Day {$item['day']} - Room Charges - {$categoryName} ({$item['duration']})",
                ]);
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
            }
        }
    }

    /**
     * Post a single folio entry with display_id assignment.
     */
    public static function postFolioEntry(\PDO $db, int $bookingId, string $type, float $amount, string $description, string $ref = 'MANUAL', ?string $paymentMethod = null): int {
        // Fetch property_id from booking
        $pStmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ?");
        $pStmt->execute([$bookingId]);
        $propertyId = (int)$pStmt->fetchColumn() ?: 1;

        $sql = "INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, transaction_ref, description";
        $params = ['pid' => $propertyId, 'bid' => $bookingId, 'type' => $type, 'amount' => $amount, 'ref' => $ref, 'desc' => $description];
        
        if ($paymentMethod !== null) {
            $sql .= ", payment_method) VALUES (:pid, :bid, :type, :amount, :ref, :desc, :method)";
            $params['method'] = $paymentMethod;
        } else {
            $sql .= ") VALUES (:pid, :bid, :type, :amount, :ref, :desc)";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $entryId = (int)$db->lastInsertId();
        SequenceGenerator::assignDisplayId($db, 'folio_ledger', $entryId, 'SEQ_RECEIPT_FORMAT');

        // Sync to Google Sheets
        try {
            if (in_array(strtolower($type), ['cash', 'card', 'online', 'payment']) || $amount < 0) {
                GoogleSheetService::syncPayment($db, $entryId);
            }
            GoogleSheetService::syncBooking($db, $bookingId);
        } catch (\Throwable $t) {
            error_log("Google Sheets sync failed for ledger entry $entryId: " . $t->getMessage());
        }

        return $entryId;
    }

    /**
     * Record a payment (negative amount) in the folio ledger.
     */
    public static function recordPayment(\PDO $db, int $bookingId, float $amount, string $method, string $ref = 'MANUAL', string $description = 'Payment'): int {
        return self::postFolioEntry($db, $bookingId, 'payment', -$amount, $description, $ref, $method);
    }

    /**
     * Calculate number of days between two dates (minimum 1).
     */
    private static function calculateDays(string $start, string $end): int {
        $days = (int)ceil((strtotime($end) - strtotime($start)) / 86400);
        return max(1, $days);
    }
}
