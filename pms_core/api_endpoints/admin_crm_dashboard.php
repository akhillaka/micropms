<?php
declare(strict_types=1);

require_once __DIR__ . '/../ApiHandler.php';
require_once __DIR__ . '/../ApiResponse.php';

ApiHandler::run(function(\PDO $db) {
    $propertyId = AuthHelper::getPropertyId();
    
    // 1. Total Guest LTV (Lifetime Value) - Sum of total_amount of all completed/checked-out bookings
    $stmt = $db->prepare("SELECT SUM(total_amount) as total_ltv FROM bookings WHERE status IN ('checked_out', 'confirmed') AND property_id = :prop_id");
    $stmt->execute(['prop_id' => $propertyId]);
    $totalLtv = (float)($stmt->fetchColumn() ?: 0);

    // 2. Repeat Guest Ratio
    // Total unique guests
    $stmt = $db->prepare("SELECT COUNT(DISTINCT id) FROM guests WHERE property_id = :prop_id");
    $stmt->execute(['prop_id' => $propertyId]);
    $totalGuests = (int)($stmt->fetchColumn() ?: 0);

    // Guests with more than 1 booking
    $stmt = $db->prepare("SELECT COUNT(*) FROM (SELECT guest_id FROM bookings WHERE property_id = :prop_id GROUP BY guest_id HAVING COUNT(id) > 1) as repeat_guests");
    $stmt->execute(['prop_id' => $propertyId]);
    $repeatGuests = (int)($stmt->fetchColumn() ?: 0);
    
    $repeatGuestRatio = $totalGuests > 0 ? round(($repeatGuests / $totalGuests) * 100, 2) : 0;

    // 3. Average Length of Stay
    $stmt = $db->prepare("
        SELECT AVG(DATEDIFF(check_out, check_in)) as avg_los 
        FROM bookings 
        WHERE status NOT IN ('cancelled', 'no_show') AND property_id = :prop_id
    ");
    $stmt->execute(['prop_id' => $propertyId]);
    $avgLos = round((float)($stmt->fetchColumn() ?: 0), 1);

    // 4. Booking Source Breakdown
    $stmt = $db->prepare("
        SELECT booking_source, COUNT(*) as count, SUM(total_amount) as revenue
        FROM bookings
        WHERE property_id = :prop_id
        GROUP BY booking_source
        ORDER BY count DESC
    ");
    $stmt->execute(['prop_id' => $propertyId]);
    $sources = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    // Formatting for charts
    $sourceLabels = [];
    $sourceCounts = [];
    $sourceRevenue = [];
    foreach ($sources as $s) {
        $sourceLabels[] = ucfirst($s['booking_source'] ?: 'Walk-in');
        $sourceCounts[] = (int)$s['count'];
        $sourceRevenue[] = (float)$s['revenue'];
    }

    // Recent top guests by LTV
    $stmt = $db->prepare("
        SELECT g.name, g.phone, COUNT(b.id) as booking_count, SUM(b.total_amount) as total_spent
        FROM guests g
        JOIN bookings b ON g.id = b.guest_id
        WHERE b.booking_status IN ('checked_out', 'checked_in', 'booked') AND g.property_id = :prop_id AND b.property_id = :prop_id
        GROUP BY g.id
        ORDER BY total_spent DESC
        LIMIT 5
    ");
    $stmt->execute(['prop_id' => $propertyId]);
    $topGuests = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'total_ltv' => $totalLtv,
            'repeat_guest_ratio' => $repeatGuestRatio,
            'avg_los' => $avgLos,
            'sources' => [
                'labels' => $sourceLabels,
                'counts' => $sourceCounts,
                'revenue' => $sourceRevenue
            ],
            'top_guests' => $topGuests
        ]
    ]);
    exit;
});
