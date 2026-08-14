<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../pms_core/Database.php';
require_once __DIR__ . '/../../../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../../../pms_core/services/FolioService.php';
require_once __DIR__ . '/../../../../pms_core/services/SaaSEntitlementsService.php';
require_once __DIR__ . '/../../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../../pms_core/CsrfToken.php';

AuthHelper::requireLoginOrRedirect();
if (!AuthHelper::can('manage_pos')) {
    header('Location: /admin');
    exit;
}

$db = Database::getInstance()->getConnection();

// Select property context
$propertyId = AuthHelper::getPropertyId();

if ($propertyId <= 0) {
    die("Error loading property context. Please log in again.");
}

// SaaS Entitlements Check: Verify POS module flag
$posEnabled = SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'pos_module');
if (!$posEnabled) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>POS Upgrade Required | StayFlexi</title>
        <?php include __DIR__ . '/../../components/ui_head.php'; ?>
        
    
    <style>
        .stayflexi-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.125rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .folio-grid-col {
            padding-bottom: 12px;
        }
    </style>
    </head>
    <body class="flex flex-col min-h-screen items-center justify-center p-6 text-center">
        <div class="max-w-md w-full bg-white border border-slate-200 p-8 rounded-2xl shadow-md space-y-5">
            <div class="w-16 h-16 bg-indigo-50 rounded-full flex items-center justify-center mx-auto border border-indigo-200 text-indigo-600">
                <i class="ph ph-lock text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold tracking-tight text-slate-800">POS Module Upgrade Needed</h2>
            <p class="text-xs text-slate-500 font-semibold leading-relaxed">
                Your current subscription tier does not have the **Point of Sale (POS) & Inventory** modules enabled. 
                Upgrade to our Pro or Enterprise plan to configure shop outlets, track central stock, and charge bills to room folios.
            </p>
            <div class="pt-2 flex flex-col gap-2">
                <a href="../../settings.php?tab=subscription" class="px-5 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition shadow cursor-pointer">Upgrade Subscription Plan</a>
                <a href="../../index.php" class="px-5 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer">Back to Dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Fetch active bookings for Room Charge option
$bookingsStmt = $db->prepare("
    SELECT b.id, b.guest_id, g.name as guest_name, r.room_number, c.name as category_name
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    JOIN room_categories c ON r.category_id = c.id
    JOIN guests g ON b.guest_id = g.id
    WHERE b.property_id = ? AND b.booking_status = 'checked_in'
    ORDER BY r.room_number ASC
");
$bookingsStmt->execute([$propertyId]);
$activeBookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch current outlets
$outletStmt = $db->prepare("SELECT * FROM pos_outlets WHERE property_id = ? ORDER BY name ASC");
$outletStmt->execute([$propertyId]);
$outlets = $outletStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch current inventory items mapped to their outlet
$invStmt = $db->prepare("
    SELECT i.*, o.name as outlet_name 
    FROM inventory_items i 
    LEFT JOIN pos_outlets o ON i.outlet_id = o.id
    WHERE i.property_id = ? 
    ORDER BY i.name ASC
");
$invStmt->execute([$propertyId]);
$inventoryItems = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active guest orders (pending delivery)
$ordersStmt = $db->prepare("
    SELECT o.*, b.room_id, r.room_number, g.name as guest_name, ot.name as outlet_name
    FROM pos_orders o
    JOIN bookings b ON o.booking_id = b.id
    JOIN rooms r ON b.room_id = r.id
    JOIN guests g ON b.guest_id = g.id
    LEFT JOIN pos_outlets ot ON o.outlet_id = ot.id
    WHERE o.property_id = ? AND o.delivery_status = 'pending'
    ORDER BY o.recorded_at DESC
");
$ordersStmt->execute([$propertyId]);
$pendingOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all order history (Paginated)
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$historyCountStmt = $db->prepare("SELECT COUNT(*) FROM pos_orders WHERE property_id = ?");
$historyCountStmt->execute([$propertyId]);
$totalHistory = $historyCountStmt->fetchColumn();
$totalPages = max(1, ceil($totalHistory / $limit));

$historyStmt = $db->prepare("
    SELECT o.*, r.room_number, g.name as guest_name, ot.name as outlet_name
    FROM pos_orders o
    LEFT JOIN bookings b ON o.booking_id = b.id
    LEFT JOIN rooms r ON b.room_id = r.id
    LEFT JOIN guests g ON b.guest_id = g.id
    LEFT JOIN pos_outlets ot ON o.outlet_id = ot.id
    WHERE o.property_id = ?
    ORDER BY o.recorded_at DESC
    LIMIT $limit OFFSET $offset
");
$historyStmt->execute([$propertyId]);
$orderHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch POS specific audit logs
$auditStmt = $db->prepare("
    SELECT a.*, u.username, u.access_level as role
    FROM audit_logs a
    LEFT JOIN staff_users u ON a.staff_id = u.id
    WHERE a.property_id = :prop_id AND (a.action LIKE '%POS%' OR a.action LIKE '%INVENTORY%')
    ORDER BY a.id DESC LIMIT 50
");
$auditStmt->execute(['prop_id' => $propertyId]);
$posAuditLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch POS Settings from system_settings
$posTax = get_db_setting($db, 'POS_DEFAULT_TAX', (int)$propertyId, '0');
$posAutoCharge = get_db_setting($db, 'POS_AUTO_POST_ROOM', (int)$propertyId, 'true');
$posAlertLevel = get_db_setting($db, 'POS_LOW_STOCK_DEFAULT', (int)$propertyId, '5');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS & Inventory | StayFlexi</title>
    <?= CsrfToken::meta() ?>
    <?php include __DIR__ . '/../../components/ui_head.php'; ?>
    <style>
        .stayflexi-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.125rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body class="bg-slate-50/50 flex flex-col min-h-screen">

    <!-- App Header -->
    <header class="bg-white px-6 py-4 flex items-center justify-between border-b border-slate-100 sticky top-0 z-50 shadow-sm mb-6">
        <div class="flex items-center gap-3">
            <a href="../../index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 transition-colors cursor-pointer">
                <i class="ph ph-caret-left text-2xl text-slate-800"></i>
            </a>
            <h1 class="text-base font-bold text-slate-900 leading-none font-display">
                POS & Central Stock
                <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mt-1 inline-block font-sans">Point of Sale</span>
            </h1>
        </div>
        <?php include __DIR__ . '/../../components/desktop_nav.php'; ?>
    </header>

    <!-- Main POS Layout -->
    <div class="flex-1 max-w-7xl w-full mx-auto px-6 grid grid-cols-1 lg:grid-cols-12 gap-6 pb-24">
        
        <!-- Left Panel: POS Registration / Catalogue / Stock (8 cols) -->
        <div class="lg:col-span-8 space-y-6">
            
            <!-- Navigation Tabs -->
            <div class="flex bg-white p-1.5 rounded-2xl border border-slate-100 max-w-2xl overflow-x-auto no-scrollbar shadow-sm gap-1">
                <button onclick="togglePosTab('register')" id="tabBtn-register" class="flex-1 py-2 rounded-xl text-xs font-bold bg-indigo-50 text-indigo-700 shrink-0 px-3 cursor-pointer transition">
                    <i class="ph ph-device-mobile"></i> Register
                </button>
                <button onclick="togglePosTab('orders')" id="tabBtn-orders" class="flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 relative shrink-0 px-3 cursor-pointer transition">
                    <i class="ph ph-bell"></i> Guest Orders
                    <?php if (count($pendingOrders) > 0): ?>
                        <span class="absolute -top-1 -right-1 w-4 h-4 bg-rose-500 text-white rounded-full flex items-center justify-center text-[8px] font-black animate-bounce"><?= htmlspecialchars((string)(count($pendingOrders)), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </button>
                <button onclick="togglePosTab('history')" id="tabBtn-history" class="flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition">
                    <i class="ph ph-clock-counter-clockwise"></i> Recent Orders
                </button>
                <button onclick="togglePosTab('reports_orders')" id="tabBtn-reports_orders" class="flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition">
                    <i class="ph ph-chart-line-up"></i> Order Reports
                </button>
                <button onclick="togglePosTab('inventory')" id="tabBtn-inventory" class="flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition">
                    <i class="ph ph-warehouse"></i> Central Stock
                </button>
                <button onclick="togglePosTab('reports_restock')" id="tabBtn-reports_restock" class="flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition">
                    <i class="ph ph-clock-user"></i> Restock History
                </button>
                <button onclick="togglePosTab('logs')" id="tabBtn-logs" class="flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition">
                    <i class="ph ph-shield-check"></i> Audit Logs
                </button>
            </div>

            <!-- TAB 1: POS REGISTER -->
            <div id="posSec-register" class="space-y-6">
                <!-- Shop Outlet Filters -->
                <div class="flex gap-2 overflow-x-auto no-scrollbar py-1">
                    <button onclick="filterOutlet(null)" id="btn-outlet-all" class="px-4 py-2 rounded-full text-xs font-bold bg-indigo-600 text-white shadow transition cursor-pointer">
                        All Shops
                    </button>
                    <?php foreach ($outlets as $o): ?>
                        <button onclick="filterOutlet(<?= htmlspecialchars((string)($o['id']), ENT_QUOTES, 'UTF-8') ?>)" id="btn-outlet-<?= htmlspecialchars((string)($o['id']), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 rounded-full text-xs font-bold bg-white text-slate-700 border border-slate-200 hover:bg-slate-100 transition shrink-0 cursor-pointer">
                            <?= htmlspecialchars((string)($o['name'])) ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Search Catalog -->
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-4">
                    <div class="relative">
                        <i class="ph ph-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" oninput="searchCatalog(this.value)" placeholder="Search products by name or SKU..." class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 !pl-12 pr-4 py-2.5 rounded-xl text-xs font-semibold">
                    </div>
                </div>

                <!-- Products Catalogue Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4" id="catalog-container">
                    <?php if (empty($inventoryItems)): ?>
                        <div class="col-span-full bg-white border border-slate-100 rounded-2xl shadow-sm p-8 text-center space-y-2">
                            <i class="ph ph-package text-4xl text-slate-400"></i>
                            <p class="text-xs font-semibold text-slate-500">No products found in catalogue.</p>
                            <button onclick="togglePosTab('inventory')" class="text-xs text-indigo-600 hover:underline cursor-pointer">Add items in Stock tab</button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($inventoryItems as $item): ?>
                            <?php 
                            $isOutOfStock = $item['stock_qty'] <= 0;
                            $isLowStock = !$isOutOfStock && $item['stock_qty'] <= $item['low_stock_threshold'];
                            ?>
                            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-4 flex flex-col justify-between hover:border-slate-350 transition duration-200 relative overflow-hidden product-card <?= htmlspecialchars((string)($isOutOfStock ? 'opacity-50' : ''), ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars((string)(strtolower($item['name']))) ?>" data-sku="<?= htmlspecialchars((string)(strtolower($item['sku']))) ?>" data-outlet-id="<?= htmlspecialchars((string)((int)$item['outlet_id']), ENT_QUOTES, 'UTF-8') ?>">
                                
                                <?php if ($isOutOfStock): ?>
                                    <span class="absolute top-2 right-2 stayflexi-badge bg-rose-50 text-rose-600 border-rose-200 border">Out of Stock</span>
                                <?php elseif ($isLowStock): ?>
                                    <span class="absolute top-2 right-2 stayflexi-badge bg-indigo-50 text-indigo-600 border-indigo-200 border">Low Stock (<?= htmlspecialchars((string)($item['stock_qty']), ENT_QUOTES, 'UTF-8') ?>)</span>
                                <?php endif; ?>

                                <div class="space-y-1 mt-2">
                                    <span class="text-[9px] uppercase tracking-wider font-bold text-indigo-600"><?= htmlspecialchars((string)($item['outlet_name'] ?: 'General')) ?></span>
                                    <span class="text-xs font-bold text-slate-800 block truncate"><?= htmlspecialchars((string)($item['name'])) ?></span>
                                    <span class="text-[9px] font-mono text-slate-400 block"><?= htmlspecialchars((string)($item['sku'] ?: 'No SKU')) ?></span>
                                    <span class="text-[10px] text-slate-500 block">In stock: <?= htmlspecialchars((string)($item['stock_qty']), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>

                                <div class="flex justify-between items-center mt-4 pt-2 border-t border-slate-100">
                                    <span class="text-xs font-bold text-slate-800 font-mono">₹<?= htmlspecialchars((string)(number_format((float)$item['selling_price'], 2)), ENT_QUOTES, 'UTF-8') ?></span>
                                    <button 
                                        onclick="addToCart(<?= htmlspecialchars((string)(json_encode($item))) ?>)"
                                        <?= htmlspecialchars((string)($isOutOfStock ? 'disabled' : ''), ENT_QUOTES, 'UTF-8') ?>
                                        class="w-8 h-8 rounded-full bg-indigo-600 text-white flex items-center justify-center hover:bg-indigo-700 disabled:bg-slate-100 disabled:text-slate-400 transition-colors cursor-pointer">
                                        <i class="ph ph-plus font-bold"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 2: ACTIVE GUEST ORDERS -->
            <div id="posSec-orders" class="hidden space-y-4">
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2"><i class="ph ph-bell text-indigo-600"></i> Pending Guest Portal Orders</h2>
                <?php if (empty($pendingOrders)): ?>
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-8 text-center text-slate-500 text-xs font-semibold">
                        No pending room service orders right now.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 gap-4">
                        <?php foreach ($pendingOrders as $order): ?>
                            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded font-mono font-bold bg-slate-100 text-indigo-600 text-xs border border-slate-200">Room <?= htmlspecialchars((string)($order['room_number'])) ?></span>
                                        <span class="text-xs font-bold text-slate-800"><?= htmlspecialchars((string)($order['guest_name'])) ?></span>
                                    </div>
                                    <p class="text-[10px] text-slate-500">Shop: <strong><?= htmlspecialchars((string)($order['outlet_name'] ?: 'General')) ?></strong> · Order ID: #<?= htmlspecialchars((string)($order['display_id'] ?? (string)$order['id'])) ?> · Ordered on <?= htmlspecialchars((string)(date('d M, H:i', strtotime($order['recorded_at']))), ENT_QUOTES, 'UTF-8') ?></p>
                                    <div class="text-xs font-bold text-slate-800 mt-2">
                                        Total Amount: <span class="font-mono text-indigo-600">₹<?= htmlspecialchars((string)(number_format((float)$order['total_amount'], 2)), ENT_QUOTES, 'UTF-8') ?></span> (Charge Posted to Folio)
                                    </div>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <button onclick="updateOrderStatus(<?= htmlspecialchars((string)($order['id']), ENT_QUOTES, 'UTF-8') ?>, 'cancelled')" class="px-4 py-2 bg-rose-50 text-rose-600 hover:bg-rose-100 border border-rose-200 rounded-xl text-xs font-bold transition cursor-pointer">Cancel</button>
                                    <button onclick="updateOrderStatus(<?= htmlspecialchars((string)($order['id']), ENT_QUOTES, 'UTF-8') ?>, 'delivered')" class="px-4 py-2 bg-emerald-600 text-white hover:bg-emerald-700 rounded-xl text-xs font-bold transition cursor-pointer">Mark Delivered</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 2.5: ORDER HISTORY & TRACKING -->
            <div id="posSec-history" class="hidden space-y-4">
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2"><i class="ph ph-clock-counter-clockwise text-indigo-600"></i> Order Logs & Tracking</h2>
                <?php if (empty($orderHistory)): ?>
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-8 text-center text-slate-500 text-xs font-semibold">
                        No orders recorded in system.
                    </div>
                <?php else: ?>
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="table-brutal">
                                <thead>
                                    <tr>
                                        <th class="p-3">Order ID</th>
                                        <th class="p-3">Date</th>
                                        <th class="p-3">Shop</th>
                                        <th class="p-3">Method</th>
                                        <th class="p-3">Total</th>
                                        <th class="p-3">Source</th>
                                        <th class="p-3">Status</th>
                                        <th class="p-3">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orderHistory as $o): ?>
                                        <tr class="hover:bg-slate-50/50">
                                            <td class="p-3 font-mono font-bold text-slate-900">#<?= htmlspecialchars((string)($o['display_id'] ?? (string)$o['id'])) ?></td>
                                            <td class="p-3 text-slate-500 font-mono"><?= htmlspecialchars((string)(date('d M, H:i', strtotime($o['recorded_at']))), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="p-3 font-bold text-indigo-600"><?= htmlspecialchars((string)($o['outlet_name'] ?: 'General')) ?></td>
                                            <td class="p-3">
                                                <?php if ($o['payment_method'] === 'room_charge'): ?>
                                                    <a href="../../folio.php?id=<?= htmlspecialchars((string)($o['booking_id']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="px-2 py-0.5 rounded text-[9px] font-bold bg-blue-50 text-blue-600 border border-blue-200 hover:bg-blue-100 transition cursor-pointer" title="View Folio">Room <?= htmlspecialchars((string)($o['room_number'] ?: '')) ?> <i class="ph ph-arrow-square-out ml-0.5"></i></a>
                                                <?php else: ?>
                                                    <span class="uppercase text-slate-600 text-xs font-bold"><?= htmlspecialchars((string)($o['payment_method'])) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-3 font-mono font-bold text-slate-900">₹<?= htmlspecialchars((string)(number_format((float)$o['total_amount'], 2)), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="p-3 text-slate-500 text-xs font-semibold"><?= htmlspecialchars((string)($o['source'])) ?></td>
                                            <td class="p-3">
                                                <select onchange="updateOrderStatus(<?= htmlspecialchars((string)($o['id']), ENT_QUOTES, 'UTF-8') ?>, this.value)" class="bg-white border border-slate-200 p-1.5 rounded-lg text-[10px] font-bold text-slate-700 outline-none focus:border-indigo-600 cursor-pointer">
                                                    <option value="delivered" <?= htmlspecialchars((string)($o['delivery_status'] === 'delivered' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Delivered</option>
                                                    <option value="pending" <?= htmlspecialchars((string)($o['delivery_status'] === 'pending' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Pending</option>
                                                    <option value="cancelled" <?= htmlspecialchars((string)($o['delivery_status'] === 'cancelled' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Cancelled</option>
                                                </select>
                                            </td>
                                            <td class="p-3 flex items-center gap-2">
                                                <button onclick="editOrder(<?= htmlspecialchars((string)($o['id']), ENT_QUOTES, 'UTF-8') ?>)" class="text-indigo-600 hover:text-indigo-800 transition text-sm p-1 rounded hover:bg-indigo-50" title="Edit Order"><i class="ph-bold ph-pencil-simple"></i></button>
                                                <button onclick="deleteOrderPOS(<?= htmlspecialchars((string)($o['id']), ENT_QUOTES, 'UTF-8') ?>)" class="text-rose-600 hover:text-rose-800 transition text-sm p-1 rounded hover:bg-rose-50" title="Delete Order"><i class="ph-bold ph-trash"></i></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($totalPages > 1): ?>
                        <div class="flex justify-between items-center mt-4 bg-white p-4 rounded-xl border border-slate-100">
                            <a href="?page=<?= htmlspecialchars((string)(max(1, $page - 1)), ENT_QUOTES, 'UTF-8') ?>&tab=history" class="text-sm px-4 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 font-bold transition <?= htmlspecialchars((string)($page <= 1 ? 'opacity-50 pointer-events-none' : ''), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                            <span class="text-xs font-semibold text-slate-500">Page <?= htmlspecialchars((string)($page), ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars((string)($totalPages), ENT_QUOTES, 'UTF-8') ?></span>
                            <a href="?page=<?= htmlspecialchars((string)(min($totalPages, $page + 1)), ENT_QUOTES, 'UTF-8') ?>&tab=history" class="text-sm px-4 py-2 bg-slate-100 hover:bg-slate-200 rounded-lg text-slate-700 font-bold transition <?= htmlspecialchars((string)($page >= $totalPages ? 'opacity-50 pointer-events-none' : ''), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 3: CENTRAL STOCK & CONFIG -->
            <div id="posSec-inventory" class="hidden space-y-6">
                <!-- POS Settings & Configs -->
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6 space-y-4">
                    <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider border-b border-slate-200 pb-3 flex items-center gap-2"><i class="ph ph-gear text-indigo-600"></i> Global Settings</h2>
                    <form onsubmit="savePosSettings(event)" id="posSettingsForm" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">POS Sales Tax / GST (%)</label>
                            <input type="number" step="0.1" min="0" max="100" id="cfg_tax" value="<?= htmlspecialchars((string)($posTax)) ?>" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Low Stock Warning Limit</label>
                            <input type="number" min="1" id="cfg_alert" value="<?= htmlspecialchars((string)($posAlertLevel)) ?>" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Auto Post Room Charges</label>
                            <select id="cfg_autocharge" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-bold cursor-pointer">
                                <option value="true" <?= htmlspecialchars((string)($posAutoCharge === 'true' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Auto-charge Folio Ledger</option>
                                <option value="false" <?= htmlspecialchars((string)($posAutoCharge === 'false' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Manual Review Required</option>
                            </select>
                        </div>
                        <div class="sm:col-span-3 flex justify-end">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs uppercase px-5 py-3 rounded-xl transition shadow cursor-pointer">Save Settings</button>
                        </div>
                    </form>
                </div>

                <!-- Manage Outlets Configuration -->
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6 space-y-4">
                    <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider border-b border-slate-200 pb-3 flex items-center gap-2"><i class="ph ph-storefront text-indigo-600"></i> Configure Outlets / Shops</h2>
                    <form onsubmit="submitNewOutlet(event)" class="flex gap-3">
                        <input type="text" id="outlet_name" required placeholder="e.g. Laundry, Cafeteria" class="flex-1 focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs uppercase px-5 rounded-xl transition shadow cursor-pointer">Create Shop</button>
                    </form>
                    <div class="flex flex-wrap gap-2 pt-2">
                        <?php foreach($outlets as $o): ?>
                            <span class="px-3 py-1 bg-slate-100 border border-slate-200 rounded-lg text-xs font-bold text-slate-700 flex items-center gap-2">
                                <?= htmlspecialchars((string)($o['name'])) ?>
                                <button onclick="deleteOutlet(<?= htmlspecialchars((string)($o['id']), ENT_QUOTES, 'UTF-8') ?>)" class="text-rose-600 hover:text-rose-500 transition cursor-pointer">
                                    <i class="ph ph-trash"></i>
                                </button>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Add product card -->
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6 space-y-4">
                    <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider border-b border-slate-200 pb-3 flex items-center gap-2"><i class="ph ph-plus text-indigo-600"></i> Add Product to Stock</h2>
                    
                    <form onsubmit="submitNewItem(event)" id="newItemForm" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Product Name</label>
                            <input type="text" id="add_name" required placeholder="e.g. Diet Coke 330ml" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">SKU / Barcode</label>
                            <input type="text" id="add_sku" placeholder="e.g. COKE-330" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold font-mono">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Assign to Outlet / Shop</label>
                            <select id="add_outlet_id" required class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-bold cursor-pointer">
                                <option value="">-- Select Shop --</option>
                                <?php foreach ($outlets as $o): ?>
                                    <option value="<?= htmlspecialchars((string)($o['id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($o['name'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Stock Quantity</label>
                            <input type="number" id="add_stock" min="0" required value="10" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Cost Price (₹)</label>
                            <input type="number" step="0.01" min="0" id="add_cost" required value="10.00" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Selling Price (₹)</label>
                            <input type="number" step="0.01" min="0" id="add_selling" required value="20.00" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold font-mono">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Low Stock Warning Limit</label>
                            <input type="number" id="add_threshold" min="1" required value="5" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Product Image Web URL (optional)</label>
                            <input type="url" id="add_image" placeholder="https://..." class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                        </div>
                        <div class="sm:col-span-3 flex justify-end pt-2">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs uppercase px-6 py-3.5 rounded-xl transition shadow cursor-pointer">
                                Add Product
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Stock List Table -->
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6 space-y-4">
                    <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider"><i class="ph ph-list-bullets text-indigo-600"></i> Stock Ledger</h2>
                    <div class="overflow-x-auto border border-slate-200 rounded-xl">
                        <table class="table-brutal">
                            <thead>
                                <tr>
                                    <th class="p-3">Product</th>
                                    <th class="p-3">Shop</th>
                                    <th class="p-3 text-center">Stock</th>
                                    <th class="p-3 text-right">Cost</th>
                                    <th class="p-3 text-right">Selling</th>
                                    <th class="p-3 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inventoryItems as $item): ?>
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="p-3 font-bold text-slate-800"><?= htmlspecialchars((string)($item['name'])) ?></td>
                                        <td class="p-3 text-slate-500"><?= htmlspecialchars((string)($item['outlet_name'] ?: 'General')) ?></td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 rounded font-mono font-bold <?= htmlspecialchars((string)($item['stock_qty'] <= $item['low_stock_threshold'] ? 'bg-rose-50 text-rose-600 border border-rose-200' : 'bg-slate-100 text-slate-600'), ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string)($item['stock_qty']), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-right font-mono text-slate-500">₹<?= htmlspecialchars((string)(number_format((float)$item['cost_price'], 2)), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="p-3 text-right font-mono font-bold text-slate-800">₹<?= htmlspecialchars((string)(number_format((float)$item['selling_price'], 2)), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="p-3 text-center flex items-center justify-center gap-2">
                                            <button onclick="openEditModal(<?= htmlspecialchars((string)(json_encode($item))) ?>)" class="px-2.5 py-1 bg-slate-50 hover:bg-slate-100 text-slate-600 rounded-lg text-[10px] font-bold transition border border-slate-200 cursor-pointer">Edit</button>
                                            <button onclick="openRestockModal(<?= htmlspecialchars((string)(json_encode($item))) ?>)" class="px-2.5 py-1 bg-indigo-50 hover:bg-amber-100 text-indigo-600 rounded-lg text-[10px] font-bold transition border border-indigo-200 cursor-pointer">Restock</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: AUDIT LOGS -->
            <!-- TAB: POS REPORTS (ORDERS) -->
            <div id="posSec-reports_orders" class="hidden space-y-4">
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                    <i class="ph ph-chart-line-up text-indigo-600"></i> Order Reports
                </h2>
                
                <div class="flex gap-2 bg-white p-2 rounded-xl border border-slate-100 shadow-sm items-center">
                    <select id="ordersReportFilter" class="p-2 rounded-lg text-xs font-bold text-slate-700 bg-slate-50 border border-slate-200 outline-none focus:border-indigo-600" onchange="toggleCustomDate('orders'); fetchOrderReports()">
                        <option value="monthly">This Month</option>
                        <option value="quarterly">This Quarter</option>
                        <option value="yearly">This Year</option>
                        <option value="custom">Custom Date Range</option>
                    </select>
                    
                    <div id="ordersCustomDate" class="hidden flex gap-2 items-center">
                        <input type="date" id="ordersStartDate" class="p-2 rounded-lg text-xs font-bold text-slate-700 bg-slate-50 border border-slate-200 outline-none focus:border-indigo-600" onchange="fetchOrderReports()">
                        <span class="text-xs font-bold text-slate-400">to</span>
                        <input type="date" id="ordersEndDate" class="p-2 rounded-lg text-xs font-bold text-slate-700 bg-slate-50 border border-slate-200 outline-none focus:border-indigo-600" onchange="fetchOrderReports()">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white border border-slate-100 rounded-2xl p-4 shadow-sm">
                        <p class="text-[10px] font-bold text-slate-400 uppercase">Total Orders</p>
                        <h3 class="text-2xl font-black text-slate-800" id="ordersTotalCount">0</h3>
                    </div>
                    <div class="bg-white border border-slate-100 rounded-2xl p-4 shadow-sm">
                        <p class="text-[10px] font-bold text-slate-400 uppercase">Total Sales (Completed)</p>
                        <h3 class="text-2xl font-black text-indigo-600 font-mono" id="ordersTotalSales">₹0.00</h3>
                    </div>
                </div>

                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table-brutal">
                            <thead>
                                <tr>
                                    <th class="p-3">Order ID</th>
                                    <th class="p-3">Date</th>
                                    <th class="p-3">Total Amount</th>
                                    <th class="p-3">Status</th>
                                </tr>
                            </thead>
                            <tbody id="ordersReportTableBody">
                                <!-- Populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB: POS REPORTS (RESTOCK) -->
            <div id="posSec-reports_restock" class="hidden space-y-4">
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                    <i class="ph ph-clock-user text-indigo-600"></i> Restock History
                </h2>
                
                <div class="flex gap-2 bg-white p-2 rounded-xl border border-slate-100 shadow-sm items-center">
                    <select id="restockReportFilter" class="p-2 rounded-lg text-xs font-bold text-slate-700 bg-slate-50 border border-slate-200 outline-none focus:border-indigo-600" onchange="toggleCustomDate('restock'); fetchRestockReports()">
                        <option value="monthly">This Month</option>
                        <option value="quarterly">This Quarter</option>
                        <option value="yearly">This Year</option>
                        <option value="custom">Custom Date Range</option>
                    </select>
                    
                    <div id="restockCustomDate" class="hidden flex gap-2 items-center">
                        <input type="date" id="restockStartDate" class="p-2 rounded-lg text-xs font-bold text-slate-700 bg-slate-50 border border-slate-200 outline-none focus:border-indigo-600" onchange="fetchRestockReports()">
                        <span class="text-xs font-bold text-slate-400">to</span>
                        <input type="date" id="restockEndDate" class="p-2 rounded-lg text-xs font-bold text-slate-700 bg-slate-50 border border-slate-200 outline-none focus:border-indigo-600" onchange="fetchRestockReports()">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white border border-slate-100 rounded-2xl p-4 shadow-sm">
                        <p class="text-[10px] font-bold text-slate-400 uppercase">Items Restocked</p>
                        <h3 class="text-2xl font-black text-slate-800" id="restockTotalItems">0</h3>
                    </div>
                    <div class="bg-white border border-slate-100 rounded-2xl p-4 shadow-sm">
                        <p class="text-[10px] font-bold text-slate-400 uppercase">Total Cost</p>
                        <h3 class="text-2xl font-black text-indigo-600 font-mono" id="restockTotalCost">₹0.00</h3>
                    </div>
                </div>

                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table-brutal">
                            <thead>
                                <tr>
                                    <th class="p-3">Date</th>
                                    <th class="p-3">Item</th>
                                    <th class="p-3">Qty Added</th>
                                    <th class="p-3">Cost/Item</th>
                                    <th class="p-3">By</th>
                                </tr>
                            </thead>
                            <tbody id="restockReportTableBody">
                                <!-- Populated via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 4: AUDIT LOGS -->
            <div id="posSec-logs" class="hidden space-y-4">
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2"><i class="ph ph-shield-check text-indigo-600"></i> POS Audit Logs</h2>
                <?php if (empty($posAuditLogs)): ?>
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-8 text-center text-slate-500 text-xs font-semibold">
                        No audit logs recorded for POS yet.
                    </div>
                <?php else: ?>
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="table-brutal">
                                <thead>
                                    <tr>
                                        <th class="p-3">Timestamp</th>
                                        <th class="p-3">User</th>
                                        <th class="p-3">Action</th>
                                        <th class="p-3">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($posAuditLogs as $log): ?>
                                        <?php
                                            $details = json_decode($log['details'] ?? '{}', true);
                                            $detailStr = '';
                                            if (!empty($details)) {
                                                $parts = [];
                                                foreach ($details as $k => $v) {
                                                    if (!in_array($k, ['timestamp', 'ip', 'source', 'action_label', 'staff_name'])) {
                                                        $parts[] = $k . ': ' . (is_array($v) ? json_encode($v) : $v);
                                                    }
                                                }
                                                $detailStr = implode(', ', $parts);
                                            }
                                        ?>
                                        <tr class="hover:bg-slate-50/50 border-b border-slate-100 last:border-0">
                                            <td class="p-3 text-slate-500 font-mono text-[10px] whitespace-nowrap">
                                                <?= htmlspecialchars((string)(date('d M Y, H:i', strtotime($log['created_at']))), ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td class="p-3">
                                                <div class="flex flex-col">
                                                    <span class="font-bold text-slate-800 text-xs"><?= htmlspecialchars((string)($log['username'] ?? 'System')) ?></span>
                                                    <span class="text-[9px] uppercase font-bold text-slate-400"><?= htmlspecialchars((string)($log['role'] ?? '')) ?></span>
                                                </div>
                                            </td>
                                            <td class="p-3">
                                                <?php
                                                    $actionLabel = $details['action_label'] ?? $log['action'];
                                                    $actionColors = [
                                                        'POS_CREATE_ORDER' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                                        'POS_ORDER_EDIT_FULL' => 'bg-blue-50 text-blue-700 border-blue-200',
                                                        'POS_ORDER_DELETE' => 'bg-rose-50 text-rose-700 border-rose-200',
                                                        'POS_ORDER_STATUS_UPDATE' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                                        'POS_RESTOCK_INVENTORY' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                                        'POS_ADD_INVENTORY' => 'bg-purple-50 text-purple-700 border-purple-200',
                                                    ];
                                                    $color = $actionColors[$log['action']] ?? 'bg-slate-100 text-slate-600 border-slate-200';
                                                ?>
                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold border <?= htmlspecialchars((string)($color), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars((string)($actionLabel)) ?>
                                                </span>
                                            </td>
                                            <td class="p-3 text-[10px] font-mono text-slate-500 max-w-[200px] truncate" title="<?= htmlspecialchars((string)($detailStr)) ?>">
                                                <?= htmlspecialchars((string)($detailStr ?: '—')) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- Right Panel: POS Order Cart (4 cols) -->
        <div class="lg:col-span-4">
            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-5 sticky top-24 flex flex-col justify-between" style="min-height: 520px;">
                
                <div class="space-y-4">
                    <div class="flex justify-between items-center border-b border-slate-200 pb-3">
                        <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2"><i class="ph ph-shopping-bag text-indigo-600"></i> Cart Basket</h2>
                        <button onclick="clearCart()" class="text-[10px] font-bold text-rose-600 hover:underline cursor-pointer">Clear All</button>
                    </div>

                    <!-- Cart list -->
                    <div id="cart-items" class="space-y-3 max-h-[250px] overflow-y-auto pr-1">
                        <p class="text-xs text-slate-400 font-semibold text-center py-8">Your cart is empty.</p>
                    </div>
                </div>

                <!-- Totals & Checkout -->
                <div class="space-y-4 border-t border-slate-200 pt-4">
                    <div class="flex justify-between text-xs font-bold text-slate-500">
                        <span>Subtotal</span>
                        <span id="cart-subtotal" class="font-mono">₹0.00</span>
                    </div>
                    <div class="flex justify-between text-sm font-bold text-slate-800 border-t border-slate-200 pt-3">
                        <span>Grand Total</span>
                        <span id="cart-total" class="font-mono text-indigo-600 text-lg">₹0.00</span>
                    </div>

                    <!-- Billing method selection -->
                    <div class="space-y-2">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider">Payment Method</label>
                        <select id="checkout_method" onchange="toggleCheckoutMethod(this.value)" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-bold cursor-pointer">
                            <option value="cash">Cash Payment</option>
                            <option value="upi">UPI / QR Scan</option>
                            <option value="card">Card Terminal</option>
                            <option value="room_charge">Charge to Guest Folio (Room)</option>
                        </select>
                    </div>

                    <!-- Room search picker -->
                    <div id="room-charge-picker" class="hidden space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                        <label class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Select Checked-In Guest / Room</label>
                        <select id="checkout_booking_id" class="w-full bg-white border border-slate-200 p-2.5 rounded-lg text-xs font-semibold text-slate-800 outline-none focus:border-indigo-600 cursor-pointer">
                            <option value="">-- Select Active Room --</option>
                            <?php foreach ($activeBookings as $bk): ?>
                                <option value="<?= htmlspecialchars((string)($bk['id']), ENT_QUOTES, 'UTF-8') ?>">Room <?= htmlspecialchars((string)($bk['room_number'])) ?> - <?= htmlspecialchars((string)($bk['guest_name'])) ?> (<?= htmlspecialchars((string)($bk['category_name'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button onclick="submitCheckout()" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl transition shadow flex items-center justify-center gap-1.5 cursor-pointer">
                        <i class="ph ph-shopping-cart"></i> Complete Checkout
                    </button>
                </div>

            </div>
        </div>

    </div>

    <!-- Restock Item Modal -->
    <div id="restockModal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-white border border-slate-100 rounded-2xl shadow-sm w-full max-w-sm p-6 space-y-4 shadow-2xl">
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider border-b border-slate-200 pb-2">Restock Product</h3>
            <p class="text-xs text-slate-500 font-semibold" id="restock_prod_name">Product Name</p>
            
            <form onsubmit="submitRestock(event)" class="space-y-4">
                <input type="hidden" id="restock_item_id">
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Add Quantity</label>
                    <input type="number" id="restock_qty" required min="1" value="10" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="closeRestockModal()" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition cursor-pointer">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-indigo-600 text-white font-bold rounded-xl text-xs transition cursor-pointer">Add Stock</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Edit Item Modal -->
    <div id="editModal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-white border border-slate-100 rounded-2xl shadow-sm w-full max-w-lg p-6 space-y-4 shadow-2xl overflow-y-auto max-h-[90vh]">
            <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider border-b border-slate-200 pb-2">Edit Product</h3>
            <form onsubmit="submitEditItem(event)" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <input type="hidden" id="edit_item_id">
                <div class="sm:col-span-2">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Product Name</label>
                    <input type="text" id="edit_name" required class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">SKU / Barcode</label>
                    <input type="text" id="edit_sku" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Assign to Shop / Outlet</label>
                    <select id="edit_outlet_id" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-bold cursor-pointer">
                        <option value="">-- General Stock --</option>
                        <?php foreach($outlets as $o): ?>
                            <option value="<?= htmlspecialchars((string)($o['id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($o['name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Stock Quantity</label>
                    <input type="number" id="edit_stock" required min="0" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Low Stock Warning Limit</label>
                    <input type="number" id="edit_threshold" required min="1" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Cost Price (₹)</label>
                    <input type="number" step="0.01" min="0" id="edit_cost" required class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Selling Price (₹)</label>
                    <input type="number" step="0.01" min="0" id="edit_selling" required class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold font-mono">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Image URL</label>
                    <input type="url" id="edit_image" class="w-full focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 p-3 rounded-xl text-xs font-semibold">
                </div>
                <div class="sm:col-span-2 flex justify-end gap-2 pt-2 border-t border-slate-200">
                    <button type="button" onclick="closeEditModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition cursor-pointer">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-indigo-600 text-white font-bold rounded-xl text-xs transition cursor-pointer">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Full Order Modal -->
    <div id="editOrderModal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-white border border-slate-100 rounded-2xl shadow-sm w-full max-w-2xl p-6 flex flex-col shadow-2xl max-h-[90vh]">
            <div class="flex justify-between items-center border-b border-slate-200 pb-3 mb-4">
                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Edit Order <span id="edit_order_title"></span></h3>
                <button onclick="closeEditOrderModal()" class="text-slate-400 hover:text-slate-600 transition"><i class="ph-bold ph-x"></i></button>
            </div>

            <div class="flex-1 overflow-y-auto space-y-4 pr-2">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Payment Method</label>
                        <select id="edit_order_method" class="w-full bg-slate-50 border border-slate-200 p-2 rounded-lg text-xs font-bold text-slate-700 outline-none focus:border-indigo-600 cursor-pointer">
                            <option value="cash">Cash Payment</option>
                            <option value="upi">UPI / QR Scan</option>
                            <option value="card">Card Terminal</option>
                            <option value="room_charge">Charge to Guest Folio (Room)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Delivery Status</label>
                        <select id="edit_order_status" class="w-full bg-slate-50 border border-slate-200 p-2 rounded-lg text-xs font-bold text-slate-700 outline-none focus:border-indigo-600 cursor-pointer">
                            <option value="delivered">Delivered</option>
                            <option value="pending">Pending</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Order Items</label>
                    <div id="edit_order_items_list" class="space-y-2">
                        <!-- Items populated via JS -->
                    </div>
                </div>

                <div class="mt-4 pt-4 border-t border-slate-200">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Add Item to Order</label>
                    <div class="flex gap-2">
                        <select id="edit_order_add_item_select" class="flex-1 bg-white border border-slate-200 p-2 rounded-lg text-xs font-semibold text-slate-800 outline-none focus:border-indigo-600 cursor-pointer">
                            <option value="">-- Choose Product --</option>
                            <?php foreach($inventoryItems as $item): ?>
                                <option value="<?= htmlspecialchars((string)($item['id']), ENT_QUOTES, 'UTF-8') ?>" data-price="<?= htmlspecialchars((string)($item['selling_price']), ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars((string)($item['name'])) ?>" data-stock="<?= htmlspecialchars((string)($item['stock_qty']), ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string)($item['name'])) ?> (₹<?= htmlspecialchars((string)($item['selling_price']), ENT_QUOTES, 'UTF-8') ?>) - Stock: <?= htmlspecialchars((string)($item['stock_qty']), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="addEditOrderItem()" class="px-4 py-2 bg-indigo-50 text-indigo-700 font-bold rounded-lg text-xs hover:bg-indigo-100 transition"><i class="ph-bold ph-plus"></i> Add</button>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-slate-200 flex justify-between items-center">
                <div class="text-sm font-bold text-slate-800">New Total: <span id="edit_order_grand_total" class="text-indigo-600 font-mono">₹0.00</span></div>
                <div class="flex gap-2">
                    <button type="button" onclick="closeEditOrderModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition cursor-pointer">Cancel</button>
                    <button type="button" onclick="saveEditOrder()" id="btn_save_edit_order" class="px-5 py-2.5 bg-indigo-600 text-white font-bold rounded-xl text-xs transition cursor-pointer flex items-center gap-2"><i class="ph-bold ph-floppy-disk"></i> Save Order Edit</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const POS_ITEMS = <?= json_encode($inventoryItems) ?>;
        let cart = [];
        let activeOutletFilter = null;

        function togglePosTab(tabId) {
            try {
                if (history.pushState) {
                    history.pushState(null, null, '#tab=' + tabId);
                }
            } catch (e) {}
            const tabs = ['register', 'orders', 'history', 'inventory', 'reports_orders', 'reports_restock', 'logs'];
            tabs.forEach(t => {
                const btn = document.getElementById('tabBtn-' + t);
                const sec = document.getElementById('posSec-' + t);
                if (btn) btn.className = 'flex-1 py-2 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-700 hover:bg-slate-50 shrink-0 px-3 cursor-pointer transition';
                if (sec) sec.classList.add('hidden');
            });
            const activeBtn = document.getElementById('tabBtn-' + tabId);
            const activeSec = document.getElementById('posSec-' + tabId);
            if (activeSec) activeSec.classList.remove('hidden');
            if (activeBtn) activeBtn.className = 'flex-1 py-2 rounded-xl text-xs font-bold bg-indigo-50 text-indigo-700 shrink-0 px-3 cursor-pointer transition';
        }

        function filterOutlet(outletId) {
            activeOutletFilter = outletId;
            
            const allBtn = document.getElementById('btn-outlet-all');
            if (outletId === null) {
                allBtn.className = 'px-4 py-2 rounded-full text-xs font-bold bg-indigo-600 text-white shadow transition cursor-pointer';
            } else {
                allBtn.className = 'px-4 py-2 rounded-full text-xs font-bold bg-white text-slate-700 border border-slate-200 hover:bg-slate-100 transition shrink-0 cursor-pointer';
            }

            const outletButtons = document.querySelectorAll('[id^="btn-outlet-"]');
            outletButtons.forEach(btn => {
                const id = btn.id.replace('btn-outlet-', '');
                if (id === 'all') return;
                
                if (parseInt(id) === outletId) {
                    btn.className = 'px-4 py-2 rounded-full text-xs font-bold bg-indigo-600 text-white shadow shrink-0 cursor-pointer';
                } else {
                    btn.className = 'px-4 py-2 rounded-full text-xs font-bold bg-white text-slate-700 border border-slate-200 hover:bg-slate-100 transition shrink-0 cursor-pointer';
                }
            });

            applyCatalogFilters();
        }

        function searchCatalog(query) {
            applyCatalogFilters();
        }

        function applyCatalogFilters() {
            const query = document.querySelector('[placeholder="Search products by name or SKU..."]').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.product-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const sku = card.getAttribute('data-sku');
                const outletId = parseInt(card.getAttribute('data-outlet-id'));

                const matchesSearch = name.includes(query) || sku.includes(query);
                const matchesOutlet = (activeOutletFilter === null) || (outletId === activeOutletFilter);

                if (matchesSearch && matchesOutlet) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function addToCart(item) {
            const existing = cart.find(c => c.id === item.id);
            if (existing) {
                if (existing.quantity >= item.stock_qty) {
                    showToast('Cannot add more: Limit exceeded stock level', 'error');
                    return;
                }
                existing.quantity++;
            } else {
                cart.push({ ...item, quantity: 1 });
            }
            renderCart();
        }

        function changeCartQty(itemId, val) {
            const item = cart.find(c => c.id === itemId);
            if (!item) return;

            item.quantity += val;
            if (item.quantity <= 0) {
                cart = cart.filter(c => c.id !== itemId);
            } else {
                const dbItem = POS_ITEMS.find(p => p.id === itemId);
                if (dbItem && item.quantity > dbItem.stock_qty) {
                    showToast('Limit exceeded stock level', 'error');
                    item.quantity = dbItem.stock_qty;
                }
            }
            renderCart();
        }

        async function deleteOrderPOS(orderId) {
            if (!confirm('Are you sure you want to permanently delete this order? This will restock the items and remove any charges from the guest folio or finance records.')) return;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'delete_order', order_id: orderId })
                });
                const data = await res.json();
                
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (e) {
                console.error(e);
                showToast('Network error deleting order.', 'error');
            }
        }

        function clearCart() {
            cart = [];
            renderCart();
        }

        function renderCart() {
            const container = document.getElementById('cart-items');
            if (cart.length === 0) {
                container.innerHTML = '<p class="text-xs text-slate-400 font-semibold text-center py-8">Your cart is empty.</p>';
                document.getElementById('cart-subtotal').textContent = '₹0.00';
                document.getElementById('cart-total').textContent = '₹0.00';
                return;
            }

            let subtotal = 0;
            container.innerHTML = cart.map(item => {
                const totalItemPrice = parseFloat(item.selling_price) * item.quantity;
                subtotal += totalItemPrice;
                return `
                    <div class="flex justify-between items-center text-xs font-semibold p-2 bg-slate-50 rounded-xl border border-slate-200">
                        <div class="max-w-[150px]">
                            <span class="text-slate-800 block truncate font-bold">${item.name}</span>
                            <span class="text-[10px] text-slate-500 font-mono">₹${parseFloat(item.selling_price).toFixed(2)}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button onclick="changeCartQty(${item.id}, -1)" class="w-6 h-6 rounded-full bg-white border border-slate-200 text-slate-600 flex items-center justify-center hover:bg-slate-50 cursor-pointer"><i class="ph ph-minus text-[10px]"></i></button>
                            <span class="font-mono font-bold text-slate-800 text-xs">${item.quantity}</span>
                            <button onclick="changeCartQty(${item.id}, 1)" class="w-6 h-6 rounded-full bg-white border border-slate-200 text-slate-600 flex items-center justify-center hover:bg-slate-50 cursor-pointer"><i class="ph ph-plus text-[10px]"></i></button>
                        </div>
                    </div>
                `;
            }).join('');

            document.getElementById('cart-subtotal').textContent = '₹' + subtotal.toFixed(2);
            document.getElementById('cart-total').textContent = '₹' + subtotal.toFixed(2);
        }

        function toggleCheckoutMethod(val) {
            const roomPicker = document.getElementById('room-charge-picker');
            if (val === 'room_charge') {
                roomPicker.classList.remove('hidden');
            } else {
                roomPicker.classList.add('hidden');
            }
        }

        async function submitCheckout() {
            if (cart.length === 0) {
                showToast('Your cart is empty', 'error');
                return;
            }

            const method = document.getElementById('checkout_method').value;
            const bookingId = document.getElementById('checkout_booking_id').value;

            if (method === 'room_charge' && !bookingId) {
                showToast('Please select a room to charge', 'error');
                return;
            }

            const outletId = cart[0].outlet_id;

            const payload = {
                action: 'create_pos_order',
                method: method,
                outlet_id: outletId,
                booking_id: method === 'room_charge' ? bookingId : null,
                items: cart.map(c => ({ id: c.id, quantity: c.quantity }))
            };

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('POS Order created successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Checkout failed', 'error');
                }
            } catch(e) {
                showToast('Network error during checkout', 'error');
            }
        }

        async function submitNewItem(e) {
            e.preventDefault();
            const payload = {
                action: 'add_inventory_item',
                name: document.getElementById('add_name').value.trim(),
                sku: document.getElementById('add_sku').value.trim(),
                outlet_id: parseInt(document.getElementById('add_outlet_id').value),
                stock_qty: parseInt(document.getElementById('add_stock').value),
                cost_price: parseFloat(document.getElementById('add_cost').value),
                selling_price: parseFloat(document.getElementById('add_selling').value),
                low_stock_threshold: parseInt(document.getElementById('add_threshold').value),
                image_url: document.getElementById('add_image').value.trim()
            };

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Product added successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Failed to add product', 'error');
                }
            } catch(e) {
                showToast('Network error adding product', 'error');
            }
        }

        async function submitNewOutlet(e) {
            e.preventDefault();
            const payload = {
                action: 'add_outlet',
                name: document.getElementById('outlet_name').value.trim()
            };
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Outlet shop configured!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Failed to create shop', 'error');
                }
            } catch(e) {
                showToast('Network error');
            }
        }

        async function updateOrderStatus(orderId, status) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'update_order_status', order_id: orderId, status: status })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Order status updated!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message);
                }
            } catch(e) {
                showToast('Connection error');
            }
        }

        function openRestockModal(item) {
            document.getElementById('restock_item_id').value = item.id;
            document.getElementById('restock_prod_name').textContent = item.name + ' (In stock: ' + item.stock_qty + ')';
            document.getElementById('restockModal').classList.remove('hidden');
        }

        function closeRestockModal() {
            document.getElementById('restockModal').classList.add('hidden');
        }

        function openEditModal(item) {
            document.getElementById('edit_item_id').value = item.id;
            document.getElementById('edit_name').value = item.name;
            document.getElementById('edit_sku').value = item.sku || '';
            document.getElementById('edit_outlet_id').value = item.outlet_id || '';
            document.getElementById('edit_stock').value = item.stock_qty;
            document.getElementById('edit_threshold').value = item.low_stock_threshold;
            document.getElementById('edit_cost').value = item.cost_price;
            document.getElementById('edit_selling').value = item.selling_price;
            document.getElementById('edit_image').value = item.image_url || '';
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        async function submitEditItem(e) {
            e.preventDefault();
            const payload = {
                action: 'edit_inventory_item',
                item_id: parseInt(document.getElementById('edit_item_id').value),
                name: document.getElementById('edit_name').value.trim(),
                sku: document.getElementById('edit_sku').value.trim(),
                outlet_id: document.getElementById('edit_outlet_id').value ? parseInt(document.getElementById('edit_outlet_id').value) : null,
                stock_qty: parseInt(document.getElementById('edit_stock').value),
                cost_price: parseFloat(document.getElementById('edit_cost').value),
                selling_price: parseFloat(document.getElementById('edit_selling').value),
                low_stock_threshold: parseInt(document.getElementById('edit_threshold').value),
                image_url: document.getElementById('edit_image').value.trim()
            };

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Product updated!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Update failed', 'error');
                }
            } catch(e) {
                showToast('Network error', 'error');
            }
        }

        async function submitRestock(e) {
            e.preventDefault();
            const payload = {
                action: 'restock_item',
                item_id: parseInt(document.getElementById('restock_item_id').value),
                quantity: parseInt(document.getElementById('restock_qty').value)
            };

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Restocked successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Restock failed', 'error');
                }
            } catch(e) {
                showToast('Network error during restock', 'error');
            }
        }

        async function deleteOutlet(outletId) {
            if (!confirm('Are you sure you want to delete this shop? Connected inventory products will be moved to General.')) return;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'delete_outlet', outlet_id: outletId })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Shop deleted successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Delete failed', 'error');
                }
            } catch(e) {
                showToast('Network error');
            }
        }

        async function savePosSettings(e) {
            e.preventDefault();
            const tax = document.getElementById('cfg_tax').value;
            const alertLevel = document.getElementById('cfg_alert').value;
            const autoCharge = document.getElementById('cfg_autocharge').value;
            
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({
                        action: 'save_pos_settings',
                        POS_DEFAULT_TAX: tax,
                        POS_LOW_STOCK_DEFAULT: alertLevel,
                        POS_AUTO_POST_ROOM: autoCharge
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('POS settings updated!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Update failed', 'error');
                }
            } catch(e) {
                showToast('Network error');
            }
        }
        
        let editOrderCart = [];
        let editingOrderId = 0;

        async function editOrder(orderId) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'get_order_full', order_id: orderId })
                });
                const data = await res.json();
                if (data.success) {
                    editingOrderId = orderId;
                    document.getElementById('edit_order_title').textContent = '#' + orderId;
                    document.getElementById('edit_order_method').value = data.order.payment_method;
                    document.getElementById('edit_order_status').value = data.order.delivery_status;
                    
                    editOrderCart = data.items.map(i => ({
                        id: parseInt(i.item_id),
                        name: i.product_name,
                        price: parseFloat(i.price_per_unit),
                        quantity: parseInt(i.quantity),
                        max_stock: parseInt(i.current_stock) + parseInt(i.quantity)
                    }));
                    
                    renderEditOrderCart();
                    document.getElementById('editOrderModal').classList.remove('hidden');
                } else {
                    showToast(data.message || 'Failed to fetch order details', 'error');
                }
            } catch(e) {
                showToast('Network error');
            }
        }

        function closeEditOrderModal() {
            document.getElementById('editOrderModal').classList.add('hidden');
            editingOrderId = 0;
            editOrderCart = [];
        }

        function renderEditOrderCart() {
            const container = document.getElementById('edit_order_items_list');
            container.innerHTML = '';
            let total = 0;

            if (editOrderCart.length === 0) {
                container.innerHTML = '<p class="text-xs text-slate-500 font-semibold py-2">No items in order.</p>';
            }

            editOrderCart.forEach((item, index) => {
                const lineTotal = item.price * item.quantity;
                total += lineTotal;
                container.innerHTML += `
                    <div class="flex justify-between items-center bg-slate-50 p-3 rounded-lg border border-slate-200">
                        <div class="flex-1">
                            <p class="text-xs font-bold text-slate-800">${item.name}</p>
                            <p class="text-[10px] font-semibold text-slate-500">₹${item.price.toFixed(2)} × ${item.quantity} = <span class="font-bold text-slate-800">₹${lineTotal.toFixed(2)}</span></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="updateEditOrderQty(${index}, -1)" class="w-6 h-6 rounded bg-white border border-slate-300 flex items-center justify-center hover:bg-slate-100 transition"><i class="ph-bold ph-minus text-[10px]"></i></button>
                            <span class="text-xs font-bold w-6 text-center">${item.quantity}</span>
                            <button type="button" onclick="updateEditOrderQty(${index}, 1)" class="w-6 h-6 rounded bg-white border border-slate-300 flex items-center justify-center hover:bg-slate-100 transition"><i class="ph-bold ph-plus text-[10px]"></i></button>
                            <button type="button" onclick="removeEditOrderItem(${index})" class="w-6 h-6 rounded bg-red-50 text-red-600 flex items-center justify-center ml-2 hover:bg-red-100 transition"><i class="ph-bold ph-trash text-[10px]"></i></button>
                        </div>
                    </div>
                `;
            });
            document.getElementById('edit_order_grand_total').textContent = '₹' + total.toFixed(2);
        }



        function updateEditOrderQty(index, change) {
            const item = editOrderCart[index];
            const newQty = item.quantity + change;
            if (newQty < 1) {
                removeEditOrderItem(index);
                return;
            }
            if (item.max_stock !== undefined && newQty > item.max_stock) {
                showToast(`Only ${item.max_stock} units available in stock`, 'error');
                return;
            }
            item.quantity = newQty;
            renderEditOrderCart();
        }

        function removeEditOrderItem(index) {
            editOrderCart.splice(index, 1);
            renderEditOrderCart();
        }

        function addEditOrderItem() {
            const select = document.getElementById('edit_order_add_item_select');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) return;

            const id = parseInt(opt.value);
            const price = parseFloat(opt.dataset.price);
            const name = opt.dataset.name;
            const stock = parseInt(opt.dataset.stock);

            if (stock <= 0) {
                showToast('Item is out of stock', 'error');
                return;
            }

            const existingIndex = editOrderCart.findIndex(i => parseInt(i.id) === id);
            if (existingIndex >= 0) {
                updateEditOrderQty(existingIndex, 1);
            } else {
                editOrderCart.push({
                    id: id,
                    name: name,
                    price: price,
                    quantity: 1,
                    max_stock: stock
                });
                renderEditOrderCart();
            }
            select.value = '';
        }

        async function saveEditOrder() {
            if (editOrderCart.length === 0) {
                showToast('Order must have at least one item', 'error');
                return;
            }

            const btn = document.getElementById('btn_save_edit_order');
            btn.innerHTML = '<i class="ph-bold ph-spinner animate-spin"></i> Saving...';
            btn.disabled = true;

            const method = document.getElementById('edit_order_method').value;
            const status = document.getElementById('edit_order_status').value;

            const payload = {
                action: 'edit_order_full',
                order_id: editingOrderId,
                payment_method: method,
                delivery_status: status,
                items: editOrderCart.map(i => ({ id: i.id, quantity: i.quantity }))
            };

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Order updated successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || 'Update failed', 'error');
                    btn.innerHTML = '<i class="ph-bold ph-floppy-disk"></i> Save Order Edit';
                    btn.disabled = false;
                }
            } catch(e) {
                showToast('Network error');
                btn.innerHTML = '<i class="ph-bold ph-floppy-disk"></i> Save Order Edit';
                btn.disabled = false;
            }
        }
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const editOrderId = params.get('edit_order');
            if (editOrderId) {
                togglePosTab('orders');
                editOrder(editOrderId);
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
        function toggleCustomDate(type) {
            const filter = document.getElementById(type + 'ReportFilter').value;
            const container = document.getElementById(type + 'CustomDate');
            if (filter === 'custom') {
                container.classList.remove('hidden');
            } else {
                container.classList.add('hidden');
            }
        }

        async function fetchOrderReports() {
            const filter = document.getElementById('ordersReportFilter').value;
            const payload = { action: 'get_order_tracking', filter };
            if (filter === 'custom') {
                payload.start_date = document.getElementById('ordersStartDate').value;
                payload.end_date = document.getElementById('ordersEndDate').value;
                if (!payload.start_date || !payload.end_date) return;
            }
            try {
                const res = await fetch('/api/admin/pos_reports', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= CsrfToken::generate() ?>' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('ordersTotalCount').textContent = data.summary.total_orders;
                    document.getElementById('ordersTotalSales').textContent = '₹' + parseFloat(data.summary.total_sales).toFixed(2);
                    
                    const tbody = document.getElementById('ordersReportTableBody');
                    tbody.innerHTML = '';
                    data.data.forEach(o => {
                        tbody.innerHTML += `
                            <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/50">
                                <td class="p-3 font-mono font-bold text-slate-800">#${o.id}</td>
                                <td class="p-3 text-xs text-slate-500">${new Date(o.created_at).toLocaleString()}</td>
                                <td class="p-3 font-bold text-indigo-600 font-mono">₹${parseFloat(o.total_amount).toFixed(2)}</td>
                                <td class="p-3 text-xs uppercase font-bold text-slate-500">${o.status}</td>
                            </tr>
                        `;
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function fetchRestockReports() {
            const filter = document.getElementById('restockReportFilter').value;
            const payload = { action: 'get_restock_history', filter };
            if (filter === 'custom') {
                payload.start_date = document.getElementById('restockStartDate').value;
                payload.end_date = document.getElementById('restockEndDate').value;
                if (!payload.start_date || !payload.end_date) return;
            }
            try {
                const res = await fetch('/api/admin/pos_reports', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= CsrfToken::generate() ?>' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('restockTotalItems').textContent = data.summary.total_items;
                    document.getElementById('restockTotalCost').textContent = '₹' + parseFloat(data.summary.total_cost).toFixed(2);
                    
                    const tbody = document.getElementById('restockReportTableBody');
                    tbody.innerHTML = '';
                    data.data.forEach(r => {
                        tbody.innerHTML += `
                            <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/50">
                                <td class="p-3 text-xs text-slate-500">${new Date(r.created_at).toLocaleString()}</td>
                                <td class="p-3 font-bold text-slate-800">
                                    ${r.item_name} <br>
                                    <span class="text-[10px] text-slate-400 font-mono">${r.sku || ''}</span>
                                </td>
                                <td class="p-3 font-bold text-emerald-600">+${r.qty_added}</td>
                                <td class="p-3 font-mono font-bold text-slate-700">₹${parseFloat(r.cost_price).toFixed(2)}</td>
                                <td class="p-3 text-xs text-slate-500">${r.restocked_by_name || 'Unknown'}</td>
                            </tr>
                        `;
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }

        // Load active tab from URL or fallback to register
        // Load active tab from URL or fallback to register
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const hashTab = window.location.hash.match(/#tab=([^&]+)/);
            const queryTab = urlParams.get('tab');
            const defaultTab = hashTab ? hashTab[1] : (queryTab || 'register');
            togglePosTab(defaultTab);
            
            if(defaultTab === 'reports_orders') fetchOrderReports();
            if(defaultTab === 'reports_restock') fetchRestockReports();
        });

        // Order Notification Polling
        let lastOrderId = <?= htmlspecialchars((string)(!empty($pendingOrders) ? (int)$pendingOrders[0]['id'] : (!empty($orderHistory) ? (int)$orderHistory[0]['id'] : 0)), ENT_QUOTES, 'UTF-8') ?>;
        
        function playNotificationBeep() {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(523.25, audioCtx.currentTime); // C5
                gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
                osc.start(audioCtx.currentTime);
                osc.stop(audioCtx.currentTime + 0.15);
                
                setTimeout(() => {
                    const osc2 = audioCtx.createOscillator();
                    const gain2 = audioCtx.createGain();
                    osc2.connect(gain2);
                    gain2.connect(audioCtx.destination);
                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(659.25, audioCtx.currentTime); // E5
                    gain2.gain.setValueAtTime(0.1, audioCtx.currentTime);
                    osc2.start(audioCtx.currentTime);
                    osc2.stop(audioCtx.currentTime + 0.2);
                }, 200);
            } catch(e) { console.error('Beep failed', e); }
        }

        setInterval(async () => {
            try {
                const res = await fetch('/api/admin/pos_actions', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?= CsrfToken::generate() ?>' },
                    body: JSON.stringify({ action: 'check_new_orders', last_id: lastOrderId })
                });
                const data = await res.json();
                if (data.success && data.new_count > 0) {
                    lastOrderId = data.latest_id;
                    playNotificationBeep();
                    showToast(`You have ${data.new_count} new POS order(s)!`, 'info');
                    
                    // Add badge to tab if not active
                    const ordersTab = document.getElementById('tabBtn-orders');
                    if (ordersTab && !ordersTab.className.includes('bg-indigo-50')) {
                        const existingBadge = ordersTab.querySelector('.animate-bounce');
                        if (existingBadge) {
                            existingBadge.textContent = parseInt(existingBadge.textContent) + data.new_count;
                        } else {
                            ordersTab.innerHTML += `<span class="absolute -top-1 -right-1 w-4 h-4 bg-rose-500 text-white rounded-full flex items-center justify-center text-[8px] font-black animate-bounce">${data.new_count}</span>`;
                        }
                    } else if (ordersTab && ordersTab.className.includes('bg-indigo-50')) {
                        setTimeout(() => location.reload(), 1500); // Reload if they are already on the orders tab
                    }
                }
            } catch(e) { }
        }, 15000); // Poll every 15 seconds
    </script>
</body>
</html>
