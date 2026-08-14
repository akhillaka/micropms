<?php
declare(strict_types=1);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';

AuthHelper::requireLogin();

$headers = getallheaders();
$csrfToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (!CsrfToken::validate($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed.']);
    exit;
}

$db = Database::getInstance()->getConnection();
$propertyId = AuthHelper::getPropertyId();

require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';
if (!SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'pos_module')) {
    echo json_encode(['success' => false, 'message' => 'POS module is not enabled for your subscription.']);
    exit;
}

if ($propertyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid property context.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data && !empty($_POST)) {
    $data = $_POST;
}
$action = $data['action'] ?? $_GET['action'] ?? '';

// Generate date ranges based on filter
function getDateRange($filter, $customStart = null, $customEnd = null) {
    $now = new DateTime();
    $start = null;
    $end = null;
    
    switch ($filter) {
        case 'monthly':
            $start = (new DateTime('first day of this month'))->setTime(0, 0, 0);
            $end = (new DateTime('last day of this month'))->setTime(23, 59, 59);
            break;
        case 'quarterly':
            $currentMonth = $now->format('n');
            $startMonth = floor(($currentMonth - 1) / 3) * 3 + 1;
            $start = (new DateTime($now->format('Y') . "-$startMonth-01"))->setTime(0, 0, 0);
            $end = (clone $start)->modify('+3 months')->modify('-1 second');
            break;
        case 'yearly':
            $start = (new DateTime('first day of January this year'))->setTime(0, 0, 0);
            $end = (new DateTime('last day of December this year'))->setTime(23, 59, 59);
            break;
        case 'custom':
            if ($customStart && $customEnd) {
                $start = new DateTime($customStart);
                $end = new DateTime($customEnd);
                $end->setTime(23, 59, 59);
            }
            break;
        default:
            $start = (new DateTime('first day of this month'))->setTime(0, 0, 0);
            $end = (new DateTime('last day of this month'))->setTime(23, 59, 59);
            break;
    }
    
    if (!$start || !$end) {
        $start = (new DateTime('first day of this month'))->setTime(0, 0, 0);
        $end = (new DateTime('last day of this month'))->setTime(23, 59, 59);
    }
    
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

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
            WHERE rh.property_id = ? AND rh.created_at BETWEEN ? AND ?
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
            SELECT o.*, 
                   o.recorded_at AS created_at,
                   COUNT(oi.id) as item_count
            FROM pos_orders o
            LEFT JOIN pos_order_items oi ON o.id = oi.order_id
            WHERE o.property_id = ? AND o.recorded_at BETWEEN ? AND ?
            GROUP BY o.id
            ORDER BY o.recorded_at DESC
        ");
        $stmt->execute([$propertyId, $startDate, $endDate]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalSales = 0;
        $totalOrders = count($orders);
        foreach ($orders as $o) {
            if ($o['status'] === 'completed' || $o['status'] === 'paid') {
                $totalSales += (float)$o['total_amount'];
            }
        }
        
        echo json_encode([
            'success' => true, 
            'data' => $orders,
            'summary' => [
                'total_orders' => $totalOrders,
                'total_sales' => $totalSales,
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
