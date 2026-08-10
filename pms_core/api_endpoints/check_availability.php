<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
ApiHandler::run(function(\PDO $db) {

    $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    if (empty($data['check_in']) || empty($data['check_out'])) {
        ApiResponse::error('Missing check-in or check-out date parameters');
    }

    $checkInStr = str_replace('T', ' ', trim((string)$data['check_in']));
    $checkOutStr = str_replace('T', ' ', trim((string)$data['check_out']));
    if (strlen($checkInStr) === 16) { $checkInStr .= ':00'; }
    if (strlen($checkOutStr) === 16) { $checkOutStr .= ':00'; }

    $propertyId = !empty($data['property_id']) ? (int)$data['property_id'] : AuthHelper::getPropertyId();
    if ($propertyId <= 0) {
        $propertyId = 1; // Default fallback for unauthenticated public booking engine
    }

    // We want to find a room that does NOT have overlapping bookings AND is not out of order.
    // An overlapping booking is one where: existing_check_in < requested_check_out AND existing_check_out > requested_check_in
    $sql = "SELECT r.*, c.name as category_name, 
                (SELECT price FROM sliding_rates s WHERE s.category_id = c.id AND s.hours = 24 LIMIT 1) as base_daily_rate
            FROM rooms r
            JOIN room_categories c ON r.category_id = c.id
            WHERE r.state != 'out_of_order'
              AND r.property_id = :property_id1
              AND r.id NOT IN (
                SELECT b.room_id FROM bookings b
                WHERE b.check_in < :check_out 
                  AND b.check_out > :check_in
                  AND b.payment_status != 'cancelled'
                  AND b.property_id = :property_id2
            )";            
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'property_id1' => $propertyId,
        'property_id2' => $propertyId,
        'check_in' => $checkInStr,
        'check_out' => $checkOutStr
    ]);
    
    $availableRooms = $stmt->fetchAll();
    
    require_once __DIR__ . '/../../pms_core/PricingEngine.php';
    // Group by category and list available rooms
    $categories = [];
    foreach ($availableRooms as $room) {
        $catId = (int)$room['category_id'];
        if (!isset($categories[$catId])) {
            
            // Get all unique rate plans for this category
            $rpStmt = $db->prepare("SELECT DISTINCT rate_plan_name FROM sliding_rates WHERE category_id = :cid");
            $rpStmt->execute(['cid' => $catId]);
            $plans = $rpStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $ratePlans = [];
            foreach ($plans as $planName) {
                if (empty($planName)) continue;
                try {
                    $totalCost = PricingEngine::calculateTotalCost($catId, $checkInStr, $checkOutStr, $planName);
                    $ratePlans[] = [
                        'name' => $planName,
                        'total_cost' => $totalCost
                    ];
                } catch (\Throwable $e) {
                    // Skip uncalculable plan
                }
            }
            
            // Fallback if no rate plans are defined or calculated
            if (empty($ratePlans)) {
                try {
                    $totalCost = PricingEngine::calculateTotalCost($catId, $checkInStr, $checkOutStr, null);
                } catch (\Throwable $e) {
                    $totalCost = 0.0;
                }
                $ratePlans[] = [
                    'name' => 'Base Rate',
                    'total_cost' => $totalCost
                ];
            }

            $categories[$catId] = [
                'category_id' => $catId,
                'name' => $room['category_name'],
                'rate_plans' => $ratePlans,
                'rooms' => []
            ];
        }
        $categories[$catId]['rooms'][] = [
            'id' => $room['id'],
            'room_number' => $room['room_number']
        ];
    }
    
    ApiResponse::success(['categories' => array_values($categories)]);
    

}, false, false, false);

