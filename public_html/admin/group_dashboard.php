<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login');
    exit;
}

$db = Database::getInstance()->getConnection();
$userId = (int)$_SESSION['user_id'];

// Fetch all hotels this user is mapped to with details
try {
    if (AuthHelper::isSuperAdmin()) {
        // Super-Admins have access to all hotels
        $stmt = $db->query("SELECT id as property_id, name, plan, address, city, state, is_active FROM properties ORDER BY id ASC");
        $assignedHotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("
            SELECT DISTINCT p.id as property_id, p.name, p.plan, p.address, p.city, p.state, p.is_active
            FROM properties p
            LEFT JOIN staff_properties sp ON p.id = sp.property_id AND sp.staff_id = ?
            JOIN staff_users su ON su.id = ?
            WHERE p.id = su.property_id OR sp.staff_id IS NOT NULL
        ");
        $stmt->execute([$userId, $userId]);
        $assignedHotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $assignedHotels = [];
}

if (count($assignedHotels) === 0) {
    die("Error: Your account is not assigned to any hotel properties. Contact the platform administrator.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Dashboard | MicroPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #f8fafc;
        }
        .sidebar-active {
            background-color: rgba(255, 255, 255, 0.08);
            border-left: 3px solid #3b82f6;
        }
    </style>
</head>
<body class="flex min-h-screen text-slate-800">

    <!-- LEFT SIDEBAR -->
    <aside class="w-64 bg-[#0f172a] text-slate-300 flex flex-col justify-between p-6 shrink-0">
        <div class="space-y-8">
            <!-- Brand Logo -->
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-extrabold text-lg">
                    M
                </div>
                <span class="font-extrabold text-white text-lg tracking-tight">MicroPMS</span>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-1.5">
                <a href="/group-dashboard" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-bold text-white sidebar-active">
                    <i class="ph ph-squares-four text-lg"></i>
                    Group dashboard
                </a>
                <a href="#" onclick="alert('Feature coming soon!')" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-semibold hover:bg-slate-800 transition">
                    <i class="ph ph-chart-bar text-lg text-slate-400"></i>
                    Group performance dashboard
                </a>
                <a href="#" onclick="alert('Feature coming soon!')" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-semibold hover:bg-slate-800 transition">
                    <i class="ph ph-calendar text-lg text-slate-400"></i>
                    Group reservation calendar
                </a>
                <a href="#" onclick="alert('Feature coming soon!')" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-semibold hover:bg-slate-800 transition">
                    <i class="ph ph-credit-card text-lg text-slate-400"></i>
                    Accounts and billing
                </a>
            </nav>
        </div>

        <!-- Logout Button -->
        <div>
            <a href="/admin/logout.php" class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-bold text-rose-400 hover:bg-rose-950/30 transition">
                <i class="ph ph-sign-out text-lg"></i>
                Logout
            </a>
        </div>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <main class="flex-1 p-8 overflow-y-auto">
        <!-- TOP ACTIONS & STATS -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Welcome to your group dashboard</h1>
                <p class="text-slate-500 text-sm mt-1">You have <?= htmlspecialchars((string)(count($assignedHotels)), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)(count($assignedHotels) === 1 ? 'property' : 'properties'), ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Search Box -->
                <div class="relative">
                    <i class="ph ph-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base"></i>
                    <input type="text" id="propertySearch" oninput="filterProperties()" placeholder="Search properties..." class="bg-white border border-slate-200 rounded-lg pl-10 pr-4 py-2 text-sm focus:border-indigo-500 focus:outline-none w-64 shadow-sm">
                </div>

                <!-- Active Filter -->
                <button class="flex items-center gap-1.5 px-3 py-2 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                    ACTIVE <i class="ph ph-funnel text-slate-400"></i>
                </button>

                <!-- Grid View Toggles -->
                <div class="flex bg-white border border-slate-200 rounded-lg p-0.5 shadow-sm">
                    <button class="p-1.5 bg-slate-100 rounded text-slate-700"><i class="ph ph-list text-base"></i></button>
                    <button class="p-1.5 text-slate-400"><i class="ph ph-squares-four text-base"></i></button>
                </div>

                <!-- Add Property Button -->
                <?php if (AuthHelper::isSuperAdmin()): ?>
                    <a href="/saas-admin/index.php" class="flex items-center gap-1.5 px-4 py-2 bg-black hover:bg-slate-900 text-white font-bold rounded-lg text-sm transition">
                        <i class="ph ph-plus font-bold"></i> Add Property
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- CARDS GRID -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="propertiesContainer">
            <?php foreach ($assignedHotels as $hotel): ?>
                <div class="bg-white rounded-2xl border border-slate-100 shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:shadow-[0_10px_30px_rgba(0,0,0,0.06)] overflow-hidden transition-all duration-300 group flex flex-col justify-between property-card" data-name="<?= htmlspecialchars(strtolower((string)$hotel['name'])) ?>" data-code="<?= htmlspecialchars(strtolower((string)$hotel['property_id'])) ?>">
                    
                    <div class="p-6 space-y-4">
                        <!-- Card Banner/Header with status -->
                        <div class="relative w-full h-40 bg-slate-50 rounded-xl overflow-hidden flex items-center justify-center border border-slate-100">
                            <!-- Status badge -->
                            <span class="absolute top-3 left-3 flex items-center gap-1 px-2.5 py-1 bg-white rounded-full text-[10px] font-bold text-emerald-600 shadow-sm">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                                Active
                            </span>
                            
                            <!-- Placeholder Logo graphics -->
                            <div class="text-center p-4">
                                <div class="w-14 h-14 rounded-full bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 font-black text-xl mx-auto shadow-sm">
                                    <?= htmlspecialchars((string)(substr($hotel['name'], 0, 1))) ?>
                                </div>
                                <div class="text-[10px] uppercase tracking-wider font-extrabold text-slate-400 mt-2"><?= htmlspecialchars((string)($hotel['plan'])) ?> tier</div>
                            </div>
                        </div>

                        <!-- Info details -->
                        <div class="space-y-1">
                            <h2 class="text-lg font-extrabold text-slate-900 group-hover:text-indigo-600 transition"><?= htmlspecialchars((string)($hotel['name'])) ?></h2>
                            <p class="text-xs font-bold text-slate-500 font-mono">ID: <?= htmlspecialchars((string)($hotel['property_id'])) ?></p>
                            <p class="text-xs text-slate-400 line-clamp-2 min-h-[2rem]">
                                <?= htmlspecialchars((string)($hotel['address'] ?: 'Address not configured')) ?><?= !empty($hotel['city']) ? ', ' . htmlspecialchars((string)($hotel['city'])) : '' ?>
                            </p>
                        </div>
                    </div>

                    <!-- Card footer button to open dashboard -->
                    <div class="p-6 pt-0">
                        <a href="index.php?hotelId=<?= htmlspecialchars((string)($hotel['property_id']), ENT_QUOTES, 'UTF-8') ?>" class="block w-full text-center py-2.5 bg-slate-50 hover:bg-indigo-600 hover:text-white border border-slate-100 hover:border-indigo-600 text-slate-700 font-bold rounded-xl text-xs transition duration-200">
                            Enter Dashboard
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- SEARCH FILTER SCRIPT -->
    <script>
        function filterProperties() {
            const query = document.getElementById('propertySearch').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.property-card');
            
            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const code = card.getAttribute('data-code');
                
                if (name.includes(query) || code.includes(query)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>

</body>
</html>
