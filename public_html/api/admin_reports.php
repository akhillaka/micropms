<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('view_reports');

    $type = (string)($_GET['type'] ?? '');
    $start_date = (string)($_GET['start_date'] ?? date('Y-m-01')); // Default to start of month
    $end_date = (string)($_GET['end_date'] ?? date('Y-m-t')); // Default to end of month

    // Append time to dates for inclusive SQL querying if they don't have it
    if (strlen($start_date) === 10) $start_date .= ' 00:00:00';
    if (strlen($end_date) === 10) $end_date .= ' 23:59:59';

    $dbObj = Database::getInstance();
    
    $propertyId = AuthHelper::getPropertyId();

    switch ($type) {
        case 'daily_manager':
            $sql = "
                SELECT 
                    b.id as booking_id,
                    r.room_number,
                    b.check_in,
                    b.check_out,
                    TIMESTAMPDIFF(DAY, b.check_in, b.check_out) as duration_days,
                    TIMESTAMPDIFF(HOUR, b.check_in, b.check_out) as duration_hours
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.property_id = :pid
                  AND b.check_in <= :end AND b.check_out >= :start
                  AND b.booking_status != 'cancelled'
                ORDER BY b.check_in ASC
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->execute(['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pmJson = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'payment_methods'")->fetchColumn();
            $configuredMethods = $pmJson ? json_decode((string)$pmJson, true, 512, JSON_THROW_ON_ERROR) : ["Cash", "UPI", "Razorpay"];

            $result = [];
            foreach ($bookings as $b) {
                $folioSql = "SELECT transaction_type, COALESCE(NULLIF(payment_method, ''), 'Cash') as payment_method, amount FROM folio_ledger WHERE booking_id = ?";
                $lStmt = $db->prepare($folioSql);
                $lStmt->execute([$b['booking_id']]);
                $ledger = $lStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $row = [
                    'booking_id' => $b['booking_id'],
                    'room_number' => $b['room_number'],
                    'check_in' => $b['check_in'],
                    'check_out' => $b['check_out'],
                    'duration' => $b['duration_days'] > 0 ? $b['duration_days'] . ' Days' : $b['duration_hours'] . ' Hours',
                    'room_charge' => 0.0,
                    'addons' => 0.0,
                    'total_paid' => 0.0
                ];
                
                // Initialize configured methods in the main row instead of a nested array
                foreach ($configuredMethods as $cm) {
                    $row[$cm] = 0.0;
                }
                
                foreach ($ledger as $l) {
                    if ($l['transaction_type'] === 'ROOM_CHARGE') {
                        $row['room_charge'] += (float)$l['amount'];
                    } elseif ($l['transaction_type'] === 'INCIDENTAL') {
                        $row['addons'] += (float)$l['amount'];
                    } else {
                        $pm = $l['payment_method'];
                        $matched = false;
                        foreach($configuredMethods as $cm) {
                            if(strcasecmp($pm, $cm) === 0) {
                                $pm = $cm;
                                $matched = true;
                                break;
                            }
                        }
                        if (!$matched) {
                            $pm = ucwords(strtolower($pm));
                        }
                        if (!isset($row[$pm])) {
                            $row[$pm] = 0.0;
                        }
                        
                        $val = -(float)$l['amount']; // Payments are negative in DB, refunds are positive. So -amount gives positive for payments, negative for refunds.
                        $row[$pm] += $val;
                        $row['total_paid'] += $val;
                    }
                }
                
                $row['pending_dues'] = ($row['room_charge'] + $row['addons']) - $row['total_paid'];
                $result[] = $row;
            }
            
            if (!empty($result)) {
                $totals = [
                    'booking_id' => 'TOTALS',
                    'room_number' => '-',
                    'check_in' => '-',
                    'check_out' => '-',
                    'duration' => '-',
                    'room_charge' => 0.0,
                    'addons' => 0.0,
                    'total_paid' => 0.0,
                    'pending_dues' => 0.0
                ];
                foreach ($configuredMethods as $cm) {
                    $totals[$cm] = 0.0;
                }
                
                foreach ($result as $r) {
                    $totals['room_charge'] += $r['room_charge'];
                    $totals['addons'] += $r['addons'];
                    $totals['total_paid'] += $r['total_paid'];
                    $totals['pending_dues'] += $r['pending_dues'];
                    
                    foreach ($configuredMethods as $cm) {
                        if(isset($r[$cm])) $totals[$cm] += $r[$cm];
                    }
                    // Add any dynamically discovered payment methods to totals
                    foreach ($r as $k => $v) {
                        if (!isset($totals[$k]) && !in_array($k, ['booking_id','room_number','check_in','check_out','duration','room_charge','addons','total_paid','pending_dues'])) {
                            $totals[$k] = $v;
                        } elseif (!in_array($k, ['booking_id','room_number','check_in','check_out','duration','room_charge','addons','total_paid','pending_dues']) && !in_array($k, $configuredMethods)) {
                            $totals[$k] += $v;
                        }
                    }
                }
                
                $result[] = $totals;
            }

            ApiResponse::success(['data' => $result]);
            break;

        case 'payment_matrix':
            $sql = "
                SELECT DATE(recorded_at) as date,
                       COALESCE(NULLIF(payment_method, ''), 'Cash') as payment_method,
                       SUM(ABS(amount)) as total
                FROM folio_ledger 
                WHERE transaction_type = 'payment' 
                  AND recorded_at >= :start AND recorded_at <= :end
                GROUP BY DATE(recorded_at), payment_method
                ORDER BY DATE(recorded_at) DESC
            ";
            
            $rows = [];
            foreach ($dbObj->yieldQuery($sql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $rows[] = $row;
            }

            $pmJson = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'payment_methods'")->fetchColumn();
            $configuredMethods = $pmJson ? json_decode((string)$pmJson, true, 512, JSON_THROW_ON_ERROR) : [];
            if (empty($configuredMethods)) {
                $configuredMethods = ["Cash", "UPI", "Razorpay"];
            }

            // Union with historical methods
            $allMethods = $configuredMethods;
            foreach($rows as &$r) {
                // Normalize casing to match configured methods if possible
                $pm = $r['payment_method'];
                $matched = false;
                foreach($configuredMethods as $cm) {
                    if(strcasecmp($pm, $cm) === 0) {
                        $pm = $cm;
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    // Fallback to title case for unconfigured methods
                    $pm = ucwords(strtolower($pm));
                }
                $r['payment_method'] = $pm;
                
                if(!in_array($pm, $allMethods, true)) {
                    $allMethods[] = $pm;
                }
            }
            unset($r);

            $matrix = [];
            foreach($rows as $r) {
                $d = $r['date'];
                if(!isset($matrix[$d])) {
                    $matrix[$d] = [];
                    foreach($allMethods as $m) $matrix[$d][$m] = 0.0;
                    $matrix[$d]['Total'] = 0.0;
                }
                $pm = $r['payment_method'];
                $matrix[$d][$pm] += (float)$r['total'];
                $matrix[$d]['Total'] += (float)$r['total'];
            }

            $result = [];
            foreach($matrix as $date => $data) {
                $row = ['Date' => $date];
                foreach($data as $k => $v) $row[$k] = $v;
                $result[] = $row;
            }
            
            ApiResponse::success(['data' => $result]);
            break;
            
        case 'expense_report':
            $sql = "
                SELECT recorded_at as date, category, description, amount, payment_method
                FROM finance_transactions 
                WHERE type = 'expense' 
                  AND recorded_at >= :start AND recorded_at <= :end
                ORDER BY recorded_at DESC
            ";
            $expenses = [];
            foreach ($dbObj->yieldQuery($sql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $expenses[] = $row;
            }
            ApiResponse::success(['data' => $expenses]);
            break;
            
        case 'rate_plan_revenue':
            $sql = "
                SELECT rate_plan_name, 
                       COUNT(id) as total_bookings, 
                       SUM(total_amount) as total_revenue
                FROM bookings 
                WHERE check_in >= :start AND check_in <= :end
                  AND booking_status != 'cancelled'
                GROUP BY rate_plan_name
                ORDER BY total_revenue DESC
            ";
            $revenues = [];
            foreach ($dbObj->yieldQuery($sql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $revenues[] = $row;
            }
            ApiResponse::success(['data' => $revenues]);
            break;
            
        case 'pos_revenue':
            $sql = "
                SELECT 
                    o.name as outlet_name,
                    COUNT(po.id) as total_orders,
                    SUM(po.total_amount) as total_revenue
                FROM pos_orders po
                JOIN pos_outlets o ON po.outlet_id = o.id
                WHERE po.property_id = :pid
                  AND po.recorded_at >= :start AND po.recorded_at <= :end
                GROUP BY o.id
                ORDER BY total_revenue DESC
            ";
            $revenues = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $revenues[] = $row;
            }
            ApiResponse::success(['data' => $revenues]);
            break;

        case 'pos_items':
            $sql = "
                SELECT 
                    i.name as item_name,
                    o.name as outlet_name,
                    SUM(poi.quantity) as quantity_sold,
                    SUM(poi.quantity * poi.price_per_unit) as total_revenue
                FROM pos_order_items poi
                JOIN pos_orders po ON poi.order_id = po.id
                JOIN inventory_items i ON poi.item_id = i.id
                LEFT JOIN pos_outlets o ON i.outlet_id = o.id
                WHERE po.property_id = :pid
                  AND po.recorded_at >= :start AND po.recorded_at <= :end
                GROUP BY i.id
                ORDER BY quantity_sold DESC
            ";
            $items = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $items[] = $row;
            }
            ApiResponse::success(['data' => $items]);
            break;

        case 'pos_inventory':
            $sql = "
                SELECT 
                    i.name as item_name,
                    o.name as outlet_name,
                    i.stock_qty as current_stock,
                    i.low_stock_threshold,
                    i.cost_price,
                    i.selling_price
                FROM inventory_items i
                LEFT JOIN pos_outlets o ON i.outlet_id = o.id
                WHERE i.property_id = :pid
                ORDER BY (i.stock_qty <= i.low_stock_threshold) DESC, o.name ASC, i.name ASC
            ";
            // For inventory, date range doesn't apply, it's real-time.
            $inventory = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId]) as $row) {
                $row['status'] = ((int)$row['current_stock'] <= (int)$row['low_stock_threshold']) ? 'Low Stock' : 'OK';
                $inventory[] = $row;
            }
            ApiResponse::success(['data' => $inventory]);
            break;

        case 'pos_pl':
            $sql = "
                SELECT 
                    i.name as item_name,
                    o.name as outlet_name,
                    SUM(poi.quantity) as quantity_sold,
                    SUM(poi.quantity * i.cost_price) as total_cost,
                    SUM(poi.quantity * poi.price_per_unit) as total_revenue,
                    SUM(poi.quantity * (poi.price_per_unit - i.cost_price)) as total_profit
                FROM pos_order_items poi
                JOIN pos_orders po ON poi.order_id = po.id
                JOIN inventory_items i ON poi.item_id = i.id
                LEFT JOIN pos_outlets o ON i.outlet_id = o.id
                WHERE po.property_id = :pid
                  AND po.recorded_at >= :start AND po.recorded_at <= :end
                  AND po.delivery_status = 'delivered'
                GROUP BY i.id
                ORDER BY total_profit DESC
            ";
            $pl = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $pl[] = $row;
            }
            // Add a Totals row at the bottom for P&L
            $totalCost = 0;
            $totalRev = 0;
            $totalProfit = 0;
            foreach ($pl as $row) {
                $totalCost += (float)$row['total_cost'];
                $totalRev += (float)$row['total_revenue'];
                $totalProfit += (float)$row['total_profit'];
            }
            if (count($pl) > 0) {
                $pl[] = [
                    'booking_id' => 'TOTALS', // Triggers bold/highlighted row styling in reports.php
                    'item_name' => 'ALL ITEMS',
                    'outlet_name' => '-',
                    'quantity_sold' => '-',
                    'total_cost' => $totalCost,
                    'total_revenue' => $totalRev,
                    'total_profit' => $totalProfit
                ];
            }
            ApiResponse::success(['data' => $pl]);
            break;
            
        case 'police_register':
            $sql = "
                SELECT b.id as folio, g.name as guest_name, g.phone as guest_phone,
                       r.room_number, b.check_in, b.check_out,
                       g.id_proof_front, g.id_proof_back
                FROM bookings b
                LEFT JOIN guests g ON b.guest_id = g.id
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.check_in >= :start AND b.check_in <= :end
                ORDER BY b.check_in DESC
            ";
            $results = [];
            foreach ($dbObj->yieldQuery($sql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                // Add ID Status
                $row['id_status'] = (!empty($row['id_proof_front']) || !empty($row['id_proof_back'])) ? 'Provided' : 'Pending';
                unset($row['id_proof_front'], $row['id_proof_back']); // don't send filenames if not needed
                $results[] = $row;
            }
            ApiResponse::success(['data' => $results]);
            break;
            
        case 'revpar':
            // Total Rooms count
            $totalRooms = (int)$db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
            
            $current = strtotime(substr($start_date, 0, 10));
            $last = strtotime(substr($end_date, 0, 10));
            
            if ($current === false || $last === false) {
                throw new \Exception("Invalid date format provided");
            }
            
            $sql = "
                SELECT b.id, b.room_id, b.check_in, b.check_out, 
                       COALESCE(fl.room_charges, b.total_amount) as total_room_charges
                FROM bookings b
                LEFT JOIN (
                    SELECT booking_id, SUM(amount) as room_charges 
                    FROM folio_ledger 
                    WHERE transaction_type = 'ROOM_CHARGE' 
                    GROUP BY booking_id
                ) fl ON b.id = fl.booking_id
                WHERE b.booking_status IN ('checked_in', 'checked_out')
                  AND b.check_in <= :end 
                  AND b.check_out >= :start
            ";
            $bookings = [];
            foreach ($dbObj->yieldQuery($sql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $bookings[] = $row;
            }
            
            $result = [];
            while($current <= $last) {
                $d_str = date('Y-m-d', $current);
                $occupiedRooms = [];
                $dailyRoomRevenue = 0.0;
                foreach($bookings as $b) {
                    $b_in = substr((string)$b['check_in'], 0, 10);
                    $b_out = substr((string)$b['check_out'], 0, 10);
                    
                    $isOccupied = ($d_str >= $b_in && $d_str < $b_out) || ($b_in === $b_out && $d_str === $b_in);
                    if ($isOccupied) {
                        $occupiedRooms[$b['room_id']] = true;
                        
                        $checkInTs = strtotime($b['check_in']);
                        $checkOutTs = strtotime($b['check_out']);
                        $numDays = max(1, round(($checkOutTs - $checkInTs) / 86400));
                        $dailyRate = (float)$b['total_room_charges'] / $numDays;
                        $dailyRoomRevenue += $dailyRate;
                    }
                }
                
                $occupiedCount = count($occupiedRooms);
                $occ_percent = $totalRooms > 0 ? ($occupiedCount / $totalRooms) * 100 : 0.0;
                $adr = $occupiedCount > 0 ? $dailyRoomRevenue / $occupiedCount : 0.0;
                $revpar = $totalRooms > 0 ? $dailyRoomRevenue / $totalRooms : 0.0;
                
                $result[] = [
                    'date' => $d_str,
                    'total_rooms' => $totalRooms,
                    'occupied_rooms' => $occupiedCount,
                    'occupancy_percent' => round($occ_percent, 1),
                    'room_revenue' => round($dailyRoomRevenue, 2),
                    'adr' => round($adr, 2),
                    'revpar' => round($revpar, 2)
                ];
                
                $current = strtotime("+1 day", $current);
            }
            
            ApiResponse::success(['data' => $result]);
            break;
            
        case 'occupancy':
            // Total Rooms count
            $totalRooms = (int)$db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
            
            // To do day-wise, we need a list of dates.
            // We'll calculate it in PHP.
            $current = strtotime(substr($start_date, 0, 10));
            $last = strtotime(substr($end_date, 0, 10));
            
            if ($current === false || $last === false) {
                throw new \Exception("Invalid date format provided");
            }
            
            $sql = "
                SELECT room_id, check_in, check_out 
                FROM bookings 
                WHERE booking_status IN ('checked_in', 'checked_out')
                  AND check_in <= :end 
                  AND check_out >= :start
            ";
            $bookings = [];
            foreach ($dbObj->yieldQuery($sql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $bookings[] = $row;
            }
            
            $result = [];
            while($current <= $last) {
                $d_str = date('Y-m-d', $current);
                $occupiedRooms = [];
                foreach($bookings as $b) {
                    $b_in = substr((string)$b['check_in'], 0, 10);
                    $b_out = substr((string)$b['check_out'], 0, 10);
                    
                    // Overnight stays cover the check_in day up to the day before check_out
                    // Hourly stays (check-in and check-out on the same day) count for that day
                    $isOccupied = ($d_str >= $b_in && $d_str < $b_out) || ($b_in === $b_out && $d_str === $b_in);
                    if ($isOccupied) {
                        $occupiedRooms[$b['room_id']] = true;
                    }
                }
                
                $occupiedCount = count($occupiedRooms);
                $occ_percent = $totalRooms > 0 ? round(($occupiedCount / $totalRooms) * 100, 1) : 0.0;
                
                $result[] = [
                    'date' => $d_str,
                    'total_rooms' => $totalRooms,
                    'occupied' => $occupiedCount,
                    'occupancy_percent' => $occ_percent
                ];
                
                $current = strtotime("+1 day", $current);
            }
            
            ApiResponse::success(['data' => $result]);
            break;
            
        case 'room_performance':
            $sql = "
                SELECT r.room_number, rc.name as category,
                       COUNT(b.id) as total_bookings, 
                       COALESCE(SUM(b.total_amount), 0) as total_revenue,
                       COALESCE(AVG(b.total_amount), 0) as adr
                FROM rooms r
                LEFT JOIN room_categories rc ON r.category_id = rc.id
                LEFT JOIN bookings b ON r.id = b.room_id AND b.check_in >= :start AND b.check_in <= :end AND b.booking_status != 'cancelled'
                GROUP BY r.id, r.room_number, rc.name
                ORDER BY total_revenue DESC
            ";
            $rooms = [];
            foreach ($dbObj->yieldQuery($sql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $row['adr'] = round((float)$row['adr'], 2);
                $row['total_revenue'] = round((float)$row['total_revenue'], 2);
                $row['adr'] = $row['total_bookings'] > 0 ? round((float)$row['total_revenue'] / (int)$row['total_bookings'], 2) : 0;
                $rooms[] = $row;
            }
            ApiResponse::success(['data' => $rooms]);
            break;

        case 'business_insights':
            // 1. New vs Returning Guests — a guest is "new" if this is their FIRST booking EVER,
            //    and "returning" if they had at least one booking before the period's booking.
            $guestSql = "
                SELECT 
                    SUM(CASE WHEN prior_bookings = 0 THEN 1 ELSE 0 END) as new_guests,
                    SUM(CASE WHEN prior_bookings > 0 THEN 1 ELSE 0 END) as returning_guests
                FROM (
                    SELECT b.guest_id,
                           (SELECT COUNT(*) FROM bookings b2
                            WHERE b2.guest_id = b.guest_id 
                              AND b2.check_in < :start_date_1
                              AND b2.booking_status != 'cancelled') as prior_bookings
                    FROM bookings b
                    WHERE b.check_in >= :start_date_2 AND b.check_in <= :end_date 
                      AND b.booking_status != 'cancelled'
                      AND b.guest_id IS NOT NULL
                    GROUP BY b.guest_id
                ) as sub
            ";
            $retention = $db->prepare($guestSql);
            $retention->execute([
                'start_date_1' => $start_date,
                'start_date_2' => $start_date,
                'end_date' => $end_date
            ]);
            $retentionData = $retention->fetch(PDO::FETCH_ASSOC);
            if (!$retentionData) {
                $retentionData = ['new_guests' => 0, 'returning_guests' => 0];
            }

            // 2. Busiest Day of Week — order by FIELD so Mon-Sun ordering is consistent
            $dowSql = "
                SELECT DAYNAME(check_in) as day_of_week, COUNT(id) as total_checkins
                FROM bookings
                WHERE check_in >= :start AND check_in <= :end AND booking_status != 'cancelled'
                GROUP BY DAYNAME(check_in)
                ORDER BY FIELD(DAYNAME(check_in),'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
            ";
            $dowData = [];
            foreach ($dbObj->yieldQuery($dowSql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $dowData[] = $row;
            }

            // 3. Peak Check-in / Check-out hours
            $hoursSql = "
                SELECT 
                    HOUR(check_in) as checkin_hour, COUNT(id) as checkin_count
                FROM bookings
                WHERE check_in >= :start AND check_in <= :end AND booking_status != 'cancelled'
                GROUP BY HOUR(check_in)
                ORDER BY checkin_count DESC
                LIMIT 5
            ";
            $peakCheckins = [];
            foreach ($dbObj->yieldQuery($hoursSql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $peakCheckins[] = $row;
            }

            $hoursOutSql = "
                SELECT 
                    HOUR(check_out) as checkout_hour, COUNT(id) as checkout_count
                FROM bookings
                WHERE check_out >= :start AND check_out <= :end AND booking_status != 'cancelled'
                GROUP BY HOUR(check_out)
                ORDER BY checkout_count DESC
                LIMIT 5
            ";
            $peakCheckouts = [];
            foreach ($dbObj->yieldQuery($hoursOutSql, ['start' => $start_date, 'end' => $end_date]) as $row) {
                $peakCheckouts[] = $row;
            }

            ApiResponse::success([
                'data' => [
                    'retention' => $retentionData,
                    'busiest_days' => $dowData,
                    'peak_checkins' => $peakCheckins,
                    'peak_checkouts' => $peakCheckouts
                ]
            ]);
            break;
            
        default:
            ApiResponse::error('Unknown report type');
    }

}, true, false, false);

