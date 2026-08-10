<?php
declare(strict_types=1);
require_once __DIR__ . '/../pms_core/config.php';
require_once __DIR__ . '/../pms_core/Database.php';

$propertyName = 'Your Reservation';
$hotelId = (int)($_GET['hotelId'] ?? 0);
if ($hotelId > 0) {
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT name FROM properties WHERE id = ?");
        $stmt->execute([$hotelId]);
        if ($prop = $stmt->fetch()) {
            $propertyName = $prop['name'];
        }
    } catch (\Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Login - Find Reservation</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; }
        .input-premium {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
            transition: all 0.2s ease;
        }
        .input-premium:focus {
            background-color: #ffffff;
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md glass-card rounded-3xl p-8 space-y-8 relative overflow-hidden">
        <!-- Decorative element -->
        <div class="absolute top-0 right-0 w-32 h-32 bg-blue-500 rounded-bl-full opacity-10 blur-xl"></div>
        <div class="absolute bottom-0 left-0 w-32 h-32 bg-amber-500 rounded-tr-full opacity-10 blur-xl"></div>

        <div class="text-center space-y-2 relative z-10">
            <div class="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center mx-auto shadow-lg mb-4">
                <i class="ph ph-buildings text-3xl text-white"></i>
            </div>
            <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight">Find <?= htmlspecialchars($propertyName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-sm font-medium text-slate-500">Enter your booking details to access the guest portal.</p>
        </div>

        <form id="loginForm" onsubmit="handleLogin(event)" class="space-y-5 relative z-10">
            
            <div id="errorBox" class="hidden bg-rose-50 border border-rose-200 text-rose-600 px-4 py-3 rounded-xl text-xs font-bold text-center">
            </div>

            <div class="space-y-1">
                <label class="block text-[11px] font-extrabold text-slate-500 uppercase tracking-wider pl-1">Booking Reference (PNR)</label>
                <div class="relative">
                    <i class="ph ph-ticket absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg"></i>
                    <input type="text" id="pnr" required placeholder="e.g. BKG-12345" class="w-full input-premium py-3 pl-10 pr-4 rounded-xl text-sm font-semibold">
                </div>
            </div>

            <div class="space-y-1">
                <label class="block text-[11px] font-extrabold text-slate-500 uppercase tracking-wider pl-1">Phone or Email</label>
                <div class="relative">
                    <i class="ph ph-identification-card absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg"></i>
                    <input type="text" id="identity" required placeholder="Registered phone or email" class="w-full input-premium py-3 pl-10 pr-4 rounded-xl text-sm font-semibold">
                </div>
            </div>

            <button type="submit" id="submitBtn" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-sm uppercase tracking-wider rounded-xl transition shadow-lg shadow-blue-600/30 flex items-center justify-center gap-2 cursor-pointer mt-4">
                <span>Access Portal</span>
                <i class="ph ph-arrow-right"></i>
            </button>
        </form>

    </div>

    <script>
        async function handleLogin(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const errorBox = document.getElementById('errorBox');
            
            btn.innerHTML = '<i class="ph ph-spinner animate-spin text-lg"></i> Checking...';
            btn.disabled = true;
            btn.classList.add('opacity-75', 'cursor-not-allowed');
            errorBox.classList.add('hidden');

            const payload = {
                pnr: document.getElementById('pnr').value.trim(),
                identity: document.getElementById('identity').value.trim()
            };

            try {
                const res = await fetch('/api/guest/auth', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                
                if (data.success) {
                    btn.innerHTML = '<i class="ph ph-check-circle text-lg"></i> Redirecting...';
                    btn.classList.replace('bg-blue-600', 'bg-emerald-500');
                    btn.classList.replace('hover:bg-blue-700', 'hover:bg-emerald-600');
                    
                    // Store token securely in sessionStorage and redirect with it for first-load verification
                    sessionStorage.setItem('guest_portal_token', data.token);
                    sessionStorage.setItem('guest_portal_booking_id', data.booking_id);
                    
                    setTimeout(() => {
                        window.location.href = `guest_portal.php?id=${data.booking_id}&token=${data.token}`;
                    }, 500);
                } else {
                    throw new Error(data.message || 'Invalid booking details.');
                }
            } catch (err) {
                errorBox.textContent = err.message || 'Network error occurred.';
                errorBox.classList.remove('hidden');
                
                // Reset button
                btn.innerHTML = '<span>Access Portal</span><i class="ph ph-arrow-right"></i>';
                btn.disabled = false;
                btn.classList.remove('opacity-75', 'cursor-not-allowed');
            }
        }
    </script>
</body>
</html>
