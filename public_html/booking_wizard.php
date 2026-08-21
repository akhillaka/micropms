<?php
require_once __DIR__ . '/../pms_core/AuthHelper.php';
require_once __DIR__ . '/../pms_core/CsrfToken.php';
AuthHelper::requireLoginOrRedirect();
if (!AuthHelper::can('create_booking')) {
    header('Location: /login');
    exit;
}

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';
$db = Database::getInstance()->getConnection();
load_db_settings($db);

$propertyId = AuthHelper::getPropertyId();
$paymentMethods = get_payment_methods($db, (int)$propertyId);
$activeGateways = get_active_payment_gateways($db, (int)$propertyId);
foreach ($activeGateways as $gw) {
    $label = $gw['gateway'] === 'phonepe' ? 'PhonePe' : 'Razorpay';
    if (!in_array($label, $paymentMethods, true)) {
        $paymentMethods[] = $label;
    }
}
$isOwner = (AuthHelper::getRole() === 'owner') ? 'true' : 'false';

// Default auto-fetched check-in & check-out dates and times
$nowTs = time();
$prefillDate = $_GET['prefill_date'] ?? '';
if (!empty($prefillDate)) {
    $prefillTs = strtotime($prefillDate);
    if ($prefillTs > 0) {
        $nowTs = $prefillTs;
    }
}
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
        window.PREFILL_ROOM_ID = <?= json_encode($_GET['prefill_room'] ?? null) ?>;
    </script>
    <meta charset="UTF-8">
    <?= CsrfToken::meta() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>MicroPMS Booking</title>
    <?php include __DIR__ . '/admin/components/micropms_icons.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="js/api-client.js"></script>
    <script src="js/ui.js"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/css/mobile-input-zoom.css" rel="stylesheet">
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
        @keyframes pms-skeleton { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
        .skeleton {
            display: block;
            background: linear-gradient(90deg, #e2e8f0 0%, #f8fafc 45%, #e2e8f0 90%);
            background-size: 200% 100%;
            animation: pms-skeleton 1.15s ease infinite;
            border-radius: 6px;
            min-height: 0.75rem;
        }
        .skeleton.h-16 { height: 4rem; }
        .skeleton.w-full { width: 100%; }
        .pms-empty-state { display:flex; flex-direction:column; align-items:center; gap:8px; padding:28px 16px; color:#64748B; text-align:center; }
        .pms-empty-retry { margin-top:6px; background:#fff; border:1px solid #E2E8F0; border-radius:0.75rem; padding:0.5rem 1rem; font-size:0.8125rem; font-weight:700; color:#1E3A8A; cursor:pointer; }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-tr from-slate-50 via-indigo-50/20 to-slate-100/50">

    <!-- Header -->
    <header class="bg-white px-6 py-4 flex items-center justify-between border-b border-slate-100 sticky top-0 z-50 shadow-sm">
        <div class="flex items-center gap-3">
            <img src="/icons/logo.svg" alt="MicroPMS" class="micropms-header-mark w-10 h-10 rounded-xl object-contain bg-white border border-slate-200" width="40" height="40">
            <div>
                <h1 class="text-base font-bold text-slate-800 tracking-tight leading-none font-display">MicroPMS</h1>
                <span class="text-[9px] font-semibold text-slate-400 uppercase tracking-wider mt-0.5 inline-block">Booking Portal</span>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin" class="text-xs font-bold text-slate-600 hover:text-slate-900 items-center gap-2 transition-all bg-slate-50 hover:bg-slate-100 px-4 py-2.5 rounded-xl border border-slate-200 flex">
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
                        <input type="tel" id="guest_phone" placeholder="Phone number..." class="flex-1 input-glass rounded-xl p-3 text-sm font-semibold text-slate-800" required>
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
                                    <option value="<?= htmlspecialchars((string)($pm)) ?>"><?= htmlspecialchars((string)($pm)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="ph ph-caret-down absolute right-3.5 top-3 text-slate-400 pointer-events-none text-xs"></i>
                        </div>
                    </div>
                </div>

                <!-- Document Uploads -->
                <div class="space-y-3">
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Guest Verification & Photos</p>
                    <p class="text-[10px] text-slate-500 font-semibold">ID: fill the card frame, avoid glare, hold still. Photo: center the face.</p>
                    <div class="grid grid-cols-3 gap-2">
                        <!-- ID Front -->
                        <div class="border border-dashed border-slate-200 rounded-xl p-3 flex flex-col items-center justify-center text-center min-h-[85px]">
                            <i class="ph ph-identification-card text-xl text-slate-400 mb-1"></i>
                            <span class="text-[9px] font-bold text-slate-600 leading-none">ID Front</span>
                            <button type="button" onclick="captureGuestDoc('id_proof_front', 'id_front')" class="mt-1.5 text-[9px] font-bold text-indigo-700">Camera</button>
                            <label class="text-[9px] font-bold text-slate-500 cursor-pointer">Upload
                                <input type="file" id="id_proof_front" accept="image/*" capture="environment" class="hidden" onchange="updateFileLabel(this, 'id-front-name')">
                            </label>
                            <span id="id-front-name" class="text-[8px] text-slate-450 mt-1 block truncate max-w-full font-semibold"></span>
                        </div>
                        <!-- ID Back -->
                        <div class="border border-dashed border-slate-200 rounded-xl p-3 flex flex-col items-center justify-center text-center min-h-[85px]">
                            <i class="ph ph-identification-card text-xl text-slate-400 mb-1"></i>
                            <span class="text-[9px] font-bold text-slate-600 leading-none">ID Back</span>
                            <button type="button" onclick="captureGuestDoc('id_proof_back', 'id_back')" class="mt-1.5 text-[9px] font-bold text-indigo-700">Camera</button>
                            <label class="text-[9px] font-bold text-slate-500 cursor-pointer">Upload
                                <input type="file" id="id_proof_back" accept="image/*" capture="environment" class="hidden" onchange="updateFileLabel(this, 'id-back-name')">
                            </label>
                            <span id="id-back-name" class="text-[8px] text-slate-450 mt-1 block truncate max-w-full font-semibold"></span>
                        </div>
                        <!-- Guest Photo -->
                        <div class="border border-dashed border-slate-200 rounded-xl p-3 flex flex-col items-center justify-center text-center min-h-[85px]">
                            <i class="ph ph-camera text-xl text-slate-400 mb-1"></i>
                            <span class="text-[9px] font-bold text-slate-600 leading-none">Photo</span>
                            <button type="button" onclick="captureGuestDoc('guest_photo', 'guest_face')" class="mt-1.5 text-[9px] font-bold text-indigo-700">Camera</button>
                            <label class="text-[9px] font-bold text-slate-500 cursor-pointer">Upload
                                <input type="file" id="guest_photo" accept="image/*" class="hidden" onchange="updateFileLabel(this, 'photo-name')">
                            </label>
                            <span id="photo-name" class="text-[8px] text-slate-450 mt-1 block truncate max-w-full font-semibold"></span>
                        </div>
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
        <a href="javascript:void(0)" class="flex flex-col items-center text-slate-900">
            <i class="ph ph-calendar-plus text-2xl font-bold"></i>
            <span class="text-[9px] font-bold uppercase tracking-wider mt-1">Book</span>
        </a>
        <a href="/admin" class="flex flex-col items-center text-slate-400 hover:text-slate-700 transition-colors">
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

        function captureGuestDoc(inputId, mode) {
            if (!window.PhotoCapture) return;
            const labels = { id_proof_front: 'id-front-name', id_proof_back: 'id-back-name', guest_photo: 'photo-name' };
            PhotoCapture.open({
                mode,
                onCapture: (_url, file) => {
                    const input = document.getElementById(inputId);
                    PhotoCapture.assignFile(input, file);
                    updateFileLabel(input, labels[inputId]);
                }
            });
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

    </script>
    <script src="js/photo_capture.js?v=<?= time() ?>"></script>
    <script src="js/booking_wizard.js?v=<?= time() ?>"></script>
    
</body>
</html>
