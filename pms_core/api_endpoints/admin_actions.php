<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/config.php';
ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('view_dashboard');

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $propertyId = AuthHelper::getPropertyId();
    $actions = [];

    // 1. Overdue Checkouts - checkout time passed, not checked out
    $stmt = $db->prepare("
        SELECT b.id, b.display_id, b.check_out, b.booking_status, b.payment_status, b.total_amount,
               r.room_number, g.name as guest_name, g.phone as guest_phone,
               c.name as category_name
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_categories c ON r.category_id = c.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.booking_status IN ('booked', 'checked_in')
          AND b.check_out < :now
          AND b.property_id = :pid
    ");
    $stmt->execute(['now' => $now, 'pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $hours = round((strtotime($now) - strtotime($row['check_out'])) / 3600, 1);
        $actions[] = [
            'type' => 'overdue_checkout',
            'severity' => 'critical',
            'icon' => 'ph-clock-warning',
            'title' => 'Overdue Checkout',
            'message' => "{$row['guest_name']} (Room {$row['room_number']}) was due {$row['check_out']} — {$hours}h overdue",
            'booking_id' => $row['id'],
            'guest_name' => $row['guest_name'],
            'guest_phone' => $row['guest_phone'],
            'room_number' => $row['room_number'],
            'category_name' => $row['category_name'],
            'action_url' => folio_href($row),
            'action_label' => 'View Folio',
            'time_diff_hours' => $hours
        ];
    }

    // 2. Overdue Check-ins - booking is 'booked' but check-in time has passed
    $stmt = $db->prepare("
        SELECT b.id, b.display_id, b.check_in, b.booking_status, b.payment_status,
               r.room_number, g.name as guest_name, g.phone as guest_phone,
               c.name as category_name
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_categories c ON r.category_id = c.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.booking_status = 'booked'
          AND b.check_in < :now
          AND b.payment_status != 'cancelled'
          AND b.property_id = :pid
    ");
    $stmt->execute(['now' => $now, 'pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $hours = round((strtotime($now) - strtotime($row['check_in'])) / 3600, 1);
        $actions[] = [
            'type' => 'overdue_checkin',
            'severity' => 'warning',
            'icon' => 'ph-user-minus',
            'title' => 'Overdue Check-in',
            'message' => "{$row['guest_name']} (Room {$row['room_number']}) was due to check in {$row['check_in']} — {$hours}h ago",
            'booking_id' => $row['id'],
            'guest_name' => $row['guest_name'],
            'guest_phone' => $row['guest_phone'],
            'room_number' => $row['room_number'],
            'category_name' => $row['category_name'],
            'action_url' => folio_href($row),
            'action_label' => 'View Folio'
        ];
    }

    // 3. Pending Dues - folio balance > 0 (sum ALL ledger entries, like folio.php does)
    $stmt = $db->prepare("
        SELECT b.id, b.display_id, b.total_amount, b.payment_status, b.booking_status,
               r.room_number, g.name as guest_name, g.phone as guest_phone,
               COALESCE(SUM(fl.amount), 0) as balance
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN folio_ledger fl ON b.id = fl.booking_id
        WHERE b.booking_status IN ('booked', 'checked_in')
          AND b.payment_status != 'cancelled'
          AND b.property_id = :pid
        GROUP BY b.id, b.display_id, b.total_amount, b.payment_status, b.booking_status, r.room_number, g.name, g.phone
        HAVING balance > 0
    ");
    $stmt->execute(['pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $due = $row['balance'];
        $actions[] = [
            'type' => 'pending_dues',
            'severity' => 'warning',
            'icon' => 'ph-currency-inr',
            'title' => 'Pending Dues',
            'message' => "{$row['guest_name']} (Room {$row['room_number']}) owes ₹" . number_format((float)$due, 2),
            'booking_id' => $row['id'],
            'guest_name' => $row['guest_name'],
            'guest_phone' => $row['guest_phone'],
            'room_number' => $row['room_number'],
            'amount_due' => $due,
            'action_url' => folio_href($row),
            'action_label' => 'Collect Payment'
        ];
    }

    // 4. Today's Arrivals - new bookings checking in today
    $stmt = $db->prepare("
        SELECT b.id, b.display_id, b.check_in, b.payment_status, b.total_amount,
               r.room_number, g.name as guest_name, g.phone as guest_phone,
               c.name as category_name
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_categories c ON r.category_id = c.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.booking_status = 'booked'
          AND DATE(b.check_in) = :today
          AND b.payment_status != 'cancelled'
          AND b.property_id = :pid
        ORDER BY b.check_in ASC
    ");
    $stmt->execute(['today' => $today, 'pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $time = date('g:i A', strtotime($row['check_in']));
        $actions[] = [
            'type' => 'today_arrival',
            'severity' => 'info',
            'icon' => 'ph-arrow-right',
            'title' => 'Arriving Today',
            'message' => "{$row['guest_name']} (Room {$row['room_number']}) arriving at {$time}",
            'booking_id' => $row['id'],
            'guest_name' => $row['guest_name'],
            'guest_phone' => $row['guest_phone'],
            'room_number' => $row['room_number'],
            'category_name' => $row['category_name'],
            'action_url' => folio_href($row),
            'action_label' => 'View Folio'
        ];
    }

    // 5. Bookings on Hold - pending payment confirmation
    $stmt = $db->prepare("
        SELECT b.id, b.display_id, b.created_at, b.total_amount,
               r.room_number, g.name as guest_name, g.phone as guest_phone,
               c.name as category_name
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_categories c ON r.category_id = c.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.payment_status = 'pending_hold'
          AND b.booking_status = 'booked'
          AND b.property_id = :pid
        ORDER BY b.created_at ASC
    ");
    $stmt->execute(['pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $minutes = round((strtotime($now) - strtotime($row['created_at'])) / 60);
        $actions[] = [
            'type' => 'booking_hold',
            'severity' => 'warning',
            'icon' => 'ph-hourglass',
            'title' => 'Booking on Hold',
            'message' => "{$row['guest_name']} (Room {$row['room_number']}) awaiting payment — ₹" . number_format((float)$row['total_amount'], 2),
            'booking_id' => $row['id'],
            'guest_name' => $row['guest_name'],
            'guest_phone' => $row['guest_phone'],
            'room_number' => $row['room_number'],
            'category_name' => $row['category_name'],
            'minutes_waiting' => $minutes,
            'action_url' => folio_href($row),
            'action_label' => 'View Folio'
        ];
    }

    // 6. Dirty Rooms - need housekeeping
    $stmt = $db->prepare("
        SELECT r.id, r.room_number, c.name as category_name
        FROM rooms r
        JOIN room_categories c ON r.category_id = c.id
        WHERE r.state = 'dirty' AND r.property_id = :pid
    ");
    $stmt->execute(['pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $actions[] = [
            'type' => 'dirty_room',
            'severity' => 'info',
            'icon' => 'ph-broom',
            'title' => 'Room Needs Cleaning',
            'message' => "Room {$row['room_number']} ({$row['category_name']}) is dirty and needs housekeeping",
            'room_id' => $row['id'],
            'room_number' => $row['room_number'],
            'category_name' => $row['category_name'],
            'action_url' => "index.php",
            'action_label' => 'Mark Clean'
        ];
    }

    // 7. Missing ID Proof
    $stmt = $db->prepare("
        SELECT g.id, g.name, g.phone
        FROM guests g
        WHERE (g.id_proof_front IS NULL OR g.id_proof_front = '')
          AND EXISTS (
              SELECT 1 FROM bookings b WHERE b.guest_id = g.id
              AND b.booking_status IN ('booked', 'checked_in')
              AND b.payment_status != 'cancelled'
              AND b.property_id = :pid
          )
    ");
    $stmt->execute(['pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $actions[] = [
            'type' => 'missing_id',
            'severity' => 'warning',
            'icon' => 'ph-identification-card',
            'title' => 'Missing ID Proof',
            'message' => "{$row['name']} ({$row['phone']}) has no ID proof uploaded",
            'guest_id' => $row['id'],
            'guest_name' => $row['name'],
            'guest_phone' => $row['phone'],
            'action_url' => "/admin/guest_profile?id={$row['id']}",
            'action_label' => 'Upload ID'
        ];
    }
 
    // 8. Incomplete Guest Profile
    $stmt = $db->prepare("
        SELECT g.id, g.name, g.phone, g.age, g.city
        FROM guests g
        WHERE (g.age IS NULL OR g.city IS NULL OR g.city = '' OR g.phone = '')
          AND EXISTS (
              SELECT 1 FROM bookings b WHERE b.guest_id = g.id
              AND b.booking_status IN ('booked', 'checked_in')
              AND b.payment_status != 'cancelled'
              AND b.property_id = :pid
          )
    ");
    $stmt->execute(['pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $missing = [];
        if (empty($row['age'])) $missing[] = 'age';
        if (empty($row['city'])) $missing[] = 'city';
        if (empty($row['phone'])) $missing[] = 'phone';
        $actions[] = [
            'type' => 'incomplete_profile',
            'severity' => 'info',
            'icon' => 'ph-user-circle',
            'title' => 'Incomplete Guest Profile',
            'message' => "{$row['name']} missing: " . implode(', ', $missing),
            'guest_id' => $row['id'],
            'guest_name' => $row['name'],
            'guest_phone' => $row['phone'],
            'action_url' => "/admin/guest_profile?id={$row['id']}",
            'action_label' => 'Edit Profile'
        ];
    }

    // 9. Upcoming Checkouts (within 2 hours)
    $twoHoursLater = date('Y-m-d H:i:s', strtotime('+2 hours'));
    $stmt = $db->prepare("
        SELECT b.id, b.display_id, b.check_out, b.booking_status,
               r.room_number, g.name as guest_name, g.phone as guest_phone
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.booking_status = 'checked_in'
          AND b.check_out > :now
          AND b.check_out <= :later
          AND b.property_id = :pid
    ");
    $stmt->execute(['now' => $now, 'later' => $twoHoursLater, 'pid' => $propertyId]);
    foreach ($stmt->fetchAll() as $row) {
        $minutes = round((strtotime($row['check_out']) - strtotime($now)) / 60);
        $actions[] = [
            'type' => 'upcoming_checkout',
            'severity' => 'info',
            'icon' => 'ph-clock',
            'title' => 'Checkout Soon',
            'message' => "{$row['guest_name']} (Room {$row['room_number']}) checking out at " . date('g:i A', strtotime($row['check_out'])),
            'booking_id' => $row['id'],
            'guest_name' => $row['guest_name'],
            'guest_phone' => $row['guest_phone'],
            'room_number' => $row['room_number'],
            'minutes_left' => $minutes,
            'action_url' => folio_href($row),
            'action_label' => 'View Folio'
        ];
    }

    // Sort by severity: critical > warning > info
    $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($actions, function($a, $b) use ($severityOrder) {
        return $severityOrder[$a['severity']] - $severityOrder[$b['severity']];
    });

    ApiResponse::success(['actions' => $actions, 'count' => count($actions)]);


}, true, false, false);

