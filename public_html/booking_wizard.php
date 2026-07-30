<?php
require_once __DIR__ . '/../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
if (!AuthHelper::can('create_booking')) {
    header('Location: admin/login.php');
    exit;
}

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';
$db = Database::getInstance()->getConnection();
load_db_settings($db);

$pmStmt = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'payment_methods'");
$pmJson = $pmStmt->fetchColumn();
$paymentMethods = $pmJson ? json_decode($pmJson, true) : [];
if (empty($paymentMethods)) {
    $paymentMethods = ["Cash", "UPI", "Online / Gateway"];
}
$isOwner = (AuthHelper::getRole() === 'owner') ? 'true' : 'false';

// Default auto-fetched check-in & check-out dates and times
$nowTs = time();
$minutes = (int)date('i', $nowTs);
$roundedMin = (int)(ceil($minutes / 30) * 30);
$roundedTs = $nowTs + (($roundedMin - $minutes) * 60);
$defaultCheckInDate = date('Y-m-d', $roundedTs);
$defaultCheckInTime = date('H:i', $roundedTs);

$checkoutTs = $roundedTs + (3 * 3600); // 3 hours stay duration default
$defaultCheckOutDate = date('Y-m-d', $checkoutTs);
$defaultCheckOutTime = date('H:i', $checkoutTs);

function renderTimeOptions(string $selectedVal = ''): string {
    $options = '';
    for ($h = 0; $h < 24; $h++) {
        for ($m = 0; $m < 60; $m += 30) {
            $hourStr = sprintf('%02d', $h);
            $minStr = sprintf('%02d', $m);
            $timeVal = "{$hourStr}:{$minStr}";
            $period = $h >= 12 ? 'PM' : 'AM';
            $displayHour = $h % 12 ?: 12;
            $displayTime = "{$displayHour}:{$minStr} {$period}";
            $selected = ($timeVal === $selectedVal) ? ' selected' : '';
            $options .= "<option value=\"{$timeVal}\"{$selected}>{$displayTime}</option>";
        }
    }
    return $options;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script>
        window.IS_ADMIN = <?= $isOwner ?>;
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>MicroPMS Booking</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="js/ui.js"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Inter"', 'sans-serif'],
                        display: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#F0F4FC',
                            100: '#DBE2F4',
                            500: '#3E69B8',
                            600: '#1E3A8A',
                            900: '#101E49',
                            accent: '#CA8A04'
                        },
                        // Map indigo to the brand Navy palette to preserve color consistency
                        indigo: {
                            50: '#F0F4FC',
                            100: '#DBE2F4',
                            200: '#BFCEEC',
                            300: '#99B2DF',
                            400: '#6F91CF',
                            500: '#3E69B8',
                            600: '#1E3A8A',
                            700: '#1A3279',
                            800: '#162963',
                            900: '#101E49',
                        }
                    },
                    boxShadow: {
                        'stark': '0 4px 20px -2px rgba(0, 0, 0, 0.05), 0 2px 6px -1px rgba(0, 0, 0, 0.03)',
                        'stark-sm': '0 2px 4px rgba(0, 0, 0, 0.05)',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Gold CTA theme overrides for buttons */
        .btn-glass {
            background: linear-gradient(135deg, #CA8A04 0%, #A16E03 100%) !important;
            color: #ffffff !important;
            border-radius: 1rem !important;
            box-shadow: 0 10px 15px -3px rgba(202, 138, 4, 0.3) !important;
            border: none !important;
        }
        .btn-glass:hover {
            background: linear-gradient(135deg, #A16E03 0%, #855B02 100%) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 12px 20px -3px rgba(202, 138, 4, 0.4) !important;
        }
        .input-glass:focus {
            border-color: #1E3A8A !important;
            box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.15) !important;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-tr from-slate-50 via-indigo-50/20 to-slate-100/50">

    <!-- Header -->
    <header class="bg-white px-6 py-4 flex items-center justify-between border-b border-slate-100 sticky top-0 z-50 shadow-sm">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center text-white shadow-md shadow-indigo-100">
                <i class="ph ph-buildings text-lg"></i>
            </div>
            <div>
                <h1 class="text-base font-bold text-slate-800 tracking-tight leading-none font-display">MicroPMS</h1>
                <span class="text-[9px] font-semibold text-slate-400 uppercase tracking-wider mt-0.5 inline-block">Booking Portal</span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="admin/index.php" class="text-xs font-bold text-slate-600 hover:text-slate-900 items-center gap-2 transition-all bg-slate-50 hover:bg-slate-100 px-4 py-2.5 rounded-xl border border-slate-200 flex">
                <i class="ph ph-arrow-left text-sm"></i> Return to Admin
            </a>
        </div>
    </header>

    <script>
        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-menu');
            menu.classList.toggle('hidden');
            if (!menu.classList.contains('hidden')) {
                const closeHandler = (e) => {
                    if (!e.target.closest('#mobile-menu-wrap')) {
                        menu.classList.add('hidden');
                        document.removeEventListener('click', closeHandler);
                    }
                };
                setTimeout(() => document.addEventListener('click', closeHandler), 10);
            }
        }
    </script>

    <!-- Main Content -->
    <main class="flex-1 w-full max-w-md mx-auto p-4 pb-28">
        
        <!-- Step Progress Bar -->
        <div id="booking-steps-bar" class="w-full bg-white border border-slate-150 rounded-2xl p-4 mb-6 shadow-sm flex items-center justify-between text-xs font-bold font-display select-none">
            <div id="step-bar-1" class="flex items-center gap-1.5 text-indigo-900">
                <span class="w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] shadow-sm shadow-indigo-100">1</span>
                <span>Dates</span>
            </div>
            <div class="h-0.5 bg-slate-200 flex-grow mx-3 rounded"></div>
            <div id="step-bar-2" class="flex items-center gap-1.5 text-slate-400">
                <span class="w-5 h-5 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-[10px]">2</span>
                <span>Rooms</span>
            </div>
            <div class="h-0.5 bg-slate-200 flex-grow mx-3 rounded"></div>
            <div id="step-bar-3" class="flex items-center gap-1.5 text-slate-400">
                <span class="w-5 h-5 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-[10px]">3</span>
                <span>Confirm</span>
            </div>
        </div>

        <!-- Step 1: Select Dates -->
        <div id="step-dates" class="card-glass p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <i class="ph ph-calendar-plus text-2xl text-slate-900"></i>
                <h2 class="text-lg font-bold text-slate-800 tracking-tight font-display">Book a Room</h2>
            </div>
            
            <div class="space-y-5">
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Check-in</label>
                    <div class="flex gap-2">
                        <input type="date" id="check_in_date" value="<?= $defaultCheckInDate ?>" min="<?= date('Y-m-d') ?>" class="flex-[3] input-glass rounded-xl p-3 text-sm font-semibold text-slate-800">
                        <div class="relative flex-[2]">
                            <select id="check_in_time" class="w-full input-glass rounded-xl p-3 text-sm font-semibold text-slate-800 appearance-none"><?= renderTimeOptions($defaultCheckInTime) ?></select>
                            <i class="ph ph-caret-down absolute right-3 top-3.5 text-slate-400 pointer-events-none"></i>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Check-out</label>
                    <div class="flex gap-2">
                        <input type="hidden" name="total_amount" id="modalTotalCost" value="">
                        <input type="hidden" name="rate_plan_name" id="modalRatePlan" value="">
                        <input type="date" id="check_out_date" value="<?= $defaultCheckOutDate ?>" min="<?= $defaultCheckInDate ?>" class="flex-[3] input-glass rounded-xl p-3 text-sm font-semibold text-slate-800">
                        <div class="relative flex-[2]">
                            <select id="check_out_time" class="w-full input-glass rounded-xl p-3 text-sm font-semibold text-slate-800 appearance-none"><?= renderTimeOptions($defaultCheckOutTime) ?></select>
                            <i class="ph ph-caret-down absolute right-3 top-3.5 text-slate-400 pointer-events-none"></i>
                        </div>
                    </div>
                </div>
                <button id="btn-check" onclick="checkAvailability()" class="w-full btn-glass mt-2 text-xs font-bold uppercase tracking-wider flex items-center justify-center gap-1.5 active:scale-[0.98]">
                    Find Available Rooms <i class="ph ph-arrow-right text-sm"></i>
                </button>
            </div>
        </div>

        <!-- Step 2: Available Rooms -->
        <div id="step-rooms" class="hidden space-y-4">
            <!-- Selected Dates Banner -->
            <div class="bg-indigo-900 text-white p-4 rounded-2xl shadow-minimal flex items-center justify-between">
                <div>
                    <span class="text-[9px] font-bold uppercase tracking-wider text-indigo-200">Selected Stay</span>
                    <div class="text-xs font-bold flex items-center gap-1.5 mt-0.5" id="summary-dates-display">
                        <!-- Filled by JS -->
                    </div>
                </div>
                <button onclick="document.getElementById('btn-back-dates').click()" class="text-[10px] font-bold bg-white/10 hover:bg-white/20 px-2.5 py-1.5 rounded-lg transition-colors">
                    Edit
                </button>
            </div>

            <div class="flex items-center gap-2 mb-2 px-1">
                <i class="ph ph-bed text-2xl text-indigo-900"></i>
                <h2 class="text-lg font-bold text-slate-800 tracking-tight font-display">Available Rooms</h2>
            </div>
            <div id="rooms-container" class="space-y-5">
                <!-- Rooms will be injected here -->
            </div>
            <button id="btn-continue-guest" onclick="proceedToGuestDetails()" class="w-full btn-glass mt-2 text-xs font-bold uppercase tracking-wider flex items-center justify-center gap-1.5 active:scale-[0.98]">
                Continue with Selected Room(s) <i class="ph ph-arrow-right text-sm"></i>
            </button>
            <button id="btn-back-dates" class="mt-2 w-full btn-glass-secondary py-3 text-xs font-bold text-slate-600 hover:text-slate-900 flex items-center justify-center gap-1.5 active:scale-[0.98]">
                <i class="ph ph-arrow-left text-sm"></i> Change Dates
            </button>
        </div>
        
        <!-- Step 3: Guest Details -->
        <div id="step-guest" class="hidden card-glass p-6">
            <div class="flex items-center gap-2 mb-4">
                <i class="ph ph-user text-2xl text-slate-900"></i>
                <h2 class="text-lg font-bold text-slate-800 tracking-tight font-display">Guest Details</h2>
            </div>
            
            <div class="bg-slate-50 border border-slate-100 p-4 rounded-2xl mb-6" id="selected-room-info">
                <!-- Room info injected here -->
            </div>
            
            <div class="space-y-5">
                <div id="guest-name-wrap">
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Full Name</label>
                    <input type="text" id="guest_name" placeholder="John Doe" class="w-full input-glass rounded-xl p-3 text-sm font-semibold text-slate-800">
                </div>
                <div class="relative" id="guest-phone-wrap">
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">WhatsApp Number</label>
                    <div class="flex gap-2">
                        <div class="relative shrink-0">
                            <select id="country_code" class="w-24 input-glass rounded-xl p-3 text-sm font-semibold text-slate-800 appearance-none bg-white border border-slate-200">
                                <option value="91" selected>🇮🇳 +91</option>
                                <option value="1">🇺🇸 +1</option>
                                <option value="44">🇬🇧 +44</option>
                                <option value="971">🇦🇪 +971</option>
                            </select>
                            <i class="ph ph-caret-down absolute right-3 top-3.5 text-slate-400 pointer-events-none text-xs"></i>
                        </div>
                        <input type="tel" id="guest_phone" placeholder="10-digit number..." class="flex-1 input-glass rounded-xl p-3 text-sm font-semibold text-slate-800" pattern="[0-9]{10}" maxlength="10" required>
                    </div>
                    <div id="guest-suggestions" class="absolute z-10 w-full mt-2 bg-white rounded-2xl shadow-xl border border-slate-100 hidden overflow-hidden max-h-48 overflow-y-auto"></div>
                </div>

                <!-- Booking Source (Dynamic option) -->
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Booking Source</label>
                    <div class="relative">
                        <select id="booking_source" class="w-full input-glass rounded-xl p-3 text-sm font-semibold text-slate-800 appearance-none">
                            <option value="Walk-in">Walk-in</option>
                            <option value="WhatsApp">WhatsApp</option>
                            <option value="Phone Call">Phone Call</option>
                            <option value="Goibibo">Goibibo</option>
                            <option value="MakeMyTrip">MakeMyTrip</option>
                            <option value="Agoda">Agoda</option>
                            <option value="Hotelzify">Hotelzify</option>
                            <option value="Booking.com">Booking.com</option>
                        </select>
                        <i class="ph ph-caret-down absolute right-3 top-3.5 text-slate-400 pointer-events-none"></i>
                    </div>
                </div>

                <!-- Hidden input to hold overall price override value -->
                <input type="hidden" id="price_override" value="">

                <!-- Optional Payment Collected -->
                <div class="bg-slate-50/50 border border-slate-100 p-4 rounded-2xl space-y-3">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Record Initial Payment (Optional)</p>
                    <div class="flex gap-2">
                        <input type="number" id="payment_collected" placeholder="Amount (₹)" min="0" step="0.01" class="flex-1 input-glass rounded-xl p-2.5 text-xs font-semibold text-slate-800">
                        <div class="relative w-36">
                            <select id="payment_method" class="w-full input-glass rounded-xl p-2.5 text-xs font-semibold text-slate-800 appearance-none">
                                <?php foreach ($paymentMethods as $pm): ?>
                                    <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="ph ph-caret-down absolute right-3.5 top-3 text-slate-400 pointer-events-none text-xs"></i>
                        </div>
                    </div>
                </div>

                <!-- Document Uploads -->
                <div class="space-y-3">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Guest Verification & Photos</p>
                    <div class="grid grid-cols-3 gap-2">
                        <!-- ID Front -->
                        <label class="border border-dashed border-slate-200 rounded-xl p-3 flex flex-col items-center justify-center text-center hover:bg-slate-50 cursor-pointer transition-colors relative min-h-[85px] overflow-hidden">
                            <i class="ph ph-identification-card text-xl text-slate-400 mb-1"></i>
                            <span class="text-[9px] font-bold text-slate-600 leading-none">ID Front</span>
                            <input type="file" id="id_proof_front" class="hidden" onchange="updateFileLabel(this, 'id-front-name')">
                            <span id="id-front-name" class="text-[8px] text-slate-450 mt-1 block truncate max-w-full font-semibold"></span>
                        </label>
                        <!-- ID Back -->
                        <label class="border border-dashed border-slate-200 rounded-xl p-3 flex flex-col items-center justify-center text-center hover:bg-slate-50 cursor-pointer transition-colors relative min-h-[85px] overflow-hidden">
                            <i class="ph ph-identification-card text-xl text-slate-400 mb-1"></i>
                            <span class="text-[9px] font-bold text-slate-600 leading-none">ID Back</span>
                            <input type="file" id="id_proof_back" class="hidden" onchange="updateFileLabel(this, 'id-back-name')">
                            <span id="id-back-name" class="text-[8px] text-slate-450 mt-1 block truncate max-w-full font-semibold"></span>
                        </label>
                        <!-- Guest Photo -->
                        <label class="border border-dashed border-slate-200 rounded-xl p-3 flex flex-col items-center justify-center text-center hover:bg-slate-50 cursor-pointer transition-colors relative min-h-[85px] overflow-hidden">
                            <i class="ph ph-camera text-xl text-slate-400 mb-1"></i>
                            <span class="text-[9px] font-bold text-slate-600 leading-none">Photo</span>
                            <input type="file" id="guest_photo" class="hidden" onchange="updateFileLabel(this, 'photo-name')">
                            <span id="photo-name" class="text-[8px] text-slate-450 mt-1 block truncate max-w-full font-semibold"></span>
                        </label>
                    </div>
                </div>

                <button id="btn-book" class="w-full btn-glass mt-2 text-xs font-bold uppercase tracking-wider flex items-center justify-center gap-1.5 active:scale-[0.98]">
                    Confirm Booking <i class="ph ph-check-circle text-sm"></i>
                </button>
                <button id="btn-back-rooms" class="w-full mt-3 btn-glass-secondary py-3 text-xs font-bold text-slate-600 hover:text-slate-800 active:scale-[0.98]">
                    Back to Rooms
                </button>
            </div>
        </div>

    </main>

    <!-- Bottom Nav -->
    <nav class="bg-white border-t border-slate-100 fixed bottom-0 w-full flex justify-around p-3 pb-safe z-40 shadow-lg">
        <a href="#" class="flex flex-col items-center text-slate-900">
            <i class="ph ph-calendar-plus text-2xl font-bold"></i>
            <span class="text-[9px] font-bold uppercase tracking-wider mt-1">Book</span>
        </a>
        <a href="admin/index.php" class="flex flex-col items-center text-slate-400 hover:text-slate-700 transition-colors">
            <i class="ph ph-users text-2xl font-bold"></i>
            <span class="text-[9px] font-bold uppercase tracking-wider mt-1">Staff</span>
        </a>
    </nav>

    <!-- Toast Notifications -->
    <div id="toast-wrapper" class="fixed bottom-24 right-4 z-50 flex flex-col gap-3 pointer-events-none"></div>

    <script>
        // File Label Helper
        function updateFileLabel(input, labelId) {
            const span = document.getElementById(labelId);
            if(input.files && input.files.length > 0) {
                span.textContent = input.files[0].name;
                span.parentElement.classList.add('bg-slate-50', 'border-slate-300');
            } else {
                span.textContent = '';
                span.parentElement.classList.remove('bg-slate-50', 'border-slate-300');
            }
        }

        // Minimalist Glass Toast Helper
        function showNotification(message, type = 'success') {
            const wrapper = document.getElementById('toast-wrapper');
            const toast = document.createElement('div');
            toast.className = `p-4 rounded-2xl border border-slate-100 shadow-xl bg-white/95 backdrop-blur-md text-xs font-semibold flex items-center gap-3 transition-all duration-300 pointer-events-auto cursor-pointer translate-y-2 opacity-0`;
            
            const colorClass = type === 'success' ? 'text-emerald-500' : 'text-rose-500';
            const icon = type === 'success' ? 'ph-check-circle' : 'ph-warning-circle';
            
            toast.innerHTML = `
                <i class="ph-fill ${icon} text-base ${colorClass}"></i>
                <span class="text-slate-700 flex-1">${message}</span>
            `;
            
            toast.addEventListener('click', () => {
                toast.style.transform = 'translateY(10px)';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            });
            
            wrapper.appendChild(toast);
            void toast.offsetHeight;
            toast.style.transform = 'translateY(0)';
            toast.style.opacity = '1';

            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.transform = 'translateY(10px)';
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 300);
                }
            }, 4000);
        }

        // TAX & BRANDING CONFIG PASS
        window.TAX_ENABLED = <?= (defined('TAX_ENABLED') && TAX_ENABLED === 'true') ? 'true' : 'false' ?>;
        window.TAX_RATE = <?= (defined('TAX_RATE') && is_numeric(TAX_RATE)) ? TAX_RATE : '12' ?>;
        window.TAX_LABEL = <?= json_encode(defined('TAX_LABEL') ? TAX_LABEL : 'GST') ?>;

        // JS Logic for SPA
        let selectedRoomIds = []; // Array for multi-room selection
        let selectedRoomsInfo = []; // Track room details for display

        function updateStepBar(activeStep) {
            try {
                for (let i = 1; i <= 3; i++) {
                    const el = document.getElementById('step-bar-' + i);
                    if (!el) continue;
                    const numSpan = el.querySelector('span');
                    if (!numSpan) continue;
                    if (i === activeStep) {
                        el.className = 'flex items-center gap-1.5 font-extrabold text-indigo-700';
                        numSpan.className = 'w-5 h-5 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px] shadow-sm';
                        numSpan.textContent = i;
                    } else if (i < activeStep) {
                        el.className = 'flex items-center gap-1.5 text-emerald-600';
                        numSpan.className = 'w-5 h-5 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-[10px]';
                        numSpan.innerHTML = '<i class="ph ph-check"></i>';
                    } else {
                        el.className = 'flex items-center gap-1.5 text-slate-400';
                        numSpan.className = 'w-5 h-5 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-[10px]';
                        numSpan.textContent = i;
                    }
                }
            } catch (err) { /* step bar update failed silently */ }
        }

        // Generate time options
        function generateTimeOptions() {
            let options = '';
            for(let h=0; h<24; h++) {
                for(let m=0; m<60; m+=30) {
                    let hourStr = h < 10 ? '0' + h : h;
                    let minStr = m === 0 ? '00' : '30';
                    let timeVal = `${hourStr}:${minStr}`;
                    let period = h >= 12 ? 'PM' : 'AM';
                    let displayHour = h % 12 || 12;
                    let displayTime = `${displayHour}:${minStr} ${period}`;
                    options += `<option value="${timeVal}">${displayTime}</option>`;
                }
            }
            return options;
        }

        // Format helpers to get local ISO string formats without timezone/shift bugs
        function formatDateToYYYYMMDD(date) {
            let y = date.getFullYear();
            let m = date.getMonth() + 1;
            let d = date.getDate();
            return `${y}-${m < 10 ? '0' + m : m}-${d < 10 ? '0' + d : d}`;
        }

        function formatTimeToHHMM(date) {
            let h = date.getHours();
            let m = date.getMinutes();
            return `${h < 10 ? '0' + h : h}:${m < 10 ? '0' + m : m}`;
        }

        function parseISOString(str) {
            if (!str) return new Date();
            let clean = str.replace('T', ' ');
            if (clean.length === 16) clean += ':00';
            let parts = clean.split(' ');
            let dateParts = (parts[0] || '').split('-');
            let timeParts = (parts[1] || '00:00:00').split(':');
            let y = parseInt(dateParts[0], 10) || 2026;
            let m = (parseInt(dateParts[1], 10) || 1) - 1;
            let d = parseInt(dateParts[2], 10) || 1;
            let hr = parseInt(timeParts[0], 10) || 0;
            let min = parseInt(timeParts[1], 10) || 0;
            let sec = parseInt(timeParts[2] || '0', 10) || 0;
            return new Date(y, m, d, hr, min, sec);
        }

        function getCheckIn() {
            const dateEl = document.getElementById('check_in_date');
            const timeEl = document.getElementById('check_in_time');
            const now = new Date();
            let dVal = (dateEl && dateEl.value) ? dateEl.value : formatDateToYYYYMMDD(now);
            let tVal = (timeEl && timeEl.value) ? timeEl.value : formatTimeToHHMM(now);
            if (dateEl && !dateEl.value) dateEl.value = dVal;
            if (timeEl && !timeEl.value) timeEl.value = tVal;
            return `${dVal}T${tVal}`;
        }

        function getCheckOut() {
            const dateEl = document.getElementById('check_out_date');
            const timeEl = document.getElementById('check_out_time');
            const now = new Date();
            let later = new Date(now.getTime() + 3 * 3600 * 1000);
            let dVal = (dateEl && dateEl.value) ? dateEl.value : formatDateToYYYYMMDD(later);
            let tVal = (timeEl && timeEl.value) ? timeEl.value : formatTimeToHHMM(later);
            if (dateEl && !dateEl.value) dateEl.value = dVal;
            if (timeEl && !timeEl.value) timeEl.value = tVal;
            return `${dVal}T${tVal}`;
        }

        function initStep1DatesAndTime() {
            const checkInDateEl = document.getElementById('check_in_date');
            const checkInTimeEl = document.getElementById('check_in_time');
            const checkOutDateEl = document.getElementById('check_out_date');
            const checkOutTimeEl = document.getElementById('check_out_time');

            if (!checkInDateEl || !checkInTimeEl || !checkOutDateEl || !checkOutTimeEl) return;

            // 1. Populate time option tags if not already rendered
            if (!checkInTimeEl.children.length) {
                checkInTimeEl.innerHTML = generateTimeOptions();
            }
            if (!checkOutTimeEl.children.length) {
                checkOutTimeEl.innerHTML = generateTimeOptions();
            }

            // 2. Compute rounded local times
            const now = new Date();
            let roundedNow = new Date(now);
            let minutes = now.getMinutes();
            let roundedMinutes = Math.ceil(minutes / 30) * 30;
            if (roundedMinutes === 60) {
                roundedNow.setHours(roundedNow.getHours() + 1, 0, 0, 0);
            } else {
                roundedNow.setMinutes(roundedMinutes, 0, 0);
            }

            // 3. Assign values to inputs automatically
            if (!checkInDateEl.value) {
                checkInDateEl.value = formatDateToYYYYMMDD(roundedNow);
            }
            if (!checkInTimeEl.value) {
                checkInTimeEl.value = formatTimeToHHMM(roundedNow);
            }

            let roundedLater = new Date(roundedNow.getTime() + 3 * 60 * 60 * 1000);
            if (!checkOutDateEl.value) {
                checkOutDateEl.value = formatDateToYYYYMMDD(roundedLater);
            }
            if (!checkOutTimeEl.value) {
                checkOutTimeEl.value = formatTimeToHHMM(roundedLater);
            }

            checkOutDateEl.min = checkInDateEl.value;

            checkInDateEl.onchange = (e) => {
                checkOutDateEl.min = e.target.value;
                if (checkOutDateEl.value < e.target.value) {
                    checkOutDateEl.value = e.target.value;
                }
            };
        }

        // Run date/time initialization immediately & on DOMContentLoaded
        initStep1DatesAndTime();
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initStep1DatesAndTime);
        }

        // Step transition helper — called immediately on button click
        function goToStep2(checkIn, checkOut) {
            const inDate = parseISOString(checkIn);
            const outDate = parseISOString(checkOut);
            const hours = Math.max(1, Math.round((outDate - inDate) / 3600000));
            const nights = Math.max(1, Math.round(hours / 24));

            try {
                const summaryDisplay = document.getElementById('summary-dates-display');
                if (summaryDisplay) {
                    const opts = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
                    const inFmt = inDate.toLocaleDateString('en-IN', opts);
                    const outFmt = outDate.toLocaleDateString('en-IN', opts);
                    summaryDisplay.innerHTML =
                        '<span>' + inFmt + '</span>' +
                        '<i class="ph ph-arrow-right text-[10px]"></i>' +
                        '<span>' + outFmt + '</span>' +
                        '<span class="ml-2 bg-indigo-500/30 text-indigo-200 px-2 py-0.5 rounded text-[10px] font-bold">' + nights + (nights === 1 ? ' Night' : ' Nights') + '</span>';
                }
            } catch (e2) {}

            const stepDates = document.getElementById('step-dates');
            const stepRooms = document.getElementById('step-rooms');
            if (stepDates) stepDates.style.display = 'none';
            if (stepRooms) { stepRooms.style.display = ''; stepRooms.classList.remove('hidden'); }
            updateStepBar(2);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Global function attached directly to btn-check and window
        window.checkAvailability = function() {
            const btn = document.getElementById('btn-check');
            const checkIn = getCheckIn();
            const checkOut = getCheckOut();

            // Immediately show loading state
            let origHtml = 'Find Available Rooms <i class="ph ph-arrow-right text-sm"></i>';
            if (btn) { origHtml = btn.innerHTML; btn.innerHTML = '<i class="ph ph-spinner animate-spin mr-2"></i> Checking...'; btn.disabled = true; }

            // Show loading placeholder in rooms container
            const container = document.getElementById('rooms-container');
            if (container) container.innerHTML = '<div class="card-glass p-8 text-center text-slate-400"><i class="ph ph-spinner animate-spin text-3xl mb-3 text-indigo-400"></i><p class="font-semibold mt-2">Finding available rooms...</p></div>';

            // IMMEDIATELY switch to step 2 — no waiting for fetch
            goToStep2(checkIn, checkOut);

            // Now fetch rooms in background
            fetch('api/check_availability.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ check_in: checkIn, check_out: checkOut })
            })
            .then(function(res) { return res.text(); })
            .then(function(text) {
                let data = null;
                try { data = JSON.parse(text); } catch (e1) {
                    const m = text.match(/\{[\s\S]*\}/);
                    if (m) { try { data = JSON.parse(m[0]); } catch(e2) {} }
                }
                const categories = (data && Array.isArray(data.categories)) ? data.categories : [];
                window.lastAvailableCategories = categories;
                renderRooms(categories);
                if (data && !data.success && data.message) showNotification(data.message, 'error');
            })
            .catch(function(err) {
                renderRooms([]);
                showNotification('Could not load rooms: ' + err.message, 'error');
            })
            .finally(function() {
                if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
            });
        };

        function renderRooms(categories) {
            const container = document.getElementById('rooms-container');
            container.innerHTML = '';
            
            if (categories.length === 0) {
                container.innerHTML = '<div class="card-glass p-8 text-center text-slate-400 font-semibold"><i class="ph ph-smiley-sad text-4xl mb-3 text-slate-300"></i><p>No rooms available for these dates.</p></div>';
                return;
            }

            categories.forEach(cat => {
                const div = document.createElement('div');
                div.className = 'card-glass p-5';
                
                // Amenity heuristics based on category name
                const lowerName = cat.name.toLowerCase();
                const amenities = [
                    { name: 'Free WiFi', icon: 'ph-wifi' },
                    { name: 'Mineral Water', icon: 'ph-drop' }
                ];
                if (lowerName.includes('ac') && !lowerName.includes('non-ac')) {
                    amenities.push({ name: 'Air Conditioning', icon: 'ph-snowflake' });
                }
                if (lowerName.includes('deluxe') || lowerName.includes('suite') || lowerName.includes('premium')) {
                    amenities.push({ name: 'Room Service', icon: 'ph-bell' });
                    amenities.push({ name: 'Premium Toiletries', icon: 'ph-sparkles' });
                    amenities.push({ name: 'King Bed', icon: 'ph-bed' });
                    if (!lowerName.includes('non-ac')) {
                        amenities.push({ name: 'Air Conditioning', icon: 'ph-snowflake' });
                    }
                } else {
                    amenities.push({ name: 'Double Bed', icon: 'ph-bed' });
                }
                if (lowerName.includes('tv') || lowerName.includes('deluxe') || lowerName.includes('suite')) {
                    amenities.push({ name: 'Flat TV', icon: 'ph-television' });
                }

                const amenitiesHtml = `
                    <div class="flex flex-wrap gap-x-3 gap-y-1.5 mb-4 border-t border-slate-100 pt-3">
                        ${amenities.map(a => `
                            <span class="flex items-center gap-1 text-[10px] font-bold text-slate-400">
                                <i class="ph ${a.icon} text-xs text-slate-400"></i> ${a.name}
                            </span>
                        `).join('')}
                    </div>
                `;
                
                div.innerHTML = `
                    <div class="mb-3 pb-2 border-b border-slate-100">
                        <h3 class="font-bold text-lg text-slate-800 font-display">${cat.name}</h3>
                    </div>
                    ${amenitiesHtml}
                    <div>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Select Rate Plan</p>
                        <div class="flex flex-col gap-3 mb-5">
                            ${cat.rate_plans.map((rp, i) => `
                                <label class="flex items-center gap-3 p-3.5 rounded-xl border border-slate-100 bg-white cursor-pointer hover:bg-slate-50/50 transition-colors shadow-sm">
                                    <input type="radio" name="rate_plan_${cat.category_id}" value="${rp.name}" class="w-4 h-4 accent-slate-900" ${i === 0 ? 'checked' : ''}>
                                    <div class="flex-1">
                                        <span class="block font-semibold text-sm text-slate-700">${rp.name}</span>
                                    </div>
                                    <span class="font-bold text-slate-900 text-base">₹${rp.total_cost}</span>
                                </label>
                            `).join('')}
                        </div>

                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Select Room(s) — Tap to toggle</p>
                        <div class="grid grid-cols-3 gap-2.5">
                            ${cat.rooms.map(room => `
                                <button onclick="toggleRoom(${room.id}, '${room.room_number}', ${cat.category_id}, this)" class="room-btn flex flex-col items-center justify-center p-3.5 rounded-2xl bg-white text-slate-800 font-bold border border-slate-150 hover:border-indigo-500 hover:bg-indigo-50/20 active:scale-[0.95] transition-all shadow-sm group" data-room-id="${room.id}">
                                    <i class="ph ph-key text-xl text-slate-350 group-hover:text-indigo-600 transition-colors mb-1.5"></i>
                                    <span class="text-[9px] text-slate-400 font-bold uppercase tracking-wider leading-none">Room</span>
                                    <span class="text-sm font-extrabold text-slate-900 mt-1">${room.room_number}</span>
                                </button>
                            `).join('')}
                        </div>
                    </div>
                `;
                container.appendChild(div);
            });
        }
        window.toggleRoom = (roomId, roomNumber, catId, btnEl) => {
            const selectedPlanInput = document.querySelector(`input[name="rate_plan_${catId}"]:checked`);
            const ratePlanName = selectedPlanInput ? selectedPlanInput.value : 'Base Rate';
            
            let totalCost = 0;
            for(let cat of window.lastAvailableCategories) {
                if(cat.category_id == catId) {
                    for(let rp of cat.rate_plans) {
                        if(rp.name === ratePlanName) {
                            totalCost = rp.total_cost;
                        }
                    }
                }
            }

            // Toggle room selection
            const existingIndex = selectedRoomIds.findIndex(r => r.id === roomId);
            if (existingIndex >= 0) {
                // Deselect
                selectedRoomIds.splice(existingIndex, 1);
                selectedRoomsInfo.splice(existingIndex, 1);
                btnEl.classList.remove('border-indigo-600', 'bg-indigo-50');
                btnEl.classList.add('border-slate-150', 'bg-white');
            } else {
                // Select
                selectedRoomIds.push({ id: roomId, catId: catId });
                selectedRoomsInfo.push({ id: roomId, roomNumber, catId, ratePlanName, totalCost });
                btnEl.classList.remove('border-slate-150', 'bg-white');
                btnEl.classList.add('border-indigo-600', 'bg-indigo-50');
            }

            // Update selected count display
            updateSelectedRoomsBar();
        };

        function updateSelectedRoomsBar() {
            let bar = document.getElementById('selected-rooms-bar');
            if (!bar) {
                // Create the bar if it doesn't exist
                bar = document.createElement('div');
                bar.id = 'selected-rooms-bar';
                bar.className = 'fixed bottom-20 left-4 right-4 z-50 bg-indigo-900 text-white p-3 rounded-2xl shadow-lg flex items-center justify-between';
                document.body.appendChild(bar);
            }

            if (selectedRoomIds.length === 0) {
                bar.style.display = 'none';
                return;
            }

            bar.style.display = 'flex';
            const totalAmount = selectedRoomsInfo.reduce((sum, r) => sum + r.totalCost, 0);
            const roomNumbers = selectedRoomsInfo.map(r => r.roomNumber).join(', ');
            
            bar.innerHTML = `
                <div>
                    <div class="text-xs font-bold text-indigo-200">${selectedRoomIds.length} Room${selectedRoomIds.length > 1 ? 's' : ''} Selected</div>
                    <div class="text-sm font-bold">${roomNumbers}</div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-indigo-200">Total</div>
                    <div class="text-lg font-extrabold">₹${totalAmount.toFixed(2)}</div>
                </div>
            `;
        }

        window.selectRoom = (roomId, roomNumber, catId) => {
            // Legacy single-select for backward compatibility
            toggleRoom(roomId, roomNumber, catId, document.querySelector(`[data-room-id="${roomId}"]`));
        };

        // Continue to guest details when rooms are selected
        window.proceedToGuestDetails = () => {
            if (selectedRoomIds.length === 0) {
                showNotification('Please select at least one room', 'error');
                return;
            }

            // Use first room's rate plan for pricing (all rooms same category/rate)
            const firstRoom = selectedRoomsInfo[0];
            window.selectedRatePlanName = firstRoom.ratePlanName;
            document.getElementById('modalRatePlan').value = firstRoom.ratePlanName;

            // Calculate total across all selected rooms
            const totalAmount = selectedRoomsInfo.reduce((sum, r) => sum + r.totalCost, 0);
            window.baseTotalCost = totalAmount;

            // Reset price override
            document.getElementById('price_override').value = '';

            document.getElementById('step-rooms').classList.add('hidden');
            const stepGuest = document.getElementById('step-guest');
            stepGuest.classList.remove('hidden');
            updateStepBar(3);

            // Hide the floating bar when moving to next step
            const bar = document.getElementById('selected-rooms-bar');
            if (bar) bar.style.display = 'none';

            updatePricingBreakdown();
        };

        window.updatePricingBreakdown = () => {
            if (selectedRoomIds.length === 0) return;
            
            const overrideVal = document.getElementById('price_override').value.trim();
            const customPrice = overrideVal !== '' ? parseFloat(overrideVal) : null;
            
            const ratePlanName = window.selectedRatePlanName;
            const taxEnabled = window.TAX_ENABLED;
            const taxRate = window.TAX_RATE || 0;
            const taxLabel = window.TAX_LABEL || 'Tax';
            
            // Track individual overrides
            if (!window.roomOverrides) {
                window.roomOverrides = {};
            }

            // Build per-room breakdown with individual edit options
            let roomBreakdownHtml = '';
            let totalBaseOverride = 0;
            let hasOverrides = false;

            selectedRoomsInfo.forEach(r => {
                const currentCost = window.roomOverrides[r.id] !== undefined ? window.roomOverrides[r.id] : r.totalCost;
                if (window.roomOverrides[r.id] !== undefined) {
                    hasOverrides = true;
                }
                totalBaseOverride += currentCost;

                const inlineEditHtml = `
                    <div class="flex items-center gap-1.5" id="room-price-wrapper-${r.id}">
                        <span class="text-slate-800 font-bold">₹${currentCost.toFixed(2)}</span>
                        <button onclick="enableRoomPriceEdit(${r.id}, ${currentCost})" class="text-[10px] text-indigo-600 hover:text-indigo-800 font-extrabold transition-colors cursor-pointer bg-indigo-50 hover:bg-indigo-100 px-1.5 py-0.5 rounded border border-indigo-100" title="Edit Room Price"><i class="ph ph-pencil-simple text-xs mr-0.5"></i>Edit</button>
                    </div>
                `;

                roomBreakdownHtml += `
                    <div class="flex justify-between items-center py-1 border-b border-slate-50 last:border-0">
                        <span>Room ${r.roomNumber} (${r.ratePlanName})</span>
                        ${inlineEditHtml}
                    </div>
                `;
            });

            const currentBaseCost = customPrice !== null ? customPrice : totalBaseOverride;
            let taxAmount = 0;
            let finalTotal = currentBaseCost;
            
            if (taxEnabled) {
                taxAmount = currentBaseCost * (taxRate / 100);
                finalTotal = currentBaseCost + taxAmount;
            }
            
            document.getElementById('modalTotalCost').value = finalTotal.toFixed(2);
            document.getElementById('price_override').value = hasOverrides ? currentBaseCost.toFixed(2) : '';
            
            // Build room list display
            const roomNumbers = selectedRoomsInfo.map(r => r.roomNumber).join(', ');
            const roomCount = selectedRoomIds.length;
            
            breakdownHtml = `
                <div class="mt-4 pt-3 border-t border-slate-100 space-y-1.5 text-xs font-semibold text-slate-600">
                    ${roomBreakdownHtml}
                    ${taxEnabled ? `
                    <div class="flex justify-between">
                        <span>${taxLabel} (${taxRate}%)</span>
                        <span class="text-slate-800">₹${taxAmount.toFixed(2)}</span>
                    </div>
                    ` : ''}
                    <div class="flex justify-between border-t border-slate-100 pt-2 text-sm font-extrabold text-slate-900 font-display items-center">
                        <span>Net Total</span>
                        <span class="text-indigo-600 text-base font-black">₹${finalTotal.toFixed(2)}</span>
                    </div>
                </div>
            `;

            document.getElementById('selected-room-info').innerHTML = `
                <div class="flex justify-between items-center mb-2.5">
                    <div class="font-bold text-base text-slate-800 font-display">Room${roomCount > 1 ? 's' : ''} ${roomNumbers}</div>
                    <div class="text-[10px] font-bold bg-indigo-50 text-indigo-600 px-2.5 py-1 rounded-lg border border-indigo-100">${ratePlanName}</div>
                </div>
                <div class="text-[11px] font-semibold text-slate-500 flex items-center gap-1.5"><i class="ph ph-clock text-sm text-slate-400"></i> ${getCheckIn().replace('T', ' ')} <i class="ph ph-arrow-right text-xs"></i> ${getCheckOut().replace('T', ' ')}</div>
                ${breakdownHtml}
            `;
        };

        window.enableRoomPriceEdit = (roomId, currentPrice) => {
            const wrapper = document.getElementById(`room-price-wrapper-${roomId}`);
            if (!wrapper) return;
            wrapper.innerHTML = `
                <div class="flex items-center gap-1 bg-white border border-slate-200 rounded-lg px-2 py-0.5 shadow-sm">
                    <span class="text-[10px] text-slate-400">₹</span>
                    <input type="number" id="override_room_${roomId}" value="${currentPrice}" class="w-12 text-right outline-none text-[11px] font-bold text-slate-800" min="0" step="0.01">
                    <button onclick="saveRoomPriceOverride(${roomId})" class="text-emerald-600 hover:bg-emerald-50 rounded" title="Save"><i class="ph ph-check text-[10px] font-bold"></i></button>
                    <button onclick="cancelRoomPriceOverride()" class="text-rose-600 hover:bg-rose-50 rounded" title="Cancel"><i class="ph ph-x text-[10px] font-bold"></i></button>
                </div>
            `;
            setTimeout(() => document.getElementById(`override_room_${roomId}`)?.focus(), 50);
        };

        window.saveRoomPriceOverride = (roomId) => {
            const val = document.getElementById(`override_room_${roomId}`).value.trim();
            if (val !== '') {
                window.roomOverrides[roomId] = parseFloat(val);
            }
            updatePricingBreakdown();
        };

        window.cancelRoomPriceOverride = () => {
            updatePricingBreakdown();
        };

        window.enablePriceEdit = () => {
            // Deprecated - replaced by room-level editing
        };
        
        window.saveInlinePriceOverride = () => {
            const val = document.getElementById('inline_price_override').value.trim();
            document.getElementById('price_override').value = val;
            updatePricingBreakdown();
        };
        
        window.cancelInlinePriceOverride = () => {
            updatePricingBreakdown();
        };

        document.getElementById('btn-back-dates').addEventListener('click', () => {
            const stepRooms = document.getElementById('step-rooms');
            const stepDates = document.getElementById('step-dates');
            if (stepRooms) {
                stepRooms.classList.add('hidden');
                stepRooms.style.display = 'none';
            }
            if (stepDates) {
                stepDates.classList.remove('hidden');
                stepDates.style.display = 'block';
            }
            updateStepBar(1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        
        document.getElementById('btn-back-rooms').addEventListener('click', () => {
            document.getElementById('step-guest').classList.add('hidden');
            const stepRooms = document.getElementById('step-rooms');
            stepRooms.classList.remove('hidden');
            updateStepBar(2);
        });

        document.getElementById('btn-book').addEventListener('click', async () => {
            const checkIn = getCheckIn();
            const checkOut = getCheckOut();
            const guestName = document.getElementById('guest_name').value.trim();
            const phoneRaw = document.getElementById('guest_phone').value.trim();
            const countryCode = document.getElementById('country_code').value;
            const guestPhone = countryCode + phoneRaw;
            const ratePlan = document.getElementById('modalRatePlan').value;
            const bookingSource = document.getElementById('booking_source').value;
            
            let hasError = false;
            if(!guestName) {
                document.getElementById('guest-name-wrap').classList.add('animate-shake');
                setTimeout(() => document.getElementById('guest-name-wrap').classList.remove('animate-shake'), 400);
                hasError = true;
            }
            if(!/^\d{10}$/.test(phoneRaw)) {
                showNotification('Phone number must be exactly 10 digits', 'error');
                document.getElementById('guest-phone-wrap').classList.add('animate-shake');
                setTimeout(() => document.getElementById('guest-phone-wrap').classList.remove('animate-shake'), 400);
                hasError = true;
            }
            if(hasError) {
                showNotification('Please fill in required guest details', 'error');
                return;
            }
            
            const btn = document.getElementById('btn-book');
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="ph ph-spinner animate-spin mr-2 text-sm"></i> Confirming...';

            // Submit via FormData to support file uploads
            const formData = new FormData();
            // Send room IDs as JSON array for multi-room support
            const roomIds = selectedRoomIds.map(r => r.id);
            formData.append('room_ids', JSON.stringify(roomIds));
            formData.append('check_in', checkIn);
            formData.append('check_out', checkOut);
            formData.append('guest_name', guestName);
            formData.append('guest_phone', guestPhone);
            formData.append('rate_plan_name', ratePlan);
            formData.append('booking_source', bookingSource);
            
            const priceOverride = document.getElementById('price_override').value.trim();
            if(priceOverride) {
                formData.append('price_override', priceOverride);
            }

            if(window.roomOverrides && Object.keys(window.roomOverrides).length > 0) {
                formData.append('room_overrides', JSON.stringify(window.roomOverrides));
            }

            const payAmt = document.getElementById('payment_collected').value.trim();
            if(payAmt) {
                formData.append('payment_collected', payAmt);
                formData.append('payment_method', document.getElementById('payment_method').value);
            }

            const idFront = document.getElementById('id_proof_front').files[0];
            if(idFront) formData.append('id_proof_front', idFront);

            const idBack = document.getElementById('id_proof_back').files[0];
            if(idBack) formData.append('id_proof_back', idBack);

            const photo = document.getElementById('guest_photo').files[0];
            if(photo) formData.append('guest_photo', photo);

            try {
                const res = await fetch('api/create_hold.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                
                if(data.success) {
                    const count = data.booking_ids ? data.booking_ids.length : 1;
                    showNotification(`${count} Booking${count > 1 ? 's' : ''} confirmed!`, 'success');
                    setTimeout(() => {
                        // Redirect to first booking's folio
                        const firstId = data.booking_ids ? data.booking_ids[0] : data.booking_id;
                        window.location.href = `admin/folio.php?id=${firstId}`;
                    }, 1000);
                } else {
                    showNotification(data.message, 'error');
                    btn.innerHTML = origHtml;
                }
            } catch(e) {
                showNotification('Error creating booking', 'error');
                btn.innerHTML = origHtml;
            }
        });
        
        // Guest Autocomplete Logic
        let suggestionTimeout;

        function handleGuestSearch(e) {
            clearTimeout(suggestionTimeout);
            const q = e.target.value.trim();
            if(q.length < 2) {
                document.getElementById('guest-suggestions').classList.add('hidden');
                return;
            }
            
            suggestionTimeout = setTimeout(async () => {
                try {
                    const res = await fetch(`api/search_guests.php?q=${encodeURIComponent(q)}`);
                    const data = await res.json();
                    
                    const container = document.getElementById('guest-suggestions');
                    if(data.success && data.guests.length > 0) {
                        container.innerHTML = '';
                        data.guests.forEach(g => {
                            const item = document.createElement('div');
                            item.className = 'p-3 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0 transition-colors';
                            
                            const nameP = document.createElement('p');
                            nameP.className = 'font-bold text-xs text-slate-800';
                            nameP.textContent = g.guest_name;
                            
                            const phoneP = document.createElement('p');
                            phoneP.className = 'text-[10px] font-semibold text-slate-400 mt-0.5';
                            phoneP.textContent = g.guest_phone;
                            
                            item.appendChild(nameP);
                            item.appendChild(phoneP);
                            
                            item.addEventListener('click', () => {
                                selectGuest(g.guest_name, g.guest_phone);
                            });
                            container.appendChild(item);
                        });
                        container.classList.remove('hidden');
                    } else {
                        container.classList.add('hidden');
                    }
                } catch(err) {
                    console.error('Search failed', err);
                }
            }, 300);
        }

        document.getElementById('guest_phone').addEventListener('input', handleGuestSearch);
        document.getElementById('guest_name').addEventListener('input', handleGuestSearch);

        window.selectGuest = (name, phone) => {
            document.getElementById('guest_name').value = name;
            document.getElementById('guest_phone').value = phone;
            document.getElementById('guest-suggestions').classList.add('hidden');
            showNotification('Guest details prefilled!', 'success');
        };

        document.addEventListener('click', (e) => {
            if(!e.target.closest('#guest-suggestions') && e.target.id !== 'guest_phone' && e.target.id !== 'guest_name') {
                document.getElementById('guest-suggestions').classList.add('hidden');
            }
        });
    </script>
</body>
</html>
