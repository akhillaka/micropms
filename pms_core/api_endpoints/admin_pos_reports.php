<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';

function getDateRange($filter, $customStart = null, $customEnd = null) {
    $now = new DateTime('now');
    $start = null;
    $end = (clone $now)->setTime(23, 59, 59);

    switch ($filter) {
        case 'today':
            $start = (clone $now)->setTime(0, 0, 0);
            break;
        case 'monthly':
        case 'this_month':
        case 'month':
            $start = (new DateTime($now->format('Y-m-01')))->setTime(0, 0, 0);
            $end = (new DateTime($now->format('Y-m-t')))->setTime(23, 59, 59);
            break;
        case 'quarterly':
            $currentMonth = (int)$now->format('n');
            $startMonth = (int)(floor(($currentMonth - 1) / 3) * 3 + 1);
            $start = (new DateTime(sprintf('%s-%02d-01', $now->format('Y'), $startMonth)))->setTime(0, 0, 0);
            $end = (clone $start)->modify('+3 months')->modify('-1 second');
            break;
        case 'yearly':
            $start = (new DateTime($now->format('Y-01-01')))->setTime(0, 0, 0);
            $end = (new DateTime($now->format('Y-12-31')))->setTime(23, 59, 59);
            break;
        case 'custom':
            if ($customStart && $customEnd) {
                $start = (new DateTime((string)$customStart))->setTime(0, 0, 0);
                $end = (new DateTime((string)$customEnd))->setTime(23, 59, 59);
            }
            break;
        default:
            $start = (new DateTime($now->format('Y-m-01')))->setTime(0, 0, 0);
            $end = (new DateTime($now->format('Y-m-t')))->setTime(23, 59, 59);
            break;
    }

    if (!$start || !$end) {
        $start = (new DateTime($now->format('Y-m-01')))->setTime(0, 0, 0);
        $end = (new DateTime($now->format('Y-m-t')))->setTime(23, 59, 59);
    }

    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requirePermission('view_pos_reports');
    $propertyId = AuthHelper::getPropertyId();
    if (!SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'pos_module')) {
        ApiResponse::error('POS module is not enabled for your subscription.');
    }

    $data = ApiHandler::getJsonInput();
    if (!$data && !empty($_POST)) {
        $data = $_POST;
    }
    $action = $data['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'get_restock_history') {
        if (!AuthHelper::can('view_reports')) {
            throw new Exception("Unauthorized to view reports.");
        }
        
        $filter = $data['filter'] ?? 'monthly';
        $customStart = $data['start_date'] ?? null;
        $customEnd = $data['end_date'] ?? null;
        
        list($startDate, $endDate) = getDateRange($filter, $customStart, $customEnd);
        
        $stmt = $db->prepare("
            SELECT rh.*, i.name as item_name, i.sku, u.username as restocked_by_name
            FROM inventory_restock_history rh
            JOIN inventory_items i ON rh.item_id = i.id
            LEFT JOIN staff_users u ON rh.restocked_by = u.id
            WHERE rh.property_id = ? AND rh.created_at >= ? AND rh.created_at <= ?
            ORDER BY rh.created_at DESC
        ");
        $stmt->execute([$propertyId, $startDate, $endDate]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $totalItemsRestocked = 0;
        $totalCost = 0;
        foreach ($history as $h) {
            $totalItemsRestocked += (int)$h['qty_added'];
            $totalCost += (float)$h['cost_price'] * (int)$h['qty_added'];
        }
        
        echo json_encode([
            'success' => true, 
            'data' => $history,
            'summary' => [
                'total_items' => $totalItemsRestocked,
                'total_cost' => $totalCost,
                'period' => $startDate . ' to ' . $endDate
            ]
        ]);
        exit;
        
    } elseif ($action === 'get_order_tracking') {
        if (!AuthHelper::can('view_reports')) {
            throw new Exception("Unauthorized to view reports.");
        }
        
        $filter = $data['filter'] ?? 'monthly';
        $customStart = $data['start_date'] ?? null;
        $customEnd = $data['end_date'] ?? null;
        
        list($startDate, $endDate) = getDateRange($filter, $customStart, $customEnd);
        
        $stmt = $db->prepare("
            SELECT o.id, o.display_id, o.booking_id, o.total_amount, o.payment_method, o.status,
                   o.source, o.delivery_status, o.recorded_at, o.recorded_at AS created_at,
                   COALESCE(ot.name, '') AS outlet_name,
                   r.room_number,
                   CASE WHEN o.booking_id IS NULL THEN 'counter' ELSE 'room' END AS order_channel,
                   (SELECT COUNT(*) FROM pos_order_items oi WHERE oi.order_id = o.id) AS item_count
            FROM pos_orders o
            LEFT JOIN pos_outlets ot ON o.outlet_id = ot.id
            LEFT JOIN bookings b ON o.booking_id = b.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE o.property_id = ?
              AND o.deleted_at IS NULL
              AND o.recorded_at >= ? AND o.recorded_at <= ?
            ORDER BY o.recorded_at DESC
        ");
        $stmt->execute([$propertyId, $startDate, $endDate]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalSales = 0;
        $totalOrders = count($orders);
        $roomOrders = 0;
        $counterOrders = 0;
        foreach ($orders as $o) {
            if (in_array((string)$o['status'], ['paid', 'posted', 'completed'], true)) {
                $totalSales += (float)$o['total_amount'];
            }
            if (($o['order_channel'] ?? '') === 'room') {
                $roomOrders++;
            } else {
                $counterOrders++;
            }
        }

        echo json_encode([
            'success' => true,
            'data' => $orders,
            'summary' => [
                'total_orders' => $totalOrders,
                'total_sales' => $totalSales,
                'room_orders' => $roomOrders,
                'counter_orders' => $counterOrders,
                'period' => $startDate . ' to ' . $endDate
            ]
        ]);
        exit;
    } else {
        throw new Exception("Invalid action.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
}, true, true, false);
