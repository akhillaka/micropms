<?php
declare(strict_types=1);
require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';

$propertyId = (int)($_GET['pid'] ?? 1);
$db = Database::getInstance()->getConnection();

// Fetch property name for branding
$stmt = $db->prepare("SELECT name FROM properties WHERE id = ?");
$stmt->execute([$propertyId]);
$propertyName = $stmt->fetchColumn() ?: 'MicroPMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Reservation | <?= htmlspecialchars((string)($propertyName)) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
        }
        .light-glass {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 20px 40px -15px rgba(15, 23, 42, 0.05);
        }
        .input-premium {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            transition: all 200ms ease;
        }
        .input-premium:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
            outline: none;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <!-- Abstract light glow circles in background -->
    <div class="absolute top-1/4 left-1/4 w-[300px] h-[300px] bg-indigo-200/40 rounded-full blur-[100px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-1/4 w-[250px] h-[250px] bg-sky-200/30 rounded-full blur-[80px] pointer-events-none"></div>

    <div class="w-full max-w-md light-glass rounded-3xl p-8 space-y-6 relative overflow-hidden animate-fade-in">
        <!-- Logo and Header -->
        <div class="text-center space-y-2 relative z-10">
            <div class="w-16 h-16 bg-indigo-50 border border-indigo-100 rounded-2xl flex items-center justify-center mx-auto shadow-sm mb-4">
                <i class="ph ph-bell-ringing text-3xl text-indigo-600"></i>
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight"><?= htmlspecialchars((string)($propertyName)) ?></h1>
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Guest Digital Assistant</p>
        </div>

        <div id="errorBox" class="hidden bg-rose-50 border border-rose-200 text-rose-600 px-4 py-3 rounded-2xl text-xs font-bold text-center"></div>

        <!-- SEARCH FORM -->
        <div id="search-section" class="space-y-4">
            <div class="space-y-1">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest pl-1">Mobile Number</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                        <i class="ph ph-phone text-lg"></i>
                    </span>
                    <input type="tel" id="guest_phone" placeholder="Enter registered phone" class="w-full input-premium py-3.5 pl-11 pr-4 rounded-xl text-sm font-semibold">
                </div>
            </div>

            <button onclick="searchGuest()" id="searchBtn" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl transition shadow-lg shadow-indigo-500/20 flex items-center justify-center gap-2 cursor-pointer mt-4">
                <span>Find Bookings</span>
                <i class="ph ph-caret-right"></i>
            </button>
        </div>

        <!-- OTP VERIFICATION FORM (HIDDEN BY DEFAULT) -->
        <div id="otp-section" class="hidden space-y-4">
            <div class="text-center space-y-1">
                <h3 class="text-sm font-bold text-slate-800">Verify Your Number</h3>
                <p class="text-xs text-slate-500">We sent a 4-digit verification code to your WhatsApp.</p>
            </div>
            <div class="flex justify-center gap-3 py-2">
                <input type="text" maxlength="1" onkeyup="moveNext(this, 'otp-2')" id="otp-1" class="w-12 h-12 text-center text-lg font-bold border border-slate-200 rounded-xl focus:border-indigo-500 focus:outline-none bg-white">
                <input type="text" maxlength="1" onkeyup="moveNext(this, 'otp-3')" id="otp-2" class="w-12 h-12 text-center text-lg font-bold border border-slate-200 rounded-xl focus:border-indigo-500 focus:outline-none bg-white">
                <input type="text" maxlength="1" onkeyup="moveNext(this, 'otp-4')" id="otp-3" class="w-12 h-12 text-center text-lg font-bold border border-slate-200 rounded-xl focus:border-indigo-500 focus:outline-none bg-white">
                <input type="text" maxlength="1" onkeyup="verifyOtp()" id="otp-4" class="w-12 h-12 text-center text-lg font-bold border border-slate-200 rounded-xl focus:border-indigo-500 focus:outline-none bg-white">
            </div>
            <button onclick="verifyOtp()" id="verifyBtn" class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold text-xs uppercase tracking-wider rounded-xl transition shadow-lg shadow-emerald-500/20 flex items-center justify-center gap-2 cursor-pointer">
                <span>Verify OTP</span>
                <i class="ph ph-check-circle"></i>
            </button>
        </div>

        <!-- BOOKINGS SELECTION LIST (HIDDEN BY DEFAULT) -->
        <div id="bookings-section" class="hidden space-y-4">
            <div class="text-center border-b border-dashed border-slate-200 pb-3">
                <h3 class="text-sm font-bold text-slate-800">Your Reservations</h3>
                <p class="text-xs text-slate-500">Select a reservation to open the guest portal.</p>
            </div>
            <div id="bookings-list" class="space-y-3 max-h-[220px] overflow-y-auto pr-1">
                <!-- dynamic list options will go here -->
            </div>
        </div>
    </div>

    <script>
        const propertyId = <?= $propertyId ?>;
        
        function moveNext(el, nextId) {
            if (el.value.length === 1) {
                document.getElementById(nextId)?.focus();
            }
        }

        function showStatus(msg, isError = true) {
            const errorBox = document.getElementById('errorBox');
            errorBox.textContent = msg;
            if (isError) {
                errorBox.classList.replace('bg-emerald-50', 'bg-rose-50');
                errorBox.classList.replace('text-emerald-600', 'text-rose-600');
                errorBox.classList.replace('border-emerald-200', 'border-rose-200');
            } else {
                errorBox.classList.replace('bg-rose-50', 'bg-emerald-50');
                errorBox.classList.replace('text-rose-600', 'text-emerald-600');
                errorBox.classList.replace('border-rose-200', 'border-emerald-200');
            }
            errorBox.classList.remove('hidden');
        }

        async function searchGuest() {
            const phone = document.getElementById('guest_phone').value.trim();
            const btn = document.getElementById('searchBtn');
            if (!phone) {
                showStatus('Please enter a valid mobile number');
                return;
            }
            btn.innerHTML = '<i class="ph ph-spinner animate-spin text-lg"></i> Searching...';
            btn.disabled = true;

            try {
                const res = await fetch('/api/guest/search', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ phone: phone, property_id: propertyId })
                });
                const data = await res.json();
                if (data.success) {
                    if (data.otp_required) {
                        document.getElementById('search-section').classList.add('hidden');
                        document.getElementById('otp-section').classList.remove('hidden');
                        showStatus('OTP sent to your WhatsApp number.', false);
                    } else {
                        renderBookings(data.bookings);
                    }
                } else {
                    showStatus(data.message || 'No bookings found');
                }
            } catch (e) {
                showStatus('Connection failure to search API');
            } finally {
                btn.innerHTML = '<span>Find Bookings</span><i class="ph ph-caret-right"></i>';
                btn.disabled = false;
            }
        }

        async function verifyOtp() {
            const otp = document.getElementById('otp-1').value + 
                        document.getElementById('otp-2').value + 
                        document.getElementById('otp-3').value + 
                        document.getElementById('otp-4').value;
            const btn = document.getElementById('verifyBtn');
            if (otp.length < 4) {
                showStatus('Please enter all 4 digits');
                return;
            }
            btn.innerHTML = '<i class="ph ph-spinner animate-spin text-lg"></i> Verifying...';
            btn.disabled = true;

            try {
                const res = await fetch('/api/guest/verify_otp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ otp: otp })
                });
                const data = await res.json();
                if (data.success) {
                    renderBookings(data.bookings);
                } else {
                    showStatus(data.message || 'Incorrect OTP');
                }
            } catch (e) {
                showStatus('Verification connection failure');
            } finally {
                btn.innerHTML = '<span>Verify OTP</span><i class="ph ph-check-circle"></i>';
                btn.disabled = false;
            }
        }

        function renderBookings(bookings) {
            document.getElementById('search-section').classList.add('hidden');
            document.getElementById('otp-section').classList.add('hidden');
            document.getElementById('errorBox').classList.add('hidden');
            
            const listContainer = document.getElementById('bookings-list');
            listContainer.innerHTML = '';
            
            bookings.forEach(b => {
                const card = document.createElement('div');
                card.className = "p-4 bg-white border border-slate-200 rounded-2xl hover:border-indigo-500 hover:shadow-md cursor-pointer transition-all flex justify-between items-center";
                card.onclick = () => {
                    window.location.href = `/guest-portal?id=${b.id}&token=${b.token}`;
                };
                card.innerHTML = `
                    <div class="space-y-1">
                        <span class="text-xs font-bold text-indigo-600 font-mono">PNR: ${b.display_id || b.id}</span>
                        <div class="text-sm font-extrabold text-slate-800">${b.guest_name}</div>
                        <div class="text-[10px] text-slate-400 font-medium">Check-in: ${b.check_in.split(' ')[0]}</div>
                    </div>
                    <i class="ph ph-arrow-square-out text-lg text-slate-400"></i>
                `;
                listContainer.appendChild(card);
            });
            
            document.getElementById('bookings-section').classList.remove('hidden');
        }
    </script>
</body>
</html>
