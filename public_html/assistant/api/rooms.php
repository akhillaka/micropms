<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/PricingEngine.php';

ApiHandler::run(function(\PDO $db) {
    // Session is checked by ApiHandler

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $_GET['action'] ?? $data['action'] ?? '';
    
    if ($action === 'all') {
        $sql = "SELECT r.id, r.room_number, r.category_id, r.state as room_state, c.name as category_name 
                FROM rooms r
                JOIN room_categories c ON r.category_id = c.id";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $allRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $processedRooms = [];
        $now = date('Y-m-d H:i:s');
        foreach ($allRooms as $room) {
            // Check if occupied right now
            $occStmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = :rid AND check_in <= :now1 AND check_out >= :now2 AND booking_status IN ('booked', 'checked_in') AND payment_status != 'cancelled'");
            $occStmt->execute(['rid' => $room['id'], 'now1' => $now, 'now2' => $now]);
            $isOccupied = (int)$occStmt->fetchColumn() > 0;
            
            $floor = 'Ground Floor';
            $numOnly = preg_replace('/[^0-9]/', '', $room['room_number']);
            if (strlen($numOnly) >= 3) {
                $firstDigit = substr($numOnly, 0, 1);
                $floor = ($firstDigit === '1') ? 'First Floor' : (($firstDigit === '2') ? 'Second Floor' : $firstDigit . 'th Floor');
            }
            
            $processedRooms[] = [
                'id' => $room['id'],
                'room_number' => $room['room_number'],
                'category_id' => $room['category_id'],
                'category_name' => $room['category_name'],
                'floor' => $floor,
                'room_state' => $room['room_state'],
                'is_occupied' => $isOccupied,
                'rate_plans' => []
            ];
        }
        ApiResponse::success(['rooms' => $processedRooms]);
        return;
    }
    
    // Default stay dates if not provided: today current hour to tomorrow 11 AM
    $checkIn = $data['check_in'] ?? date('Y-m-d H:00:00');
    $checkOut = $data['check_out'] ?? date('Y-m-d H:00:00', strtotime('+1 day'));
    
    if (strtotime($checkOut) <= strtotime($checkIn)) {
        ApiResponse::error('Check-out must be after Check-in');
    }

    // 1. Fetch all rooms that do NOT have active overlapping bookings for this timeframe
    // Overlapping bookings: check_in < requested_check_out AND check_out > requested_check_in
    $sql = "SELECT r.id, r.room_number, r.category_id, r.state as room_state, c.name as category_name 
            FROM rooms r
            JOIN room_categories c ON r.category_id = c.id
            WHERE r.state != 'out_of_order'
              AND r.id NOT IN (
                  SELECT b.room_id FROM bookings b
                  WHERE b.check_in < :check_out 
                    AND b.check_out > :check_in
                    AND b.payment_status != 'cancelled'
              )";
              
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'check_in' => $checkIn,
        'check_out' => $checkOut
    ]);
    
    $availableRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Fetch rate plans for each category to compute prices
    $categories = [];
    $processedRooms = [];
    
    foreach ($availableRooms as $room) {
        $catId = (int)$room['category_id'];
        $roomNum = $room['room_number'];
        
        // Dynamic floor extraction
        $floor = 'Ground Floor';
        $numOnly = preg_replace('/[^0-9]/', '', $roomNum);
        if (strlen($numOnly) >= 3) {
            $firstDigit = substr($numOnly, 0, 1);
            if ($firstDigit === '1') {
                $floor = 'First Floor';
            } elseif ($firstDigit === '2') {
                $floor = 'Second Floor';
            } elseif ((int)$firstDigit > 2) {
                $floor = $firstDigit . 'th Floor';
            }
        }
        
        // Dynamic attributes based on category name
        $catNameLower = strtolower($room['category_name']);
        $isAc = (strpos($catNameLower, 'non') === false);
        $isSuite = (strpos($catNameLower, 'suite') !== false);
        $isDeluxe = (strpos($catNameLower, 'deluxe') !== false || $isSuite);
        
        $roomType = 'Standard';
        if ($isSuite) $roomType = 'Suite';
        elseif ($isDeluxe) $roomType = 'Deluxe';

        $capacity = (strpos($catNameLower, 'triple') !== false) ? 3 : ((strpos($catNameLower, 'single') !== false) ? 1 : 2);
        if ($isSuite) $capacity = 4;

        // Fetch pricing breakdown and rate plans if not loaded for this category
        if (!isset($categories[$catId])) {
            $rpStmt = $db->prepare("SELECT DISTINCT rate_plan_name FROM sliding_rates WHERE category_id = :cid");
            $rpStmt->execute(['cid' => $catId]);
            $plans = $rpStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $ratePlans = [];
            foreach ($plans as $planName) {
                // Determine rate plan name display
                $displayName = empty($planName) ? 'Standard' : $planName;
                
                try {
                    $totalCost = PricingEngine::calculateTotalCost($catId, $checkIn, $checkOut, $planName);
                    $breakdown = PricingEngine::getCostBreakdown($catId, $checkIn, $checkOut, $planName);
                    
                    $ratePlans[] = [
                        'name' => $displayName,
                        'rate_plan_key' => $planName, // Null for base rate
                        'total_cost' => $totalCost,
                        'breakdown' => $breakdown
                    ];
                } catch (\Exception $ex) {
                    // Fallback to zero if rate plan is not configured up to 24h
                    continue;
                }
            }
            
            // Fallback rate plan if nothing configured
            if (empty($ratePlans)) {
                try {
                    $totalCost = PricingEngine::calculateTotalCost($catId, $checkIn, $checkOut, null);
                    $breakdown = PricingEngine::getCostBreakdown($catId, $checkIn, $checkOut, null);
                    $ratePlans[] = [
                        'name' => 'Standard',
                        'rate_plan_key' => null,
                        'total_cost' => $totalCost,
                        'breakdown' => $breakdown
                    ];
                } catch (\Exception $ex) {
                    $ratePlans[] = [
                        'name' => 'Standard',
                        'rate_plan_key' => null,
                        'total_cost' => 0.00,
                        'breakdown' => []
                    ];
                }
            }
            
            $categories[$catId] = $ratePlans;
        }

        $processedRooms[] = [
            'id' => $room['id'],
            'room_number' => $roomNum,
            'category_id' => $catId,
            'category_name' => $room['category_name'],
            'floor' => $floor,
            'is_ac' => $isAc,
            'room_type' => $roomType,
            'capacity' => $capacity,
            'room_state' => $room['room_state'],
            'rate_plans' => $categories[$catId]
        ];
    }
    
    ApiResponse::success(['rooms' => $processedRooms]);

}, true, false, false);
