<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
CsrfToken::checkTimeout();

if (isset($_GET['hotelId'])) {
    AuthHelper::setPropertyId((int)$_GET['hotelId']);
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
$db = Database::getInstance()->getConnection();
$todayStr = date('Y-m-d');
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$staffName = $_SESSION['username'] ?? 'Admin';
$staffName = ucfirst(strtolower($staffName));

$propertyId = AuthHelper::getPropertyId();

// Fetch all bookings for active display (include pending_amount via folio)
$allBookings = $db->prepare("
    SELECT b.*, r.room_number, c.name as category_name, g.name as guest_name, g.phone as guest_phone, g.photo as guest_photo,
        COALESCE((
            SELECT SUM(fl.amount) FROM folio_ledger fl WHERE fl.booking_id = b.id
        ), 0) AS ledger_balance
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_categories c ON r.category_id = c.id 
    LEFT JOIN guests g ON b.guest_id = g.id 
    WHERE b.property_id = :pid
    ORDER BY b.check_in ASC
");
$allBookings->execute(['pid' => $propertyId]);
$allBookings = $allBookings->fetchAll();

// Categorize bookings for summary counts
$summaryCounts = [
    'active' => 0,
    'in_house' => 0,
    'arrivals' => 0,
    'departures' => 0,
    'cancelled' => 0,
    'on_hold' => 0
];

foreach ($allBookings as $b) {
    $biDate = substr($b['check_in'], 0, 10);
    $boDate = substr($b['check_out'], 0, 10);
    $status = $b['booking_status'];
    $paymentStatus = $b['payment_status'];

    if ($status === 'booked' || $status === 'checked_in') $summaryCounts['active']++;
    if ($status === 'checked_in') $summaryCounts['in_house']++;
    if ($status === 'booked' && $biDate === $todayStr) $summaryCounts['arrivals']++;
    if ($status === 'checked_in' && $boDate === $todayStr) $summaryCounts['departures']++;
    if ($status === 'cancelled') $summaryCounts['cancelled']++;
    if ($paymentStatus === 'pending_hold') $summaryCounts['on_hold']++;
}

// Occupancy KPI: total rooms vs occupied today
$trStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = :pid");
$trStmt->execute(['pid' => $propertyId]);
$totalRoomsCount = (int)$trStmt->fetchColumn();

$occStmt = $db->prepare("
    SELECT COUNT(DISTINCT room_id) FROM bookings 
    WHERE property_id = :pid
      AND ((booking_status = 'checked_in')
       OR (booking_status = 'booked' AND DATE(check_in) <= :today1 AND DATE(check_out) > :today2))
");
$occStmt->execute(['pid' => $propertyId, 'today1' => $todayStr, 'today2' => $todayStr]);
$occupiedTodayCount = (int)$occStmt->fetchColumn();
$occupancyPct = $totalRoomsCount > 0 ? round(($occupiedTodayCount / $totalRoomsCount) * 100) : 0;

// Revenue KPI: today's payments collected
$revStmt = $db->prepare("
    SELECT COALESCE(SUM(ABS(fl.amount)),0) FROM folio_ledger fl 
    JOIN bookings b ON fl.booking_id = b.id
    WHERE fl.amount < 0 AND DATE(fl.recorded_at) = :today AND b.property_id = :pid
");
$revStmt->execute(['today' => $todayStr, 'pid' => $propertyId]);
$revenueToday = (float)$revStmt->fetchColumn();

// Fetch Room Categories and availability in optimized bulk queries (No N+1)
$catStmt = $db->prepare("SELECT * FROM room_categories WHERE property_id = :pid ORDER BY name ASC");
$catStmt->execute(['pid' => $propertyId]);
$categories = $catStmt->fetchAll();

$roomCounts = [];
$rcStmt = $db->prepare("SELECT category_id, COUNT(*) as total FROM rooms WHERE property_id = :pid GROUP BY category_id");
$rcStmt->execute(['pid' => $propertyId]);
foreach ($rcStmt->fetchAll() as $row) {
    $roomCounts[$row['category_id']] = (int)$row['total'];
}

$occCounts = [];
$occStmtBatch = $db->prepare("
    SELECT r.category_id, COUNT(DISTINCT b.room_id) as occupied
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    WHERE b.property_id = :pid
      AND b.booking_status IN ('booked', 'checked_in')
      AND DATE(b.check_in) <= :today1 AND DATE(b.check_out) > :today2
    GROUP BY r.category_id
");
$occStmtBatch->execute(['pid' => $propertyId, 'today1' => $todayStr, 'today2' => $todayStr]);
foreach ($occStmtBatch->fetchAll() as $row) {
    $occCounts[$row['category_id']] = (int)$row['occupied'];
}

$prices = [];
$priceStmtBatch = $db->query("SELECT category_id, price FROM sliding_rates WHERE hours = 24");
foreach ($priceStmtBatch->fetchAll() as $row) {
    $prices[$row['category_id']] = (float)$row['price'];
}

$availabilityData = [];
foreach ($categories as $cat) {
    $catId = $cat['id'];
    $total = $roomCounts[$catId] ?? 0;
    $occupied = $occCounts[$catId] ?? 0;
    $available = max(0, $total - $occupied);
    $catOccPct = $total > 0 ? round(($occupied / $total) * 100) : 0;

    $price = $prices[$catId] ?? 1500.00;

    $availabilityData[] = [
        'name' => $cat['name'],
        'total' => $total,
        'occupied' => $occupied,
        'available' => $available,
        'occ_pct' => $catOccPct,
        'price' => $price
    ];
}

// Active Property resolution for SaaS view
$activePropertyId = AuthHelper::getPropertyId();
$propName = '';
if ($activePropertyId > 1) {
    $propStmt = $db->prepare("SELECT name FROM properties WHERE id = ?");
    $propStmt->execute([$activePropertyId]);
    $propName = $propStmt->fetchColumn() ?: 'Secondary Property';
}

$hotelName = !empty($propName) ? $propName : (defined('PROPERTY_NAME') ? PROPERTY_NAME : 'MicroPMS');
$hotelLogo = defined('PROPERTY_LOGO_BASE64') ? PROPERTY_LOGO_BASE64 : '';

// Fetch all rooms for housekeeping quick list (filtered by property_id)
$hkStmt = $db->prepare("SELECT r.*, c.name as category_name FROM rooms r JOIN room_categories c ON r.category_id = c.id WHERE r.property_id = ? ORDER BY r.room_number ASC");
$hkStmt->execute([$activePropertyId]);
$housekeepingRooms = $hkStmt->fetchAll();
$dirtyCount = count(array_filter($housekeepingRooms, fn($r) => $r['state'] === 'dirty'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Property Dashboard | MicroPMS</title>
    
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <link rel="stylesheet" href="../css/style.css">
    
    <style>
        /* StayFlexi Metric Counters Row */
        .metric-tile {
            flex: 1;
            min-width: 130px;
            background: #FFF;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }
        .metric-tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border-color: #CBD5E1;
        }
        .metric-tile.active {
            border-color: #1E3A8A;
            background: #EFF6FF;
            box-shadow: 0 4px 6px -1px rgba(30,58,138,0.10);
        }
        .metric-tile.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #1E3A8A, #3B82F6);
            border-radius: 0 0 16px 16px;
        }
        
        .guest-card {
            background: #FFF;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 18px;
            transition: all 0.15s ease;
        }
        .guest-card:hover {
            border-color: #CBD5E1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-slate-50/50">
    <?php if ($activePropertyId > 1 && AuthHelper::isSuperAdmin()): ?>
        <div class="bg-blue-700 text-white px-4 py-2.5 text-center text-xs font-bold flex items-center justify-center gap-2">
            <i class="ph ph-eye"></i>
            <span>SaaS VIEW: Currently viewing dashboard for <strong><?= htmlspecialchars($hotelName) ?></strong> (Property ID: <?= $activePropertyId ?>)</span>
            <form method="POST" action="/saas-admin/index.php" class="inline">
                <input type="hidden" name="action" value="switch_context">
                <input type="hidden" name="property_id" value="1">
                <button type="submit" class="underline text-yellow-300 ml-2 hover:text-yellow-100 transition">Switch back to Primary Hotel</button>
            </form>
        </div>
    <?php endif; ?>
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto pb-24 md:pb-6">
        
        <!-- App Bar / Top Navigation -->
        <header class="bg-white/90 backdrop-blur-md px-6 py-3.5 flex items-center justify-between border-b border-slate-200/80 sticky top-0 z-50 shadow-xs mb-6">
            <div class="flex items-center gap-3">
                <?php if($hotelLogo): ?>
                <img src="data:image/png;base64,<?= htmlspecialchars($hotelLogo) ?>" alt="Logo" class="w-10 h-10 rounded-xl object-cover shadow-xs border border-slate-200">
                <?php else: ?>
                <div class="w-10 h-10 bg-brand-900 rounded-xl flex items-center justify-center text-white shadow-sm shadow-brand-900/20">
                    <i class="ph ph-buildings text-xl"></i>
                </div>
                <?php endif; ?>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base font-bold text-slate-900 leading-tight font-display"><?= htmlspecialchars($hotelName) ?></h1>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Live
                        </span>
                    </div>
                    <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider block">Property Dashboard</span>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <!-- User Session Pill -->
                <div class="hidden lg:flex items-center gap-2.5 px-3 py-1.5 rounded-xl bg-slate-50 border border-slate-200/80 text-xs font-semibold text-slate-700">
                    <div class="w-6 h-6 rounded-lg bg-brand-100 text-brand-800 font-bold flex items-center justify-center text-[10px] uppercase font-display">
                        <?= strtoupper(substr($staffName, 0, 2)) ?>
                    </div>
                    <span><?= htmlspecialchars($staffName) ?></span>
                    <span class="text-[9px] uppercase tracking-wider font-extrabold px-1.5 py-0.5 rounded-md bg-slate-200/60 text-slate-600"><?= htmlspecialchars(AuthHelper::getRole() ?? 'staff') ?></span>
                </div>

                <?php if(AuthHelper::can('create_booking')): ?>
                <a href="../booking_wizard.php" target="_blank" class="hidden sm:inline-flex btn-minimal text-xs py-2 px-3.5 gap-1.5 rounded-xl">
                    <i class="ph ph-plus-circle text-base"></i> New Reservation
                </a>
                <?php endif; ?>
                <?php include __DIR__ . '/components/desktop_nav.php'; ?>
            </div>
        </header>
        
        <main class="px-6 space-y-6">
            <!-- Greeting & Search Banner -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-extrabold text-slate-900 tracking-tight font-display flex items-center gap-2"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($staffName) ?> <i class="ph ph-hand-waving text-amber-500 text-2xl"></i></h2>
                    <p class="text-xs text-slate-500 font-semibold mt-0.5">Property summary for <span class="text-slate-800 font-bold"><?= date('l, d M Y') ?></span></p>
                </div>
                <div class="relative w-full md:w-80">
                    <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg"></i>
                    <input type="text" id="dashboard-search" placeholder="Search bookings, guests... (Ctrl+K)"
                        class="w-full bg-white border border-slate-200 rounded-xl py-2.5 pl-10 pr-4 text-xs font-semibold text-slate-800 focus:outline-none focus:border-slate-900 focus:ring-0 focus:shadow-minimal transition-all">
                </div>
            </div>

            <!-- KPI Strip: Occupancy & Revenue -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="kpi-tile flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
                        <i class="ph ph-bed text-lg text-indigo-600"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Occupancy</p>
                        <p class="text-xl font-extrabold text-slate-900 leading-tight"><?= $occupancyPct ?>%</p>
                        <p class="text-[10px] text-slate-400"><?= $occupiedTodayCount ?>/<?= $totalRoomsCount ?> rooms</p>
                    </div>
                </div>
                <div class="kpi-tile flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center flex-shrink-0">
                        <i class="ph ph-currency-inr text-lg text-emerald-600"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Today's Revenue</p>
                        <p class="text-xl font-extrabold text-slate-900 leading-tight">₹<?= number_format($revenueToday) ?></p>
                        <p class="text-[10px] text-slate-400">collected today</p>
                    </div>
                </div>
                <div class="kpi-tile flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
                        <i class="ph ph-arrow-circle-down text-lg text-amber-600"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Arrivals Today</p>
                        <p class="text-xl font-extrabold text-slate-900 leading-tight"><?= $summaryCounts['arrivals'] ?></p>
                        <p class="text-[10px] text-slate-400">check-ins due</p>
                    </div>
                </div>
                <div class="kpi-tile flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-50 flex items-center justify-center flex-shrink-0">
                        <i class="ph ph-arrow-circle-up text-lg text-rose-600"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Departures</p>
                        <p class="text-xl font-extrabold text-slate-900 leading-tight"><?= $summaryCounts['departures'] ?></p>
                        <p class="text-[10px] text-slate-400">check-outs due</p>
                    </div>
                </div>
            </div>

            <!-- StayFlexi Summary Metric Tiles Row -->
            <div class="flex gap-3 overflow-x-auto pb-2 scroll-x">
                <div onclick="filterBookings('active', this)" class="metric-tile active">
                    <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">All Active</span>
                    <span class="text-2xl font-extrabold text-slate-800 mt-2 block"><?= $summaryCounts['active'] ?></span>
                </div>
                <div onclick="filterBookings('in_house', this)" class="metric-tile">
                    <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">In House</span>
                    <span class="text-2xl font-extrabold text-emerald-600 mt-2 block"><?= $summaryCounts['in_house'] ?></span>
                </div>
                <div onclick="filterBookings('arrivals', this)" class="metric-tile">
                    <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Arrivals</span>
                    <span class="text-2xl font-extrabold text-amber-600 mt-2 block"><?= $summaryCounts['arrivals'] ?></span>
                </div>
                <div onclick="filterBookings('departures', this)" class="metric-tile">
                    <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Departures</span>
                    <span class="text-2xl font-extrabold text-indigo-600 mt-2 block"><?= $summaryCounts['departures'] ?></span>
                </div>
                <div onclick="filterBookings('cancelled', this)" class="metric-tile">
                    <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Cancellations</span>
                    <span class="text-2xl font-extrabold text-rose-600 mt-2 block"><?= $summaryCounts['cancelled'] ?></span>
                </div>
                <div onclick="filterBookings('on_hold', this)" class="metric-tile">
                    <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">On Hold</span>
                    <span class="text-2xl font-extrabold text-slate-500 mt-2 block"><?= $summaryCounts['on_hold'] ?></span>
                </div>
            </div>

            <!-- Two Column Dashboard Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left: Interactive Bookings Cards List -->
                <div class="lg:col-span-2 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Reservations & Stays</h3>
                        <span id="list-count-badge" class="bg-indigo-100 text-indigo-700 text-[10px] font-bold px-2.5 py-0.5 rounded-full border border-indigo-200/50"></span>
                    </div>

                    <div id="bookings-cards-container" class="space-y-4">
                        <!-- Bookings list injected by JS -->
                    </div>
                </div>

                <!-- Right Sidebar Widgets -->
                <div class="space-y-6">
                    <!-- Actions Center -->
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden flex flex-col min-h-[250px]">
                        <div class="bg-slate-50/50 px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Actions Center</h3>
                                <span id="actions-count-badge" class="hidden bg-rose-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"></span>
                            </div>
                            <a href="actions.php" id="actions-view-all" class="hidden text-[10px] font-bold text-indigo-600 hover:underline">All →</a>
                        </div>
                        <div id="actions-container" class="p-4 space-y-3 overflow-y-auto max-h-[300px]">
                            <div class="flex items-center justify-center py-8 text-slate-400 text-xs">
                                <i class="ph ph-spinner animate-spin mr-2"></i> Loading...
                            </div>
                        </div>
                    </div>

                    <!-- Availability Today Widget -->
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                        <div class="bg-slate-50/50 px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                            <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Availability Today</h3>
                            <span class="text-[10px] font-bold text-slate-500"><?= $occupancyPct ?>% occupied</span>
                        </div>
                        <div class="p-4 space-y-3">
                            <?php foreach ($availabilityData as $avail): ?>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-bold text-slate-700"><?= htmlspecialchars($avail['name']) ?></span>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] font-semibold text-slate-400"><?= $avail['occupied'] ?>/<?= $avail['total'] ?></span>
                                        <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-full <?= $avail['available'] > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' ?>"><?= $avail['available'] ?> free</span>
                                        <span class="text-[10px] font-bold text-slate-500">₹<?= number_format($avail['price']) ?></span>
                                    </div>
                                </div>
                                <div class="occ-bar">
                                    <div class="occ-bar-fill <?= $avail['occ_pct'] >= 90 ? 'bg-rose-400' : ($avail['occ_pct'] >= 60 ? 'bg-amber-400' : 'bg-emerald-400') ?>" style="width:<?= $avail['occ_pct'] ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Housekeeping Widget -->
                    <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                        <div class="bg-slate-50/50 px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Room Cleaning</h3>
                                <?php if($dirtyCount > 0): ?>
                                <span class="text-[9px] font-bold bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full"><?= $dirtyCount ?> dirty</span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <?php if($dirtyCount > 0): ?>
                                <button onclick="markAllClean()" class="text-[10px] font-bold text-emerald-600 hover:text-emerald-800 hover:underline flex items-center gap-1">
                                    <i class="ph ph-sparkle text-xs"></i> Clean All
                                </button>
                                <?php endif; ?>
                                <a href="modules/housekeeping/rooms_calendar.php" class="text-[10px] font-bold text-slate-500 hover:text-slate-600">Grid →</a>
                            </div>
                        </div>
                        <div class="p-4 max-h-[360px] overflow-y-auto space-y-2">
                            <?php foreach ($housekeepingRooms as $room):
                                $isDirty = $room['state'] === 'dirty';
                                $isOOO   = $room['state'] === 'out_of_order';
                                if ($isDirty) {
                                    $badgeColor = 'text-amber-700 bg-amber-50 border-amber-100';
                                } elseif ($isOOO) {
                                    $badgeColor = 'text-red-700 bg-red-50 border-red-100';
                                } else {
                                    $badgeColor = 'text-emerald-700 bg-emerald-50 border-emerald-100';
                                }
                                $deepCleanFreq = (int)(defined('DEEP_CLEAN_FREQ_DAYS') ? DEEP_CLEAN_FREQ_DAYS : 15);
                                $needsDeepClean = false;
                                if ($deepCleanFreq > 0) {
                                    $lastDeepClean = !empty($room['last_deep_clean']) ? strtotime($room['last_deep_clean']) : 0;
                                    $needsDeepClean = (time() - $lastDeepClean) > ($deepCleanFreq * 86400);
                                }
                            ?>
                            <div class="flex items-center justify-between p-2.5 border border-slate-100 rounded-xl hover:shadow-sm transition-all bg-white" id="hk-room-<?= $room['id'] ?>">
                                <div>
                                    <span class="font-extrabold text-slate-800 text-xs">Room <?= htmlspecialchars($room['room_number']) ?></span>
                                    <span class="text-[9px] text-slate-400 block mt-0.5"><?= htmlspecialchars($room['category_name']) ?></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?php if ($needsDeepClean): ?>
                                    <span title="Requires Deep Clean" class="text-rose-500"><i class="ph ph-sparkle text-lg"></i></span>
                                    <?php endif; ?>
                                    <span class="text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded border <?= $badgeColor ?>"><?= str_replace('_', ' ', $room['state']) ?></span>
                                    <?php if ($isDirty): ?>
                                        <?php if ($needsDeepClean): ?>
                                        <button onclick="quickDeepCleanRoom(<?= $room['id'] ?>)" title="Mark Deep Cleaned" class="w-7 h-7 border border-rose-200 rounded-full bg-rose-500 text-white flex items-center justify-center hover:bg-rose-600 active:scale-95 transition-all shadow-sm">
                                            <i class="ph ph-sparkle text-xs"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button onclick="quickCleanRoom(<?= $room['id'] ?>)" title="Mark Clean" class="w-7 h-7 border border-amber-200 rounded-full bg-amber-500 text-white flex items-center justify-center hover:bg-amber-600 active:scale-95 transition-all shadow-sm">
                                            <i class="ph ph-broom text-xs"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const bookingsData = <?= json_encode($allBookings) ?>;
        let currentFilter = 'active';
        let searchQuery = '';
        let searchTimer = null;

        function toggleMenu(id) {
            const el = document.getElementById(id);
            el.classList.toggle('hidden');
        }

        // Ctrl+K focuses the search
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const inp = document.getElementById('dashboard-search');
                if (inp) { inp.focus(); inp.select(); }
            }
        });

        // Debounced search
        document.getElementById('dashboard-search').addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                searchQuery = this.value.toLowerCase().trim();
                renderBookingCards();
            }, 280);
        });

        document.addEventListener('click', (e) => {
            const menu = document.getElementById('desktop-menu');
            const wrap = document.getElementById('desktop-menu-wrap');
            if (menu && !menu.classList.contains('hidden') && !wrap.contains(e.target)) {
                menu.classList.add('hidden');
            }
        });

        // Filter bookings by metric tiles click
        function filterBookings(type, element) {
            currentFilter = type;
            document.querySelectorAll('.metric-tile').forEach(tile => tile.classList.remove('active'));
            if(element) element.classList.add('active');
            renderBookingCards();
        }

        function filterDashboardSearch(val) {
            searchQuery = val.toLowerCase().trim();
            renderBookingCards();
        }

        function renderBookingCards() {
            const container = document.getElementById('bookings-cards-container');
            const badge = document.getElementById('list-count-badge');
            const todayStr = '<?= $todayStr ?>';
            
            let filtered = bookingsData.filter(b => {
                // If there's a search term, search globally across all categories
                if (searchQuery) {
                    const guestName = (b.guest_name || '').toLowerCase();
                    const guestPhone = (b.guest_phone || '').toLowerCase();
                    const roomNum = (b.room_number || '').toLowerCase();
                    const bookCode = String(b.id);
                    return guestName.includes(searchQuery) || guestPhone.includes(searchQuery) || roomNum.includes(searchQuery) || bookCode.includes(searchQuery);
                }

                const biDate = b.check_in.substring(0, 10);
                const boDate = b.check_out.substring(0, 10);
                
                // Active filter rules
                if (currentFilter === 'active' && b.booking_status !== 'booked' && b.booking_status !== 'checked_in') return false;
                if (currentFilter === 'in_house' && b.booking_status !== 'checked_in') return false;
                if (currentFilter === 'arrivals' && (b.booking_status !== 'booked' || biDate !== todayStr)) return false;
                if (currentFilter === 'departures' && (b.booking_status !== 'checked_in' || boDate !== todayStr)) return false;
                if (currentFilter === 'cancelled' && b.booking_status !== 'cancelled') return false;
                if (currentFilter === 'on_hold' && b.payment_status !== 'pending_hold') return false;

                return true;
            });

            badge.textContent = `${filtered.length} booking(s)`;

            if (filtered.length === 0) {
                container.innerHTML = `<div class="bg-white border border-slate-100 rounded-2xl p-12 text-center text-slate-400 font-semibold"><i class="ph ph-calendar-x text-3xl mb-2 text-slate-300 block"></i> No bookings found for this category.</div>`;
                return;
            }

            container.innerHTML = filtered.map(b => {
                let statusClass = 'bg-amber-50 text-amber-800 border-amber-100';
                if (b.booking_status === 'checked_in') statusClass = 'bg-emerald-50 text-emerald-800 border-emerald-100';
                if (b.booking_status === 'checked_out') statusClass = 'bg-slate-100 text-slate-800 border-slate-200';
                if (b.booking_status === 'cancelled') statusClass = 'bg-rose-100 text-rose-800 border-rose-200';
                
                const initials = (b.guest_name || 'G').charAt(0).toUpperCase();
                const balance = parseFloat(b.ledger_balance) || 0;
                const balanceDue = balance > 0;
                const balanceHtml = balanceDue
                    ? `<span class="text-[9px] font-bold text-rose-600 bg-rose-50 border border-rose-100 px-2 py-0.5 rounded-full">₹${Math.round(balance)} due</span>`
                    : (balance < 0 ? `<span class="text-[9px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-100 px-2 py-0.5 rounded-full">Settled</span>` : '');
                
                // Calculate stay duration (nights or hours)
                const checkIn = new Date(b.check_in.replace(' ', 'T'));
                const checkOut = new Date(b.check_out.replace(' ', 'T'));
                const diffMs = checkOut - checkIn;
                const diffHrs = Math.max(0, Math.round(diffMs / (1000 * 60 * 60)));
                const diffDays = Math.max(0, Math.round(diffMs / (1000 * 60 * 60 * 24)));
                let durationText = '';
                if (diffDays >= 1) {
                    durationText = `(${diffDays} night${diffDays > 1 ? 's' : ''})`;
                } else {
                    durationText = `(${diffHrs} hour${diffHrs > 1 ? 's' : ''})`;
                }

                return `
                    <div class="guest-card flex flex-col md:flex-row justify-between items-start md:items-center gap-4 animate-fade-up">
                        <div class="flex items-center gap-3.5">
                            <div class="w-11 h-11 rounded-full bg-slate-100 border border-slate-200/50 flex items-center justify-center font-bold text-slate-700 text-sm overflow-hidden relative uppercase shrink-0 font-display">
                                ${b.guest_photo ? `<img src="/api/admin/view_document?file=${b.guest_photo}" class="w-full h-full object-cover">` : initials}
                            </div>
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h4 class="font-extrabold text-slate-800 text-sm font-display">${b.guest_name || 'Guest'}</h4>
                                    <span class="text-[9px] font-bold text-slate-500 border border-slate-200/50 px-1.5 py-0.5 rounded-md">#${b.id}</span>
                                    ${balanceHtml}
                                </div>
                                <p class="text-xs text-slate-400 mt-1 font-semibold flex items-center gap-1"><i class="ph ph-phone text-xs"></i> ${b.guest_phone || 'N/A'}</p>
                                <p class="text-[10px] text-slate-500 font-semibold mt-1 flex items-center gap-1">
                                    <i class="ph ph-calendar text-xs"></i> 
                                    ${b.check_in.substring(0, 16)} → ${b.check_out.substring(0, 16)} 
                                    <span class="text-indigo-600 font-extrabold ml-1">${durationText}</span>
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col items-start md:items-end gap-1.5 pl-14 md:pl-0">
                            <span class="text-xs font-bold text-slate-700 bg-slate-100 px-2.5 py-0.5 border border-slate-200/50 rounded-lg">Room ${b.room_number} <span class="text-slate-400 font-normal">(${b.category_name})</span></span>
                            <span class="text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 border rounded-lg ${statusClass}">${b.booking_status.replace('_',' ')}</span>
                        </div>
                        <div class="flex items-center gap-2 w-full md:w-auto pl-14 md:pl-0 border-t border-slate-50 md:border-0 pt-3 md:pt-0 justify-end">
                            ${b.booking_status === 'booked' ? `<button onclick="quickCheckin(${b.id})" class="px-4 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold text-xs rounded-xl transition-all border border-emerald-100"><i class="ph ph-sign-in"></i> Check In</button>` : ''}
                            <a href="folio.php?id=${b.id}" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition-colors">Folio</a>
                            ${b.booking_status === 'checked_in' ? `<button onclick="quickCheckout(${b.id})" class="px-4 py-2 border border-rose-200 text-rose-600 hover:bg-rose-50 font-bold text-xs rounded-xl transition-all">Check Out</button>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        async function quickCleanRoom(roomId, silent = false) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            try {
                const res = await fetch('/api/admin/room_action', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ room_id: roomId, action: 'mark_clean' })
                });
                const data = await res.json();
                if(data.success) {
                    const roomCard = document.getElementById('hk-room-' + roomId);
                    if (roomCard) {
                        roomCard.style.opacity = '0.5';
                        setTimeout(() => roomCard.remove(), 300);
                    }
                    if (!silent) showToast('Room marked clean!', 'success');
                    return true;
                } else {
                    if (!silent) showToast('Error: ' + data.message, 'error');
                    return false;
                }
            } catch(e) {
                if (!silent) showToast('Connection error', 'error');
                return false;
            }
        }

        async function quickDeepCleanRoom(roomId) {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            try {
                const res = await fetch('/api/admin/room_action', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ room_id: roomId, action: 'mark_deep_clean' })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Room marked as deep cleaned', 'success');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch (e) {
                showToast('Connection error', 'error');
            }
        }

        async function markAllClean() {
            if (!await pmsConfirm('Mark all dirty rooms as clean?', 'Mark All Clean', 'info')) return;
            // Collect all dirty room IDs from the DOM
            const dirtyBtns = document.querySelectorAll('[id^="hk-room-"] button[onclick^="quickCleanRoom"]');
            if (dirtyBtns.length === 0) { showToast('No dirty rooms found.', 'info'); return; }
            showLoading('Marking all rooms clean...');
            let count = 0;
            for (const btn of dirtyBtns) {
                const match = btn.getAttribute('onclick').match(/quickCleanRoom\((\d+)/);
                if (match) {
                    const ok = await quickCleanRoom(parseInt(match[1]), true);
                    if (ok) count++;
                }
            }
            hideLoading();
            showToast(`${count} room(s) marked clean!`, 'success');
        }

        async function quickCheckin(bookingId) {
            if (!await pmsConfirm('Process check-in for this booking?', 'Confirm Check-in', 'info')) return;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            showLoading('Processing check-in...');
            try {
                const res = await fetch('/api/admin/booking_status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ booking_id: bookingId, action: 'check_in', reason: 'Quick check-in from Property Dashboard' })
                });
                const data = await res.json();
                hideLoading();
                if(data.success) {
                    showToast('Guest checked in successfully!', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch(e) { hideLoading(); showToast('Connection error', 'error'); }
        }

        async function quickCheckout(bookingId) {
            const booking = bookingsData.find(b => parseInt(b.id) === parseInt(bookingId));
            const balance = booking ? parseFloat(booking.ledger_balance) : 0;
            if (balance > 0) {
                showToast(`Cannot check-out: Guest owes ₹${Math.round(balance)}. Please collect payment from Folio first.`, 'error');
                return;
            }

            if (!await pmsConfirm('Process checkout for this booking?', 'Confirm Check-out', 'warning')) return;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            showLoading('Processing checkout...');
            try {
                const res = await fetch('/api/admin/booking_status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ booking_id: bookingId, action: 'check_out', reason: 'Quick checkout from Property Dashboard' })
                });
                const data = await res.json();
                hideLoading();
                if(data.success) {
                    showToast('Checked out successfully!', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast('Error: ' + data.message, 'error');
                }
            } catch(e) { hideLoading(); showToast('Connection error', 'error'); }
        }

        // Actions Center
        const severityColors = {
            critical: { bg: 'bg-rose-50/50', border: 'border-rose-100', icon: 'text-rose-500', badge: 'bg-rose-100 text-rose-700' },
            warning: { bg: 'bg-amber-50/50', border: 'border-amber-100', icon: 'text-amber-500', badge: 'bg-amber-100 text-amber-700' },
            info: { bg: 'bg-indigo-50/50', border: 'border-indigo-100', icon: 'text-indigo-500', badge: 'bg-indigo-100 text-indigo-700' }
        };

        async function loadActions() {
            try {
                const res = await fetch('/api/admin/actions');
                const data = await res.json();
                const container = document.getElementById('actions-container');
                const badge = document.getElementById('actions-count-badge');
                const viewAll = document.getElementById('actions-view-all');

                if (!data.success || data.actions.length === 0) {
                    container.innerHTML = '<div class="bg-emerald-50/30 border border-emerald-100/50 rounded-xl p-4 text-center"><p class="text-[10px] font-bold text-emerald-700">No actions needed.</p></div>';
                    badge.classList.add('hidden');
                    viewAll.classList.add('hidden');
                    return;
                }

                const count = data.actions.length;
                badge.textContent = count;
                badge.classList.remove('hidden');
                viewAll.classList.remove('hidden');

                const shown = data.actions.slice(0, 3);
                container.innerHTML = shown.map(a => {
                    const c = severityColors[a.severity] || severityColors.info;
                    return `
                        <a href="${a.action_url}" class="block ${c.bg} border ${c.border} rounded-xl p-3 hover:shadow-sm active:scale-[0.99] transition-all">
                            <div class="flex items-start gap-2.5">
                                <div class="mt-0.5"><i class="ph ${a.icon} ${c.icon} text-base"></i></div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-[8px] font-bold uppercase tracking-wider ${c.badge} px-1.5 py-0.5 rounded-md inline-block mb-1">${a.title}</span>
                                    <p class="text-[10px] font-semibold text-slate-600 leading-relaxed">${a.message}</p>
                                </div>
                                <i class="ph ph-caret-right text-slate-400 mt-1"></i>
                            </div>
                        </a>
                    `;
                }).join('');
            } catch(e) {
                document.getElementById('actions-container').innerHTML = '<div class="text-center py-4 text-slate-400 text-xs">Could not load actions</div>';
            }
        }

        // Run on load
        renderBookingCards();
        loadActions();
        setInterval(loadActions, 15000);
        
        // Pause polling when tab is hidden
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) loadActions();
        });
    </script>

    <!-- Floating New Booking Button -->
    <a href="../assistant/index.html" class="fixed bottom-24 right-4 z-50 bg-indigo-600 text-white w-14 h-14 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-200 hover:bg-indigo-700 hover:shadow-xl active:scale-95 transition-all" title="New Walk-in Booking (Assistant)">
        <i class="ph ph-plus text-2xl"></i>
    </a>
</body>
</html>
