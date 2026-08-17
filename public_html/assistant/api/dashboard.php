<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/config.php';

ApiHandler::run(function(\PDO $db) {
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $twoHoursLater = date('Y-m-d H:i:s', strtotime('+2 hours'));
    $propertyId = AuthHelper::getPropertyId();

    // ═══════════════════════════════════════════════════════
        // SUMMARY QUERIES — All scoped to current property_id
        // ═══════════════════════════════════════════════════════
        
        // Total rooms
        $s = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ?"); $s->execute([$propertyId]); $totalRooms = (int)$s->fetchColumn();
        
        // Dirty rooms
        $s = $db->prepare("SELECT COUNT(*) FROM rooms WHERE state = 'dirty' AND property_id = ?"); $s->execute([$propertyId]); $dirtyRooms = (int)$s->fetchColumn();
        
        // Occupied rooms (checked_in)
        $s = $db->prepare("SELECT COUNT(*) FROM bookings WHERE booking_status = 'checked_in' AND payment_status != 'cancelled' AND property_id = ?"); $s->execute([$propertyId]); $occupiedRooms = (int)$s->fetchColumn();
        
        // Available rooms (clean rooms not occupied)
        $s = $db->prepare("
            SELECT COUNT(*) FROM rooms r 
            WHERE r.state = 'clean' AND r.property_id = ?
            AND r.id NOT IN (
                SELECT DISTINCT room_id FROM bookings 
                WHERE booking_status IN ('booked', 'checked_in') 
                AND payment_status != 'cancelled'
                AND property_id = ?
                AND check_in <= NOW() AND check_out >= NOW()
            )
        ");
        $s->execute([$propertyId, $propertyId]);
        $availableRooms = (int)$s->fetchColumn();
        
        // Today's arrivals
        $s = $db->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE DATE(check_in) = CURDATE() 
            AND booking_status = 'booked' 
            AND payment_status != 'cancelled'
            AND property_id = ?
        ");
        $s->execute([$propertyId]); $arrivals = (int)$s->fetchColumn();
        
        // Today's departures
        $s = $db->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE DATE(check_out) = CURDATE() 
            AND booking_status = 'checked_in' 
            AND payment_status != 'cancelled'
            AND property_id = ?
        ");
        $s->execute([$propertyId]); $departures = (int)$s->fetchColumn();
        
        // Pending payments (bookings with positive balance)
        $sp = $db->prepare("
            SELECT COUNT(DISTINCT sub.id) FROM (
                SELECT b.id FROM bookings b LEFT JOIN folio_ledger fl ON b.id = fl.booking_id
                WHERE b.booking_status IN ('booked', 'checked_in') AND b.payment_status != 'cancelled' AND b.property_id = ?
                GROUP BY b.id HAVING COALESCE(SUM(fl.amount), 0) > 0
            ) sub
        ");
        $sp->execute([$propertyId]); $pendingPayments = (int)$sp->fetchColumn();
        
        // Pending ID verification
        $s = $db->prepare("
            SELECT COUNT(DISTINCT b.id) FROM bookings b 
            JOIN guests g ON b.guest_id = g.id 
            WHERE b.booking_status IN ('booked', 'checked_in') 
            AND b.payment_status != 'cancelled'
            AND b.property_id = ?
            AND (g.id_proof_front IS NULL OR g.id_proof_front = '' OR g.id_proof_back IS NULL OR g.id_proof_back = '')
        ");
        $s->execute([$propertyId]); $pendingIds = (int)$s->fetchColumn();
        
        $summary = [
            'new_bookings' => 0,
            'arrivals' => $arrivals,
            'departures' => $departures,
            'available' => $availableRooms,
            'occupied' => $occupiedRooms,
            'dirty_rooms' => $dirtyRooms,
            'pending_payments' => $pendingPayments,
            'pending_ids' => $pendingIds
        ];
        
        // Get today's new bookings count
        $s = $db->prepare("SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = CURDATE() AND payment_status != 'cancelled' AND property_id = ?");
        $s->execute([$propertyId]); $summary['new_bookings'] = (int)$s->fetchColumn();

    // ═══════════════════════════════════════════════════════
    // COMBINED ALERTS QUERY — Scoped to current property_id
    // ═══════════════════════════════════════════════════════
    $alertsStmt = $db->prepare("
        SELECT 
            b.id as booking_id,
            b.check_in,
            b.check_out,
            b.booking_status,
            b.payment_status,
            b.total_amount,
            b.created_at,
            r.id as room_id,
            r.room_number,
            r.state as room_state,
            c.name as category_name,
            g.id as guest_id,
            g.name as guest_name,
            g.phone as guest_phone,
            g.id_proof_front,
            g.id_proof_back,
            COALESCE(fl_agg.balance, 0) as balance
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_categories c ON r.category_id = c.id
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN (
            SELECT booking_id, SUM(amount) as balance
            FROM folio_ledger
            GROUP BY booking_id
        ) fl_agg ON b.id = fl_agg.booking_id
        WHERE b.payment_status != 'cancelled'
          AND b.property_id = :property_id
          AND (
              (b.booking_status = 'checked_in')
              OR (b.booking_status = 'booked' AND DATE(b.check_in) <= :today)
          )
        ORDER BY b.check_out ASC
        LIMIT 50
    ");
    $alertsStmt->execute(['today' => $today, 'property_id' => $propertyId]);
    $bookings = $alertsStmt->fetchAll(\PDO::FETCH_ASSOC);

    // ═══════════════════════════════════════════════════════
    // DIRTY ROOMS QUERY — Scoped to current property_id
    // ═══════════════════════════════════════════════════════
    $dirtyStmt = $db->prepare("
        SELECT r.id, r.room_number, c.name as category_name 
        FROM rooms r 
        JOIN room_categories c ON r.category_id = c.id 
        WHERE r.state = 'dirty' AND r.property_id = ?
        LIMIT 5
    ");
    $dirtyStmt->execute([$propertyId]);
    $dirtyRooms = $dirtyStmt->fetchAll(\PDO::FETCH_ASSOC);

    // ═══════════════════════════════════════════════════════
    // BUILD ALERTS FROM BOOKING DATA
    // ═══════════════════════════════════════════════════════
    $alerts = [];

    // Dirty room alerts
    foreach ($dirtyRooms as $row) {
        $alerts[] = [
            'type' => 'dirty_room',
            'severity' => 'warning',
            'title' => 'Room needs cleaning',
            'message' => "Room {$row['room_number']} ({$row['category_name']}) needs cleaning",
            'room_id' => $row['id'],
            'room_number' => $row['room_number']
        ];
    }

    // Add Service Requests to alerts
    $srStmt = $db->prepare("
        SELECT gsr.id, gsr.booking_id, gsr.service_type, gsr.created_at, r.room_number, g.name as guest_name
        FROM guest_service_requests gsr
        JOIN bookings b ON gsr.booking_id = b.id
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE gsr.property_id = ? AND gsr.status = 'pending'
    ");
    $srStmt->execute([$propertyId]);
    $serviceRequests = $srStmt->fetchAll();

    foreach ($serviceRequests as $req) {
        $typeLabel = ucwords(str_replace('_', ' ', $req['service_type']));
        $alerts[] = [
            'type' => 'service_request',
            'severity' => 'danger',
            'title' => "Request: {$typeLabel}",
            'message' => "Room {$req['room_number']} requested {$typeLabel}",
            'booking_id' => $req['booking_id'],
            'request_id' => $req['id'],
            'service_type' => $req['service_type'],
            'guest_name' => $req['guest_name'],
            'room_number' => $req['room_number']
        ];
    }

    // Process bookings into alerts
    foreach ($bookings as $b) {
        $checkIn = strtotime($b['check_in']);
        $checkOut = strtotime($b['check_out']);
        $nowTs = strtotime($now);
        $twoHoursTs = strtotime($twoHoursLater);

        // Overdue checkout (critical)
        if ($b['booking_status'] === 'checked_in' && $checkOut < $nowTs) {
            $hours = round(($nowTs - $checkOut) / 3600, 1);
            $alerts[] = [
                'type' => 'overdue_checkout',
                'severity' => 'critical',
                'title' => 'Overdue Checkout',
                'message' => "{$b['guest_name']} (Room {$b['room_number']}) was due {$hours}h ago",
                'booking_id' => $b['booking_id'],
                'guest_name' => $b['guest_name'],
                'room_number' => $b['room_number'],
                'hours_overdue' => $hours
            ];
        }
        // Upcoming checkout (within 2 hours)
        elseif ($b['booking_status'] === 'checked_in' && $checkOut > $nowTs && $checkOut <= $twoHoursTs) {
            $minutes = round(($checkOut - $nowTs) / 60);
            $alerts[] = [
                'type' => 'upcoming_checkout',
                'severity' => 'info',
                'title' => 'Checkout Soon',
                'message' => "{$b['guest_name']} (Room {$b['room_number']}) checking out in {$minutes} min",
                'booking_id' => $b['booking_id'],
                'guest_name' => $b['guest_name'],
                'room_number' => $b['room_number'],
                'minutes_left' => $minutes
            ];
        }
        // Today's checkout
        elseif ($b['booking_status'] === 'checked_in' && date('Y-m-d', $checkOut) === $today) {
            $time = date('g:i A', $checkOut);
            $alerts[] = [
                'type' => 'today_departure',
                'severity' => 'info',
                'title' => 'Checkout Today',
                'message' => "{$b['guest_name']} (Room {$b['room_number']}) due at {$time}",
                'booking_id' => $b['booking_id'],
                'guest_name' => $b['guest_name'],
                'room_number' => $b['room_number']
            ];
        }

        // Overdue check-in (warning)
        if ($b['booking_status'] === 'booked' && $checkIn < $nowTs) {
            $hours = round(($nowTs - $checkIn) / 3600, 1);
            $alerts[] = [
                'type' => 'overdue_checkin',
                'severity' => 'warning',
                'title' => 'Overdue Check-in',
                'message' => "{$b['guest_name']} (Room {$b['room_number']}) was due {$hours}h ago",
                'booking_id' => $b['booking_id'],
                'guest_name' => $b['guest_name'],
                'room_number' => $b['room_number']
            ];
        }
        // Today's arrival
        elseif ($b['booking_status'] === 'booked' && date('Y-m-d', $checkIn) === $today) {
            $time = date('g:i A', $checkIn);
            $alerts[] = [
                'type' => 'today_arrival',
                'severity' => 'info',
                'title' => 'Arriving Today',
                'message' => "{$b['guest_name']} (Room {$b['room_number']}) arriving at {$time}",
                'booking_id' => $b['booking_id'],
                'guest_name' => $b['guest_name'],
                'room_number' => $b['room_number']
            ];
        }

        // Pending payment
        if ($b['balance'] > 0) {
            $alerts[] = [
                'type' => 'pending_payment',
                'severity' => 'danger',
                'title' => 'Pending Payment',
                'message' => "{$b['guest_name']} (Room {$b['room_number']}) owes ₹" . number_format((float)$b['balance'], 2),
                'booking_id' => $b['booking_id'],
                'balance' => (float)$b['balance'],
                'guest_name' => $b['guest_name']
            ];
        }

        // Missing ID proof
        if (empty($b['id_proof_front']) && empty($b['id_proof_back'])) {
            $alerts[] = [
                'type' => 'missing_id',
                'severity' => 'warning',
                'title' => 'Missing ID Proof',
                'message' => "{$b['guest_name']} (Room {$b['room_number']}) has no ID proof",
                'booking_id' => $b['booking_id'],
                'guest_id' => $b['guest_id'],
                'guest_name' => $b['guest_name']
            ];
        }

        // Booking on hold
        if ($b['payment_status'] === 'pending_hold' && $b['booking_status'] === 'booked') {
            $minutes = round(($nowTs - strtotime($b['created_at'])) / 60);
            $alerts[] = [
                'type' => 'booking_hold',
                'severity' => 'warning',
                'title' => 'Booking on Hold',
                'message' => "{$b['guest_name']} (Room {$b['room_number']}) awaiting payment — ₹" . number_format((float)$b['total_amount'], 2),
                'booking_id' => $b['booking_id'],
                'guest_name' => $b['guest_name'],
                'room_number' => $b['room_number'],
                'minutes_waiting' => $minutes
            ];
        }
    }

    // Sort by severity
    $severityOrder = ['critical' => 0, 'danger' => 1, 'warning' => 2, 'info' => 3];

    usort($alerts, function($a, $b) use ($severityOrder) {
        return ($severityOrder[$a['severity']] ?? 3) - ($severityOrder[$b['severity']] ?? 3);
    });

    $methods = get_payment_methods($db, (int)$propertyId);
    $categories = get_payment_categories($db, (int)$propertyId);
    $gateways = get_active_payment_gateways($db, (int)$propertyId);

    ApiResponse::success([
        'summary' => [
            'today_new_bookings' => (int)$summary['new_bookings'],
            'today_check_in' => (int)$summary['arrivals'],
            'today_check_out' => (int)$summary['departures'],
            'available_rooms' => (int)$summary['available'],
            'occupied_rooms' => (int)$summary['occupied'],
            'cleaning_rooms' => (int)$summary['dirty_rooms'],
            'pending_payments' => (int)$summary['pending_payments'],
            'pending_id_verification' => (int)$summary['pending_ids']
        ],
        'alerts' => $alerts,
        'payment_methods' => $methods,
        'payment_categories' => $categories,
        'active_gateways' => array_values($gateways),
        'razorpay_key_id' => (string)($gateways['razorpay']['key_id'] ?? ''),
    ]);

}, true, true, false);
