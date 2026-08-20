<?php
declare(strict_types=1);

require_once __DIR__ . '/../PricingEngine.php';
require_once __DIR__ . '/../PhoneHelper.php';
require_once __DIR__ . '/../SequenceGenerator.php';
require_once __DIR__ . '/../NotificationRelay.php';
require_once __DIR__ . '/../AuditLogger.php';
require_once __DIR__ . '/../GoogleSheetService.php';
require_once __DIR__ . '/FolioService.php';
require_once __DIR__ . '/StayPolicy.php';

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
        $propId = (int)($params['property_id'] ?? 0);
        if ($propId <= 0) {
            $propId = class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1;
        }

        // Block booking creation if there are pending Night Audit actions
        if (empty($params['skip_night_audit'])) {
            try {
                $actStmt = $db->prepare("SELECT COUNT(*) FROM night_audit_actions WHERE property_id = ? AND status = 'pending'");
                $actStmt->execute([$propId]);
                if ($actStmt->fetchColumn() > 0) {
                    throw new \Exception('Cannot create booking. Please resolve all pending Night Audit actions first.');
                }
            } catch (\PDOException $e) {
                // Ignore if table doesn't exist yet
            }
        }

        // Validation
        if (!$roomId || !$guestId || !$checkIn || !$checkOut) {
            throw new \Exception('Missing room ID, guest ID, or stay dates');
        }
        if (strtotime($checkOut) <= strtotime($checkIn)) {
            throw new \Exception('Check-out date must be after check-in');
        }
        if ($adults < 1 || $adults > 20 || $children < 0 || $children > 20) {
            throw new \Exception('Invalid occupancy counts');
        }
        if ($priceOverride !== null && $priceOverride < 0) {
            throw new \Exception('Price override cannot be negative');
        }

        $idempotencyKey = $params['idempotency_key'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            try {
                $stmt = $db->prepare("SELECT response_body FROM idempotency_keys WHERE property_id = ? AND idempotency_key = ?");
                $stmt->execute([$propId, $idempotencyKey]);
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
            $stmt = $db->prepare("SELECT r.category_id, r.property_id, c.name as category_name FROM rooms r JOIN room_categories c ON r.category_id = c.id WHERE r.id = :room_id AND r.property_id = :pid FOR UPDATE");
            $stmt->execute(['room_id' => $roomId, 'pid' => $propId]);
            $room = $stmt->fetch();
            if (!$room) {
                throw new \Exception('Invalid room selected');
            }

            // Verify if admin has access to this room's property
            if (class_exists('AuthHelper')) {
                if (session_status() === PHP_SESSION_NONE && empty($_SESSION['user_id'])) {
                    session_start();
                }
                if (isset($_SESSION['user_id'])) {
                    $activePropId = AuthHelper::getPropertyId();
                    if ((int)$room['property_id'] !== $activePropId) {
                        throw new \Exception('Access denied: Room belongs to a different property');
                    }
                }
            }

            // Check availability (ignore this session's own 15-minute hold)
            $holdToken = isset($params['hold_token']) ? (string)$params['hold_token'] : null;
            if (!self::isRoomAvailable($db, $roomId, $checkIn, $checkOut, null, $propId, $holdToken ?: null)) {
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
                $extraBedCost = $days * self::extraBedNightlyRate($db, (int)($room['property_id'] ?? $propId));
                if ($priceOverride === null) {
                    $totalAmount += $extraBedCost;
                }
            }

            $offlineFolioId = $params['offline_folio_id'] ?? null;
            if ($offlineFolioId === '') $offlineFolioId = null;

            $propertyId = (int)($room['property_id'] ?? $propId);

            // Insert booking
            $insertStmt = $db->prepare("
                INSERT INTO bookings (property_id, room_id, guest_id, check_in, check_out, payment_status, booking_status, total_amount, rate_plan_name, booking_source, price_override, adults, children, extra_bed, offline_folio_id)
                VALUES (:property_id, :room_id, :guest_id, :check_in, :check_out, :payment_status, :booking_status, :total_amount, :rate_plan_name, :booking_source, :price_override, :adults, :children, :extra_bed, :offline_folio_id)
            ");
            $paymentCollected = (float)($params['payment_collected'] ?? 0.0);

            // BUG-12 fix: default to 'pending'; only mark completed when advance covers full amount
            $defaultPaymentStatus = ($paymentCollected > 0 && $paymentCollected >= $totalAmount)
                ? 'completed_paid'
                : 'pending_hold';
            $insertStmt->execute([
                'property_id'     => $propertyId,
                'room_id'         => $roomId,
                'guest_id'        => $guestId,
                'check_in'        => $checkIn,
                'check_out'       => $checkOut,
                'payment_status'  => $defaultPaymentStatus,
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
            $dispStmt = $db->prepare("SELECT display_id FROM bookings WHERE id = ? AND property_id = ?");
            $dispStmt->execute([$bookingId, $propertyId]);
            $bookingDisplayId = $dispStmt->fetchColumn() ?: 'BKG-' . $bookingId;

            $skipRoomCharges = !empty($params['skip_room_charges']);
            $skipGoogleSheets = !empty($params['skip_google_sheets']);

            // Post room charges to folio
            if (!$skipRoomCharges) {
                self::postRoomCharges($db, $bookingId, $categoryId, $room['category_name'], $checkIn, $checkOut, $ratePlanName, $priceOverride);

                // Post extra bed charges with proper tax calculation
                if ($extraBedCost > 0 && $priceOverride === null) {
                    $days = self::calculateDays($checkIn, $checkOut);
                    $extraDesc = "Extra Bed Charge ({$days} night" . ($days > 1 ? 's' : '') . ")";
                    self::postRoomCharges($db, $bookingId, $categoryId, $room['category_name'], $checkIn, $checkOut, null, $extraBedCost, $extraDesc);
                }
            }

            // Record advance payment if collected
            $paymentCollected = isset($params['payment_collected']) ? (float)$params['payment_collected'] : 0.0;
            $paymentMethod = $params['payment_method'] ?? 'Cash';
            $paymentRef = $params['payment_ref'] ?? '';

            if ($paymentCollected > 0) {
                self::recordPayment($db, $bookingId, $paymentCollected, $paymentMethod, $paymentRef ?: 'MANUAL', 'Booking Advance Payment');
                
                // FolioService::recordPayment() above already synced to finance_transactions.
                // No duplicate insert needed.
            }

            if (empty($params['skip_notifications'])) {
                $guestName = (string)($params['guest_name'] ?? 'Guest');
                $roomNum   = (string)($room['room_number'] ?? 'N/A');
                $catName   = (string)($room['category_name'] ?? '');
                $checkInFmt  = date('d M Y, g:i A', strtotime($checkIn));
                $checkOutFmt = date('d M Y, g:i A', strtotime($checkOut));
                $source = ucfirst($bookingSource ?: 'front_desk');
                $folioUrl = '/admin/folio?id=' . rawurlencode((string)$bookingDisplayId);

                try {
                    $phoneStmt = $db->prepare("SELECT phone FROM guests WHERE id = ? AND property_id = ?");
                    $phoneStmt->execute([$guestId, $propertyId]);
                    $guestPhone = $phoneStmt->fetchColumn();
                    NotificationRelay::triggerAutomation(
                        'booking_confirmed',
                        $guestPhone ? PhoneHelper::toE164((string)$guestPhone) : null,
                        $bookingId,
                        [],
                        $propertyId
                    );
                    if ($bookingStatus === 'checked_in') {
                        NotificationRelay::triggerAutomation(
                            'guest_check_in',
                            $guestPhone ? PhoneHelper::toE164((string)$guestPhone) : null,
                            $bookingId,
                            [],
                            $propertyId
                        );
                    }
                } catch (\Throwable $t) {
                    error_log('Booking WhatsApp notify failed: ' . $t->getMessage());
                }

                try {
                    $tgMsg = "🏨 <b>New Booking Created</b>\n\n" .
                        "<b>Guest:</b> {$guestName}\n" .
                        "<b>Room:</b> {$roomNum}" . ($catName ? " ({$catName})" : '') . "\n" .
                        "<b>Check-in:</b> {$checkInFmt}\n" .
                        "<b>Check-out:</b> {$checkOutFmt}\n" .
                        "<b>Amount:</b> ₹" . number_format($totalAmount, 2) . "\n" .
                        "<b>Source:</b> {$source} | <b>Ref:</b> {$bookingDisplayId}";
                    NotificationRelay::sendTelegram($tgMsg, 'new_booking', [
                        'guest_name'   => $guestName,
                        'room_number'  => $roomNum,
                        'check_in'     => $checkInFmt,
                        'check_out'    => $checkOutFmt,
                        'total_amount' => number_format($totalAmount, 2),
                        'source'       => $source,
                    ], $propertyId);
                } catch (\Throwable $t) {
                    error_log('Booking Telegram notify failed: ' . $t->getMessage());
                }

                try {
                    NotificationRelay::sendInAppNotification($propertyId, 'New Booking Confirmed', "Room {$roomNum} booked for {$guestName} (₹" . number_format($totalAmount, 2) . ")", 'booking_confirmed', $folioUrl);
                    if ($bookingStatus === 'checked_in') {
                        NotificationRelay::sendInAppNotification($propertyId, 'Guest Checked In', "{$guestName} checked into Room {$roomNum}", 'check_in', $folioUrl);
                    }
                } catch (\Throwable $t) {
                    error_log('Booking in-app notify failed: ' . $t->getMessage());
                }
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
                    $insertIdemp = $db->prepare("INSERT IGNORE INTO idempotency_keys (property_id, idempotency_key, response_body) VALUES (?, ?, ?)");
                    $insertIdemp->execute([$propertyId, $idempotencyKey, json_encode($result)]);
                } catch (\PDOException $e) {
                    // If table doesn't exist yet, proceed
                }
            }

            if ($shouldCommit) {
                $db->commit();
            }

            if (empty($params['skip_google_sheets'])) {
                try {
                    GoogleSheetService::syncBooking($db, $bookingId);
                } catch (\Throwable $t) {
                    error_log("Google Sheets sync failed for booking $bookingId: " . $t->getMessage());
                }
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
    public static function isRoomAvailable(\PDO $db, int $roomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null, ?int $propertyId = null, ?string $exceptHoldToken = null): bool {
        // BUG-5 fix: pad both dates to 00:00:00 for consistent open-interval comparisons
        if (strlen($checkIn) === 10)  $checkIn  .= ' 00:00:00';
        if (strlen($checkOut) === 10) $checkOut .= ' 00:00:00';

        $propId = $propertyId ?? (class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1);

        $sql = "SELECT COUNT(*) FROM bookings
                WHERE room_id = :room_id
                  AND property_id = :property_id
                  AND payment_status != 'cancelled'
                  AND booking_status NOT IN ('cancelled', 'checked_out')
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
        
        if ($db->inTransaction()) {
            $sql .= " FOR UPDATE";
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() > 0) {
            return false;
        }

        try {
            $maintSql = "SELECT COUNT(*) FROM room_maintenance
                         WHERE room_id = :room_id
                           AND start_date < DATE(:check_out)
                           AND end_date > DATE(:check_in)";
            $maintStmt = $db->prepare($maintSql);
            $maintStmt->execute([
                'room_id' => $roomId,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
            ]);
            if ((int)$maintStmt->fetchColumn() > 0) {
                return false;
            }
        } catch (\PDOException $e) {
            // room_maintenance may be missing on older schemas
        }

        try {
            $holdSql = "SELECT COUNT(*) FROM room_holds
                        WHERE room_id = :room_id
                          AND property_id = :property_id
                          AND expires_at > NOW()
                          AND check_in < :check_out
                          AND check_out > :check_in";
            $holdParams = [
                'room_id' => $roomId,
                'property_id' => $propId,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
            ];
            if ($exceptHoldToken) {
                $holdSql .= " AND token != :token";
                $holdParams['token'] = $exceptHoldToken;
            }
            $holdStmt = $db->prepare($holdSql);
            $holdStmt->execute($holdParams);
            if ((int)$holdStmt->fetchColumn() > 0) {
                return false;
            }
        } catch (\PDOException $e) {
            // room_holds may not exist until migration 026 runs
        }

        return true;
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
    public static function extendStay(\PDO $db, int $bookingId, string $newCheckOut, ?int $propertyId = null): array {
        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            $propertyId = $propertyId ?: (class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1);
            $stmt = $db->prepare("
                SELECT b.*, r.category_id, c.name as category_name
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                JOIN room_categories c ON r.category_id = c.id
                WHERE b.id = :id AND b.property_id = :prop_id
                FOR UPDATE
            ");
            $stmt->execute(['id' => $bookingId, 'prop_id' => $propertyId]);
            $booking = $stmt->fetch();
            if (!$booking) {
                throw new \Exception('Booking not found');
            }
            StayPolicy::assert($booking, StayPolicy::CHECK_OUT);
            if ($booking['payment_status'] === 'cancelled') {
                throw new \Exception('Cannot extend stay for a cancelled booking');
            }

            $oldCheckOut = $booking['check_out'];
            if (strtotime($newCheckOut) == strtotime($oldCheckOut)) {
                throw new \Exception('New checkout date is the same as the current checkout date');
            }
            $isShortening = strtotime($newCheckOut) < strtotime($oldCheckOut);
            
            if ($isShortening && strtotime($newCheckOut) <= strtotime($booking['check_in'])) {
                throw new \Exception('New checkout must be after check-in');
            }

            // Check availability if extending
            if (!$isShortening && !self::isRoomAvailable($db, (int)$booking['room_id'], $oldCheckOut, $newCheckOut, $bookingId, $propertyId)) {
                throw new \Exception('Room not available for extended timeframe');
            }

            $isOverride = ($booking['price_override'] !== null);
            $newTotal = 0.0;
            $difference = 0.0;

            if ($isOverride) {
                try {
                    $difference = PricingEngine::calculateTotalCost($booking['category_id'], $isShortening ? $newCheckOut : $oldCheckOut, $isShortening ? $oldCheckOut : $newCheckOut, $booking['rate_plan_name']);
                    if ($isShortening) $difference = -$difference;
                    $newTotal = (float)$booking['total_amount'] + $difference;
                } catch (\Exception $e) {
                    $days = self::calculateDays($isShortening ? $newCheckOut : $oldCheckOut, $isShortening ? $oldCheckOut : $newCheckOut);
                    $difference = $days * 1000.00;
                    if ($isShortening) $difference = -$difference;
                    $newTotal = (float)$booking['total_amount'] + $difference;
                }
            } else {
                try {
                    $newTotal = PricingEngine::calculateTotalCost($booking['category_id'], $booking['check_in'], $newCheckOut, $booking['rate_plan_name']);
                    $difference = $newTotal - (float)$booking['total_amount'];
                } catch (\Exception $e) {
                    $days = self::calculateDays($booking['check_in'], $newCheckOut);
                    $newTotal = $days * 1000.00;
                    $difference = $newTotal - (float)$booking['total_amount'];
                }
            }

            // Post difference with proper taxes, instead of deleting and replacing
            if (abs($difference) > 0.001) {
                $desc = $isShortening ? "Stay Shortened - {$booking['category_name']} Refund/Adjustment" : "Stay Extension - {$booking['category_name']}";
                self::postRoomCharges($db, $bookingId, (int)$booking['category_id'], $booking['category_name'], $oldCheckOut, $newCheckOut, null, $difference, $desc);
            }

            // Update booking
            $db->prepare("UPDATE bookings SET check_out = :co, total_amount = :total WHERE id = :id AND property_id = :prop_id")
               ->execute(['co' => $newCheckOut, 'total' => $newTotal, 'id' => $bookingId, 'prop_id' => $propertyId]);

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

    private static function postRoomCharges(\PDO $db, int $bookingId, int $categoryId, string $categoryName, string $checkIn, string $checkOut, ?string $ratePlanName, ?float $priceOverride, ?string $customDesc = null): void {
        $pStmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ?");
        $pStmt->execute([$bookingId]);
        $propertyId = (int)$pStmt->fetchColumn() ?: 1;

        $taxEnabled = defined('TAX_ENABLED') && TAX_ENABLED === 'true';
        $taxRate = (defined('TAX_RATE') && is_numeric(TAX_RATE)) ? (float)TAX_RATE : 12.0;
        $taxLabel = defined('TAX_LABEL') ? TAX_LABEL : 'GST';

        $postCharge = function(float $grossAmount, string $baseDesc) use ($db, $propertyId, $bookingId, $taxEnabled, $taxRate, $taxLabel) {
            $ledgerStmt = $db->prepare("INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, transaction_ref, description) VALUES (:pid, :bid, :type, :amount, :ref, :desc)");

            if ($taxEnabled && $taxRate > 0) {
                $baseAmount = round($grossAmount / (1 + ($taxRate / 100)), 2);
                $taxAmount = round($grossAmount - $baseAmount, 2);

                $ledgerStmt->execute([
                    'pid'    => $propertyId,
                    'bid'    => $bookingId,
                    'type'   => 'ROOM_CHARGE',
                    'amount' => $baseAmount,
                    'ref'    => FolioService::uniqueRef('RC'),
                    'desc'   => $baseDesc,
                ]);
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');

                $ledgerStmt->execute([
                    'pid'    => $propertyId,
                    'bid'    => $bookingId,
                    'type'   => 'TAX',
                    'amount' => $taxAmount,
                    'ref'    => FolioService::uniqueRef('TAX'),
                    'desc'   => "{$taxLabel} ({$taxRate}%) - " . $baseDesc,
                ]);
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
            } else {
                $ledgerStmt->execute([
                    'pid'    => $propertyId,
                    'bid'    => $bookingId,
                    'type'   => 'ROOM_CHARGE',
                    'amount' => $grossAmount,
                    'ref'    => FolioService::uniqueRef('RC'),
                    'desc'   => $baseDesc,
                ]);
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', (int)$db->lastInsertId(), 'SEQ_RECEIPT_FORMAT');
            }
        };

        if ($priceOverride !== null) {
            $postCharge($priceOverride, $customDesc ?? "Room Charges - {$categoryName} (Manual Override)");
        } else {
            $breakdown = PricingEngine::getCostBreakdown($categoryId, $checkIn, $checkOut, $ratePlanName);
            foreach ($breakdown as $item) {
                $postCharge((float)$item['cost'], $customDesc ?? "Day {$item['day']} - Room Charges - {$categoryName} ({$item['duration']})");
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

        if ($ref === '' || strcasecmp($ref, 'MANUAL') === 0) {
            $ref = FolioService::uniqueRef('LED');
        }

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
     * Check in a booked stay.
     */
    public static function checkIn(\PDO $db, int $bookingId, int $propertyId, array $opts = []): void {
        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }
        try {
        $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :id AND property_id = :pid FOR UPDATE");
        $stmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
        $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new \Exception('Booking not found');
        }
        StayPolicy::assert($booking, StayPolicy::CHECK_IN_ACTION);
        StayPolicy::assertCheckInTime($booking);

        $upd = $db->prepare("UPDATE bookings SET booking_status = 'checked_in' WHERE id = :id AND property_id = :pid");
        $upd->execute(['id' => $bookingId, 'pid' => $propertyId]);

        AuditLogger::log($opts['staff_id'] ?? ($_SESSION['user_id'] ?? null), 'CHECK_IN', 'BOOKING', $bookingId, [
            'action' => 'check_in',
            'from_status' => 'booked',
            'to_status' => 'checked_in',
            'source' => $opts['source'] ?? 'admin',
            'check_in_time' => date('Y-m-d H:i:s'),
        ]);

        if (($opts['notify'] ?? true) !== false) {
            $roomStmt = $db->prepare("SELECT room_number FROM rooms WHERE id = :id AND property_id = :pid");
            $roomStmt->execute(['id' => $booking['room_id'], 'pid' => $propertyId]);
            $roomNum = (string)($roomStmt->fetchColumn() ?: $booking['room_id']);
            $guestStmt = $db->prepare("SELECT name, phone FROM guests WHERE id = :id AND property_id = :pid");
            $guestStmt->execute(['id' => $booking['guest_id'], 'pid' => $propertyId]);
            $guest = $guestStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $guestName = (string)($guest['name'] ?? 'N/A');
            $tgMsg = "🏨 <b>Guest Checked In</b>\n\nRoom: {$roomNum}\nGuest: " . htmlspecialchars($guestName) . "\nCheckout: {$booking['check_out']}";
            NotificationRelay::sendTelegram($tgMsg, 'check_in', [
                'guest_name' => $guestName,
                'room_number' => $roomNum,
                'check_out_date' => $booking['check_out'],
                'total_amount' => number_format((float)$booking['total_amount'], 2),
            ], $propertyId);
            NotificationRelay::triggerAutomation(
                'guest_check_in',
                !empty($guest['phone']) ? PhoneHelper::toE164((string)$guest['phone']) : null,
                $bookingId,
                [],
                $propertyId
            );
            NotificationRelay::sendInAppNotification(
                $propertyId,
                'Guest Checked In',
                "{$guestName} checked into Room {$roomNum}",
                'check_in',
                '/admin/folio?id=' . $bookingId
            );
        }

        try {
            GoogleSheetService::syncBooking($db, $bookingId);
        } catch (\Throwable $t) {
            error_log('Google Sheets sync error in checkIn: ' . $t->getMessage());
        }
        if ($shouldCommit) {
            $db->commit();
        }
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Change stay dates and/or room, then rebuild room-charge folio lines.
     *
     * @param array{rate_plan_name?: string, tax_preference?: string} $opts
     */
    public static function reschedule(\PDO $db, int $bookingId, int $propertyId, string $checkIn, string $checkOut, ?int $newRoomId = null, array $opts = []): array {
        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            $stmt = $db->prepare("SELECT b.*, r.category_id, c.name as category_name FROM bookings b JOIN rooms r ON b.room_id = r.id JOIN room_categories c ON r.category_id = c.id WHERE b.id = :id AND b.property_id = :pid FOR UPDATE");
            $stmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
            $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$booking) {
                throw new \Exception('Booking not found');
            }

            $checkIn = StayPolicy::normalizeDateTime($checkIn, (string)$booking['check_in'], '14:00:00');
            $checkOut = StayPolicy::normalizeDateTime($checkOut, (string)$booking['check_out'], '11:00:00');
            if (strtotime($checkOut) <= strtotime($checkIn)) {
                throw new \Exception('Check-out must be after check-in');
            }

            $checkInChanged = !StayPolicy::sameInstant($checkIn, (string)$booking['check_in']);
            $checkOutChanged = !StayPolicy::sameInstant($checkOut, (string)$booking['check_out']);
            $roomId = $newRoomId ?: (int)$booking['room_id'];
            $roomChanged = $roomId !== (int)$booking['room_id'];

            if ($checkInChanged) {
                StayPolicy::assert($booking, StayPolicy::CHECK_IN);
            }
            if ($checkOutChanged) {
                StayPolicy::assert($booking, StayPolicy::CHECK_OUT);
            }
            if ($roomChanged) {
                StayPolicy::assert($booking, StayPolicy::ROOM);
            }
            if (!$checkInChanged && !$checkOutChanged && !$roomChanged && empty($opts['rate_plan_name']) && empty($opts['tax_preference'])) {
                throw new \Exception('No stay changes to save');
            }

            if ($checkInChanged === false && $roomChanged === false && $checkOutChanged
                && (string)$booking['booking_status'] === 'checked_in'
                && empty($opts['rate_plan_name']) && empty($opts['tax_preference'])) {
                $result = self::extendStay($db, $bookingId, $checkOut, $propertyId);
                if ($shouldCommit && $db->inTransaction()) {
                    $db->commit();
                }
                return [
                    'new_total' => $result['new_total'],
                    'room_number' => self::roomNumber($db, (int)$booking['room_id'], $propertyId),
                    'check_in' => (string)$booking['check_in'],
                    'check_out' => $checkOut,
                ];
            }
            $roomStmt = $db->prepare("SELECT r.id, r.room_number, r.category_id, c.name as category_name FROM rooms r JOIN room_categories c ON r.category_id = c.id WHERE r.id = :id AND r.property_id = :pid FOR UPDATE");
            $roomStmt->execute(['id' => $roomId, 'pid' => $propertyId]);
            $room = $roomStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$room) {
                throw new \Exception('Room not found');
            }
            if (!self::isRoomAvailable($db, $roomId, $checkIn, $checkOut, $bookingId, $propertyId)) {
                throw new \Exception('Room is not available for those dates');
            }

            $ratePlan = $opts['rate_plan_name'] ?? ($booking['rate_plan_name'] ?? null);
            try {
                $newTotal = PricingEngine::calculateTotalCost((int)$room['category_id'], $checkIn, $checkOut, $ratePlan);
            } catch (\Exception $e) {
                $newTotal = self::calculateDays($checkIn, $checkOut) * 1000.00;
            }

            $setSql = "room_id = :room_id, check_in = :cin, check_out = :cout, total_amount = :total, rate_plan_name = :rate";
            $updParams = [
                'room_id' => $roomId,
                'cin' => $checkIn,
                'cout' => $checkOut,
                'total' => $newTotal,
                'rate' => $ratePlan,
                'id' => $bookingId,
                'pid' => $propertyId,
            ];
            if (array_key_exists('tax_preference', $booking) || array_key_exists('tax_preference', $opts)) {
                $setSql .= ", tax_preference = :tax";
                $updParams['tax'] = $opts['tax_preference'] ?? ($booking['tax_preference'] ?? null);
            }
            $upd = $db->prepare("UPDATE bookings SET {$setSql} WHERE id = :id AND property_id = :pid");
            $upd->execute($updParams);

            if ($roomChanged) {
                require_once __DIR__ . '/HousekeepingFlow.php';
                $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = :id AND property_id = :pid")->execute(['id' => $booking['room_id'], 'pid' => $propertyId]);
            }

            $db->prepare("DELETE FROM folio_ledger WHERE booking_id = :id AND transaction_type = 'ROOM_CHARGE' AND property_id = :pid")
                ->execute(['id' => $bookingId, 'pid' => $propertyId]);
            self::postRoomCharges($db, $bookingId, (int)$room['category_id'], (string)$room['category_name'], $checkIn, $checkOut, $ratePlan);

            AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_BOOKING', 'BOOKING', $bookingId, [
                'old_room' => $booking['room_id'],
                'new_room' => $roomId,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'new_total' => $newTotal,
            ]);

            if ($shouldCommit) {
                $db->commit();
            }
            return [
                'new_total' => $newTotal,
                'room_number' => $room['room_number'],
                'check_in' => $checkIn,
                'check_out' => $checkOut,
            ];
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel a booked reservation. Ledger is kept; staff refunds separately if paid.
     *
     * @return array{message: string, refund_alert?: bool, refund_amount?: float}
     */
    public static function cancelBooking(\PDO $db, int $bookingId, int $propertyId, string $reason, array $opts = []): array {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \Exception('Reason is required for cancellation');
        }

        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :id AND property_id = :pid FOR UPDATE");
            $stmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
            $booking = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$booking) {
                throw new \Exception('Booking not found');
            }
            StayPolicy::assert($booking, StayPolicy::CANCEL);

            $pmtStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM folio_ledger WHERE booking_id = :id AND amount < 0");
            $pmtStmt->execute(['id' => $bookingId]);
            $totalPaid = abs((float)$pmtStmt->fetchColumn());

            $db->prepare("UPDATE bookings SET booking_status = 'cancelled', payment_status = 'cancelled' WHERE id = :id AND property_id = :pid")
                ->execute(['id' => $bookingId, 'pid' => $propertyId]);

            try {
                $db->prepare("DELETE FROM jobs_queue WHERE property_id = :pid AND status = 'pending' AND JSON_EXTRACT(payload_json, '$.booking_id') = :id")
                    ->execute(['pid' => $propertyId, 'id' => $bookingId]);
            } catch (\PDOException $e) {
            }

            try {
                $posStmt = $db->prepare("SELECT id FROM pos_orders WHERE booking_id = :id AND status IN ('posted', 'pending') AND property_id = :pid");
                $posStmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
                $activePosOrders = $posStmt->fetchAll(\PDO::FETCH_COLUMN);
                if (!empty($activePosOrders)) {
                    $updatePos = $db->prepare("UPDATE pos_orders SET status = 'cancelled' WHERE id = ?");
                    $restock = $db->prepare("UPDATE inventory_items ii JOIN pos_order_items poi ON ii.id = poi.item_id SET ii.stock_qty = ii.stock_qty + poi.quantity WHERE poi.order_id = ?");
                    foreach ($activePosOrders as $oid) {
                        $restock->execute([$oid]);
                        $updatePos->execute([$oid]);
                    }
                }
            } catch (\PDOException $e) {
            }

            AuditLogger::log($opts['staff_id'] ?? ($_SESSION['user_id'] ?? null), 'CANCEL_BOOKING', 'BOOKING', $bookingId, [
                'action' => 'cancel',
                'from_status' => $booking['booking_status'],
                'to_status' => 'cancelled',
                'reason' => $reason,
                'total_paid' => $totalPaid,
                'refund_needed' => $totalPaid > 0,
                'source' => $opts['source'] ?? 'admin',
            ]);

            NotificationRelay::triggerAutomation('booking_cancelled', null, $bookingId, [], $propertyId);
            try {
                GoogleSheetService::syncBooking($db, $bookingId);
            } catch (\Throwable $t) {
                error_log('Google Sheets sync error in cancelBooking: ' . $t->getMessage());
            }

            if ($shouldCommit) {
                $db->commit();
            }

            $out = ['message' => 'Booking cancelled'];
            if ($totalPaid > 0) {
                $out['refund_alert'] = true;
                $out['refund_amount'] = $totalPaid;
                $out['message'] = 'Booking cancelled. Guest paid ₹' . number_format($totalPaid, 2) . ' — please process a refund.';
            }
            return $out;
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function applyLateCheckoutHours(\PDO $db, int $bookingId, int $propertyId, int $hours = 3): array {
        $stmt = $db->prepare("SELECT check_out FROM bookings WHERE id = ? AND property_id = ?");
        $stmt->execute([$bookingId, $propertyId]);
        $current = $stmt->fetchColumn();
        if (!$current) {
            throw new \Exception('Booking not found');
        }
        $newCheckOut = date('Y-m-d H:i:s', strtotime((string)$current . " +{$hours} hours"));
        return self::extendStay($db, $bookingId, $newCheckOut, $propertyId);
    }

    private static function roomNumber(\PDO $db, int $roomId, int $propertyId): string {
        $stmt = $db->prepare("SELECT room_number FROM rooms WHERE id = ? AND property_id = ?");
        $stmt->execute([$roomId, $propertyId]);
        return (string)($stmt->fetchColumn() ?: $roomId);
    }

    /**
     * Nightly extra-bed rate for a property (system_settings.EXTRA_BED_RATE, default 500).
     */
    public static function extraBedNightlyRate(\PDO $db, int $propertyId): float {
        $raw = '500';
        if (function_exists('get_db_setting')) {
            $raw = get_db_setting($db, 'EXTRA_BED_RATE', $propertyId, '500');
        }
        $rate = (float)$raw;
        return $rate > 0 ? $rate : 500.00;
    }

    /**
     * Calculate number of days between two dates (minimum 1).
     */
    public static function calculateDays(string $start, string $end): int {
        $days = (int)ceil((strtotime($end) - strtotime($start)) / 86400);
        return max(1, $days);
    }
}
