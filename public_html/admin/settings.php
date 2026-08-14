<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
if (!AuthHelper::can('manage_settings')) {
    header('Location: /admin');
    exit;
}
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
$db = Database::getInstance()->getConnection();
load_db_settings($db);

$propId = AuthHelper::getPropertyId();

$categories = $db->prepare("SELECT * FROM room_categories WHERE property_id = ?");
$categories->execute([$propId]);
$categories = $categories->fetchAll();

$rooms = $db->prepare("SELECT r.*, c.name as category_name FROM rooms r JOIN room_categories c ON r.category_id = c.id WHERE r.property_id = ?");
$rooms->execute([$propId]);
$rooms = $rooms->fetchAll();

$rates = $db->prepare("SELECT s.*, c.name as category_name FROM sliding_rates s JOIN room_categories c ON s.category_id = c.id WHERE s.property_id = ? ORDER BY s.category_id, s.hours");
$rates->execute([$propId]);
$rates = $rates->fetchAll();

$pmStmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'payment_methods' AND property_id = ?");
$pmStmt->execute([$propId]);
$pmJson = $pmStmt->fetchColumn();
$paymentMethods = $pmJson ? json_decode($pmJson, true) : [];
if (empty($paymentMethods)) {
    $paymentMethods = ["Cash", "UPI"];
}

$pcStmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'payment_categories' AND property_id = ?");
$pcStmt->execute([$propId]);
$pcJson = $pcStmt->fetchColumn();
$paymentCategories = $pcJson ? json_decode($pcJson, true) : [];
if (empty($paymentCategories)) {
    $paymentCategories = ["Room Revenue", "F&B", "Other"];
}

$incStmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'FINANCE_INCOME_CATEGORIES' AND property_id = ?");
$incStmt->execute([$propId]);
$incJson = $incStmt->fetchColumn();
$incomeCategories = $incJson ? json_decode($incJson, true) : [];
if (empty($incomeCategories)) {
    $incomeCategories = ["Misc", "F&B", "Laundry", "POS"];
}

$expStmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'FINANCE_EXPENSE_CATEGORIES' AND property_id = ?");
$expStmt->execute([$propId]);
$expJson = $expStmt->fetchColumn();
$expenseCategories = $expJson ? json_decode($expJson, true) : [];
if (empty($expenseCategories)) {
    $expenseCategories = ["F&B", "Laundry", "Maintenance", "Salary", "Misc"];
}

$isSuperAdminUser = AuthHelper::isSuperAdmin() ? 1 : 0;
if ($isSuperAdminUser) {
    $suStmt = $db->prepare("SELECT * FROM staff_users WHERE property_id = ? ORDER BY created_at DESC");
    $suStmt->execute([$propId]);
    $staffUsers = $suStmt->fetchAll();
} else {
    $suStmt = $db->prepare("SELECT * FROM staff_users WHERE access_level != 'superadmin' AND property_id = ? ORDER BY created_at DESC");
    $suStmt->execute([$propId]);
    $staffUsers = $suStmt->fetchAll();
}

$currentLogoB64 = defined('PROPERTY_LOGO_BASE64') ? PROPERTY_LOGO_BASE64 : '';
$currentHotelName = defined('PROPERTY_NAME') ? PROPERTY_NAME : 'MicroPMS';

// Fetch SaaS Subscription and Usage details
$propStmt = $db->prepare("SELECT * FROM properties WHERE id = ?");
$propStmt->execute([$propId]);
$propertyDetails = $propStmt->fetch();

$pgConfigs = [];
try {
    $pgStmt = $db->prepare("SELECT * FROM payment_gateway_configs WHERE property_id = ?");
    $pgStmt->execute([$propId]);
    while ($row = $pgStmt->fetch()) {
        $pgConfigs[$row['gateway']] = $row;
    }
} catch (\PDOException $e) {}

$customRoles = [];
try {
    AuthHelper::seedRolesForProperty($db, $propId);
    $rolesStmt = $db->prepare("SELECT * FROM roles WHERE property_id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') ORDER BY is_system DESC, name ASC");
    $rolesStmt->execute([$propId]);
    $customRoles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    try {
        $rolesStmt = $db->prepare("SELECT * FROM roles WHERE property_id = ? ORDER BY name ASC");
        $rolesStmt->execute([$propId]);
        $customRoles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e2) {}
}
$builtInRoles = AuthHelper::getBuiltInRoles();
$permissionLabels = AuthHelper::getAllPermissions();


// Current counts
$stmtRoomCount = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ? OR (? = 1 AND (property_id IS NULL OR property_id = 0))");
$stmtRoomCount->execute([$propId, $propId]);
$roomCount = (int)$stmtRoomCount->fetchColumn();

$stmtStaffCount = $db->prepare("SELECT COUNT(*) FROM staff_users WHERE property_id = ? AND is_active = 1");
$stmtStaffCount->execute([$propId]);
$staffCount = (int)$stmtStaffCount->fetchColumn();

require_once __DIR__ . '/../../pms_core/saas_plans.php';
$plansConfig = SaaSPlans::get($db);
$currentPlan = $propertyDetails['plan'] ?? 'starter';
$planLimits = $plansConfig[$currentPlan] ?? [
    'name' => ucfirst($currentPlan),
    'price' => 1999,
    'max_rooms' => 15,
    'max_staff' => 5,
];

$upsellEnabled = (defined('GUEST_PORTAL_UPSELL_ENABLED') && GUEST_PORTAL_UPSELL_ENABLED === 'true') || (get_db_setting($db, 'GUEST_PORTAL_UPSELL_ENABLED', $propId) === 'true');
$posEnabled = (defined('GUEST_PORTAL_POS_ENABLED') && GUEST_PORTAL_POS_ENABLED === 'true') || (get_db_setting($db, 'GUEST_PORTAL_POS_ENABLED', $propId) === 'true');
$selfCheckoutEnabled = (defined('GUEST_PORTAL_SELF_CHECKOUT_ENABLED') && GUEST_PORTAL_SELF_CHECKOUT_ENABLED === 'true') || (get_db_setting($db, 'GUEST_PORTAL_SELF_CHECKOUT_ENABLED', $propId) === 'true');
$housekeepingEnabled = (defined('GUEST_PORTAL_HOUSEKEEPING_ENABLED') && GUEST_PORTAL_HOUSEKEEPING_ENABLED === 'true') || (get_db_setting($db, 'GUEST_PORTAL_HOUSEKEEPING_ENABLED', $propId) === 'true');
$earlyLateFee = floatval(get_db_setting($db, 'GUEST_PORTAL_EARLY_LATE_FEE', $propId, '0.00'));
// Advanced guest portal settings queries
$propertyId = AuthHelper::getPropertyId();
$loyaltyEnabled = (get_db_setting($db, 'GUEST_PORTAL_LOYALTY_ENABLED', $propertyId, 'true')) === 'true';
$loyaltyGold = intval(get_db_setting($db, 'GUEST_PORTAL_LOYALTY_GOLD', $propertyId, '5'));
$loyaltyPlatinum = intval(get_db_setting($db, 'GUEST_PORTAL_LOYALTY_PLATINUM', $propertyId, '10'));

$preArrivalEnabled = (get_db_setting($db, 'GUEST_PORTAL_PRE_ARRIVAL_ENABLED', $propertyId, 'true')) === 'true';
$preArrivalSignature = (get_db_setting($db, 'GUEST_PORTAL_PRE_ARRIVAL_SIGNATURE', $propertyId, 'true')) === 'true';
$preArrivalDoc = (get_db_setting($db, 'GUEST_PORTAL_PRE_ARRIVAL_DOC', $propertyId, 'true')) === 'true';

$upsellBreakfastPrice = floatval(get_db_setting($db, 'GUEST_PORTAL_UPSELL_BREAKFAST_PRICE', $propertyId, '350.00'));
$upsellTransferPrice = floatval(get_db_setting($db, 'GUEST_PORTAL_UPSELL_TRANSFER_PRICE', $propertyId, '1200.00'));

$waToken = get_db_setting($db, 'WHATSAPP_TOKEN', $propertyId, (defined('WHATSAPP_TOKEN') ? WHATSAPP_TOKEN : ''));
$waPhoneId = get_db_setting($db, 'WHATSAPP_PHONE_NUMBER_ID', $propertyId, (defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : ''));
$waWabaId = get_db_setting($db, 'WHATSAPP_WABA_ID', $propertyId, (defined('WHATSAPP_WABA_ID') ? WHATSAPP_WABA_ID : ''));

$otpEnabled = (get_db_setting($db, 'GUEST_PORTAL_OTP_ENABLED', $propertyId, 'false')) === 'true';

// New guest portal info settings
$portalWifiSsid        = (string)get_db_setting($db, 'GUEST_PORTAL_WIFI_SSID', $propertyId, (defined('PROPERTY_WIFI_NAME') ? PROPERTY_WIFI_NAME : ''));
$portalWifiPassword    = (string)get_db_setting($db, 'GUEST_PORTAL_WIFI_PASSWORD', $propertyId, (defined('PROPERTY_WIFI_PASS') ? PROPERTY_WIFI_PASS : ''));
$portalHelpDeskNo      = (string)get_db_setting($db, 'GUEST_PORTAL_HELP_DESK_NO', $propertyId, '');
$portalLocalAttractions= (string)get_db_setting($db, 'GUEST_PORTAL_LOCAL_ATTRACTIONS', $propertyId, '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Settings | MicroPMS</title>
    
        

    
        <?php include __DIR__ . '/components/mobile_nav.php'; ?>

    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
</head>
<body class="flex flex-col min-h-screen">
    <!-- Main App Container -->
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
        
        <!-- App Bar -->
        <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0">
            <div class="flex items-center gap-3">
                <a href="index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                    <i class="ph ph-caret-left text-2xl text-slate-800"></i>
                </a>
                <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Settings</h1>
            </div>
            <?php include __DIR__ . '/components/desktop_nav.php'; ?>
        </header>

        <style>
            .settings-tab-btn {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                padding: 0.875rem 1.25rem;
                border-radius: 0.75rem;
                font-weight: 600;
                font-size: 0.875rem;
                transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
                text-align: left;
                white-space: nowrap;
            }
            /* Override ui_head.php defaults just for this page */
            .settings-tab-btn.tab-active {
                background-color: #000000;
                color: #FFFFFF !important;
                border: none !important;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            .settings-tab-btn.tab-inactive {
                background-color: transparent;
                color: #475569 !important;
                border: none !important;
            }
            .settings-tab-btn.tab-inactive:hover {
                background-color: #F1F5F9;
                color: #0F172A !important;
            }
        </style>

        <!-- Settings Layout Structure -->
        <div class="flex flex-col md:flex-row flex-1 w-full max-w-7xl mx-auto p-4 md:p-8 gap-6 md:gap-8 overflow-hidden">
            
            <!-- Sidebar Navigation -->
            <aside class="w-full md:w-64 flex-shrink-0">
                <h2 class="text-[10px] font-bold text-brand-900/40 uppercase tracking-widest mb-3 hidden md:block px-3">Configuration</h2>
                <nav class="flex overflow-x-auto md:flex-col gap-2 no-scrollbar md:pr-4 pb-2 md:pb-0">
                    <button onclick="switchTab('categories')" id="tab-categories" class="settings-tab-btn tab-active">
                        <i class="ph ph-squares-four text-lg opacity-80"></i> Categories
                    </button>
                    <button onclick="switchTab('rooms')" id="tab-rooms" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-door text-lg opacity-80"></i> Physical Rooms
                    </button>
                    <button onclick="switchTab('rates')" id="tab-rates" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-clock text-lg opacity-80"></i> Hourly Rates
                    </button>
                    <button onclick="switchTab('integrations')" id="tab-integrations" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-plugs-connected text-lg opacity-80"></i> Integrations
                    </button>
                    <button onclick="switchTab('guest-portal')" id="tab-guest-portal" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-envelope-open text-lg opacity-80"></i> Guest Portal
                    </button>
                    <button onclick="switchTab('payments')" id="tab-payments" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-credit-card text-lg opacity-80"></i> Payment Config
                    </button>
                    <button onclick="switchTab('finance')" id="tab-finance" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-currency-circle-dollar text-lg opacity-80"></i> Finance Config
                    </button>
                    <button onclick="switchTab('staff')" id="tab-staff" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-users text-lg opacity-80"></i> Staff Users
                    </button>
                    <button onclick="switchTab('roles')" id="tab-roles" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-shield-check text-lg opacity-80"></i> Roles & Permissions
                    </button>
                    <button onclick="switchTab('housekeeping')" id="tab-housekeeping" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-list-checks text-lg opacity-80"></i> Housekeeping Checklist
                    </button>
                    <button onclick="switchTab('property')" id="tab-property" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-buildings text-lg opacity-80"></i> Property & Tax
                    </button>
                    <button onclick="switchTab('folio-items')" id="tab-folio-items" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-receipt text-lg opacity-80"></i> Folio Items
                    </button>
                    <button onclick="switchTab('sequences')" id="tab-sequences" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-hash text-lg opacity-80"></i> Custom Sequences
                    </button>
                    <button onclick="switchTab('night-audit')" id="tab-night-audit" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-moon-stars text-lg opacity-80"></i> Night Audit
                    </button>
                    <button onclick="switchTab('subscription')" id="tab-subscription" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-credit-card text-lg opacity-80"></i> Subscription & Usage
                    </button>
                </nav>
            </aside>

            <!-- Main Content Area -->
            <main class="flex-1 min-w-0 bg-white border border-brand-900/10 shadow-minimal rounded-3xl p-6 relative overflow-y-auto">
            
            <!-- Finance Config Tab -->
            <div id="content-finance" class="pb-24 hidden space-y-8">
                <div>
                    <h3 class="text-xl font-bold text-slate-800 mb-4">Finance Categories</h3>
                    <p class="text-sm text-slate-500 mb-6">Manage the categories used for recording miscellaneous income and expenses in the Finance module.</p>
                    
                    <form onsubmit="saveFinanceConfig(event)" class="space-y-6 max-w-2xl">
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Income Categories (comma-separated)</label>
                            <input type="text" id="finance_income_categories" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all outline-none" value="<?= htmlspecialchars((string)(implode(', ', $incomeCategories))) ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Expense Categories (comma-separated)</label>
                            <input type="text" id="finance_expense_categories" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-brand-500 focus:ring-4 focus:ring-brand-500/10 transition-all outline-none" value="<?= htmlspecialchars((string)(implode(', ', $expenseCategories))) ?>">
                        </div>
                        <button type="submit" class="bg-brand-900 text-white px-6 py-3 rounded-xl font-bold hover:bg-brand-800 transition-colors">
                            Save Finance Configuration
                        </button>
                    </form>
                </div>
            </div>

            <!-- 1. Categories Tab -->
            <div id="content-categories" class="pb-24 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach($categories as $c): ?>
                    <div class="card-minimal p-4  flex justify-between items-center active:scale-[0.98] transition-transform">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-full bg-brand-accentLight flex items-center justify-center text-brand-accent">
                                <i class="ph ph-bed text-xl"></i>
                            </div>
                            <div>
                                <span class="font-bold text-brand-900 block"><?= htmlspecialchars((string)($c['name'])) ?></span>
                                <span class="text-xs font-medium text-brand-900/70">Room Category</span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="openModal('catModal', {id: <?= htmlspecialchars((string)($c['id']), ENT_QUOTES, 'UTF-8') ?>, name: '<?= htmlspecialchars((string)(addslashes($c['name'])), ENT_QUOTES, 'UTF-8') ?>'})" class="w-12 h-12 rounded-full bg-brand-50 text-brand-900 flex items-center justify-center hover:bg-brand-100">
                                <i class="ph ph-pencil-simple text-lg"></i>
                            </button>
                            <button onclick="deleteItem('category', <?= htmlspecialchars((string)($c['id']), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars((string)(addslashes($c['name'])), ENT_QUOTES, 'UTF-8') ?>')" class="w-12 h-12 rounded-full bg-error-50 text-error-600 flex items-center justify-center hover:bg-error-100">
                                <i class="ph ph-trash text-lg"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 2. Rooms Tab -->
            <div id="content-rooms" class="pb-24 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" style="display:none">
                <?php foreach($rooms as $r): ?>
                    <div class="card-minimal p-4  flex justify-between items-center active:scale-[0.98] transition-transform">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-indigo-50 flex items-center justify-center text-indigo-700 font-semibold text-lg border border-indigo-100">
                                <?= htmlspecialchars((string)($r['room_number'])) ?>
                            </div>
                            <div>
                                <span class="text-sm font-bold text-brand-900 block">Room <?= htmlspecialchars((string)($r['room_number'])) ?></span>
                                <span class="text-xs font-medium text-brand-900/70 bg-brand-100 px-2 py-0.5 rounded mt-1 inline-block"><?= htmlspecialchars((string)($r['category_name'])) ?></span>
                                <div class="mt-2.5">
                                    <label class="relative inline-flex items-center cursor-pointer select-none">
                                        <input type="checkbox" class="sr-only peer" <?= htmlspecialchars((string)($r['state'] === 'out_of_order' ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> onchange="toggleRoomOOO(<?= htmlspecialchars((string)($r['id']), ENT_QUOTES, 'UTF-8') ?>, this.checked)">
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-rose-500"></div>
                                        <span class="ml-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Out of Order</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="openModal('roomModal', {id: <?= htmlspecialchars((string)($r['id']), ENT_QUOTES, 'UTF-8') ?>, num: '<?= htmlspecialchars((string)(addslashes($r['room_number'])), ENT_QUOTES, 'UTF-8') ?>', cat: <?= htmlspecialchars((string)($r['category_id']), ENT_QUOTES, 'UTF-8') ?>})" class="w-12 h-12 rounded-full bg-brand-50 text-brand-900 flex items-center justify-center hover:bg-brand-100">
                                <i class="ph ph-pencil-simple text-lg"></i>
                            </button>
                            <button onclick="deleteItem('room', <?= htmlspecialchars((string)($r['id']), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars((string)(addslashes($r['room_number'])), ENT_QUOTES, 'UTF-8') ?>')" class="w-12 h-12 rounded-full bg-error-50 text-error-600 flex items-center justify-center hover:bg-error-100">
                                <i class="ph ph-trash text-lg"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 3. Rates Tab -->
            <div id="content-rates" class="pb-24 grid grid-cols-1 gap-4 font-display" style="display:none">
                <?php 
                $groupedRates = [];
                foreach($rates as $r) {
                    $rp = empty($r['rate_plan_name']) ? 'Base Rate' : $r['rate_plan_name'];
                    $groupedRates[$r['category_id']][$rp][$r['hours']] = $r;
                }
                foreach($categories as $cat): 
                    $catPlans = $groupedRates[$cat['id']] ?? [];
                    
                    $bulkRates = [];
                    foreach ($catPlans as $planName => $hoursData) {
                        for ($h = 1; $h <= 24; $h++) {
                            $bulkRates[$planName][$h] = isset($hoursData[$h]) ? (float)$hoursData[$h]['price'] : '';
                        }
                    }
                    if (empty($bulkRates)) {
                        $bulkRates['Base Rate'] = array_fill(1, 24, '');
                    }
                    
                    $jsonData = json_encode([
                        'cat_id' => $cat['id'],
                        'cat_name' => $cat['name'],
                        'rates' => $bulkRates
                    ]);
                ?>
                    <div class="card-minimal overflow-hidden mb-4 p-5 flex justify-between items-center">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-orange-50 text-orange-600 flex items-center justify-center font-semibold text-xl border border-orange-100/50">
                                <i class="ph ph-clock"></i>
                            </div>
                            <div>
                                <span class="font-semibold text-brand-900 text-lg block"><?= htmlspecialchars((string)($cat['name'])) ?></span>
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    <?php if (empty($catPlans)): ?>
                                        <span class="text-[10px] font-bold text-orange-500 bg-orange-50 px-2 py-0.5 rounded border border-orange-100/50">No Rates Set</span>
                                    <?php else: ?>
                                        <?php foreach (array_keys($catPlans) as $planName): ?>
                                            <span class="text-[10px] font-bold text-slate-700 bg-slate-100 px-2.5 py-0.5 rounded-lg border border-slate-200"><?= htmlspecialchars((string)($planName)) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button onclick='openBulkRateModal(<?= htmlspecialchars((string)($jsonData), ENT_QUOTES, "UTF-8") ?>)' class="bg-brand-accentLight text-brand-accent px-4 py-2 rounded-xl text-sm font-bold hover:bg-brand-accentLight active:scale-95 transition-all flex items-center gap-2">
                                <i class="ph ph-pencil-simple text-lg"></i> Manage Rates
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 4. Integrations Tab -->
            <div id="content-integrations" class="pb-24 max-w-2xl mx-auto" style="display:none">
                <form id="integrationsForm" onsubmit="submitIntegrations(event)" class="space-y-6">
                    <!-- Razorpay -->
                    <div class="card-minimal p-5">
                        <div class="flex items-center gap-3 mb-4 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-brand-accentLight text-brand-accent flex items-center justify-center">
                                <i class="ph ph-credit-card text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Razorpay Settings</h2>
                                <p class="text-xs text-brand-900/70">Payment Gateway Configuration</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Key ID</label>
                                <input type="text" name="RAZORPAY_KEY_ID" value="<?= htmlspecialchars((string)(defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Key Secret</label>
                                <input type="password" name="RAZORPAY_KEY_SECRET" value="" autocomplete="new-password" placeholder="Leave blank to keep current secret" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Webhook Secret</label>
                                <input type="password" name="RAZORPAY_WEBHOOK_SECRET" value="" autocomplete="new-password" placeholder="Leave blank to keep current secret" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                        </div>
                    </div>

                    <!-- WhatsApp -->
                    <div class="card-minimal p-5 flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-xl bg-success-50 text-success-600 flex items-center justify-center shrink-0">
                                    <i class="ph ph-whatsapp-logo text-2xl"></i>
                                </div>
                                <div>
                                    <h2 class="font-bold text-brand-900">WhatsApp Cloud API</h2>
                                    <p class="text-xs text-brand-900/70">Configure credentials, templates, and quick replies in the WhatsApp settings panel.</p>
                                </div>
                            </div>
                            <a href="<?php echo $adminBaseUrl; ?>automations.php" class="shrink-0 bg-brand-900 text-white text-xs font-bold px-4 py-2.5 rounded-xl hover:bg-brand-800 transition-colors shadow-minimal flex items-center gap-1">
                                <i class="ph ph-gear"></i> Automations
                            </a>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 border-t border-brand-100 pt-4 mt-2">
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Permanent Token</label>
                                <input type="password" name="WHATSAPP_TOKEN" value="" autocomplete="new-password" placeholder="Leave blank to keep current token" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono" placeholder="EAAD... ">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Phone Number ID</label>
                                <input type="text" name="WHATSAPP_PHONE_NUMBER_ID" value="<?= htmlspecialchars((string)($waPhoneId)) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">WABA ID</label>
                                <input type="text" name="WHATSAPP_WABA_ID" value="<?= htmlspecialchars((string)($waWabaId)) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                        </div>
                    </div>

                    <!-- Google Sheets Sync -->
                    <div class="card-minimal p-5">
                        <div class="flex items-center gap-3 mb-4 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                <i class="ph ph-table text-2xl"></i>
                            </div>
                            <div class="flex-1">
                                <h2 class="font-bold text-brand-900">Google Sheets Sync</h2>
                                <p class="text-xs text-brand-900/70">Real-time & Periodic Sync for Bookings, Payments, and Expenses</p>
                            </div>
                            <button type="button" onclick="testGoogleSheetsConnection()" id="gs-test-btn" class="text-xs font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1">
                                <i class="ph ph-plugs-connected"></i> Test Connection
                            </button>
                        </div>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between bg-brand-50 p-3 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800">Enable Google Sheets Auto-Sync</span>
                                    <p class="text-xs text-brand-900/70">Automatically sync bookings, payments, and expenses as they are recorded.</p>
                                </div>
                                <input type="hidden" name="GOOGLE_SHEETS_ENABLED" id="GOOGLE_SHEETS_ENABLED" value="<?= htmlspecialchars((string)((defined('GOOGLE_SHEETS_ENABLED') && (GOOGLE_SHEETS_ENABLED === 'true' || GOOGLE_SHEETS_ENABLED === true || GOOGLE_SHEETS_ENABLED === '1')) ? 'true' : 'false'), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="checkbox" id="gs_enable_checkbox" <?= htmlspecialchars((string)((defined('GOOGLE_SHEETS_ENABLED') && (GOOGLE_SHEETS_ENABLED === 'true' || GOOGLE_SHEETS_ENABLED === true || GOOGLE_SHEETS_ENABLED === '1')) ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> onchange="document.getElementById('GOOGLE_SHEETS_ENABLED').value = this.checked ? 'true' : 'false'" class="w-5 h-5 rounded border-brand-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Google Apps Script Webhook URL</label>
                                <input type="text" id="gs_webhook_url" name="GOOGLE_SHEETS_WEBHOOK_URL" value="<?= htmlspecialchars((string)(defined('GOOGLE_SHEETS_WEBHOOK_URL') ? GOOGLE_SHEETS_WEBHOOK_URL : '')) ?>" placeholder="https://script.google.com/macros/s/.../exec" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                            <div class="pt-2 border-t border-brand-100">
                                <p class="text-xs font-bold text-brand-900/70 uppercase tracking-widest mb-2">Manual Bulk Resync:</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" onclick="bulkSyncGoogleSheets('all')" class="text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 px-3 py-2 rounded-lg transition-colors flex items-center gap-1 shadow-sm">
                                        <i class="ph ph-arrows-clockwise"></i> Bulk Sync All
                                    </button>
                                    <button type="button" onclick="bulkSyncGoogleSheets('booking')" class="text-xs font-bold text-brand-800 bg-brand-100 hover:bg-brand-200 px-3 py-2 rounded-lg transition-colors flex items-center gap-1">
                                        <i class="ph ph-calendar"></i> Sync Bookings
                                    </button>
                                    <button type="button" onclick="bulkSyncGoogleSheets('payment')" class="text-xs font-bold text-brand-800 bg-brand-100 hover:bg-brand-200 px-3 py-2 rounded-lg transition-colors flex items-center gap-1">
                                        <i class="ph ph-currency-inr"></i> Sync Payments
                                    </button>
                                    <button type="button" onclick="bulkSyncGoogleSheets('expense')" class="text-xs font-bold text-brand-800 bg-brand-100 hover:bg-brand-200 px-3 py-2 rounded-lg transition-colors flex items-center gap-1">
                                        <i class="ph ph-receipt"></i> Sync Expenses
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Telegram -->
                    <div class="card-minimal p-5">
                        <div class="flex items-center gap-3 mb-4 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center">
                                <i class="ph ph-telegram-logo text-2xl"></i>
                            </div>
                            <div class="flex-1">
                                <h2 class="font-bold text-brand-900">Telegram Bot</h2>
                                <p class="text-xs text-brand-900/70">System Alerts & Notifications</p>
                            </div>
                            <button type="button" onclick="testTelegram()" id="tg-test-btn" class="text-xs font-bold text-sky-600 bg-sky-50 hover:bg-sky-100 px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1">
                                <i class="ph ph-paper-plane-tilt"></i> Test
                            </button>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <h3 class="text-xs font-bold text-brand-900 bg-brand-100 px-3 py-1.5 rounded inline-block mb-1 mt-2">Notifier Bot (Outbound)</h3>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1 uppercase tracking-wider">Bot Token</label>
                                <input type="text" name="TELEGRAM_BOT_TOKEN" value="<?= htmlspecialchars((string)(defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1 uppercase tracking-wider">Chat IDs (Comma separated)</label>
                                <input type="text" name="TELEGRAM_CHAT_ID" value="<?= htmlspecialchars((string)(defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                            <div class="pt-4 border-t border-brand-100">
                                <h3 class="text-xs font-bold text-emerald-800 bg-emerald-100 px-3 py-1.5 rounded inline-block mb-1 mt-1">Operations Bot (Two-Way Interactive)</h3>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1 uppercase tracking-wider">Operations Bot Token</label>
                                <input type="text" name="TELEGRAM_OPERATIONS_BOT_TOKEN" value="<?= htmlspecialchars((string)(defined('TELEGRAM_OPERATIONS_BOT_TOKEN') ? TELEGRAM_OPERATIONS_BOT_TOKEN : '')) ?>" class="w-full bg-emerald-50 border border-emerald-200 p-3 rounded-xl text-sm outline-none focus:ring-2 focus:ring-emerald-500 transition-all font-mono">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1 uppercase tracking-wider">Authorized Chat IDs (Comma separated)</label>
                                <input type="text" name="TELEGRAM_OPERATIONS_CHAT_IDS" value="<?= htmlspecialchars((string)(defined('TELEGRAM_OPERATIONS_CHAT_IDS') ? TELEGRAM_OPERATIONS_CHAT_IDS : '')) ?>" placeholder="e.g. 12345678,87654321" class="w-full bg-emerald-50 border border-emerald-200 p-3 rounded-xl text-sm outline-none focus:ring-2 focus:ring-emerald-500 transition-all font-mono">
                                <p class="text-[9px] text-slate-500 mt-1 font-semibold">Only these IDs can interact with the operations menu. Don't forget to set the webhook to `/api/telegram_webhook.php`.</p>
                            </div>
                            <div class="mt-4">
                                <label class="block text-[10px] font-bold text-slate-500 mb-1 uppercase tracking-wider">Telegram Roles (JSON)</label>
                                <textarea name="TELEGRAM_ROLES" rows="3" class="w-full bg-emerald-50 border border-emerald-200 p-3 rounded-xl text-sm outline-none focus:ring-2 focus:ring-emerald-500 transition-all font-mono"><?= htmlspecialchars((string)(defined('TELEGRAM_ROLES') ? TELEGRAM_ROLES : '')) ?></textarea>
                                <p class="text-[9px] text-slate-500 mt-1 font-semibold">Example: {"12345678": "admin", "87654321": "staff"}. Maps chat IDs to roles. "admin" role is required for /report command.</p>
                            </div>
                        </div>

                        <!-- Notification Event Toggles -->
                        <div class="mt-5 pt-4 border-t border-brand-100">
                            <p class="text-xs font-bold text-brand-900/70 uppercase tracking-widest mb-3">Notify owner on:</p>
                            <?php
                            $notifyEvents = json_decode(defined('NOTIFY_EVENTS') ? NOTIFY_EVENTS : '{}', true);
                            $eventLabels = [
                                'booking_confirmed' => ['New Booking Confirmed', 'ph-calendar-check', 'When online payment received'],
                                'check_in'          => ['Guest Check-in', 'ph-arrow-square-in', 'When staff marks check-in'],
                                'check_out'         => ['Guest Check-out', 'ph-arrow-square-out', 'When staff marks check-out'],
                                'overstay'          => ['Overstay Alert', 'ph-clock-warning', 'Guest surpassed checkout time'],
                                'payment_received'  => ['Payment Received', 'ph-currency-inr', 'When payment is recorded'],
                                'room_dirty'        => ['Room Needs Cleaning', 'ph-broom', 'When room becomes dirty'],
                                'daily_summary'     => ['Daily Summary', 'ph-chart-bar', 'End-of-day hotel summary'],
                                'folio_activity'    => ['Folio Activity', 'ph-receipt', 'Charge added, edited, deleted, stay extended'],
                                'pre_departure'     => ['Pre-Departure Reminder', 'ph-bell-ringing', '30 min before checkout'],
                            ];
                            ?>
                            <div class="space-y-2" id="notify-events-wrap">
                                <input type="hidden" id="notify_events_json" name="NOTIFY_EVENTS" value="<?= htmlspecialchars((string)(defined('NOTIFY_EVENTS') ? NOTIFY_EVENTS : '{}')) ?>">
                                <?php foreach ($eventLabels as $key => [$label, $icon, $desc]): ?>
                                <div class="bg-brand-50 rounded-xl border border-brand-200/50 p-3 hover:border-brand-300 transition-all">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox" data-event="<?= htmlspecialchars((string)($key), ENT_QUOTES, 'UTF-8') ?>" <?= htmlspecialchars((string)((!empty($notifyEvents[$key])) ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> onchange="syncNotifyEvents()" class="w-5 h-5 rounded border-brand-300 text-sky-600 focus:ring-sky-500">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <i class="ph <?= htmlspecialchars((string)($icon), ENT_QUOTES, 'UTF-8') ?> text-sm text-brand-400"></i>
                                                <span class="text-sm font-bold text-brand-800"><?= htmlspecialchars((string)($label), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <p class="text-[11px] text-brand-900/70 mt-0.5"><?= htmlspecialchars((string)($desc), ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <button type="button" onclick="toggleTemplateEdit('<?= htmlspecialchars((string)($key), ENT_QUOTES, 'UTF-8') ?>')" class="text-xs font-bold text-brand-500 hover:text-brand-900 flex items-center gap-1 select-none">
                                            <i class="ph ph-note-pencil"></i> Customize
                                        </button>
                                    </label>
                                    
                                    <div id="tg-edit-container-<?= htmlspecialchars((string)($key), ENT_QUOTES, 'UTF-8') ?>" class="hidden mt-3 pt-3 border-t border-brand-100/50 space-y-2">
                                        <label class="block text-[10px] font-bold text-brand-400 uppercase tracking-wider">Custom Message Template (Telegram)</label>
                                        <?php
                                        $defaultTgs = [
                                            'booking_confirmed' => "⚡ <b>Online Booking Confirmed</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Check-in:</b> {check_in_date}\n<b>Check-out:</b> {check_out_date}\n<b>Paid:</b> ₹{paid_amount}",
                                            'check_in'          => "🔑 <b>Guest Checked In</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Total Folio:</b> ₹{total_amount}",
                                            'check_out'         => "🚪 <b>Guest Checked Out</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Total Paid:</b> ₹{paid_amount}",
                                            'overstay'          => "🕛 <b>Overstay Alert</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Checkout was:</b> {check_out_date}",
                                            'payment_received'  => "💰 <b>Payment Recorded</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Amount:</b> ₹{amount}\n<b>Method:</b> {method}\n<b>Ref:</b> {ref}",
                                            'room_dirty'        => "🧹 <b>Room marked Dirty (Checkout)</b>\n\n<b>Room:</b> {room_number}\n<b>Category:</b> {room_type}",
                                            'daily_summary'     => "📊 <b>Daily Summary Report</b>\n\n<b>Revenue:</b> ₹{total_amount}\n<b>Occupancy:</b> {occupancy_pct}%\n<b>Dirty Rooms:</b> {dirty_count}",
                                            'folio_activity'    => "🧾 <b>Folio Activity Alert</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Activity:</b> {description}\n<b>Amount:</b> ₹{amount}",
                                            'pre_departure'     => "🔔 <b>Pre-Departure Notice</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Checkout scheduled at:</b> {check_out_date}",
                                        ];
                                        $savedTgTemplate = defined('TG_TEMPLATE_' . strtoupper($key)) ? constant('TG_TEMPLATE_' . strtoupper($key)) : ($defaultTgs[$key] ?? '');
                                        ?>
                                        <textarea name="TG_TEMPLATE_<?= htmlspecialchars((string)(strtoupper($key)), ENT_QUOTES, 'UTF-8') ?>" rows="3" class="w-full bg-white border border-brand-200 p-2.5 rounded-xl text-xs font-mono outline-none focus:border-brand-900"><?= htmlspecialchars((string)($savedTgTemplate)) ?></textarea>
                                        <p class="text-[9px] text-slate-400 font-semibold leading-tight">Placeholders: <code>{guest_name}</code>, <code>{room_number}</code>, <code>{check_in_date}</code>, <code>{check_out_date}</code>, <code>{total_amount}</code>, <code>{paid_amount}</code>, <code>{balance_amount}</code>, <code>{amount}</code>, <code>{method}</code>, <code>{ref}</code>, <code>{description}</code></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Email Reports Configuration -->
                    <div class="card-minimal p-6">
                        <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <i class="ph ph-envelope-simple text-2xl"></i>
                            </div>
                            <div class="flex-1">
                                <h2 class="font-bold text-brand-900">Email Reports Config</h2>
                                <p class="text-xs text-brand-900/70">Automated Daily & Weekly Business Summaries</p>
                            </div>
                        </div>
                        <?php
                            $emailConfig = ['daily_audit_emails' => '', 'weekly_revenue_emails' => '', 'is_active' => 1];
                            try {
                                $emailConfigStmt = $db->prepare("SELECT * FROM email_report_config WHERE property_id = ?");
                                $emailConfigStmt->execute([$propertyId]);
                                $fetchedConfig = $emailConfigStmt->fetch(PDO::FETCH_ASSOC);
                                if ($fetchedConfig) {
                                    $emailConfig = $fetchedConfig;
                                }
                            } catch (\PDOException $e) {
                                // Table might not exist yet if migration hasn't been run
                            }
                        ?>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between bg-brand-50 p-3 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800">Enable Email Reports</span>
                                    <p class="text-xs text-brand-900/70">Allow the system to dispatch automated reports.</p>
                                </div>
                                <input type="hidden" name="EMAIL_REPORTS_ACTIVE" id="EMAIL_REPORTS_ACTIVE" value="<?= htmlspecialchars((string)($emailConfig['is_active'] ? '1' : '0'), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="checkbox" onchange="document.getElementById('EMAIL_REPORTS_ACTIVE').value = this.checked ? '1' : '0'" <?= htmlspecialchars((string)($emailConfig['is_active'] ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="w-5 h-5 rounded border-brand-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Daily Audit Emails (Comma separated)</label>
                                <input type="text" name="DAILY_AUDIT_EMAILS" value="<?= htmlspecialchars((string)($emailConfig['daily_audit_emails'])) ?>" placeholder="admin@hotel.com, manager@hotel.com" class="w-full bg-white border border-brand-200 p-3 rounded-xl text-sm outline-none focus:border-brand-900 transition-all font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Weekly Revenue Emails (Comma separated)</label>
                                <input type="text" name="WEEKLY_REVENUE_EMAILS" value="<?= htmlspecialchars((string)($emailConfig['weekly_revenue_emails'])) ?>" placeholder="owner@hotel.com, accountant@hotel.com" class="w-full bg-white border border-brand-200 p-3 rounded-xl text-sm outline-none focus:border-brand-900 transition-all font-mono">
                            </div>
                        </div>
                    </div>

                    <!-- Finance Categories Configuration -->
                    <div class="card-minimal p-6 mb-6">
                        <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                <i class="ph ph-currency-circle-dollar text-2xl"></i>
                            </div>
                            <div class="flex-1">
                                <h2 class="font-bold text-brand-900">Finance Categories</h2>
                                <p class="text-xs text-brand-900/70">Custom Income & Expense Categories for Ledger</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Income Categories (Comma separated)</label>
                                <input type="text" name="FINANCE_INCOME_CATEGORIES" value="<?= htmlspecialchars((string)(defined('FINANCE_INCOME_CATEGORIES') ? FINANCE_INCOME_CATEGORIES : 'F&B, Laundry, POS, Misc, Event, Transport')) ?>" placeholder="F&B, Laundry, POS, Misc, Event, Transport" class="w-full bg-white border border-brand-200 p-3 rounded-xl text-sm outline-none focus:border-brand-900 transition-all font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Expense Categories (Comma separated)</label>
                                <input type="text" name="FINANCE_EXPENSE_CATEGORIES" value="<?= htmlspecialchars((string)(defined('FINANCE_EXPENSE_CATEGORIES') ? FINANCE_EXPENSE_CATEGORIES : 'Salaries, Utility Bills, F&B Supplies, Maintenance, Refunds, Marketing, Misc')) ?>" placeholder="Salaries, Utility Bills, F&B Supplies, Maintenance, Refunds, Marketing, Misc" class="w-full bg-white border border-brand-200 p-3 rounded-xl text-sm outline-none focus:border-brand-900 transition-all font-mono">
                            </div>
                        </div>
                    </div>

                    <!-- Google Vision API -->
                    <div class="card-minimal p-6">
                        <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                                <i class="ph ph-eye text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Google Vision API (OCR)</h2>
                                <p class="text-xs text-brand-900/70">For accurate Aadhaar/ID card text extraction</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">API Key</label>
                                <input type="password" name="GOOGLE_VISION_API_KEY" value="" autocomplete="new-password" placeholder="Leave blank to keep current key" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-mono outline-none focus:border-brand-900">
                                <p class="text-[10px] text-brand-900/50 mt-1">Get from Google Cloud Console > APIs > Vision API > Credentials</p>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="saveIntegrationBtn" class="w-full bg-brand-900 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2">
                        <i class="ph ph-check-circle text-xl"></i> Save Integrations
                    </button>

                    <button type="button" onclick="sendDailySummary()" id="daily-summary-btn" class="w-full bg-sky-600 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2 mt-3">
                        <i class="ph ph-chart-bar text-xl"></i> Send Daily Summary Now
                    </button>
                </form>
            </div>

            <!-- Guest Portal Settings Tab -->
            <div id="content-guest-portal" class="pb-24 max-w-2xl mx-auto" style="display:none">
                <form id="guestPortalSettingsForm" onsubmit="submitGuestPortalSettings(event)" class="space-y-6">
                    <div class="card-minimal p-6">
                        <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <i class="ph ph-envelope-open text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Guest Portal Configuration</h2>
                                <p class="text-xs text-brand-900/70">Customizable online portal settings for checked-in guests</p>
                            </div>
                        </div>

                        <div class="space-y-5">
                            
                            <!-- Direct Portal Link -->
                            <div class="p-4 bg-indigo-50 border border-indigo-100 rounded-xl">
                                <div class="flex justify-between items-center flex-wrap gap-4">
                                    <div>
                                        <span class="text-sm font-bold text-indigo-900 block">Direct Portal Link</span>
                                        <p class="text-[11px] text-indigo-700">Share this link with your guests to log in directly to your property.</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <?php $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://"; ?>
                                        <input type="text" readonly value="<?= htmlspecialchars($protocol . $_SERVER['HTTP_HOST'] . '/guest-login?hotelId=' . $propertyId, ENT_QUOTES, 'UTF-8') ?>" class="bg-white border border-indigo-200 px-3 py-2 rounded-lg text-xs font-mono text-indigo-900 w-64 focus:outline-none" onclick="this.select()">
                                        <button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($protocol . $_SERVER['HTTP_HOST'] . '/guest-login?hotelId=' . $propertyId, ENT_QUOTES, 'UTF-8') ?>'); alert('Copied to clipboard!')" class="p-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition" title="Copy Link">
                                            <i class="ph ph-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <!-- Loyalty Settings -->
                            <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800 block">Enable Guest Loyalty Badges</span>
                                    <p class="text-[11px] text-slate-500">Classify guests into tiers (Silver, Gold, Platinum) with dynamic badges.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="portal_loyalty_enabled" <?= htmlspecialchars((string)($loyaltyEnabled ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>
                            <div class="pl-4 border-l-2 border-brand-200 grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 uppercase tracking-wider">Gold Tier Min. Stays</label>
                                    <input type="number" id="portal_loyalty_gold" value="<?= htmlspecialchars((string)($loyaltyGold), ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 uppercase tracking-wider">Platinum Tier Min. Stays</label>
                                    <input type="number" id="portal_loyalty_platinum" value="<?= htmlspecialchars((string)($loyaltyPlatinum), ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none font-mono">
                                </div>
                            </div>

                            <!-- Pre-Arrival Settings -->
                            <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800 block">Enable pre-Arrival Check-in Workflow</span>
                                    <p class="text-[11px] text-slate-500">Allow guests to check-in online prior to their physical arrival.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="portal_pre_arrival_enabled" <?= htmlspecialchars((string)($preArrivalEnabled ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>
                            <div class="pl-4 border-l-2 border-brand-200 space-y-2">
                                <label class="flex items-center gap-2 text-xs font-semibold text-brand-800">
                                    <input type="checkbox" id="portal_pre_arrival_signature" <?= htmlspecialchars((string)($preArrivalSignature ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?>> Require Digital Signature
                                </label>
                                <label class="flex items-center gap-2 text-xs font-semibold text-brand-800">
                                    <input type="checkbox" id="portal_pre_arrival_doc" <?= htmlspecialchars((string)($preArrivalDoc ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?>> Require Government ID Upload
                                </label>
                            </div>

                            <!-- Upselling Toggle & Prices -->
                            <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800 block">Enable Guest Upselling Requests</span>
                                    <p class="text-[11px] text-slate-500">Allow guests to buy upgrades/packages directly in the portal.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="portal_upsell_enabled" <?= htmlspecialchars((string)($upsellEnabled ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <div class="pl-4 border-l-2 border-brand-200 grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-[10px] font-bold text-brand-900 uppercase tracking-wider">Early/Late Check Fee</label>
                                    <input type="number" step="0.01" id="portal_early_late_fee" value="<?= htmlspecialchars((string)(number_format($earlyLateFee, 2, '.', '')), ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-xl text-sm outline-none font-mono">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-brand-900 uppercase tracking-wider">Breakfast Buffet Fee</label>
                                    <input type="number" step="0.01" id="portal_upsell_breakfast_price" value="<?= htmlspecialchars((string)(number_format($upsellBreakfastPrice, 2, '.', '')), ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-xl text-sm outline-none font-mono">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-bold text-brand-900 uppercase tracking-wider">Airport Cab Transfer</label>
                                    <input type="number" step="0.01" id="portal_upsell_transfer_price" value="<?= htmlspecialchars((string)(number_format($upsellTransferPrice, 2, '.', '')), ENT_QUOTES, 'UTF-8') ?>" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-xl text-sm outline-none font-mono">
                                </div>
                            </div>

                            <!-- POS Toggle -->
                            <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800 block">Enable Guest Portal POS</span>
                                    <p class="text-[11px] text-slate-500">Allow guests to order food, beverages, and items from the POS menu.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="portal_pos_enabled" <?= htmlspecialchars((string)($posEnabled ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <!-- WhatsApp OTP Toggle -->
                            <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800 block">Require WhatsApp OTP Verification</span>
                                    <p class="text-[11px] text-slate-500">Send verification code to guest phone when searching by mobile.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="portal_otp_enabled" <?= htmlspecialchars((string)($otpEnabled ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <!-- Housekeeping Toggle -->
                            <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800 block">Enable Online Housekeeping Requests</span>
                                    <p class="text-[11px] text-slate-500">Guests can request room cleaning and service directly from their browser.</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="portal_housekeeping_enabled" <?= htmlspecialchars((string)($housekeepingEnabled ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <!-- Self-Checkout Toggle -->
                            <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl border border-brand-200/50">
                                <div>
                                    <span class="text-sm font-bold text-brand-800 block">Enable Guest Self-Checkout</span>
                                    <p class="text-[11px] text-slate-500">Allow guests to mark their checkout online (if balance is zero).</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="portal_self_checkout_enabled" <?= htmlspecialchars((string)($selfCheckoutEnabled ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <!-- === PROPERTY INFO FOR GUEST PORTAL === -->
                            <div class="pt-4 mt-2 border-t-2 border-dashed border-brand-200">
                                <div class="flex items-center gap-2 mb-4">
                                    <div class="w-8 h-8 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center">
                                        <i class="ph ph-info text-lg"></i>
                                    </div>
                                    <div>
                                        <span class="text-sm font-bold text-brand-800 block">Guest Portal Info Cards</span>
                                        <p class="text-[11px] text-slate-500">Displayed on the guest's portal home screen</p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-brand-900 uppercase tracking-wider mb-1">WiFi Network (SSID)</label>
                                        <input type="text" id="portal_wifi_ssid" value="<?= htmlspecialchars((string)($portalWifiSsid)) ?>" placeholder="Hotel_Guest_WiFi" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none font-mono focus:border-indigo-400 focus:bg-white transition">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-brand-900 uppercase tracking-wider mb-1">WiFi Password</label>
                                        <input type="text" id="portal_wifi_password" value="<?= htmlspecialchars((string)($portalWifiPassword)) ?>" placeholder="Wi-Fi password" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none font-mono focus:border-indigo-400 focus:bg-white transition">
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-xs font-bold text-brand-900 uppercase tracking-wider mb-1">Help Desk / WhatsApp Number</label>
                                    <input type="text" id="portal_help_desk_no" value="<?= htmlspecialchars((string)($portalHelpDeskNo)) ?>" placeholder="+91 98765 43210" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none font-mono focus:border-indigo-400 focus:bg-white transition">
                                    <p class="text-[10px] text-slate-400 mt-1">Guests can tap to WhatsApp/call this number for help</p>
                                </div>
                                <div class="mt-4">
                                    <label class="block text-xs font-bold text-brand-900 uppercase tracking-wider mb-1">Local Attractions / Sightseeing</label>
                                    <textarea id="portal_local_attractions" rows="4" placeholder="1. Taj Mahal - 5 km&#10;2. City Museum - 2 km&#10;3. Local Market - 1 km" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none font-mono focus:border-indigo-400 focus:bg-white transition resize-none"><?= htmlspecialchars((string)($portalLocalAttractions)) ?></textarea>
                                    <p class="text-[10px] text-slate-400 mt-1">One attraction per line — displayed as a visual list for guests</p>
                                </div>
                            </div>

                        </div>
                    </div>

                    <button type="submit" class="w-full bg-brand-900 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2">
                        <i class="ph ph-check-circle text-xl"></i> Save Portal Settings
                    </button>
                </form>
            </div>

            <!-- 5. Payment Methods Tab -->
            <div id="content-payments" class="pb-24 max-w-2xl mx-auto space-y-6" style="display:none">
                <form id="paymentMethodsForm" onsubmit="submitPaymentMethods(event)" class="space-y-6">
                    <div class="card-minimal p-6 ">
                        <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                                <i class="ph ph-wallet text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Payment Methods</h2>
                                <p class="text-xs text-brand-900/70">Configure global payment options</p>
                            </div>
                        </div>
                        <div id="pmList" class="space-y-3">
                            <?php foreach($paymentMethods as $pm): ?>
                                <div class="flex items-center gap-2">
                                    <input type="text" name="payment_methods[]" value="<?= htmlspecialchars((string)($pm)) ?>" required class="flex-1 bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:border-blue-500 font-bold text-brand-900">
                                    <button type="button" onclick="this.parentElement.remove()" class="w-11 h-11 flex-shrink-0 bg-error-50 text-error-600 rounded-xl flex items-center justify-center hover:bg-error-100"><i class="ph ph-trash text-lg"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addPaymentMethodRow()" class="text-sm font-bold text-brand-accent flex items-center gap-1 hover:bg-brand-accentLight py-2 px-3 rounded-lg mt-4 transition-colors"><i class="ph ph-plus"></i> Add Method</button>
                    </div>

                    <div class="card-minimal p-6 mt-6">
                        <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-orange-50 text-orange-600 flex items-center justify-center">
                                <i class="ph ph-tag text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Payment Categories</h2>
                                <p class="text-xs text-brand-900/70">Configure categories for revenue tracking (e.g. Room Revenue, F&B)</p>
                            </div>
                        </div>
                        <div id="pcList" class="space-y-3">
                            <?php foreach($paymentCategories as $pc): ?>
                                <div class="flex items-center gap-2">
                                    <input type="text" name="payment_categories[]" value="<?= htmlspecialchars((string)($pc)) ?>" required class="flex-1 bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:border-blue-500 font-bold text-brand-900">
                                    <button type="button" onclick="this.parentElement.remove()" class="w-11 h-11 flex-shrink-0 bg-error-50 text-error-600 rounded-xl flex items-center justify-center hover:bg-error-100"><i class="ph ph-trash text-lg"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addPaymentCategoryRow()" class="text-sm font-bold text-brand-accent flex items-center gap-1 hover:bg-brand-accentLight py-2 px-3 rounded-lg mt-4 transition-colors"><i class="ph ph-plus"></i> Add Category</button>
                    </div>

                    <button type="submit" id="savePmBtn" class="w-full bg-brand-900 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2 mt-6">
                        <i class="ph ph-check-circle text-xl"></i> Save Payment Config
                    </button>
                </form>

                <div class="card-minimal p-6">
                    <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                        <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <i class="ph ph-plug text-2xl"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-brand-900">Payment Gateways</h2>
                            <p class="text-xs text-brand-900/70">Configure property-specific gateway credentials</p>
                        </div>
                    </div>
                    
                    <form onsubmit="submitGatewayConfig(event, 'razorpay')" class="space-y-4 border-b border-slate-100 pb-6 mb-6">
                        <div class="flex items-center justify-between">
                            <h3 class="font-bold text-slate-800">Razorpay</h3>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="is_active" class="sr-only peer" <?= htmlspecialchars((string)(($pgConfigs['razorpay']['is_active'] ?? 1) ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?>>
                                <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                                <span class="ml-2 text-xs font-bold text-slate-600 uppercase tracking-wide">Active</span>
                            </label>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Mode</label>
                                <select name="mode" class="w-full border-slate-200 rounded-lg text-sm p-2 outline-none">
                                    <option value="test" <?= htmlspecialchars((string)(($pgConfigs['razorpay']['mode'] ?? 'test') === 'test' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Test</option>
                                    <option value="live" <?= htmlspecialchars((string)(($pgConfigs['razorpay']['mode'] ?? 'test') === 'live' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Live</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Key ID</label>
                                <input type="text" name="key_id" value="<?= htmlspecialchars((string)($pgConfigs['razorpay']['key_id'] ?? '')) ?>" class="w-full border-slate-200 rounded-lg text-sm p-2 outline-none">
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Key Secret</label>
                                <input type="password" name="key_secret" value="" autocomplete="new-password" placeholder="Leave blank to keep current secret" class="w-full border-slate-200 rounded-lg text-sm p-2 outline-none">
                            </div>
                        </div>
                        <button type="submit" class="bg-indigo-50 text-indigo-700 font-bold px-4 py-2 rounded-lg text-sm hover:bg-indigo-100">Save Razorpay Config</button>
                    </form>
                    
                    <form onsubmit="submitGatewayConfig(event, 'phonepe')" class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="font-bold text-slate-800">PhonePe</h3>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="is_active" class="sr-only peer" <?= htmlspecialchars((string)(($pgConfigs['phonepe']['is_active'] ?? 1) ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?>>
                                <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                                <span class="ml-2 text-xs font-bold text-slate-600 uppercase tracking-wide">Active</span>
                            </label>
                        </div>
                        <?php 
                            $peExtra = json_decode($pgConfigs['phonepe']['extra_config'] ?? '{}', true);
                        ?>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Mode</label>
                                <select name="mode" class="w-full border-slate-200 rounded-lg text-sm p-2 outline-none">
                                    <option value="test" <?= htmlspecialchars((string)(($pgConfigs['phonepe']['mode'] ?? 'test') === 'test' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Test (UAT)</option>
                                    <option value="live" <?= htmlspecialchars((string)(($pgConfigs['phonepe']['mode'] ?? 'test') === 'live' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Live</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Merchant ID</label>
                                <input type="text" name="key_id" value="<?= htmlspecialchars((string)($pgConfigs['phonepe']['key_id'] ?? '')) ?>" class="w-full border-slate-200 rounded-lg text-sm p-2 outline-none">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Salt Key</label>
                                <input type="password" name="key_secret" value="" autocomplete="new-password" placeholder="Leave blank to keep current secret" class="w-full border-slate-200 rounded-lg text-sm p-2 outline-none">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">Salt Index</label>
                                <input type="number" name="salt_index" value="<?= htmlspecialchars((string)($peExtra['salt_index'] ?? '1')) ?>" class="w-full border-slate-200 rounded-lg text-sm p-2 outline-none">
                            </div>
                        </div>
                        <button type="submit" class="bg-indigo-50 text-indigo-700 font-bold px-4 py-2 rounded-lg text-sm hover:bg-indigo-100">Save PhonePe Config</button>
                    </form>
                </div>
            </div>

            <div id="content-staff" class="pb-24" style="display:none">
                <div class="flex items-center justify-between mb-4">
                    <p class="text-sm font-bold text-brand-900/70"><?= htmlspecialchars((string)(count($staffUsers)), ENT_QUOTES, 'UTF-8') ?> user(s)</p>
                    <div class="flex items-center gap-2">
                        <button onclick="openInviteModal()" class="text-sm font-bold text-indigo-600 bg-indigo-50 flex items-center gap-1 hover:bg-indigo-100 py-2 px-3 rounded-lg transition-colors"><i class="ph ph-envelope-simple-open"></i> Invite via Link</button>
                        <button onclick="openStaffModal()" class="text-sm font-bold text-brand-accent flex items-center gap-1 hover:bg-brand-accentLight py-2 px-3 rounded-lg transition-colors"><i class="ph ph-plus"></i> Add User</button>
                    </div>
                </div>
                <div class="space-y-2">
                    <?php 
                    foreach($staffUsers as $su): 
                        $suRole = $su['role'] ?? $su['access_level'] ?? 'manager';
                        if ($suRole === 'front_desk') $suRole = 'manager';
                        
                        $roleBadge = 'bg-blue-50 text-blue-700 border-blue-200';
                        $permSummary = 'Access to bookings, payments, and guests';
                        
                        if ($suRole === 'owner') {
                            $roleBadge = 'bg-purple-50 text-purple-700 border-purple-200';
                            $permSummary = 'Full administrative control';
                        } elseif ($suRole === 'housekeeping') {
                            $roleBadge = 'bg-teal-50 text-teal-700 border-teal-200';
                            $permSummary = 'Clean/Dirty room list view';
                        }
                        
                        $isActive = !isset($su['is_active']) || (int)$su['is_active'] === 1;
                    ?>
                    <div class="card-minimal p-4 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center border border-slate-200 shrink-0">
                                <span class="text-slate-700 font-bold text-sm"><?= htmlspecialchars((string)(strtoupper(substr($su['username'], 0, 1)))) ?></span>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-brand-900"><?= htmlspecialchars((string)($su['username'])) ?></span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-lg border text-[9px] font-black uppercase <?= htmlspecialchars((string)($roleBadge), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)(ucfirst($suRole)), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if ($isActive): ?>
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Active</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-1.5 py-0.2 rounded-full text-[9px] font-bold bg-slate-100 text-slate-500 border border-slate-200">Suspended</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[11px] text-slate-500 font-medium mt-0.5"><?= htmlspecialchars((string)($permSummary), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if (isset($su['last_login_at']) && $su['last_login_at']): ?>
                                    <p class="text-[9px] text-slate-400 font-bold mt-1">Last Login: <?= htmlspecialchars((string)(date('M j, H:i', strtotime($su['last_login_at']))), ENT_QUOTES, 'UTF-8') ?> · IP: <?= htmlspecialchars((string)($su['last_login_ip'] ?? '')) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php 
                            $staffDropdownValue = (!empty($su['role_id'])) ? 'custom_' . $su['role_id'] : $su['access_level'];
                        ?>
                        <div class="flex gap-2 self-end md:self-auto">
                            <button onclick="editStaff(<?= htmlspecialchars((string)($su['id']), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars((string)(addslashes($su['username']))) ?>', '<?= htmlspecialchars((string)($staffDropdownValue)) ?>', <?= htmlspecialchars((string)($isActive ? 1 : 0), ENT_QUOTES, 'UTF-8') ?>)" class="w-10 h-10 rounded-full bg-brand-50 text-brand-900 flex items-center justify-center hover:bg-brand-100 transition-colors" title="Edit User">
                                <i class="ph ph-pencil-simple text-base"></i>
                            </button>
                            <?php if($su['id'] != $_SESSION['user_id']): ?>
                            <button onclick="deleteStaff(<?= htmlspecialchars((string)($su['id']), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars((string)(addslashes($su['username'])), ENT_QUOTES, 'UTF-8') ?>')" class="w-10 h-10 rounded-full bg-error-50 text-error-600 flex items-center justify-center hover:bg-error-100 transition-colors" title="Suspend User">
                                <i class="ph ph-trash text-base"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>            <!-- Custom Roles Config Tab -->
            <div id="content-roles" class="pb-24 max-w-3xl mx-auto space-y-6" style="display:none">
                <div class="card-minimal p-6">
                    <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100">
                        <div>
                            <h2 class="font-extrabold text-brand-900 text-xl">Roles & Permissions</h2>
                            <p class="text-sm font-semibold text-slate-500 mt-1">Built-in roles and custom roles assigned to staff</p>
                        </div>
                        <button onclick="openRoleModal()" class="bg-brand-900 hover:bg-brand-800 text-white font-bold px-4 py-2.5 rounded-xl transition-colors shadow-sm flex items-center gap-2">
                            <i class="ph ph-plus-circle text-lg"></i> Create Custom Role
                        </button>
                    </div>

                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">System roles</h3>
                    <div class="space-y-3 mb-8">
                        <?php foreach ($builtInRoles as $sysRole): ?>
                            <details class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                                <summary class="cursor-pointer list-none p-4 flex items-center justify-between hover:bg-slate-50">
                                    <div>
                                        <h3 class="font-bold text-brand-900 text-base"><?= htmlspecialchars($sysRole['label']) ?></h3>
                                        <p class="text-xs font-semibold text-slate-500 mt-0.5"><span class="text-indigo-600 font-bold"><?= count($sysRole['permissions']) ?></span> permissions · read-only</p>
                                    </div>
                                    <i class="ph ph-caret-down text-slate-400"></i>
                                </summary>
                                <div class="px-4 pb-4 flex flex-wrap gap-1.5 border-t border-slate-100 pt-3">
                                    <?php foreach ($sysRole['permission_labels'] as $plabel): ?>
                                        <span class="text-[10px] font-semibold uppercase tracking-wide bg-slate-100 text-slate-600 px-2 py-1 rounded-full"><?= htmlspecialchars($plabel) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Custom roles</h3>
                    <div id="roles-list-container" class="space-y-3">
                        <?php
                        $userDefinedRoles = array_filter($customRoles, static function ($r) {
                            return empty($r['is_system']);
                        });
                        ?>
                        <?php if (empty($userDefinedRoles)): ?>
                            <div class="text-center py-10 bg-slate-50 rounded-2xl border border-slate-100 border-dashed">
                                <i class="ph ph-shield-check text-4xl text-slate-300 mb-3 block"></i>
                                <p class="text-sm text-slate-500 font-bold">No custom roles created yet.</p>
                                <p class="text-xs text-slate-400 mt-1">Staff can use the system roles above, or create a custom role.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($userDefinedRoles as $crole): ?>
                                <?php 
                                    $rawPerms = $crole['permissions'] ?? '[]';
                                    $cperms = json_decode($rawPerms, true);
                                    if (!is_array($cperms)) $cperms = [];
                                    $flatPerms = [];
                                    array_walk_recursive($cperms, function($a) use (&$flatPerms) { $flatPerms[] = $a; });
                                    $permCount = count($flatPerms);
                                    $jsRoleName = htmlspecialchars((string)(json_encode($crole['name'])), ENT_QUOTES, 'UTF-8');
                                ?>
                                <details class="bg-white border border-slate-200 rounded-xl hover:border-slate-300 transition-all shadow-sm overflow-hidden">
                                    <summary class="cursor-pointer list-none p-4 flex items-center justify-between">
                                        <div>
                                            <h3 class="font-bold text-brand-900 text-base"><?= htmlspecialchars((string)($crole['name'])) ?></h3>
                                            <p class="text-xs font-semibold text-slate-500 mt-0.5"><span class="text-indigo-600 font-bold"><?= htmlspecialchars((string)($permCount), ENT_QUOTES, 'UTF-8') ?></span> permissions assigned</p>
                                        </div>
                                        <div class="flex gap-2" onclick="event.preventDefault(); event.stopPropagation();">
                                            <button type="button" data-role-id="<?= htmlspecialchars((string)((int)$crole['id']), ENT_QUOTES, 'UTF-8') ?>" data-role-name="<?= htmlspecialchars((string)($crole['name'])) ?>" data-role-perms="<?= htmlspecialchars((string)(json_encode($flatPerms)), ENT_QUOTES, 'UTF-8') ?>" onclick="editRoleFromBtn(this)" class="w-10 h-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center hover:bg-brand-50 hover:text-brand-600 transition-colors" title="Edit Role">
                                                <i class="ph ph-pencil-simple text-base"></i>
                                            </button>
                                            <button type="button" onclick="deleteRole(<?= htmlspecialchars((string)((int)$crole['id']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars((string)($jsRoleName), ENT_QUOTES, 'UTF-8') ?>)" class="w-10 h-10 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center hover:bg-rose-100 transition-colors" title="Delete Role">
                                                <i class="ph ph-trash text-base"></i>
                                            </button>
                                        </div>
                                    </summary>
                                    <div class="px-4 pb-4 flex flex-wrap gap-1.5 border-t border-slate-100 pt-3">
                                        <?php foreach ($flatPerms as $pkey): ?>
                                            <span class="text-[10px] font-semibold uppercase tracking-wide bg-indigo-50 text-indigo-700 px-2 py-1 rounded-full"><?= htmlspecialchars((string)($permissionLabels[$pkey] ?? $pkey)) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Housekeeping Checklist Config Tab -->
            <div id="content-housekeeping" class="pb-24 max-w-3xl mx-auto space-y-6" style="display:none">
                <div class="card-minimal p-6">
                    <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                        <div>
                            <h2 class="font-bold text-slate-900 text-lg">Housekeeping Cleaning Checklist</h2>
                            <p class="text-xs text-slate-500 font-semibold">Configure task items for staff room cleaning & inspection</p>
                        </div>
                    </div>

                    <!-- Deep Clean Config -->
                    <div class="mb-6 p-4 bg-slate-50 rounded-2xl border border-slate-200">
                        <h3 class="font-bold text-sm text-slate-800 mb-2">Deep Cleaning Schedule</h3>
                        <p class="text-xs text-slate-500 mb-3">Set how often a room requires a deep clean (in days).</p>
                        <div class="flex items-center gap-3">
                            <input type="number" id="deep_clean_frequency" min="0" value="<?= htmlspecialchars((string)(defined('DEEP_CLEAN_FREQ_DAYS') ? DEEP_CLEAN_FREQ_DAYS : '15')) ?>" class="w-24 bg-white border border-slate-300 p-2 rounded-lg text-sm font-bold text-slate-900 outline-none focus:border-indigo-500">
                            <span class="text-sm font-semibold text-slate-600">days</span>
                            <button onclick="saveDeepCleanFreq()" class="ml-4 bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs px-4 py-2 rounded-lg transition-all">Save</button>
                        </div>
                    </div>

                    <!-- Add New Item Form -->
                    <form onsubmit="addHkChecklistItem(event)" class="flex flex-col sm:flex-row gap-3 mb-6 p-4 bg-slate-50 rounded-2xl border border-slate-200">
                        <input type="text" id="hk_item_text" placeholder="e.g. Disinfect TV & AC Remotes" required class="flex-1 bg-white border border-slate-300 p-3 rounded-xl text-sm font-semibold outline-none focus:border-indigo-500">
                        <label class="flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer px-2">
                            <input type="checkbox" id="hk_item_mandatory" checked class="w-4 h-4 accent-indigo-600">
                            Mandatory Item
                        </label>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs uppercase px-5 py-3 rounded-xl transition-all shadow-sm">
                            Add Item
                        </button>
                    </form>

                    <!-- Active Checklist Items List -->
                    <?php
                    $hkItems = $db->query("SELECT * FROM housekeeping_checklist_items ORDER BY display_order ASC, id ASC")->fetchAll();
                    ?>
                    <div id="hk-checklist-items-admin" class="space-y-2">
                        <?php if (empty($hkItems)): ?>
                            <p class="text-xs text-slate-400 font-semibold text-center py-6">No checklist items configured.</p>
                        <?php else: ?>
                            <?php foreach ($hkItems as $item): ?>
                                <div class="flex items-center justify-between p-3.5 bg-white border border-slate-200 rounded-xl hover:border-slate-300 transition-all">
                                    <div class="flex items-center gap-3">
                                        <i class="ph ph-check-square text-indigo-600 text-xl"></i>
                                        <span class="font-bold text-sm text-slate-800"><?= htmlspecialchars((string)($item['item_text'])) ?></span>
                                        <?php if ((int)$item['is_mandatory'] === 1): ?>
                                            <span class="px-2 py-0.5 rounded text-[9px] font-black bg-rose-50 text-rose-600 border border-rose-200 uppercase">Mandatory</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-[9px] font-black bg-slate-100 text-slate-500 border border-slate-200 uppercase">Optional</span>
                                        <?php endif; ?>
                                    </div>
                                    <button onclick="deleteHkChecklistItem(<?= htmlspecialchars((string)($item['id']), ENT_QUOTES, 'UTF-8') ?>)" class="w-8 h-8 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center hover:bg-rose-100 transition-colors" title="Delete Item">
                                        <i class="ph ph-trash text-sm"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 7. Property & Tax Tab -->
            <div id="content-property" class="pb-24 max-w-2xl mx-auto" style="display:none">
                <!-- Live Branding Preview -->
                <div class="card-minimal p-4 mb-4 flex items-center gap-4">
                    <div id="preview-logo-wrap">
                        <?php if($currentLogoB64): ?>
                        <img id="preview-logo" src="data:image/png;base64,<?= htmlspecialchars((string)($currentLogoB64)) ?>" class="w-14 h-14 rounded-xl object-cover border border-slate-200" alt="Hotel Logo">
                        <?php else: ?>
                        <div id="preview-logo-placeholder" class="w-14 h-14 rounded-xl bg-slate-900 flex items-center justify-center text-white text-xl">
                            <i class="ph ph-buildings"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p id="preview-hotel-name" class="text-lg font-extrabold text-slate-900 font-display leading-tight"><?= htmlspecialchars((string)($currentHotelName)) ?></p>
                        <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Live Preview</p>
                    </div>
                </div>

                <form id="propertyForm" onsubmit="submitPropertySettings(event)" class="space-y-6">
                    <!-- Property Details -->
                    <div class="card-minimal p-5">
                        <div class="flex items-center gap-3 mb-4 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-brand-accentLight text-brand-accent flex items-center justify-center">
                                <i class="ph ph-buildings text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Property Details</h2>
                                <p class="text-xs text-brand-900/70">Receipt Branding &amp; Contact Info</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Property Name</label>
                                <input type="text" name="PROPERTY_NAME" id="property_name_input"
                                    oninput="document.getElementById('preview-hotel-name').textContent = this.value || 'Your Hotel'"
                                    value="<?= htmlspecialchars((string)($currentHotelName)) ?>" 
                                    class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-bold">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Address</label>
                                <textarea name="PROPERTY_ADDRESS" rows="3" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-bold"><?= htmlspecialchars((string)(defined('PROPERTY_ADDRESS') ? PROPERTY_ADDRESS : '')) ?></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Phone / Contact</label>
                                <input type="text" name="PROPERTY_PHONE" value="<?= htmlspecialchars((string)(defined('PROPERTY_PHONE') ? PROPERTY_PHONE : '')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-bold">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Email</label>
                                <input type="email" name="PROPERTY_EMAIL" value="<?= htmlspecialchars((string)(defined('PROPERTY_EMAIL') ? PROPERTY_EMAIL : '')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-bold">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Wi-Fi Network SSID (Smart Reply)</label>
                                    <input type="text" name="PROPERTY_WIFI_NAME" value="<?= htmlspecialchars((string)(defined('PROPERTY_WIFI_NAME') ? PROPERTY_WIFI_NAME : 'Hotel_Guest_WiFi')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-bold">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Wi-Fi Password (Smart Reply)</label>
                                    <input type="text" name="PROPERTY_WIFI_PASS" value="<?= htmlspecialchars((string)(defined('PROPERTY_WIFI_PASS') ? PROPERTY_WIFI_PASS : '')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-bold">
                                </div>
                            </div>
                            <!-- Logo Upload -->
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-2 uppercase tracking-wider">Hotel Logo</label>
                                <div class="border-2 border-dashed border-slate-200 rounded-2xl p-4 text-center cursor-pointer hover:border-slate-400 hover:bg-slate-50 transition-all" onclick="document.getElementById('property_logo_file').click()">
                                    <?php if($currentLogoB64): ?>
                                    <img id="logo-preview" src="data:image/png;base64,<?= htmlspecialchars((string)($currentLogoB64)) ?>" class="w-20 h-20 rounded-xl mx-auto object-cover mb-2" alt="Current Logo">
                                    <p class="text-xs text-slate-500 font-semibold">Click to replace logo</p>
                                    <?php else: ?>
                                    <div class="w-14 h-14 bg-slate-100 rounded-xl mx-auto flex items-center justify-center mb-2">
                                        <i class="ph ph-image text-2xl text-slate-400"></i>
                                    </div>
                                    <p class="text-xs font-bold text-slate-600">Click to upload logo</p>
                                    <p class="text-[10px] text-slate-400 mt-0.5">PNG, JPG, SVG — shown in admin header</p>
                                    <?php endif; ?>
                                    <input type="file" id="property_logo_file" accept="image/*" onchange="convertLogoToBase64()" class="hidden">
                                    <input type="hidden" name="PROPERTY_LOGO_BASE64" id="property_logo_base64" value="<?= htmlspecialchars((string)($currentLogoB64)) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Taxation -->
                    <div class="card-minimal p-5">
                        <div class="flex items-center gap-3 mb-4 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                                <i class="ph ph-percent text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Taxation</h2>
                                <p class="text-xs text-brand-900/70">Configure automatic tax calculations</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <label class="flex items-center gap-3 p-3 bg-brand-50 rounded-xl cursor-pointer hover:bg-brand-100 transition-colors">
                                <input type="checkbox" id="tax_enabled_checkbox" <?= htmlspecialchars((string)((defined('TAX_ENABLED') && TAX_ENABLED === 'true') ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> onchange="document.getElementById('TAX_ENABLED').value = this.checked ? 'true' : 'false'" class="w-5 h-5 rounded border-brand-300 text-sky-600 focus:ring-sky-500">
                                <input type="hidden" name="TAX_ENABLED" id="TAX_ENABLED" value="<?= htmlspecialchars((string)(defined('TAX_ENABLED') ? TAX_ENABLED : 'false'), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-bold text-brand-800">Enable Tax</span>
                                    <p class="text-[11px] text-brand-900/70 mt-0.5">Automatically apply tax calculations to booking totals and receipts</p>
                                </div>
                            </label>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Tax Label (e.g. GST, VAT)</label>
                                    <input type="text" name="TAX_LABEL" value="<?= htmlspecialchars((string)(defined('TAX_LABEL') ? TAX_LABEL : 'GST')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-bold">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Tax Rate (%)</label>
                                    <input type="number" name="TAX_RATE" step="0.01" min="0" value="<?= htmlspecialchars((string)(defined('TAX_RATE') ? TAX_RATE : '12')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-bold">
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- WhatsApp API Configuration -->
                    <div class="card-minimal p-5 mt-6">
                        <div class="flex items-center gap-3 mb-4 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center">
                                <i class="ph ph-whatsapp-logo text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">WhatsApp API Integration</h2>
                                <p class="text-xs text-brand-900/70">Configure Meta Cloud API credentials</p>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Access Token</label>
                                <input type="password" name="WHATSAPP_TOKEN" value="" autocomplete="new-password" placeholder="Leave blank to keep current token" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Phone Number ID</label>
                                    <input type="text" name="WHATSAPP_PHONE_NUMBER_ID" value="<?= htmlspecialchars((string)(defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">WABA ID</label>
                                    <input type="text" name="WHATSAPP_WABA_ID" value="<?= htmlspecialchars((string)(defined('WHATSAPP_WABA_ID') ? WHATSAPP_WABA_ID : '')) ?>" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm outline-none focus:shadow-minimal transition-all font-mono">
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" id="savePropertyBtn" class="w-full bg-brand-900 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2">
                        <i class="ph ph-check-circle text-xl"></i> Save Settings
                    </button>
                </form>
            </div>
            <!-- 8. Folio Items Tab -->
            <div id="content-folio-items" class="pb-24 max-w-2xl mx-auto" style="display:none">
                <form id="folioItemsForm" onsubmit="submitFolioItems(event)" class="space-y-6">
                    <div class="card-minimal p-6">
                        <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-orange-50 text-orange-600 flex items-center justify-center">
                                <i class="ph ph-receipt text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Folio Quick Charges</h2>
                                <p class="text-xs text-brand-900/70">Configure presets for quick billing</p>
                            </div>
                        </div>
                        
                        <?php
                        $qcJson = get_db_setting($db, 'folio_quick_charges', (int)$propId, '[]');
                        $qcArray = json_decode($qcJson, true);
                        if (!is_array($qcArray) || empty($qcArray)) {
                            $qcArray = [
                                ['name' => 'Breakfast', 'icon' => 'ph-coffee', 'amount' => 150, 'desc' => 'Morning Buffet'],
                                ['name' => 'Laundry', 'icon' => 'ph-washing-machine', 'amount' => 100, 'desc' => 'Per Bag'],
                                ['name' => 'Room Service', 'icon' => 'ph-fork-knife', 'amount' => 200, 'desc' => 'In-Room Dining'],
                                ['name' => 'Mini Bar', 'icon' => 'ph-wine', 'amount' => 300, 'desc' => 'Beverages & Snacks'],
                                ['name' => 'Parking', 'icon' => 'ph-car', 'amount' => 100, 'desc' => 'Valet Parking'],
                                ['name' => 'Extra Person', 'icon' => 'ph-user-plus', 'amount' => 500, 'desc' => 'Extra Bed']
                            ];
                            $qcJson = json_encode($qcArray);
                        }
                        ?>
                        <input type="hidden" id="folio_quick_charges_json" name="folio_quick_charges" value="<?= htmlspecialchars((string)($qcJson)) ?>">
                        
                        <div id="quick-charges-list" class="space-y-3">
                            <!-- Injected via JS -->
                        </div>
                        
                        <button type="button" onclick="addQuickChargeItem()" class="text-sm font-bold text-brand-accent flex items-center gap-1 hover:bg-brand-accentLight py-2 px-3 rounded-lg mt-4 transition-colors">
                            <i class="ph ph-plus-circle"></i> Add Quick Charge
                        </button>
                    </div>
                    <button type="submit" id="saveFolioItemsBtn" class="w-full bg-brand-900 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2">
                        <i class="ph ph-check-circle text-xl"></i> Save Folio Items
                    </button>
                </form>
            </div>

            <!-- 9. Sequences Tab -->
            <div id="content-sequences" class="pb-24 max-w-2xl mx-auto" style="display:none">
                <form id="sequencesForm" onsubmit="submitSequences(event)" class="space-y-6">
                    <div class="card-minimal p-6">
                        <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                <i class="ph ph-hash text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="font-bold text-brand-900">Custom Sequence Formats</h2>
                                <p class="text-xs text-brand-900/70">Define prefix and format for system IDs</p>
                            </div>
                        </div>

                        <div class="bg-indigo-50 border border-indigo-200 text-indigo-800 text-xs p-4 rounded-xl mb-6">
                            <strong>Available Tags:</strong><br>
                            <code>{ID}</code> = Incremental number (e.g. 1)<br>
                            <code>{ID:4}</code> = Zero-padded incremental number (e.g. 0001)<br>
                            <code>{YY}</code> = 2-digit Year (e.g. 26)<br>
                            <code>{YYYY}</code> = 4-digit Year (e.g. 2026)<br>
                            <code>{MM}</code> = 2-digit Month (e.g. 07)<br>
                            <code>{DD}</code> = 2-digit Day (e.g. 15)
                        </div>

                        <div class="space-y-6">
                            <!-- Booking ID -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Booking ID Format</label>
                                    <input type="text" name="SEQ_BOOKING_FORMAT" value="<?= htmlspecialchars((string)(defined('SEQ_BOOKING_FORMAT') ? SEQ_BOOKING_FORMAT : 'BKG-{YY}{MM}-{ID}')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-mono outline-none focus:border-indigo-500 font-bold text-brand-900">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Reset Rule</label>
                                    <div class="relative">
                                        <select name="SEQ_BOOKING_RESET" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500 appearance-none">
                                            <option value="never" <?= htmlspecialchars((string)((defined('SEQ_BOOKING_RESET') && SEQ_BOOKING_RESET === 'never') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Never (Continuous ID)</option>
                                            <option value="monthly" <?= htmlspecialchars((string)((defined('SEQ_BOOKING_RESET') && SEQ_BOOKING_RESET === 'monthly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Monthly (Resets to 1 each month)</option>
                                            <option value="yearly" <?= htmlspecialchars((string)((defined('SEQ_BOOKING_RESET') && SEQ_BOOKING_RESET === 'yearly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Yearly (Resets to 1 each year)</option>
                                            <option value="daily" <?= htmlspecialchars((string)((defined('SEQ_BOOKING_RESET') && SEQ_BOOKING_RESET === 'daily') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Daily (Resets to 1 each day)</option>
                                        </select>
                                        <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Guest ID -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Guest ID Format</label>
                                    <input type="text" name="SEQ_GUEST_FORMAT" value="<?= htmlspecialchars((string)(defined('SEQ_GUEST_FORMAT') ? SEQ_GUEST_FORMAT : 'GST-{YY}{MM}-{ID}')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-mono outline-none focus:border-indigo-500 font-bold text-brand-900">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Reset Rule</label>
                                    <div class="relative">
                                        <select name="SEQ_GUEST_RESET" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500 appearance-none">
                                            <option value="never" <?= htmlspecialchars((string)((defined('SEQ_GUEST_RESET') && SEQ_GUEST_RESET === 'never') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Never (Continuous ID)</option>
                                            <option value="monthly" <?= htmlspecialchars((string)((defined('SEQ_GUEST_RESET') && SEQ_GUEST_RESET === 'monthly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Monthly (Resets to 1 each month)</option>
                                            <option value="yearly" <?= htmlspecialchars((string)((defined('SEQ_GUEST_RESET') && SEQ_GUEST_RESET === 'yearly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Yearly (Resets to 1 each year)</option>
                                            <option value="daily" <?= htmlspecialchars((string)((defined('SEQ_GUEST_RESET') && SEQ_GUEST_RESET === 'daily') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Daily (Resets to 1 each day)</option>
                                        </select>
                                        <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Receipt ID -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Receipt ID Format</label>
                                    <input type="text" name="SEQ_RECEIPT_FORMAT" value="<?= htmlspecialchars((string)(defined('SEQ_RECEIPT_FORMAT') ? SEQ_RECEIPT_FORMAT : 'RCPT-{YY}{MM}-{ID}')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-mono outline-none focus:border-indigo-500 font-bold text-brand-900">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Reset Rule</label>
                                    <div class="relative">
                                        <select name="SEQ_RECEIPT_RESET" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500 appearance-none">
                                            <option value="never" <?= htmlspecialchars((string)((defined('SEQ_RECEIPT_RESET') && SEQ_RECEIPT_RESET === 'never') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Never (Continuous ID)</option>
                                            <option value="monthly" <?= htmlspecialchars((string)((defined('SEQ_RECEIPT_RESET') && SEQ_RECEIPT_RESET === 'monthly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Monthly (Resets to 1 each month)</option>
                                            <option value="yearly" <?= htmlspecialchars((string)((defined('SEQ_RECEIPT_RESET') && SEQ_RECEIPT_RESET === 'yearly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Yearly (Resets to 1 each year)</option>
                                            <option value="daily" <?= htmlspecialchars((string)((defined('SEQ_RECEIPT_RESET') && SEQ_RECEIPT_RESET === 'daily') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Daily (Resets to 1 each day)</option>
                                        </select>
                                        <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Transaction ID -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Transaction ID Format</label>
                                    <input type="text" name="SEQ_TRANSACTION_FORMAT" value="<?= htmlspecialchars((string)(defined('SEQ_TRANSACTION_FORMAT') ? SEQ_TRANSACTION_FORMAT : 'TXN-{YY}{MM}-{ID}')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-mono outline-none focus:border-indigo-500 font-bold text-brand-900">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Reset Rule</label>
                                    <div class="relative">
                                        <select name="SEQ_TRANSACTION_RESET" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500 appearance-none">
                                            <option value="never" <?= htmlspecialchars((string)((defined('SEQ_TRANSACTION_RESET') && SEQ_TRANSACTION_RESET === 'never') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Never (Continuous ID)</option>
                                            <option value="monthly" <?= htmlspecialchars((string)((defined('SEQ_TRANSACTION_RESET') && SEQ_TRANSACTION_RESET === 'monthly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Monthly (Resets to 1 each month)</option>
                                            <option value="yearly" <?= htmlspecialchars((string)((defined('SEQ_TRANSACTION_RESET') && SEQ_TRANSACTION_RESET === 'yearly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Yearly (Resets to 1 each year)</option>
                                            <option value="daily" <?= htmlspecialchars((string)((defined('SEQ_TRANSACTION_RESET') && SEQ_TRANSACTION_RESET === 'daily') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Daily (Resets to 1 each day)</option>
                                        </select>
                                        <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                                    </div>
                                </div>
                            </div>

                            <!-- Folio ID -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-4 border-t border-brand-100">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Folio ID Format</label>
                                    <input type="text" name="SEQ_FOLIO_FORMAT" value="<?= htmlspecialchars((string)(defined('SEQ_FOLIO_FORMAT') ? SEQ_FOLIO_FORMAT : 'FLO-{YY}{MM}-{ID}')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-mono outline-none focus:border-indigo-500 font-bold text-brand-900">
                                    <p class="text-[10px] text-brand-900/50 mt-1">Applied to each folio ledger entry</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Reset Rule</label>
                                    <div class="relative">
                                        <select name="SEQ_FOLIO_RESET" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500 appearance-none">
                                            <option value="never" <?= htmlspecialchars((string)((defined('SEQ_FOLIO_RESET') && SEQ_FOLIO_RESET === 'never') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Never (Continuous ID)</option>
                                            <option value="monthly" <?= htmlspecialchars((string)((defined('SEQ_FOLIO_RESET') && SEQ_FOLIO_RESET === 'monthly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Monthly</option>
                                            <option value="yearly" <?= htmlspecialchars((string)((defined('SEQ_FOLIO_RESET') && SEQ_FOLIO_RESET === 'yearly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Yearly</option>
                                            <option value="daily" <?= htmlspecialchars((string)((defined('SEQ_FOLIO_RESET') && SEQ_FOLIO_RESET === 'daily') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Daily</option>
                                        </select>
                                        <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Reset Threshold (1 to Max Limit)</label>
                                    <input type="number" name="SEQ_FOLIO_MAX" min="1" value="<?= htmlspecialchars((string)(defined('SEQ_FOLIO_MAX') ? SEQ_FOLIO_MAX : '150')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-mono outline-none focus:border-indigo-500 font-bold text-brand-900">
                                    <p class="text-[10px] text-brand-900/50 mt-1">Resets sequence back to 1 when reached (e.g. 150)</p>
                                </div>
                            </div>

                            <!-- POS Order ID -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-brand-100">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">POS Order ID Format</label>
                                    <input type="text" name="SEQ_POS_ORDER_FORMAT" value="<?= htmlspecialchars((string)(defined('SEQ_POS_ORDER_FORMAT') ? SEQ_POS_ORDER_FORMAT : 'ORD-{YY}{MM}-{ID}')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-mono outline-none focus:border-indigo-500 font-bold text-brand-900">
                                    <p class="text-[10px] text-brand-900/50 mt-1">Applied to each POS store order</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Reset Rule</label>
                                    <div class="relative">
                                        <select name="SEQ_POS_ORDER_RESET" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500 appearance-none">
                                            <option value="never" <?= htmlspecialchars((string)((defined('SEQ_POS_ORDER_RESET') && SEQ_POS_ORDER_RESET === 'never') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Never (Continuous ID)</option>
                                            <option value="monthly" <?= htmlspecialchars((string)((defined('SEQ_POS_ORDER_RESET') && SEQ_POS_ORDER_RESET === 'monthly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Monthly</option>
                                            <option value="yearly" <?= htmlspecialchars((string)((defined('SEQ_POS_ORDER_RESET') && SEQ_POS_ORDER_RESET === 'yearly') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Yearly</option>
                                            <option value="daily" <?= htmlspecialchars((string)((defined('SEQ_POS_ORDER_RESET') && SEQ_POS_ORDER_RESET === 'daily') ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Daily</option>
                                        </select>
                                        <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" id="saveSequencesBtn" class="w-full bg-brand-900 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2">
                        <i class="ph ph-check-circle text-xl"></i> Save Sequence Formats
                    </button>
                </form>
            </div>

            <!-- 10. Night Audit Tab -->
            <div id="content-night-audit" class="pb-24 max-w-2xl mx-auto" style="display:none">
                <div class="card-minimal p-6 mb-6">
                    <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                        <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <i class="ph ph-moon-stars text-2xl"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-brand-900">Night Audit Configuration</h2>
                            <p class="text-xs text-brand-900/70">Automated end-of-day process settings</p>
                        </div>
                    </div>

                    <div id="night-audit-status" class="mb-6"></div>

                    <form id="nightAuditForm" class="space-y-6">
                        <!-- Enable/Disable -->
                        <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl">
                            <div>
                                <p class="font-bold text-brand-900">Enable Night Audit</p>
                                <p class="text-xs text-brand-900/70">Run automated end-of-day process</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" id="night_audit_enabled" class="sr-only peer">
                                <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                            </label>
                        </div>

                        <!-- Audit Time -->
                        <div>
                            <label class="block text-xs font-bold text-brand-900 mb-1.5 uppercase tracking-wider">Audit Run Time</label>
                            <input type="time" id="night_audit_time" value="02:00" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500">
                            <p class="text-[10px] text-brand-900/60 mt-1">Time when the night audit runs (24-hour format)</p>
                        </div>

                        <!-- Auto Checkout Section -->
                        <div class="border border-brand-100 rounded-xl p-4 space-y-4">
                            <h3 class="font-bold text-brand-900 text-sm">Overdue Checkout Handling</h3>
                            
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-bold text-brand-900 text-sm">Auto Checkout</p>
                                    <p class="text-[10px] text-brand-900/60">Automatically check out overdue guests</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="night_audit_auto_checkout" class="sr-only peer">
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1.5 uppercase tracking-wider">Grace Period (Hours)</label>
                                <input type="number" id="night_audit_auto_checkout_hours" value="2" min="0" max="24" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500">
                                <p class="text-[10px] text-brand-900/60 mt-1">Hours past checkout before auto-checkout (0 = immediate)</p>
                            </div>

                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-bold text-brand-900 text-sm">Mark Room Dirty</p>
                                    <p class="text-[10px] text-brand-900/60">Mark room as dirty after auto-checkout</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="night_audit_mark_dirty" class="sr-only peer" checked>
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>
                        </div>

                        <!-- Notifications -->
                        <div class="border border-brand-100 rounded-xl p-4 space-y-4">
                            <h3 class="font-bold text-brand-900 text-sm">Notifications</h3>
                            
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="font-bold text-brand-900 text-sm">Telegram Report</p>
                                    <p class="text-[10px] text-brand-900/60">Send audit report via Telegram</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="night_audit_notify_telegram" class="sr-only peer" checked>
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1.5 uppercase tracking-wider">Email Report To (Optional)</label>
                                <input type="email" id="night_audit_notify_email" placeholder="manager@hotel.com" class="w-full bg-brand-50 border border-brand-200 p-3 rounded-xl text-sm font-bold text-brand-900 outline-none focus:border-indigo-500">
                                <p class="text-[10px] text-brand-900/60 mt-1">Send a copy of the Night Audit report to this email address</p>
                            </div>
                        </div>

                        <!-- Report Sections -->
                        <div class="border border-brand-100 rounded-xl p-4 space-y-4">
                            <h3 class="font-bold text-brand-900 text-sm">Report Sections</h3>
                            
                            <div class="flex items-center justify-between">
                                <p class="font-bold text-brand-900 text-sm">Revenue Summary</p>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="night_audit_report_revenue" class="sr-only peer" checked>
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <div class="flex items-center justify-between">
                                <p class="font-bold text-brand-900 text-sm">Occupancy Stats</p>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="night_audit_report_occupancy" class="sr-only peer" checked>
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <div class="flex items-center justify-between">
                                <p class="font-bold text-brand-900 text-sm">Room Status</p>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="night_audit_report_room_status" class="sr-only peer" checked>
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <div class="flex items-center justify-between">
                                <p class="font-bold text-brand-900 text-sm">Booking Activity</p>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="night_audit_report_bookings" class="sr-only peer" checked>
                                    <div class="w-11 h-6 bg-brand-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-brand-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>
                        </div>

                        <button type="button" onclick="saveNightAuditSettings()" class="w-full bg-brand-900 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2">
                            <i class="ph ph-check-circle text-xl"></i> Save Night Audit Settings
                        </button>

                        <button type="button" onclick="runNightAuditNow()" class="w-full bg-indigo-600 text-white font-bold py-4 rounded-xl active:scale-95 transition-transform text-lg flex items-center justify-center gap-2">
                            <i class="ph ph-play text-xl"></i> Run Night Audit Now
                        </button>
                    </form>
                </div>

                <!-- Night Audit Exceptions -->
                <div class="card-minimal p-6">
                    <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                        <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                            <i class="ph ph-warning-circle text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <h2 class="font-bold text-brand-900">Overdue Checkouts (Exceptions)</h2>
                            <p class="text-xs text-brand-900/70">Bookings past their checkout time</p>
                        </div>
                        <button onclick="bulkResolveExceptions()" class="bg-amber-100 hover:bg-amber-200 text-amber-700 font-bold px-4 py-2 rounded-xl text-xs flex items-center gap-2 transition-all">
                            <i class="ph ph-check-square"></i> Auto-Checkout Selected
                        </button>
                    </div>
                    <div class="mb-3 flex items-center gap-2 px-2">
                        <input type="checkbox" id="selectAllExceptions" onchange="toggleAllExceptions(this)" class="w-4 h-4 rounded border-brand-300 text-amber-600 focus:ring-amber-500">
                        <label for="selectAllExceptions" class="text-xs font-bold text-brand-900 cursor-pointer">Select All</label>
                    </div>
                    <div id="audit-exceptions-list" class="space-y-3">
                        <div class="text-center py-4 text-brand-400">Loading exceptions...</div>
                    </div>
                </div>

                <!-- Audit History -->
                <div class="card-minimal p-6">
                    <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                        <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i class="ph ph-clock-counter-clockwise text-2xl"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-brand-900">Audit History</h2>
                            <p class="text-xs text-brand-900/70">Previous night audit runs</p>
                        </div>
                    </div>
                    <div id="audit-history-list" class="space-y-3">
                        <div class="text-center py-4 text-brand-400">Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Subscription & Usage Tab -->
            <div id="content-subscription" class="pb-24 max-w-2xl mx-auto space-y-6" style="display:none">
                <div class="card-minimal p-6">
                    <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                        <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                            <i class="ph ph-sparkle text-2xl"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-brand-900">Your Subscription Plan</h2>
                            <p class="text-xs text-brand-900/70">Plan tier, workspace entitlements and feature list</p>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-slate-900 to-indigo-950 p-6 rounded-2xl border border-indigo-500/20 text-white relative overflow-hidden mb-6">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-500/10 rounded-full blur-2xl"></div>
                        <div class="text-[10px] uppercase font-black text-indigo-300 tracking-widest">Active Plan</div>
                        <div class="text-3xl font-black mt-1"><?= htmlspecialchars((string)($planLimits['name'])) ?> Tier</div>
                        <div class="text-sm text-indigo-200 mt-2">Price: ₹<?= htmlspecialchars((string)(number_format((float)$planLimits['price'], 2)), ENT_QUOTES, 'UTF-8') ?> / month</div>
                        
                        <div class="grid grid-cols-2 gap-4 mt-6 pt-6 border-t border-white/10 text-xs">
                            <div>
                                <span class="text-indigo-300 block uppercase font-bold text-[9px] tracking-wider">Rooms Registered</span>
                                <span class="font-extrabold text-white text-base mt-1 block"><?= htmlspecialchars((string)($roomCount), ENT_QUOTES, 'UTF-8') ?> <span class="text-slate-400 font-normal text-xs">/ <?= htmlspecialchars((string)($planLimits['max_rooms']), ENT_QUOTES, 'UTF-8') ?> Rooms limit</span></span>
                            </div>
                            <div>
                                <span class="text-indigo-300 block uppercase font-bold text-[9px] tracking-wider">Team Occupancy</span>
                                <span class="font-extrabold text-white text-base mt-1 block"><?= htmlspecialchars((string)($staffCount), ENT_QUOTES, 'UTF-8') ?> <span class="text-slate-400 font-normal text-xs">/ <?= htmlspecialchars((string)($planLimits['max_staff']), ENT_QUOTES, 'UTF-8') ?> Seats limit</span></span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <h3 class="font-bold text-brand-900 text-sm">Active Feature Entitlements</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                            <div class="flex items-center gap-2 p-3 bg-brand-50 rounded-xl border border-brand-200/50">
                                <i class="ph <?= htmlspecialchars((string)(($planLimits['features']['ocr_google_vision'] ?? false) ? 'ph-check-circle text-emerald-500' : 'ph-x-circle text-slate-400'), ENT_QUOTES, 'UTF-8') ?> text-lg"></i>
                                <div>
                                    <span class="font-bold text-brand-800">Google Vision ID Scanner (OCR)</span>
                                    <p class="text-[10px] text-slate-500">Extract check-in ID card details automatically</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 p-3 bg-brand-50 rounded-xl border border-brand-200/50">
                                <i class="ph <?= htmlspecialchars((string)(($planLimits['features']['whatsapp_automations'] ?? false) ? 'ph-check-circle text-emerald-500' : 'ph-x-circle text-slate-400'), ENT_QUOTES, 'UTF-8') ?> text-lg"></i>
                                <div>
                                    <span class="font-bold text-brand-800">WhatsApp Automated Alerts</span>
                                    <p class="text-[10px] text-slate-500">Send instant alerts to checking-in guests</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 p-3 bg-brand-50 rounded-xl border border-brand-200/50">
                                <i class="ph <?= htmlspecialchars((string)(($planLimits['features']['custom_domain_mapping'] ?? false) ? 'ph-check-circle text-emerald-500' : 'ph-x-circle text-slate-400'), ENT_QUOTES, 'UTF-8') ?> text-lg"></i>
                                <div>
                                    <span class="font-bold text-brand-800">Custom Domain Mapping</span>
                                    <p class="text-[10px] text-slate-500">Map a private domain name to your workspace</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-minimal p-6">
                    <div class="flex items-center gap-3 mb-6 border-b border-brand-100 pb-4">
                        <div class="w-12 h-12 rounded-xl bg-slate-50 text-slate-600 flex items-center justify-center">
                            <i class="ph ph-receipt text-2xl"></i>
                        </div>
                        <div>
                            <h2 class="font-bold text-brand-900">Billing & Payment Receipts</h2>
                            <p class="text-xs text-brand-900/70">Previous platform transaction history</p>
                        </div>
                    </div>
                    <div class="border border-brand-100 rounded-xl overflow-hidden text-xs">
                        <table class="w-full text-left">
                            <thead class="bg-brand-50 text-brand-800 uppercase text-[9px] font-bold border-b border-brand-100">
                                <tr>
                                    <th class="px-4 py-2.5">Date</th>
                                    <th class="px-4 py-2.5">Description</th>
                                    <th class="px-4 py-2.5">Amount</th>
                                    <th class="px-4 py-2.5">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="px-4 py-3 text-slate-500 font-mono"><?= htmlspecialchars((string)(date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-4 py-3 font-bold text-brand-900">Monthly Plan Subscription Renewal</td>
                                    <td class="px-4 py-3 font-mono">₹<?= htmlspecialchars((string)(number_format((float)$planLimits['price'], 2)), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-emerald-50 text-emerald-600 border border-emerald-200">Paid</span></td>
                                </tr>
                                <tr class="border-b border-slate-100 hover:bg-slate-50">
                                    <td class="px-4 py-3 text-slate-500 font-mono"><?= htmlspecialchars((string)(date('Y-m-d', strtotime('-1 month'))), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-4 py-3 font-bold text-brand-900">Monthly Plan Subscription Renewal</td>
                                    <td class="px-4 py-3 font-mono">₹<?= htmlspecialchars((string)(number_format((float)$planLimits['price'], 2)), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-emerald-50 text-emerald-600 border border-emerald-200">Paid</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
        </div>

        <!-- Floating Action Button (FAB) -->
        <button id="fabBtn" onclick="handleFabClick()" class="absolute bottom-24 md:bottom-6 right-4 md:right-6 w-14 h-14 bg-brand-accent text-white rounded-full  shadow-blue-300 flex items-center justify-center hover:bg-brand-accentHover active:scale-95 transition-transform z-20">
            <i class="ph ph-plus text-2xl font-bold"></i>
        </button>

        <!-- BOTTOM SHEETS (Modals) -->
        <div id="modalOverlay" class="fixed inset-0 bg-brand-900/40 z-40 hidden transition-opacity opacity-0 backdrop-blur-sm" onclick="closeModals()"></div>

        <!-- 1. Category Modal -->
        <div id="catModal" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto modal-brutal z-50 transform translate-y-full transition-transform duration-300 ease-out">
            <div class="p-6">
                <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
                <h3 id="catModalTitle" class="text-2xl font-semibold mb-6 text-brand-900 tracking-tight">Add Category</h3>
                <form onsubmit="submitForm(event, this, 'catModal')" class="space-y-4">
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="cat_id" id="cat_id" value="">
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Category Name</label>
                        <input type="text" name="cat_name" id="cat_name" required class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal transition-all p-3.5 transition-all outline-none font-bold text-brand-900 text-lg">
                    </div>
                    <button type="submit" class="w-full bg-brand-900 text-white font-bold py-4 mt-2 rounded-xl active:scale-95 transition-transform text-lg">Save Category</button>
                </form>
            </div>
        </div>

        <!-- 2. Room Modal -->
        <div id="roomModal" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto modal-brutal z-50 transform translate-y-full transition-transform duration-300 ease-out">
            <div class="p-6">
                <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
                <h3 id="roomModalTitle" class="text-2xl font-semibold mb-6 text-brand-900 tracking-tight">Add Room</h3>
                <form onsubmit="submitForm(event, this, 'roomModal')" class="space-y-4">
                    <input type="hidden" name="action" value="save_room">
                    <input type="hidden" name="room_id" id="room_id" value="">
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Room Number</label>
                        <input type="text" name="room_number" id="room_number" required class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal transition-all p-3.5 transition-all outline-none font-bold text-brand-900 text-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Assign to Category</label>
                        <div class="relative">
                            <select name="category_id" id="room_category_id" required class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal transition-all p-3.5 transition-all outline-none font-bold text-brand-900 text-lg appearance-none">
                                <?php foreach($categories as $c): ?>
                                    <option value="<?= htmlspecialchars((string)($c['id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($c['name'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-brand-900 text-white font-bold py-4 mt-2 rounded-xl active:scale-95 transition-transform text-lg">Save Room</button>
                </form>
            </div>
        </div>

        <!-- 3. Rate Modal (Bulk Rate Editor) -->
        <div id="rateModal" class="fixed bottom-0 left-0 right-0 max-w-2xl mx-auto modal-brutal z-50 transform translate-y-full transition-transform duration-300 ease-out flex flex-col" style="max-height: 90vh;">
            <div class="p-6 pb-2 shrink-0">
                <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
                <h3 id="rateModalTitle" class="text-2xl font-semibold text-brand-900 tracking-tight">Bulk Rate Editor</h3>
                <p id="rateModalSubtitle" class="text-brand-900/70 text-sm font-bold mt-1">Category Name</p>
            </div>
            
            <form onsubmit="submitBulkRates(event, this)" class="flex flex-col flex-1 overflow-hidden">
                <input type="hidden" name="category_id" id="rate_category_id" value="">
                
                <div class="px-6 py-4 overflow-y-auto no-scrollbar flex-1 space-y-4">
                    <!-- horizontal scrolling table for editing rates -->
                    <div id="bulk_rate_table_container" class="w-full">
                        <!-- injected by JS -->
                    </div>
                    
                    <!-- Add new rate plan field -->
                    <div class="bg-slate-50 p-4 rounded-2xl flex items-end gap-3 border border-slate-100">
                        <div class="flex-grow">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">New Rate Plan Column</label>
                            <input type="text" id="new_plan_name" placeholder="e.g. Weekend Plan" class="w-full bg-white rounded-xl border border-slate-200 p-2.5 text-xs font-semibold outline-none focus:border-slate-900">
                        </div>
                        <button type="button" onclick="addBulkPlanColumn()" class="bg-slate-900 text-white text-xs font-bold px-4 py-3 rounded-xl active:scale-95 transition-all">Add Plan</button>
                    </div>
                </div>
                
                <div class="p-6 shrink-0 bg-white border-t border-brand-100 flex gap-3">
                    <button type="button" onclick="closeModals()" class="flex-1 bg-slate-100 text-slate-700 font-bold py-3.5 rounded-xl active:scale-95 transition-transform text-sm">Cancel</button>
                    <button type="submit" class="flex-1 bg-brand-900 text-white font-bold py-3.5 rounded-xl active:scale-95 transition-transform text-sm">Save All Rates</button>
                </div>
            </form>
        </div>

        <!-- 4. Staff Modal -->
        <div id="staffModal" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto modal-brutal z-50 transform translate-y-full transition-transform duration-300 ease-out">
            <div class="p-6">
                <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
                <h3 id="staffModalTitle" class="text-2xl font-semibold mb-6 text-brand-900 tracking-tight">Add Staff User</h3>
                <form onsubmit="submitStaff(event)" class="space-y-4">
                    <input type="hidden" name="staff_user_id" id="staff_user_id" value="">
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Username</label>
                        <input type="text" name="username" id="staff_username" required class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal transition-all p-3.5 transition-all outline-none font-bold text-brand-900">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider" id="staff_password_label">Password</label>
                        <input type="password" name="password" id="staff_password" minlength="6" class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal transition-all p-3.5 transition-all outline-none font-bold text-brand-900">
                        <p id="staff_password_hint" class="hidden text-xs text-brand-400 mt-1">Leave blank to keep current password</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider" id="staff_pin_label">4-Digit PIN (Assistant)</label>
                        <input type="text" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" name="pin" id="staff_pin" class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal transition-all p-3.5 transition-all outline-none font-bold text-brand-900">
                        <p id="staff_pin_hint" class="hidden text-xs text-brand-400 mt-1">Leave blank to keep current PIN</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Role</label>
                        <div class="relative">
                            <select name="access_level" id="staff_access_level" required class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal transition-all p-3.5 transition-all outline-none font-bold text-brand-900 appearance-none">
                                <optgroup label="System Roles">
                                    <option value="owner">Owner (Full Access)</option>
                                    <option value="manager">Manager (Front Desk + Operations)</option>
                                    <option value="receptionist">Receptionist</option>
                                    <option value="housekeeping">Housekeeping</option>
                                    <option value="maintenance">Maintenance</option>
                                    <option value="fb_cashier">F&B Cashier</option>
                                    <option value="night_auditor">Night Auditor</option>
                                </optgroup>
                                <?php if (!empty($customRoles)): ?>
                                <optgroup label="Custom Roles">
                                    <?php foreach ($customRoles as $cr): ?>
                                    <option value="custom_<?= htmlspecialchars((string)($cr['id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($cr['name'])) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Status</label>
                        <div class="relative">
                            <select name="is_active" id="staff_is_active" required class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal transition-all p-3.5 transition-all outline-none font-bold text-brand-900 appearance-none">
                                <option value="1">Active</option>
                                <option value="0">Suspended / Inactive</option>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none"></i>
                        </div>
                    </div>
                    <button type="submit" id="staffSubmitBtn" class="w-full bg-brand-900 text-white font-bold py-4 mt-2 rounded-xl active:scale-95 transition-transform text-lg">Add User</button>
                </form>
            </div>
        </div>

        <!-- 5. Role Modal -->
        <div id="roleModal" class="fixed bottom-0 left-0 right-0 max-w-2xl mx-auto modal-brutal z-50 transform translate-y-full transition-transform duration-300 ease-out h-[85vh] flex flex-col bg-white rounded-t-3xl shadow-2xl">
            <div class="p-6 pb-4 border-b border-slate-100 shrink-0">
                <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
                <div class="flex justify-between items-center">
                    <h3 id="roleModalTitle" class="text-2xl font-extrabold text-brand-900 tracking-tight">Create Custom Role</h3>
                    <button type="button" onclick="closeModals()" class="text-slate-400 hover:text-slate-600"><i class="ph ph-x text-2xl"></i></button>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-6 pt-4 bg-slate-50">
                <form onsubmit="submitRole(event)" id="roleForm" class="space-y-6">
                    <input type="hidden" name="role_id" id="role_id" value="">
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Role Name</label>
                        <input type="text" name="name" id="role_name" required placeholder="e.g. Night Auditor" class="w-full bg-white rounded-xl border border-brand-200 focus:shadow-minimal transition-all p-3.5 outline-none font-bold text-brand-900">
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-3 uppercase tracking-wider">Permissions</label>
                        <div class="space-y-6">
                            <?php foreach (AuthHelper::getGroupedPermissions() as $groupName => $perms): ?>
                            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                                <div class="bg-slate-50 px-4 py-2 border-b border-slate-200">
                                    <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider"><?= htmlspecialchars((string)($groupName)) ?></h4>
                                </div>
                                <div class="p-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach ($perms as $key => $label): ?>
                                    <label class="flex items-center gap-3 p-2 rounded-lg hover:bg-indigo-50/50 cursor-pointer transition-all">
                                        <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars((string)($key)) ?>" class="w-4 h-4 accent-indigo-600 rounded border-slate-300">
                                        <span class="text-sm font-medium text-slate-700 select-none"><?= htmlspecialchars((string)($label)) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>
            </div>
            <div class="p-6 border-t border-slate-100 shrink-0 bg-white">
                <button type="submit" form="roleForm" class="w-full bg-brand-900 hover:bg-brand-800 text-white font-bold py-4 rounded-xl active:scale-95 transition-all text-lg shadow-sm">Save Role</button>
            </div>
        </div>


    </div>

    <script>
        const SETTINGS_DATA = {
            firstCategoryId: <?= htmlspecialchars((string)(!empty($categories) ? $categories[0]['id'] : '""'), ENT_QUOTES, 'UTF-8') ?>,
            firstCategoryName: <?= !empty($categories) ? json_encode(addslashes($categories[0]['name'])) : '""' ?>
        };
    </script>
    <script src="/js/settings.js?v=<?= htmlspecialchars((string)(time()), ENT_QUOTES, 'UTF-8') ?>"></script>
    <!-- Logo Cropping Modal -->
    <div id="cropModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 opacity-0 transition-opacity duration-300">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl overflow-hidden transform scale-95 transition-transform duration-300">
            <div class="px-5 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h3 class="font-display font-bold text-slate-800">Crop Logo</h3>
                <button type="button" onclick="closeCropModal()" class="text-slate-400 hover:text-slate-600 w-8 h-8 flex items-center justify-center rounded-full hover:bg-slate-100 transition-colors">
                    <i class="ph ph-x text-lg"></i>
                </button>
            </div>
            <div class="p-4 bg-slate-100 h-[60vh] w-full relative">
                <img id="cropImage" src="" alt="Crop Preview" class="block max-w-full">
            </div>
            <div class="px-5 py-4 border-t border-slate-100 flex justify-end gap-3 bg-white">
                <button type="button" onclick="closeCropModal()" class="btn-secondary px-5 py-2 text-sm">Cancel</button>
                <button type="button" onclick="applyCrop()" class="btn-minimal px-6 py-2 text-sm bg-brand-600 hover:bg-brand-700">Crop & Apply</button>
            </div>
        </div>
    </div>

    <script>
    async function saveFinanceConfig(e) {
        e.preventDefault();
        const inc = document.getElementById('finance_income_categories').value.split(',').map(s => s.trim()).filter(s => s);
        const exp = document.getElementById('finance_expense_categories').value.split(',').map(s => s.trim()).filter(s => s);
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            const res = await fetch('/api/admin/save_settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    FINANCE_INCOME_CATEGORIES: JSON.stringify(inc.length ? inc : ['Misc']),
                    FINANCE_EXPENSE_CATEGORIES: JSON.stringify(exp.length ? exp : ['Misc'])
                })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Finance Configuration saved successfully.');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('Error: ' + data.error);
            }
        } catch(e) {
            showToast('Connection error');
        }
    }

    async function saveDeepCleanFreq() {
        const val = document.getElementById('deep_clean_frequency').value;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            const res = await fetch('/api/admin/save_settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ settings: { DEEP_CLEAN_FREQ_DAYS: val } })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Deep Clean Frequency saved successfully.');
            } else {
                showToast('Error: ' + data.error);
            }
        } catch(e) {
            showToast('Connection error');
        }
    }

    async function testWhatsApp() {
        const phone = prompt('Enter a WhatsApp phone number with country code (e.g. 919876543210):');
        if (!phone) return;
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            const res = await fetch('/api/admin/test_whatsapp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ phone: phone })
            });
            const data = await res.json();
            showToast(data.message);
        } catch(e) {
            showToast('Connection error');
        }
    }

    async function syncTemplates(event) {
        try {
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = 'Syncing...';
            btn.disabled = true;
            
            const res = await fetch('/api/admin/sync_wa_templates');
            const data = await res.json();
            
            if (data.success) {
                showToast(`Synced ${data.count} templates successfully!`);
            } else {
                showToast('Error: ' + data.error);
            }
            
            btn.textContent = originalText;
            btn.disabled = false;
        } catch(e) {
            showToast('Connection error');
        }
    }

    async function testGoogleSheetsConnection() {
        const urlInput = document.getElementById('gs_webhook_url');
        const url = urlInput ? urlInput.value.trim() : '';
        if (!url) {
            alert('Please enter a Google Apps Script Webhook URL first.');
            return;
        }
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            showToast('Testing Google Sheets connection...');
            const res = await fetch('/api/admin/sync_google_sheets', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action: 'test', webhook_url: url })
            });
            const data = await res.json();
            if (data.success) {
                alert('Success: ' + data.data.message);
            } else {
                alert('Connection Failed: ' + (data.error || 'Unknown error'));
            }
        } catch(e) {
            alert('Error testing connection: ' + e.message);
        }
    }

    async function bulkSyncGoogleSheets(type = 'all') {
        if (!confirm(`Are you sure you want to bulk sync ${type} data to Google Sheets?`)) return;
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            showToast(`Starting bulk sync for ${type}...`);
            const res = await fetch('/api/admin/sync_google_sheets', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action: 'bulk_sync', type: type })
            });
            const data = await res.json();
            if (data.success) {
                alert('Bulk Sync Successful: ' + data.data.message);
            } else {
                alert('Bulk Sync Failed: ' + (data.error || 'Unknown error'));
            }
        } catch(e) {
            alert('Error during bulk sync: ' + e.message);
        }
    }

    async function addHkChecklistItem(e) {
        e.preventDefault();
        const text = document.getElementById('hk_item_text').value.trim();
        const isMandatory = document.getElementById('hk_item_mandatory').checked ? 1 : 0;
        if (!text) return;

        try {
            const res = await fetch('../assistant/api/housekeeping.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add_checklist_item', item_text: text, is_mandatory: isMandatory })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Checklist item added!');
                location.reload();
            } else {
                alert('Error: ' + (data.message || data.error));
            }
        } catch(err) {
            alert('Failed to add checklist item');
        }
    }

    async function deleteHkChecklistItem(itemId) {
        if (!confirm('Delete this checklist item?')) return;
        try {
            const res = await fetch('../assistant/api/housekeeping.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_checklist_item', item_id: itemId })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Item deleted');
                location.reload();
            } else {
                alert('Error: ' + (data.message || data.error));
            }
        } catch(err) {
            alert('Failed to delete checklist item');
        }
    }

    function openInviteModal() {
        document.getElementById('inviteResultPanel').classList.add('hidden');
        document.getElementById('invite_email').value = '';
        openModal('inviteModal');
    }

    async function submitInvitation(e) {
        e.preventDefault();
        const email = document.getElementById('invite_email').value;
        const role = document.getElementById('invite_role').value;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        try {
            const res = await fetch('/api/admin/manage_staff', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action: 'invite', email: email, role: role })
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('inviteGeneratedLink').value = data.invite_link;
                document.getElementById('inviteResultPanel').classList.remove('hidden');
            } else {
                alert('Error generating invite: ' + (data.message || data.error));
            }
        } catch (err) {
            alert('Failed to connect to API');
        }
    }


    async function submitGuestPortalSettings(e) {
        e.preventDefault();
        const upsell = document.getElementById('portal_upsell_enabled').checked;
        const pos = document.getElementById('portal_pos_enabled').checked;
        const housekeeping = document.getElementById('portal_housekeeping_enabled').checked;
        const checkout = document.getElementById('portal_self_checkout_enabled').checked;
        const fee = document.getElementById('portal_early_late_fee').value;
        
        const loyalty = document.getElementById('portal_loyalty_enabled').checked;
        const gold = document.getElementById('portal_loyalty_gold').value;
        const platinum = document.getElementById('portal_loyalty_platinum').value;
        
        const preArrival = document.getElementById('portal_pre_arrival_enabled').checked;
        const signature = document.getElementById('portal_pre_arrival_signature').checked;
        const doc = document.getElementById('portal_pre_arrival_doc').checked;
        
        const breakfast = document.getElementById('portal_upsell_breakfast_price').value;
        const transfer = document.getElementById('portal_upsell_transfer_price').value;
        
        const otp = document.getElementById('portal_otp_enabled').checked;
        const wifiSsid = document.getElementById('portal_wifi_ssid').value.trim();
        const wifiPassword = document.getElementById('portal_wifi_password').value.trim();
        const helpDeskNo = document.getElementById('portal_help_desk_no').value.trim();
        const localAttractions = document.getElementById('portal_local_attractions').value.trim();
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        try {
            const res = await fetch('/api/admin/save_portal_settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    upsell_enabled: upsell,
                    pos_enabled: pos,
                    housekeeping_enabled: housekeeping,
                    self_checkout_enabled: checkout,
                    early_late_fee: fee,
                    loyalty_enabled: loyalty,
                    loyalty_gold: gold,
                    loyalty_platinum: platinum,
                    pre_arrival_enabled: preArrival,
                    pre_arrival_signature: signature,
                    pre_arrival_doc: doc,
                    upsell_breakfast_price: breakfast,
                    upsell_transfer_price: transfer,
                    otp_enabled: otp,
                    wifi_ssid: wifiSsid,
                    wifi_password: wifiPassword,
                    help_desk_no: helpDeskNo,
                    local_attractions: localAttractions
                })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message);
            } else {
                alert('Error saving settings: ' + (data.message || data.error));
            }
        } catch (err) {
            alert('Connection error');
        }
    }

    async function submitGatewayConfig(e, gateway) {
        e.preventDefault();
        const form = e.target;
        const data = new FormData(form);
        const payload = Object.fromEntries(data.entries());
        payload.gateway = gateway;
        // Checkboxes only send value if checked
        payload.is_active = form.querySelector('[name="is_active"]').checked ? 1 : 0;
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            const res = await fetch('/api/admin/save_gateway_config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const result = await res.json();
            if (result.success) {
                showToast(result.message || 'Gateway config saved');
            } else {
                alert('Error: ' + (result.error || 'Unknown error'));
            }
        } catch (err) {
            alert('Connection error');
        }
    }
    </script>

    <!-- Team Invitation Modal -->
    <div id="inviteModal" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto modal-brutal z-50 transform translate-y-full transition-transform duration-300 ease-out">
        <div class="p-6 bg-white">
            <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
            <h3 class="text-2xl font-semibold mb-6 text-brand-900 tracking-tight">Invite Staff Member</h3>
            
            <form onsubmit="submitInvitation(event)" class="space-y-4" id="invitationForm">
                <div>
                    <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Email Address</label>
                    <input type="email" id="invite_email" required placeholder="staff@hotel.com" class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal p-3.5 outline-none font-bold text-brand-900">
                </div>
                <div>
                    <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Role</label>
                    <div class="relative">
                        <select id="invite_role" required class="w-full bg-brand-50 rounded-none border border-brand-200 focus:shadow-minimal p-3.5 outline-none font-bold text-brand-900 appearance-none">
                            <option value="manager">Manager / Front Desk</option>
                            <option value="housekeeping">Housekeeping</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-4 mt-2 rounded-xl transition-all">Generate Invite Link</button>
            </form>

            <!-- Link Result Panel -->
            <div id="inviteResultPanel" class="hidden mt-4 p-4 bg-emerald-50 rounded-xl border border-emerald-200 space-y-3">
                <p class="text-xs font-bold text-emerald-800 font-sans">🎉 Share this link with your staff member:</p>
                <input type="text" id="inviteGeneratedLink" readonly onclick="this.select(); document.execCommand('copy'); alert('Copied!');" class="w-full p-2.5 bg-white border border-emerald-300 rounded text-xs font-mono select-all cursor-pointer">
                <p class="text-[10px] text-slate-500 font-sans">Click box above to copy. Link expires in 7 days.</p>
            </div>
        </div>
    </div>
</body>
</html>
