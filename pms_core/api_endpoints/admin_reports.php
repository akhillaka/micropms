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

            $pmJsonStmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'payment_methods' AND property_id = ?");
            $pmJsonStmt->execute([$propertyId]);
            $pmJson = $pmJsonStmt->fetchColumn();
            $configuredMethods = $pmJson ? json_decode((string)$pmJson, true, 512, JSON_THROW_ON_ERROR) : ["Cash", "UPI", "Razorpay"];

            // BUG-24 fix: fetch all folio entries for all bookings in one query (eliminates N+1)
            $bookingIds = array_column($bookings, 'booking_id');
            $ledgerByBooking = [];
            if (!empty($bookingIds)) {
                $inClause = implode(',', array_fill(0, count($bookingIds), '?'));
                $allLedgerStmt = $db->prepare("SELECT booking_id, entry_kind, COALESCE(NULLIF(payment_method, ''), 'Cash') as payment_method, amount FROM folio_ledger WHERE booking_id IN ({$inClause}) AND property_id = ?");
                $allLedgerStmt->execute(array_merge($bookingIds, [$propertyId]));
                foreach ($allLedgerStmt->fetchAll(PDO::FETCH_ASSOC) as $le) {
                    $ledgerByBooking[$le['booking_id']][] = $le;
                }
            }

            $result = [];
            foreach ($bookings as $b) {
                $ledger = $ledgerByBooking[$b['booking_id']] ?? [];
                
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
                    $kind = strtoupper((string)($l['entry_kind'] ?? ''));
                    if ($kind === 'ROOM_CHARGE') {
                        $row['room_charge'] += (float)$l['amount'];
                    } elseif ($kind === 'INCIDENTAL' || $kind === 'POS_ORDER') {
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
                WHERE entry_kind = 'payment' 
                  AND property_id = :pid
                  AND recorded_at >= :start AND recorded_at <= :end
                GROUP BY DATE(recorded_at), payment_method
                ORDER BY DATE(recorded_at) DESC
            ";
            
            $rows = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $rows[] = $row;
            }

            $pmJsonStmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'payment_methods' AND property_id = ?");
            $pmJsonStmt->execute([$propertyId]);
            $pmJson = $pmJsonStmt->fetchColumn();
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
            
        case 'cashier_shift':
            $sql = "
                SELECT 
                    payment_method,
                    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as receipts,
                    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as payouts
                FROM (
                    SELECT 'income' as type, payment_method, ABS(amount) as amount FROM folio_ledger WHERE amount < 0 AND DATE(recorded_at) >= :sd1 AND DATE(recorded_at) <= :ed1 AND property_id = :p1
                    UNION ALL
                    SELECT type, payment_method, amount FROM finance_transactions WHERE DATE(recorded_at) >= :sd2 AND DATE(recorded_at) <= :ed2 AND property_id = :p2 AND (booking_id IS NULL OR booking_id = 0)
                ) as combined
                GROUP BY payment_method
                ORDER BY receipts DESC
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'sd1' => substr($start_date, 0, 10), 
                'ed1' => substr($end_date, 0, 10), 
                'sd2' => substr($start_date, 0, 10), 
                'ed2' => substr($end_date, 0, 10), 
                'p1' => $propertyId, 
                'p2' => $propertyId
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $res = [];
            foreach ($rows as $r) {
                $pm = empty($r['payment_method']) ? 'Unspecified' : $r['payment_method'];
                $res[] = [
                    'payment_method' => $pm,
                    'total_receipts' => (float)$r['receipts'],
                    'total_payouts' => (float)$r['payouts'],
                    'net_collection' => (float)$r['receipts'] - (float)$r['payouts']
                ];
            }
            ApiResponse::success(['data' => $res]);
            break;
            
        case 'expense_report':
            $sql = "
                SELECT recorded_at as date, category, description, amount, payment_method
                FROM finance_transactions 
                WHERE type = 'expense' 
                  AND property_id = :pid
                  AND recorded_at >= :start AND recorded_at <= :end
                ORDER BY recorded_at DESC
            ";
            $expenses = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
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
                  AND property_id = :pid
                GROUP BY rate_plan_name
                ORDER BY total_revenue DESC
            ";
            $revenues = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
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

        case 'pos_order_tracking':
            $sql = "
                SELECT 
                    po.id as order_id,
                    po.recorded_at as date,
                    o.name as outlet,
                    po.total_amount,
                    po.status,
                    po.delivery_status
                FROM pos_orders po
                LEFT JOIN pos_outlets o ON po.outlet_id = o.id
                WHERE po.property_id = :pid
                  AND po.recorded_at >= :start AND po.recorded_at <= :end
                ORDER BY po.recorded_at DESC
            ";
            $data = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $data[] = $row;
            }
            ApiResponse::success(['data' => $data]);
            break;

        case 'pos_restock_history':
            $sql = "
                SELECT 
                    rh.created_at as date,
                    i.name as item_name,
                    rh.qty_added,
                    rh.cost_price,
                    u.username as restocked_by
                FROM inventory_restock_history rh
                JOIN inventory_items i ON rh.item_id = i.id
                LEFT JOIN staff_users u ON rh.restocked_by = u.id
                WHERE rh.property_id = :pid
                  AND rh.created_at >= :start AND rh.created_at <= :end
                ORDER BY rh.created_at DESC
            ";
            $data = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $data[] = $row;
            }
            ApiResponse::success(['data' => $data]);
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
                WHERE b.property_id = :pid AND b.check_in >= :start AND b.check_in <= :end
                ORDER BY b.check_in DESC
            ";
            $results = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                // Add ID Status
                $row['id_status'] = (!empty($row['id_proof_front']) || !empty($row['id_proof_back'])) ? 'Provided' : 'Pending';
                unset($row['id_proof_front'], $row['id_proof_back']); // don't send filenames if not needed
                $results[] = $row;
            }
            ApiResponse::success(['data' => $results]);
            break;
            
        case 'revpar':
            require_once __DIR__ . '/../../pms_core/services/ReportingCacheService.php';
            $cacheStart = substr($start_date, 0, 10);
            $cacheEnd = substr($end_date, 0, 10);
            $today = date('Y-m-d');
            if ($cacheEnd < $today) {
                $cached = ReportingCacheService::getRange($db, $propertyId, $cacheStart, $cacheEnd);
                if ($cached !== null) {
                    ApiResponse::success(['data' => $cached, 'source' => 'cache']);
                    break;
                }
            }

            // Total Rooms count
            $roomsStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ? AND deleted_at IS NULL");
            $roomsStmt->execute([$propertyId]);
            $totalRooms = (int)$roomsStmt->fetchColumn();
            
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
                    WHERE entry_kind = 'ROOM_CHARGE' AND property_id = :pid1
                    GROUP BY booking_id
                ) fl ON b.id = fl.booking_id
                WHERE b.booking_status IN ('booked', 'checked_in', 'checked_out')
                  AND b.payment_status != 'cancelled'
                  AND b.property_id = :pid2
                  AND b.check_in <= :end 
                  AND b.check_out >= :start
            ";
            $bookings = [];
            foreach ($dbObj->yieldQuery($sql, ['pid1' => $propertyId, 'pid2' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $bookings[] = $row;
            }
            
            $result = [];
            // Pre-fill date buckets
            $dateBuckets = [];
            while ($current <= $last) {
                $d_str = date('Y-m-d', $current);
                $dateBuckets[$d_str] = [
                    'date' => $d_str,
                    'total_rooms' => $totalRooms,
                    'occupied_rooms' => 0,
                    'occupancy_percent' => 0.0,
                    'room_revenue' => 0.0,
                    'adr' => 0.0,
                    'revpar' => 0.0,
                    'unique_rooms' => []
                ];
                $current = strtotime("+1 day", $current);
            }

            // Bucketize bookings
            foreach ($bookings as $b) {
                $b_in = substr((string)$b['check_in'], 0, 10);
                $b_out = substr((string)$b['check_out'], 0, 10);
                
                $checkInTs = strtotime($b['check_in']);
                $checkOutTs = strtotime($b['check_out']);
                $numDays = max(1, round(($checkOutTs - $checkInTs) / 86400));
                $dailyRate = (float)$b['total_room_charges'] / $numDays;

                $currB = strtotime($b_in);
                $endB = strtotime($b_out);
                
                while ($currB < $endB || ($currB === $endB && $b_in === $b_out)) {
                    $d_str = date('Y-m-d', $currB);
                    if (isset($dateBuckets[$d_str])) {
                        $dateBuckets[$d_str]['unique_rooms'][$b['room_id']] = true;
                        $dateBuckets[$d_str]['room_revenue'] += $dailyRate;
                    }
                    if ($currB === $endB && $b_in === $b_out) break; // Hourly stay handled
                    $currB = strtotime("+1 day", $currB);
                }
            }

            // Calculate final metrics per day
            foreach ($dateBuckets as $d_str => &$metrics) {
                $occupiedCount = count($metrics['unique_rooms']);
                $metrics['occupied_rooms'] = $occupiedCount;
                $metrics['occupancy_percent'] = $totalRooms > 0 ? round(($occupiedCount / $totalRooms) * 100, 2) : 0.0;
                $metrics['adr'] = $occupiedCount > 0 ? round($metrics['room_revenue'] / $occupiedCount, 2) : 0.0;
                $metrics['revpar'] = $totalRooms > 0 ? round($metrics['room_revenue'] / $totalRooms, 2) : 0.0;
                $metrics['room_revenue'] = round($metrics['room_revenue'], 2);
                unset($metrics['unique_rooms']); // clean up
                $result[] = $metrics;
            }
            
            ApiResponse::success(['data' => $result]);
            break;
            
        case 'occupancy':
            // Total Rooms count
            $roomsStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ? AND deleted_at IS NULL");
            $roomsStmt->execute([$propertyId]);
            $totalRooms = (int)$roomsStmt->fetchColumn();
            
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
                WHERE booking_status IN ('booked', 'checked_in', 'checked_out')
                  AND payment_status != 'cancelled'
                  AND property_id = :pid
                  AND check_in <= :end 
                  AND check_out >= :start
            ";
            $bookings = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $bookings[] = $row;
            }
            
            $result = [];
            $dateBuckets = [];
            while ($current <= $last) {
                $d_str = date('Y-m-d', $current);
                $dateBuckets[$d_str] = [
                    'date' => $d_str,
                    'total_rooms' => $totalRooms,
                    'occupied' => 0,
                    'occupancy_percent' => 0.0,
                    'unique_rooms' => []
                ];
                $current = strtotime("+1 day", $current);
            }

            foreach ($bookings as $b) {
                $b_in = substr((string)$b['check_in'], 0, 10);
                $b_out = substr((string)$b['check_out'], 0, 10);
                
                $currB = strtotime($b_in);
                $endB = strtotime($b_out);
                
                while ($currB < $endB || ($currB === $endB && $b_in === $b_out)) {
                    $d_str = date('Y-m-d', $currB);
                    if (isset($dateBuckets[$d_str])) {
                        $dateBuckets[$d_str]['unique_rooms'][$b['room_id']] = true;
                    }
                    if ($currB === $endB && $b_in === $b_out) break;
                    $currB = strtotime("+1 day", $currB);
                }
            }

            foreach ($dateBuckets as $d_str => &$metrics) {
                $occupiedCount = count($metrics['unique_rooms']);
                $metrics['occupied'] = $occupiedCount;
                $metrics['occupancy_percent'] = $totalRooms > 0 ? round(($occupiedCount / $totalRooms) * 100, 2) : 0.0;
                unset($metrics['unique_rooms']);
                $result[] = $metrics;
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
                LEFT JOIN bookings b ON r.id = b.room_id AND b.check_in >= :start AND b.check_in <= :end AND b.booking_status != 'cancelled' AND b.property_id = :pid1
                WHERE r.property_id = :pid2
                GROUP BY r.id, r.room_number, rc.name
                ORDER BY total_revenue DESC
            ";
            $rooms = [];
            foreach ($dbObj->yieldQuery($sql, ['pid1' => $propertyId, 'pid2' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
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
                              AND b2.property_id = :pid1
                              AND b2.check_in < :start_date_1
                              AND b2.booking_status != 'cancelled') as prior_bookings
                    FROM bookings b
                    WHERE b.check_in >= :start_date_2 AND b.check_in <= :end_date 
                      AND b.booking_status != 'cancelled'
                      AND b.property_id = :pid2
                      AND b.guest_id IS NOT NULL
                    GROUP BY b.guest_id
                ) as sub
            ";
            $retention = $db->prepare($guestSql);
            $retention->execute([
                'pid1' => $propertyId,
                'pid2' => $propertyId,
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
                WHERE check_in >= :start AND check_in <= :end AND booking_status != 'cancelled' AND property_id = :pid
                GROUP BY DAYNAME(check_in)
                ORDER BY FIELD(DAYNAME(check_in),'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
            ";
            $dowData = [];
            foreach ($dbObj->yieldQuery($dowSql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $dowData[] = $row;
            }
 
            // 3. Peak Check-in / Check-out hours
            $hoursSql = "
                SELECT 
                    HOUR(check_in) as checkin_hour, COUNT(id) as checkin_count
                FROM bookings
                WHERE check_in >= :start AND check_in <= :end AND booking_status != 'cancelled' AND property_id = :pid
                GROUP BY HOUR(check_in)
                ORDER BY checkin_count DESC
                LIMIT 5
            ";
            $peakCheckins = [];
            foreach ($dbObj->yieldQuery($hoursSql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $peakCheckins[] = $row;
            }
 
            $hoursOutSql = "
                SELECT 
                    HOUR(check_out) as checkout_hour, COUNT(id) as checkout_count
                FROM bookings
                WHERE check_out >= :start AND check_out <= :end AND booking_status != 'cancelled' AND property_id = :pid
                GROUP BY HOUR(check_out)
                ORDER BY checkout_count DESC
                LIMIT 5
            ";
            $peakCheckouts = [];
            foreach ($dbObj->yieldQuery($hoursOutSql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
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

        case 'tax_report':
            $sql = "
                SELECT 
                    DATE(fl.recorded_at) as date,
                    SUM(fl.cgst_amount) as cgst_collected,
                    SUM(fl.sgst_amount) as sgst_collected,
                    SUM(fl.cgst_amount + fl.sgst_amount) as total_tax
                FROM folio_ledger fl
                JOIN bookings b ON fl.booking_id = b.id
                WHERE b.property_id = :pid
                  AND fl.entry_kind IN ('ROOM_CHARGE', 'INCIDENTAL')
                  AND fl.recorded_at >= :start AND fl.recorded_at <= :end
                GROUP BY DATE(fl.recorded_at)
                ORDER BY DATE(fl.recorded_at) DESC
            ";
            $data = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $data[] = $row;
            }
            ApiResponse::success(['data' => $data]);
            break;

        case 'accounts_receivable':
            // Balance = SUM(amount) (charges positive, payments/discounts negative),
            // matching FolioService::getBalance. Avoid SELECT aliases in HAVING —
            // native prepares (ATTR_EMULATE_PREPARES=false) reject them on MariaDB.
            $sql = "
                SELECT
                    b.id AS booking_id,
                    COALESCE(g.name, 'Walk-in') AS guest_name,
                    r.room_number,
                    b.check_in,
                    b.check_out,
                    b.booking_status,
                    ROUND(COALESCE(SUM(fl.amount), 0), 2) AS pending_dues
                FROM bookings b
                LEFT JOIN guests g ON b.guest_id = g.id
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN folio_ledger fl ON fl.booking_id = b.id
                WHERE b.property_id = :pid
                  AND b.booking_status IN ('checked_out', 'checked_in')
                  AND (
                    b.booking_status = 'checked_in'
                    OR (b.check_in <= :end AND b.check_out >= :start)
                  )
                GROUP BY b.id, g.name, r.room_number, b.check_in, b.check_out, b.booking_status
                HAVING COALESCE(SUM(fl.amount), 0) > 0.01
                ORDER BY COALESCE(SUM(fl.amount), 0) DESC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'pid' => $propertyId,
                'start' => $start_date,
                'end' => $end_date,
            ]);
            $data = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['pending_dues'] = round((float)$row['pending_dues'], 2);
                $data[] = $row;
            }
            ApiResponse::success(['data' => $data]);
            break;

        case 'booking_source':
            $sql = "
                SELECT 
                    COALESCE(NULLIF(booking_source, ''), 'Direct/Walk-in') as source,
                    COUNT(id) as total_bookings,
                    SUM(total_amount) as total_revenue
                FROM bookings
                WHERE property_id = :pid
                  AND check_in >= :start AND check_in <= :end
                  AND booking_status != 'cancelled'
                GROUP BY COALESCE(NULLIF(booking_source, ''), 'Direct/Walk-in')
                ORDER BY total_revenue DESC
            ";
            $data = [];
            foreach ($dbObj->yieldQuery($sql, ['pid' => $propertyId, 'start' => $start_date, 'end' => $end_date]) as $row) {
                $data[] = $row;
            }
            ApiResponse::success(['data' => $data]);
            break;

        case 'save_custom_report':
            $input = json_decode(file_get_contents('php://input'), true);
            $name = trim($input['name'] ?? '');
            $dataset = trim($input['dataset'] ?? '');
            $columns = $input['columns'] ?? [];
            $filters = $input['filters'] ?? null;
            if (empty($name) || empty($dataset) || empty($columns)) {
                ApiResponse::error('Missing required fields for saving custom report.');
                break;
            }
            $stmt = $db->prepare("INSERT INTO saved_reports (property_id, name, dataset, columns, filters) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$propertyId, $name, $dataset, json_encode($columns), $filters]);
            ApiResponse::success(['message' => 'Custom report format saved successfully!']);
            break;
            
        case 'get_saved_reports':
            $stmt = $db->prepare("SELECT id, name, dataset, columns, filters FROM saved_reports WHERE property_id = ? ORDER BY name ASC");
            $stmt->execute([$propertyId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['columns'] = json_decode($r['columns'], true);
            }
            ApiResponse::success(['data' => $rows]);
            break;
            
        case 'delete_saved_report':
            $reportId = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM saved_reports WHERE id = ? AND property_id = ?");
            $stmt->execute([$reportId, $propertyId]);
            ApiResponse::success(['message' => 'Report template deleted.']);
            break;
            
        case 'custom_builder':
            // Ensure display_id exists in pos_orders table
            try {
                $check = $db->query("SHOW COLUMNS FROM `pos_orders` LIKE 'display_id'")->fetch();
                if (!$check) {
                    $db->exec("ALTER TABLE `pos_orders` ADD COLUMN `display_id` VARCHAR(50) NULL AFTER `id`;");
                    $db->exec("CREATE INDEX `idx_pos_orders_display` ON `pos_orders`(`display_id`);");
                }
            } catch (\Exception $e) {}

            $dataset = (string)($_GET['dataset'] ?? '');
            $joinDataset = (string)($_GET['join_dataset'] ?? '');
            $selectedCols = isset($_GET['columns']) ? explode(',', (string)$_GET['columns']) : [];
            
            if (empty($dataset) || empty($selectedCols)) {
                ApiResponse::error('Dataset and columns are required.');
                break;
            }
            
            $colExprs = [
                'bookings' => [
                    'id' => 'b.id', 
                    'display_id' => 'b.display_id', 
                    'check_in' => 'b.check_in', 
                    'check_out' => 'b.check_out', 
                    'total_amount' => 'b.total_amount', 
                    'booking_status' => 'b.booking_status', 
                    'booking_source' => 'b.booking_source', 
                    'rate_plan_name' => 'b.rate_plan_name', 
                    'created_at' => 'b.created_at',
                    'guest_name' => 'g.name', 
                    'room_number' => 'r.room_number'
                ],
                'guests' => [
                    'id' => 'g.id', 
                    'name' => 'g.name', 
                    'email' => 'g.email', 
                    'phone' => 'g.phone', 
                    'city' => 'g.city', 
                    'state' => 'g.state', 
                    'country' => 'g.country', 
                    'created_at' => 'g.created_at'
                ],
                'folio_ledger' => [
                    'id' => 'fl.id', 
                    'display_id' => 'fl.display_id', 
                    'booking_id' => 'fl.booking_id', 
                    'entry_kind' => 'fl.entry_kind', 
                    'amount' => 'fl.amount', 
                    'payment_method' => 'fl.payment_method', 
                    'description' => 'fl.description', 
                    'recorded_at' => 'fl.recorded_at',
                    'room_number' => 'r.room_number'
                ],
                'finance_transactions' => [
                    'id' => 'ft.id', 
                    'display_id' => 'ft.display_id', 
                    'type' => 'ft.type', 
                    'category' => 'ft.category', 
                    'amount' => 'ft.amount', 
                    'payment_method' => 'ft.payment_method', 
                    'description' => 'ft.description', 
                    'recorded_at' => 'ft.recorded_at'
                ],
                'pos_orders' => [
                    'id' => 'po.id', 
                    'display_id' => 'po.display_id', 
                    'booking_id' => 'po.booking_id', 
                    'outlet_id' => 'po.outlet_id', 
                    'total_amount' => 'po.total_amount', 
                    'payment_method' => 'po.payment_method', 
                    'status' => 'po.status', 
                    'delivery_status' => 'po.delivery_status', 
                    'recorded_at' => 'po.recorded_at',
                    'room_number' => 'r.room_number', 
                    'guest_name' => 'g.name', 
                    'outlet_name' => 'o.name'
                ]
            ];
            
            if (!isset($colExprs[$dataset])) {
                ApiResponse::error('Invalid primary dataset.');
                break;
            }
            
            $selectFields = [];
            $needsBookingsJoin = false;
            $needsRoomsJoin = false;
            $needsGuestsJoin = false;
            $needsOutletsJoin = false;
            
            foreach ($selectedCols as $col) {
                $tblName = $dataset;
                $colName = $col;
                if (strpos($col, '.') !== false) {
                    list($tblName, $colName) = explode('.', $col);
                }
                
                if (isset($colExprs[$tblName][$colName])) {
                    $expr = $colExprs[$tblName][$colName];
                    $selectFields[] = "{$expr} AS `{$tblName}_{$colName}`";
                    
                    if (str_starts_with($expr, 'b.')) $needsBookingsJoin = true;
                    if (str_starts_with($expr, 'r.')) { $needsRoomsJoin = true; $needsBookingsJoin = true; }
                    if (str_starts_with($expr, 'g.')) $needsGuestsJoin = true;
                    if (str_starts_with($expr, 'o.')) $needsOutletsJoin = true;
                }
            }
            
            if (empty($selectFields)) {
                ApiResponse::error('No valid columns selected.');
                break;
            }
            
            $primaryAlias = '';
            if ($dataset === 'bookings') $primaryAlias = 'b';
            if ($dataset === 'guests') $primaryAlias = 'g';
            if ($dataset === 'folio_ledger') $primaryAlias = 'fl';
            if ($dataset === 'finance_transactions') $primaryAlias = 'ft';
            if ($dataset === 'pos_orders') $primaryAlias = 'po';
            
            if (!empty($joinDataset) && $joinDataset !== $dataset) {
                if ($joinDataset === 'bookings') $needsBookingsJoin = true;
                if ($joinDataset === 'guests') $needsGuestsJoin = true;
                if ($joinDataset === 'folio_ledger') $needsBookingsJoin = true;
                if ($joinDataset === 'pos_orders') $needsBookingsJoin = true;
            }
            
            $fromStr = "`{$dataset}` {$primaryAlias}";
            
            if ($primaryAlias === 'b') {
                if ($needsGuestsJoin) $fromStr .= " LEFT JOIN `guests` g ON b.guest_id = g.id";
                if ($needsRoomsJoin) $fromStr .= " LEFT JOIN `rooms` r ON b.room_id = r.id";
                if (!empty($joinDataset) && $joinDataset === 'folio_ledger') {
                    $fromStr .= " JOIN `folio_ledger` fl ON b.id = fl.booking_id";
                }
                if (!empty($joinDataset) && $joinDataset === 'pos_orders') {
                    $fromStr .= " JOIN `pos_orders` po ON b.id = po.booking_id";
                }
            } elseif ($primaryAlias === 'g') {
                if ($needsBookingsJoin || !empty($joinDataset)) {
                    $fromStr .= " JOIN `bookings` b ON g.id = b.guest_id";
                    if ($needsRoomsJoin) $fromStr .= " LEFT JOIN `rooms` r ON b.room_id = r.id";
                    if (!empty($joinDataset) && $joinDataset === 'folio_ledger') {
                        $fromStr .= " JOIN `folio_ledger` fl ON b.id = fl.booking_id";
                    }
                }
            } elseif ($primaryAlias === 'fl') {
                $fromStr .= " LEFT JOIN `bookings` b ON fl.booking_id = b.id";
                if ($needsRoomsJoin) $fromStr .= " LEFT JOIN `rooms` r ON b.room_id = r.id";
                if ($needsGuestsJoin) $fromStr .= " LEFT JOIN `guests` g ON b.guest_id = g.id";
            } elseif ($primaryAlias === 'po') {
                $fromStr .= " LEFT JOIN `bookings` b ON po.booking_id = b.id";
                if ($needsRoomsJoin) $fromStr .= " LEFT JOIN `rooms` r ON b.room_id = r.id";
                if ($needsGuestsJoin) $fromStr .= " LEFT JOIN `guests` g ON b.guest_id = g.id";
                if ($needsOutletsJoin) $fromStr .= " LEFT JOIN `pos_outlets` o ON po.outlet_id = o.id";
            }
            
            $dateField = "{$primaryAlias}.created_at";
            if ($dataset === 'bookings') $dateField = "b.check_in";
            if ($dataset === 'folio_ledger') $dateField = "fl.recorded_at";
            if ($dataset === 'finance_transactions') $dateField = "ft.recorded_at";
            if ($dataset === 'pos_orders') $dateField = "po.recorded_at";
            
            $whereClause = "{$primaryAlias}.property_id = :pid";
            if ($dataset === 'guests') {
                $whereClause = "1=1";
                if ($needsBookingsJoin) {
                    $whereClause = "b.property_id = :pid";
                }
            }
            
            $selectStr = implode(', ', $selectFields);
            $sql = "SELECT DISTINCT {$selectStr} FROM {$fromStr} WHERE {$whereClause} AND {$dateField} >= :start AND {$dateField} <= :end";
            
            try {
                $stmt = $db->prepare($sql);
                $bindParams = ['start' => $start_date, 'end' => $end_date];
                if (strpos($whereClause, ':pid') !== false) {
                    $bindParams['pid'] = $propertyId;
                }
                $stmt->execute($bindParams);
                $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ApiResponse::success(['data' => $allRows]);
            } catch (\PDOException $e) {
                ApiResponse::error('Database Error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            }
            break;
            
        default:
            ApiResponse::error('Unknown report type');
    }

}, true, false, false);

