<?php
declare(strict_types=1);

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';
require_once __DIR__ . '/../pms_core/AuditLogger.php';

$db = Database::getInstance()->getConnection();
load_db_settings($db);

$bookingId = $_GET['id'] ?? $_POST['id'] ?? '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if (empty($bookingId) || empty($token)) {
    die("Access Denied: Missing parameters.");
}

// Compute secure token to verify authenticity
$computedToken = hash_hmac('sha256', (string)$bookingId, INVOICE_SECRET);
if (!hash_equals($computedToken, $token)) {
    die("Access Denied: Invalid secure token.");
}

// Fetch booking & property info
$stmt = $db->prepare("
    SELECT b.*, p.name as property_name, p.logo_url, g.name as guest_name, g.email as guest_email, g.phone as guest_phone,
           rc.name as room_type, b.rate_plan_name, r.room_number
    FROM bookings b
    JOIN properties p ON b.property_id = p.id
    LEFT JOIN guests g ON b.guest_id = g.id
    LEFT JOIN rooms r ON b.room_id = r.id
    LEFT JOIN room_categories rc ON r.category_id = rc.id
    WHERE b.id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    die("Booking not found.");
}

// Reload DB settings for this specific property
load_db_settings($db, (int)$booking['property_id']);

// Load configurations
$propId = (int)$booking['property_id'];
$upsellEnabled = (get_db_setting($db, 'GUEST_PORTAL_UPSELL_ENABLED', $propId) === 'true');
$posEnabled = (get_db_setting($db, 'GUEST_PORTAL_POS_ENABLED', $propId) === 'true');
$housekeepingEnabled = (get_db_setting($db, 'GUEST_PORTAL_HOUSEKEEPING_ENABLED', $propId) === 'true');
$selfCheckoutEnabled = (get_db_setting($db, 'GUEST_PORTAL_SELF_CHECKOUT_ENABLED', $propId) === 'true');
$earlyLateFee = floatval(get_db_setting($db, 'GUEST_PORTAL_EARLY_LATE_FEE', $propId) ?: '0.00');

// Calculate Ledger financial summaries
$ledgerStmt = $db->prepare("SELECT * FROM folio_ledger WHERE booking_id = ? ORDER BY recorded_at ASC");
$ledgerStmt->execute([$bookingId]);
$ledger = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

$subtotalCharges = 0;
$totalPayments = 0;
$refundsIssued = 0;
foreach($ledger as $l) {
    $val = (float)$l['amount'];
    if ($val > 0) {
        $subtotalCharges += $val;
    } else {
        if (strpos(strtolower($l['description']), 'refund') !== false) {
            $refundsIssued += abs((float)$val);
        } else {
            $totalPayments += abs((float)$val);
        }
    }
}
$taxEnabled = defined('TAX_ENABLED') && TAX_ENABLED === 'true';
$taxRate = defined('TAX_RATE') ? (float)TAX_RATE : 0.0;
$taxPref = $booking['tax_preference'] ?? 'exclusive';
$taxAmount = 0.0;
$totalCharges = $subtotalCharges;

if ($taxEnabled) {
    if ($taxPref === 'exclusive') {
        $taxAmount = $subtotalCharges * ($taxRate / 100);
        $totalCharges = $subtotalCharges + $taxAmount;
    } elseif ($taxPref === 'inclusive') {
        $taxAmount = $subtotalCharges - ($subtotalCharges / (1 + ($taxRate / 100)));
        $totalCharges = $subtotalCharges;
    }
}
$balance = $totalCharges - $totalPayments + $refundsIssued;

// Fetch active payment gateway configurations
$gatewayStmt = $db->prepare("SELECT gateway, is_active FROM payment_gateway_configs WHERE property_id = ? AND is_active = 1");
$gatewayStmt->execute([$booking['property_id']]);
$activeGateways = $gatewayStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$message = null;
$error = null;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_cleaning' && $housekeepingEnabled) {
        try {
            $upStmt = $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ?");
            $upStmt->execute([$booking['room_id']]);

            AuditLogger::log(0, 'PORTAL_CLEANING_REQUEST', 'BOOKING', $booking['id'], [
                'guest' => $booking['guest_name'],
                'room' => $booking['room_number']
            ], (int)$booking['property_id']);

            $message = "🧹 Housekeeping cleaning request sent successfully! Our staff is on the way.";
        } catch (Exception $e) {
            $error = "Failed to submit request: " . $e->getMessage();
        }
    } elseif ($action === 'sign_reg_card') {
        $signatureData = $_POST['signature_data'] ?? '';
        if (empty($signatureData) || !str_starts_with($signatureData, 'data:image/png;base64,')) {
            $error = "Invalid signature data drawing.";
        } else {
            try {
                $signStmt = $db->prepare("UPDATE guests SET digital_signature = ? WHERE id = ?");
                $signStmt->execute([$signatureData, $booking['guest_id']]);
                
                AuditLogger::log(0, 'PORTAL_SIGN_REG_CARD', 'BOOKING', $booking['id'], [
                    'guest' => $booking['guest_name']
                ], (int)$booking['property_id']);
                
                header("Location: /guest-portal?id={$bookingId}&token={$token}&msg=signature_success");
                exit;
            } catch (Exception $e) {
                $error = "Failed to save digital signature: " . $e->getMessage();
            }
        }
    } elseif ($action === 'submit_review') {
        $rating = (int)($_POST['rating'] ?? 5);
        if ($rating < 1 || $rating > 5) {
            $rating = 5;
        }
        $comment = trim($_POST['comment'] ?? '');
        
        try {
            $revStmt = $db->prepare("INSERT INTO guest_reviews (booking_id, property_id, rating, comment) VALUES (?, ?, ?, ?)");
            $revStmt->execute([$bookingId, (int)$booking['property_id'], $rating, $comment]);
            
            AuditLogger::log(0, 'PORTAL_SUBMIT_REVIEW', 'BOOKING', $booking['id'], [
                'rating' => $rating,
                'comment' => $comment
            ], (int)$booking['property_id']);
            
            header("Location: /guest-portal?id={$bookingId}&token={$token}&msg=review_success");
            exit;
        } catch (Exception $e) {
            $error = "Failed to submit review: " . $e->getMessage();
        }
    } elseif ($action === 'upsell_late_checkout' && $upsellEnabled) {
        try {
            // Check if one already exists
            $chk = $db->prepare("SELECT id FROM guest_service_requests WHERE booking_id = ? AND status = 'pending' AND (service_type = 'late_checkout' OR service_type = 'Late Checkout')");
            $chk->execute([$bookingId]);
            if ($chk->fetch()) {
                throw new Exception("You already have a pending request for late checkout.");
            }

            $postStmt = $db->prepare("INSERT INTO guest_service_requests (property_id, booking_id, service_type, status) VALUES (?, ?, 'Late Checkout', 'pending')");
            $postStmt->execute([(int)$booking['property_id'], $bookingId]);

            // Notify admin
            $db->prepare("INSERT INTO admin_notifications (property_id, type, title, message) VALUES (?, 'service_request', 'Late Checkout Request', ?)")
               ->execute([(int)$booking['property_id'], "Room {$booking['room_number']} requested late checkout"]);

            AuditLogger::log(0, 'PORTAL_LATE_CHECKOUT_REQUEST', 'BOOKING', $booking['id'], [
                'guest' => $booking['guest_name'],
                'room' => $booking['room_number']
            ], (int)$booking['property_id']);

            header("Location: /guest-portal?id={$bookingId}&token={$token}&msg=late_checkout_request_success");
            exit;
        } catch (Exception $e) {
            $error = "Failed to submit request: " . $e->getMessage();
        }
    } elseif ($action === 'self_checkout' && $selfCheckoutEnabled) {
        if (abs($balance) > 0.001) { // Balance must be exactly zero
            $error = "Cannot checkout: Your balance is not zero (₹" . number_format($balance, 2) . "). Please settle dues or refunds at the front desk.";
        } elseif ($booking['booking_status'] !== 'checked_in') {
            $error = "Cannot checkout: Active check-in session not found.";
        } else {
            try {
                $db->beginTransaction();

                $statusStmt = $db->prepare("UPDATE bookings SET booking_status = 'checked_out', actual_checkout = NOW() WHERE id = ?");
                $statusStmt->execute([$bookingId]);

                $roomStmt = $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = ?");
                $roomStmt->execute([$booking['room_id']]);

                AuditLogger::log(0, 'PORTAL_SELF_CHECKOUT', 'BOOKING', $booking['id'], [
                    'guest' => $booking['guest_name'],
                    'room' => $booking['room_number']
                ], (int)$booking['property_id']);

                $db->commit();
                header("Location: /guest-portal?id={$bookingId}&token={$token}&msg=checkout_success");
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Self-checkout failed: " . $e->getMessage();
            }
        }
    }
}

if (($_GET['msg'] ?? '') === 'late_checkout_request_success') {
    $message = "⚡ Your request for a late checkout has been sent to the front desk. They will review and update your booking shortly.";
}
if (($_GET['msg'] ?? '') === 'checkout_success') {
    $message = "🚪 Checked out successfully! Thank you for staying with us.";
}
if (($_GET['msg'] ?? '') === 'signature_success') {
    $message = "✍️ Registration card signed successfully! Your identity check is complete.";
}
if (($_GET['msg'] ?? '') === 'review_success') {
    $message = "💖 Review submitted! Thank you for your valuable feedback.";
}

// Fetch if guest already reviewed
$hasReviewed = false;
try {
    $revCheck = $db->prepare("SELECT COUNT(*) FROM guest_reviews WHERE booking_id = ?");
    $revCheck->execute([$bookingId]);
    $hasReviewed = (int)$revCheck->fetchColumn() > 0;
} catch (Exception $e) {}

// Fetch dynamic banners and WiFi settings
$dbBanners = get_db_setting($db, 'GUEST_PORTAL_BANNERS', (int)$booking['property_id']);
$bannersStr = $dbBanners !== '' ? $dbBanners : '[]';
$banners = json_decode($bannersStr, true) ?? [];

$dbWifi = get_db_setting($db, 'GUEST_PORTAL_WIFI_SSID', (int)$booking['property_id']);
$wifiSSID = $dbWifi !== '' ? $dbWifi : 'Guest_Network';
$dbPass = get_db_setting($db, 'GUEST_PORTAL_WIFI_PASS', (int)$booking['property_id']);
$wifiPass = $dbPass !== '' ? $dbPass : 'password';

$dbAttractions = get_db_setting($db, 'GUEST_PORTAL_LOCAL_ATTRACTIONS', (int)$booking['property_id']);
$portalLocalAttractions = $dbAttractions !== '' ? $dbAttractions : '';
$attractions = array_filter(array_map('trim', explode("\n", $portalLocalAttractions)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Guest Portal - <?= htmlspecialchars($booking['property_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/css/guest_theme.css" rel="stylesheet">
</head>
<body>
    
    <!-- Hero Background -->
    <div class="hero-bg">
        <div class="hero-overlay"></div>
    </div>

    <div class="relative z-10 px-4 pt-8 pb-24 max-w-lg mx-auto">
        <!-- Top Nav Icons -->
        <div class="flex justify-between items-center mb-4 text-slate-800">
            <button class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center hover:bg-slate-300 transition text-slate-700">
                <i class="fas fa-bars text-lg"></i>
            </button>
            <button class="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center hover:bg-slate-300 transition relative text-slate-700">
                <i class="fas fa-bell text-lg"></i>
                <span class="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full"></span>
            </button>
        </div>

        <!-- Header Titles -->
        <div class="flex justify-between items-end mb-6">
            <div class="text-slate-900">
                <h1 class="text-2xl font-serif font-bold uppercase tracking-widest"><?= htmlspecialchars($booking['property_name']) ?></h1>
                <span class="text-[10px] font-bold text-slate-600 bg-slate-200 px-2 py-1 rounded-full uppercase tracking-wider inline-block mt-1">Guest Portal</span>
            </div>
            <div class="text-right text-slate-800 pb-1">
                <p class="text-xs text-slate-500 font-medium">Welcome,</p>
                <p class="font-bold text-sm text-slate-900"><?= htmlspecialchars($booking['guest_name'] ?: 'Guest') ?></p>
                <p class="text-xs font-semibold text-brand-600">Room <?= htmlspecialchars($booking['room_number'] ?: 'TBA') ?></p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- POS / DINING TAB -->
        <?php if ($posEnabled): ?>
        <div id="view-pos" class="view-section hidden">
            <div class="flex items-center mb-6">
                <button onclick="switchTab('home')" class="w-8 h-8 rounded-full bg-white flex items-center justify-center text-gray-600 mr-4 shadow-sm border border-gray-200">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider">Room Service</h2>
            </div>
            
            <div id="posCategories" class="flex overflow-x-auto gap-3 pb-4 hide-scrollbar mb-4">
                <!-- Categories loaded dynamically -->
            </div>
            
            <div id="posItems" class="grid grid-cols-2 gap-4 mb-20">
                <!-- Items loaded dynamically -->
                <p class="col-span-2 text-center text-gray-500 text-sm py-10" id="posLoadingMsg">Loading menu...</p>
            </div>
            
            <!-- Floating Cart -->
            <div id="posCartBar" class="fixed bottom-[calc(80px+env(safe-area-inset-bottom))] left-4 right-4 bg-slate-900 text-white p-4 rounded-2xl shadow-xl flex justify-between items-center transform translate-y-[150%] transition duration-300 z-40">
                <div>
                    <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-1">Your Order</p>
                    <p class="font-bold text-xl" id="posCartTotal">₹0.00</p>
                </div>
                <button onclick="submitPosOrder()" class="bg-brand-600 hover:bg-brand-500 text-white px-6 py-2 rounded-xl font-bold text-sm shadow transition flex items-center gap-2" id="posSubmitBtn">
                    <span>Order</span> <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- VIEW WRAPPER -->
        <?php if ($booking['booking_status'] === 'booked'): ?>
            <!-- Self Check-in View -->
            <div id="view-checkin" class="view-section">
                <div class="glass-panel p-5 mb-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-2">Self Check-in</h2>
                    <p class="text-xs text-gray-500 mb-4">Please verify your details and upload your ID proof to complete check-in.</p>
                    <form id="selfCheckinForm" onsubmit="submitSelfCheckin(event)">
                        <div class="mb-3">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Full Name</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($booking['guest_name'] ?? '') ?>" class="w-full bg-white bg-opacity-50 border border-transparent rounded p-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent-gold)]" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Email</label>
                            <input type="email" name="email" class="w-full bg-white bg-opacity-50 border border-transparent rounded p-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent-gold)]" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Phone</label>
                            <input type="text" name="phone" class="w-full bg-white bg-opacity-50 border border-transparent rounded p-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent-gold)]" required>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">City</label>
                                <input type="text" name="city" class="w-full bg-white bg-opacity-50 border border-transparent rounded p-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent-gold)]" required>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">State</label>
                                <input type="text" name="state" class="w-full bg-white bg-opacity-50 border border-transparent rounded p-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent-gold)]" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">ID Proof (Front)</label>
                            <input type="file" name="id_front" accept="image/*,application/pdf" class="w-full text-xs" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">ID Proof (Back)</label>
                            <input type="file" name="id_back" accept="image/*,application/pdf" class="w-full text-xs" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Digital Signature</label>
                            <div class="border border-gray-300 rounded bg-white relative">
                                <canvas id="signatureCanvas" class="w-full h-32"></canvas>
                                <button type="button" onclick="clearSignature()" class="absolute top-1 right-1 text-[10px] bg-gray-200 px-2 py-1 rounded shadow hover:bg-gray-300">Clear</button>
                            </div>
                            <input type="hidden" name="signature_data" id="signatureData">
                        </div>
                        <button type="submit" id="btnCheckin" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-3 rounded-xl shadow transition uppercase tracking-widest text-sm">
                            Complete Check-in
                        </button>
                    </form>
                </div>
            </div>
            
            <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.5/dist/signature_pad.umd.min.js"></script>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const canvas = document.getElementById('signatureCanvas');
                    if(canvas) {
                        const ratio =  Math.max(window.devicePixelRatio || 1, 1);
                        canvas.width = canvas.offsetWidth * ratio;
                        canvas.height = canvas.offsetHeight * ratio;
                        canvas.getContext("2d").scale(ratio, ratio);
                        window.signaturePad = new SignaturePad(canvas);
                    }
                });
                
                function clearSignature() {
                    if (window.signaturePad) window.signaturePad.clear();
                }
                
                async function submitSelfCheckin(e) {
                    e.preventDefault();
                    if (!window.signaturePad || window.signaturePad.isEmpty()) {
                        alert("Please provide your digital signature.");
                        return;
                    }
                    
                    document.getElementById('signatureData').value = window.signaturePad.toDataURL("image/jpeg");
                    const form = e.target;
                    const btn = document.getElementById('btnCheckin');
                    btn.disabled = true;
                    btn.innerText = 'Uploading...';
                    
                    try {
                        const formData = new FormData(form);
                        formData.append('booking_id', '<?= $bookingId ?>');
                        formData.append('token', '<?= $token ?>');
                        
                        const res = await fetch('/api/guest/self_checkin', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();
                        if (data.success) {
                            alert(data.message || 'Check-in complete!');
                            window.location.reload();
                        } else {
                            alert("Error: " + (data.message || 'Unknown error'));
                            btn.disabled = false;
                            btn.innerText = 'Complete Check-in';
                        }
                    } catch (err) {
                        alert("An error occurred during check-in.");
                        btn.disabled = false;
                        btn.innerText = 'Complete Check-in';
                    }
                }
            </script>
        <?php else: ?>
        <div id="view-home" class="view-section">
            
            <!-- Active Booking Card -->
            <?php 
                $checkin = new DateTime($booking['check_in']);
                $checkout = new DateTime($booking['check_out']);
                $now = new DateTime();
                $totalDays = $checkin->diff($checkout)->days;
                $daysRemaining = $now->diff($checkout)->days;
                $progress = $totalDays > 0 ? (1 - ($daysRemaining / $totalDays)) * 100 : 100;
                $progress = max(0, min(100, $progress));
            ?>
            <div class="glass-panel p-5 mb-6">
                <div class="flex justify-between items-end mb-4">
                    <div>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Active Booking</p>
                        <p class="text-lg font-bold text-gray-800">
                            <?= $checkin->format('M d') ?> - <?= $checkout->format('M d') ?>
                        </p>
                    </div>
                    <div class="text-right bg-white bg-opacity-50 px-3 py-2 rounded-xl">
                        <p class="text-xs font-bold text-gray-500">Day Count</p>
                        <p class="text-xl font-bold text-gray-800"><?= $totalDays - $daysRemaining ?>/<?= $totalDays ?></p>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 mb-2">Checkout countdown</p>
                    <div class="progress-container">
                        <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Quick Action Grid -->
            <div class="grid grid-cols-2 gap-4 mb-8">
                <?php if ($posEnabled): ?>
                <button onclick="switchTab('pos')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-yellow-50 text-yellow-600 flex items-center justify-center text-xl">
                        <i class="fas fa-utensils"></i>
                    </div>
                    <span class="text-sm font-semibold">Room Service</span>
                </button>
                <?php endif; ?>
                
                <button onclick="switchTab('profile')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center text-xl">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <span class="text-sm font-semibold">Guest Profile</span>
                </button>
                
                <button onclick="switchTab('services')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                        <i class="fas fa-broom"></i>
                    </div>
                    <span class="text-sm font-semibold">Request Service</span>
                </button>
                
                <button onclick="switchTab('attractions')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <span class="text-sm font-semibold">Sightseeing</span>
                </button>
                
                <button onclick="submitService('Extend Stay', 'Reception')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center text-xl">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <span class="text-sm font-semibold">Extend Stay</span>
                </button>
                
                <button onclick="submitService('Room Upgrade', 'Reception')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-green-50 text-green-600 flex items-center justify-center text-xl">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <span class="text-sm font-semibold">Upgrade Room</span>
                </button>
            </div>

            <!-- Today's Highlights -->
            <?php if (!empty($banners)): ?>
            <div class="mb-4 flex justify-between items-center">
                <h3 class="text-sm font-bold text-gray-600 uppercase tracking-wider">Today's Highlights</h3>
                <i class="fas fa-chevron-right text-gray-400 text-xs"></i>
            </div>
            <div class="flex overflow-x-auto gap-4 pb-4 hide-scrollbar">
                <?php foreach ($banners as $banner): ?>
                <div class="neumorphic-card min-w-[200px] max-w-[240px] p-4 flex-shrink-0 flex flex-col justify-between" onclick="window.location.href='<?= htmlspecialchars($banner['action']) ?>'">
                    <div>
                        <?php if(!empty($banner['image'])): ?>
                            <img src="<?= htmlspecialchars($banner['image']) ?>" alt="" class="w-full h-24 object-cover rounded-lg mb-3">
                        <?php endif; ?>
                        <p class="font-bold text-gray-800 text-sm mb-1"><?= htmlspecialchars($banner['title']) ?></p>
                        <p class="text-xs text-[var(--accent-gold-dark)] font-bold mb-2"><?= htmlspecialchars($banner['subtitle']) ?></p>
                        <?php if(!empty($banner['description'])): ?>
                            <p class="text-[10px] text-gray-500 leading-tight"><?= htmlspecialchars($banner['description']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
        </div>

        <!-- LOCAL ATTRACTIONS / SIGHTSEEING TAB -->
        <div id="view-attractions" class="view-section hidden">
            <div class="flex items-center mb-6">
                <button onclick="switchTab('home')" class="w-8 h-8 rounded-full bg-white flex items-center justify-center text-gray-600 mr-4 shadow-sm border border-gray-200">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider">Local Attractions / Sightseeing</h2>
            </div>
            
            <?php if (empty($attractions)): ?>
                <div class="glass-panel p-5 text-center text-slate-500">
                    <p class="text-sm">No local attractions listed yet.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($attractions as $attraction): ?>
                        <div class="glass-panel p-4 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($attraction) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- SERVICES TAB -->
        <div id="view-services" class="view-section hidden">
            
            <div class="flex items-center mb-6">
                <button onclick="switchTab('home')" class="w-8 h-8 rounded-full bg-white bg-opacity-50 flex items-center justify-center text-gray-600 mr-4 shadow-sm">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider">Service Requests</h2>
            </div>

            <!-- Housekeeping Services -->
            <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Housekeeping</h3>
            <div class="grid grid-cols-3 gap-3 mb-6">
                <div class="neumorphic-card p-3 flex flex-col justify-between items-center text-center">
                    <i class="fas fa-layer-group text-2xl text-blue-400 mb-2"></i>
                    <span class="text-xs font-semibold mb-2 leading-tight">Extra Towels</span>
                    <button onclick="submitService('Extra Towels', 'housekeeping')" class="text-[10px] uppercase font-bold text-gray-500 bg-gray-100 px-3 py-1 rounded-full w-full">Order</button>
                </div>
                <div class="neumorphic-card p-3 flex flex-col justify-between items-center text-center">
                    <i class="fas fa-broom text-2xl text-green-400 mb-2"></i>
                    <span class="text-xs font-semibold mb-2 leading-tight">Room Cleaning</span>
                    <button onclick="submitService('Housekeeping', 'housekeeping')" class="text-[10px] uppercase font-bold text-gray-500 bg-gray-100 px-3 py-1 rounded-full w-full">Order</button>
                </div>
                <div class="neumorphic-card p-3 flex flex-col justify-between items-center text-center">
                    <i class="fas fa-pump-soap text-2xl text-purple-400 mb-2"></i>
                    <span class="text-xs font-semibold mb-2 leading-tight">Toiletries</span>
                    <button onclick="submitService('Toiletries', 'housekeeping')" class="text-[10px] uppercase font-bold text-gray-500 bg-gray-100 px-3 py-1 rounded-full w-full">Order</button>
                </div>
                <div class="neumorphic-card p-3 flex flex-col justify-between items-center text-center">
                    <i class="fas fa-bed text-2xl text-yellow-400 mb-2"></i>
                    <span class="text-xs font-semibold mb-2 leading-tight">Extra Bed</span>
                    <button onclick="submitService('Extra Bed', 'housekeeping')" class="text-[10px] uppercase font-bold text-gray-500 bg-gray-100 px-3 py-1 rounded-full w-full">Order</button>
                </div>
                <div class="neumorphic-card p-3 flex flex-col justify-between items-center text-center">
                    <i class="fas fa-blanket text-2xl text-indigo-400 mb-2"></i>
                    <span class="text-xs font-semibold mb-2 leading-tight">Blanket</span>
                    <button onclick="submitService('Blanket', 'housekeeping')" class="text-[10px] uppercase font-bold text-gray-500 bg-gray-100 px-3 py-1 rounded-full w-full">Order</button>
                </div>
            </div>

            <!-- Property Info -->
            <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Property Info</h3>
            <div class="glass-panel p-4 mb-6 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-white bg-opacity-60 flex items-center justify-center text-gray-600">
                        <i class="fas fa-wifi text-lg"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-medium">Network: <span class="font-bold text-gray-800"><?= htmlspecialchars($wifiSSID) ?></span></p>
                        <p class="text-xs text-gray-500 font-medium">Password: <span id="wifiPass" class="font-bold text-gray-800"><?= htmlspecialchars($wifiPass) ?></span></p>
                    </div>
                </div>
                <button onclick="copyWifi()" class="text-xs bg-white px-3 py-1 rounded-full text-gray-600 font-bold shadow-sm">Copy</button>
            </div>

            <!-- Active Requests -->
            <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">My Active Requests</h3>
            <div id="activeRequestsContainer" class="space-y-3">
                <div class="text-center py-4 text-xs text-gray-400">Loading requests...</div>
            </div>
            
        </div>

        <!-- FOLIO TAB -->
        <div id="view-folio" class="view-section hidden">
            <h2 class="text-sm font-bold text-gray-600 uppercase tracking-wider text-center mb-6">Checkout & Folio<br><span class="text-[10px] font-normal text-gray-400">Departure & Billing</span></h2>

            <div class="glass-panel p-5 mb-6 text-sm text-gray-700 space-y-2">
                <div class="flex justify-between border-b pb-2"><span class="font-semibold text-gray-500">Check-in:</span> <span class="font-bold"><?= $checkin->format('d M Y, h:i A') ?></span></div>
                <div class="flex justify-between border-b pb-2"><span class="font-semibold text-gray-500">Check-out:</span> <span class="font-bold"><?= $checkout->format('d M Y, h:i A') ?></span></div>
                <div class="flex justify-between border-b pb-2"><span class="font-semibold text-gray-500">Duration:</span> <span class="font-bold"><?= $totalDays ?> Nights</span></div>
                <div class="flex justify-between border-b pb-2"><span class="font-semibold text-gray-500">Room Type:</span> <span class="font-bold"><?= htmlspecialchars($booking['room_type'] ?? 'TBA') ?></span></div>
                <div class="flex justify-between border-b pb-2"><span class="font-semibold text-gray-500">Room No:</span> <span class="font-bold"><?= htmlspecialchars($booking['room_number'] ?? 'TBA') ?></span></div>
                <div class="flex justify-between"><span class="font-semibold text-gray-500">Rate Plan:</span> <span class="font-bold text-right truncate w-40"><?= htmlspecialchars($booking['rate_plan_name'] ?? 'Standard') ?></span></div>
            </div>

            <div class="glass-panel p-5 mb-6 bg-white bg-opacity-70">
                <div class="flex justify-between items-center mb-4 border-b border-gray-200 pb-3">
                    <span class="font-bold text-gray-800">Balance Due:</span>
                    <span class="font-bold text-lg text-gray-900">₹<?= number_format($balance, 2) ?></span>
                </div>
                
                <div class="space-y-3 mb-4 max-h-[300px] overflow-y-auto">
                    <?php foreach($ledger as $l): ?>
                    <div class="flex justify-between items-center text-sm">
                        <div class="text-gray-600 truncate max-w-[200px]"><?= date('M d', strtotime($l['recorded_at'])) ?> - <?= htmlspecialchars($l['description']) ?></div>
                        <div class="font-medium text-gray-800">₹<?= number_format((float)$l['amount'], 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>

            <?php if ($selfCheckoutEnabled && $balance <= 0.05 && $booking['booking_status'] === 'checked_in'): ?>
            <form method="POST" class="mb-6">
                <input type="hidden" name="action" value="self_checkout">
                <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-4 rounded-xl shadow-lg transition uppercase tracking-widest text-sm flex justify-between items-center px-6">
                    <span>Complete Express Checkout</span>
                    <span>₹<?= number_format($balance, 2) ?></span>
                </button>
            </form>
            <?php elseif ($balance > 0.05): ?>
                <?php if (!empty($activeGateways)): ?>
                    <div class="mb-6 space-y-3">
                        <p class="text-xs font-bold text-gray-500 uppercase text-center mb-2">Pay Outstanding Balance</p>
                        <?php if (isset($activeGateways['razorpay'])): ?>
                        <button onclick="payWithRazorpay()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl shadow transition text-sm flex justify-center items-center gap-2">
                            <i class="fas fa-credit-card"></i> Pay with Razorpay
                        </button>
                        <?php endif; ?>
                        <?php if (isset($activeGateways['phonepe'])): ?>
                        <button onclick="payWithPhonePe()" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 rounded-xl shadow transition text-sm flex justify-center items-center gap-2">
                            <i class="fas fa-mobile-alt"></i> Pay with PhonePe
                        </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <button disabled class="w-full bg-gray-300 text-gray-500 font-bold py-4 rounded-xl shadow transition uppercase tracking-widest text-sm flex justify-between items-center px-6 cursor-not-allowed mb-6">
                        <span>Clear dues to Checkout (Pay at Desk)</span>
                        <span>₹<?= number_format($balance, 2) ?></span>
                    </button>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($upsellEnabled && $booking['booking_status'] === 'checked_in'): ?>
            <div class="glass-panel p-5 mb-6 bg-white bg-opacity-70 text-center">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Need more time?</p>
                <form method="POST">
                    <input type="hidden" name="action" value="upsell_late_checkout">
                    <button type="submit" class="text-xs uppercase font-bold text-white bg-slate-800 px-6 py-3 rounded-xl shadow-sm hover:bg-slate-700 transition w-full">Request Late Checkout</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!$hasReviewed && $booking['booking_status'] !== 'cancelled'): ?>
            <div class="glass-panel p-5 text-center mb-6">
                <p class="text-xs font-bold text-gray-500 uppercase mb-3">Feedback</p>
                <form method="POST" id="reviewForm">
                    <input type="hidden" name="action" value="submit_review">
                    <input type="hidden" name="rating" id="ratingInput" value="5">
                    <div class="flex justify-center gap-2 mb-3">
                        <i class="far fa-star text-2xl text-[var(--accent-gold)] cursor-pointer star-rate" data-val="1"></i>
                        <i class="far fa-star text-2xl text-[var(--accent-gold)] cursor-pointer star-rate" data-val="2"></i>
                        <i class="far fa-star text-2xl text-[var(--accent-gold)] cursor-pointer star-rate" data-val="3"></i>
                        <i class="far fa-star text-2xl text-[var(--accent-gold)] cursor-pointer star-rate" data-val="4"></i>
                        <i class="far fa-star text-2xl text-[var(--accent-gold)] cursor-pointer star-rate" data-val="5"></i>
                    </div>
                    <textarea name="comment" class="w-full bg-white bg-opacity-50 border border-transparent rounded p-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent-gold)] transition mb-3" placeholder="Tell us about your stay..." rows="2"></textarea>
                    <button type="submit" class="text-xs uppercase font-bold text-gray-500 bg-white px-4 py-2 rounded-full shadow-sm hover:bg-gray-50">Submit</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="text-center pb-8">
                <?php
                $propPhone = defined('PROPERTY_PHONE') ? PROPERTY_PHONE : '';
                $chatHref = $propPhone ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $propPhone) : 'javascript:alert(\'Chat not configured yet.\')';
                ?>
                <a href="<?= htmlspecialchars($chatHref) ?>" target="_blank" class="text-xs font-bold text-[var(--accent-gold-dark)] uppercase tracking-wider">Need Help? Chat</a>
            </div>
        </div>
    <?php endif; // end check-in check ?>

        <div id="view-profile" class="view-section hidden">
            <div class="glass-panel p-5 mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-2">Guest Profile</h2>
                <div class="mb-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Name</p>
                    <p class="font-semibold text-gray-800"><?= htmlspecialchars($booking['guest_name'] ?? 'N/A') ?></p>
                </div>
                <div class="mb-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Email</p>
                    <p class="font-semibold text-gray-800"><?= htmlspecialchars($booking['guest_email'] ?? 'N/A') ?></p>
                </div>
                <div class="mb-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Phone</p>
                    <p class="font-semibold text-gray-800"><?= htmlspecialchars($booking['guest_phone'] ?? 'N/A') ?></p>
                </div>
            </div>

            <h3 class="text-md font-bold text-gray-800 mb-3">ID Verification</h3>
            <div id="profile-id-loading" class="text-center py-4 text-gray-500 text-sm hidden">
                <i class="fas fa-spinner fa-spin"></i> Loading ID documents...
            </div>
            
            <div id="profile-id-section" class="hidden">
                <!-- Front ID -->
                <div class="glass-panel p-4 mb-4">
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="font-bold text-sm text-gray-800">Front ID</h4>
                    </div>
                    <div id="id-front-preview" class="hidden mb-3 border rounded overflow-hidden h-32 relative bg-gray-100 flex items-center justify-center">
                        <img src="" class="max-w-full max-h-full object-contain">
                    </div>
                    <div id="id-front-upload-form">
                        <ul class="text-xs text-gray-500 mb-3 list-disc pl-4">
                            <li>Ensure good lighting without glare</li>
                            <li>Frame the ID completely within the photo</li>
                            <li>Accepted formats: JPG, PNG, PDF (Max 5MB)</li>
                        </ul>
                        <button onclick="document.getElementById('idFrontInput').click()" class="w-full bg-blue-50 text-blue-600 border border-blue-200 hover:bg-blue-100 py-2 rounded-xl text-sm font-bold transition">
                            <i class="fas fa-camera mr-2"></i> Upload Front ID
                        </button>
                        <input type="file" id="idFrontInput" class="hidden" accept="image/*,application/pdf" onchange="uploadProfileId(this, 'id_proof_front')">
                    </div>
                </div>

                <!-- Back ID -->
                <div class="glass-panel p-4 mb-4">
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="font-bold text-sm text-gray-800">Back ID</h4>
                    </div>
                    <div id="id-back-preview" class="hidden mb-3 border rounded overflow-hidden h-32 relative bg-gray-100 flex items-center justify-center">
                        <img src="" class="max-w-full max-h-full object-contain">
                    </div>
                    <div id="id-back-upload-form">
                        <ul class="text-xs text-gray-500 mb-3 list-disc pl-4">
                            <li>Ensure good lighting without glare</li>
                            <li>Frame the ID completely within the photo</li>
                            <li>Accepted formats: JPG, PNG, PDF (Max 5MB)</li>
                        </ul>
                        <button onclick="document.getElementById('idBackInput').click()" class="w-full bg-blue-50 text-blue-600 border border-blue-200 hover:bg-blue-100 py-2 rounded-xl text-sm font-bold transition">
                            <i class="fas fa-camera mr-2"></i> Upload Back ID
                        </button>
                        <input type="file" id="idBackInput" class="hidden" accept="image/*,application/pdf" onchange="uploadProfileId(this, 'id_proof_back')">
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Bottom Navigation -->
    <?php if ($booking['booking_status'] !== 'booked'): ?>
    <nav class="bottom-nav">
        <a href="#" onclick="switchTab('home')" class="nav-item active" id="nav-home">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <?php if ($posEnabled): ?>
        <a href="#" onclick="switchTab('pos')" class="nav-item" id="nav-pos">
            <i class="fas fa-utensils"></i>
            <span>Dining</span>
        </a>
        <?php endif; ?>
        <a href="#" onclick="switchTab('services')" class="nav-item" id="nav-services">
            <i class="fas fa-concierge-bell"></i>
            <span>Services</span>
        </a>
        <a href="#" onclick="switchTab('attractions')" class="nav-item" id="nav-attractions">
            <i class="fas fa-map-marked-alt"></i>
            <span>Sightseeing</span>
        </a>
        <a href="#" onclick="switchTab('profile')" class="nav-item" id="nav-profile">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </a>
        <a href="#" onclick="switchTab('folio')" class="nav-item" id="nav-folio">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Folio</span>
        </a>
    </nav>
    <?php endif; ?>

    <script>
        const bookingId = '<?= $bookingId ?>';
        const token = '<?= $token ?>';

        function switchTab(tabId) {
            const view = document.getElementById('view-' + tabId);
            if (!view) return;
            document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            
            view.classList.remove('hidden');
            if(document.getElementById('nav-' + tabId)) {
                document.getElementById('nav-' + tabId).classList.add('active');
            }
            
            if (tabId === 'services') loadActiveRequests();
            if (tabId === 'profile') loadProfileDocuments();
            if (tabId === 'pos') loadPosMenu();
        }

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
        }

        async function loadActiveRequests() {
            try {
                const res = await fetch(`/api/guest/service_request?action=list&booking_id=${bookingId}&token=${token}`);
                const data = await res.json();
                const container = document.getElementById('activeRequestsContainer');
                const requests = data.requests || data.data?.requests || [];
                
                if (data.success === true && requests.length > 0) {
                    container.innerHTML = requests.map(req => {
                        const type = String(req.service_type || '');
                        const icon = type === 'Housekeeping' || type === 'housekeeping' ? 'fa-broom' : 'fa-bell';
                        const statusColor = req.status === 'completed' ? 'text-green-500' : (req.status === 'rejected' ? 'text-red-500' : 'text-blue-500');
                        return `
                        <div class="glass-panel p-3 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded bg-white flex items-center justify-center text-[var(--accent-gold)]">
                                    <i class="fas ${icon}"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-gray-800">${escapeHtml(type.replace(/_/g, ' '))}</p>
                                    <p class="text-[10px] text-gray-500">Status: <span class="capitalize">${escapeHtml(String(req.status || '').replace('_', ' '))}</span></p>
                                </div>
                            </div>
                            <i class="fas fa-circle text-[8px] ${statusColor}"></i>
                        </div>
                        `;
                    }).join('');
                } else {
                    container.innerHTML = '<p class="text-xs text-center text-gray-500">No active requests.</p>';
                }
            } catch (e) {
                console.error(e);
                const container = document.getElementById('activeRequestsContainer');
                if (container) {
                    container.innerHTML = '<p class="text-xs text-center text-red-500">Failed to load requests. Please check your connection.</p>';
                }
            }
        }

        async function submitService(serviceType, category) {
            if (!confirm(`Request ${serviceType}?`)) return;
            try {
                const res = await fetch(`/api/guest/service_request`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'create', booking_id: bookingId, token: token, service_type: serviceType, category: category})
                });
                const data = await res.json();
                if (data.success === true) {
                    alert('Request sent successfully!');
                    loadActiveRequests();
                } else {
                    alert('Failed to send request: ' + (data.message || 'Unknown error'));
                }
            } catch (e) {
                alert('An error occurred.');
            }
        }

        function copyWifi() {
            const pass = document.getElementById('wifiPass').innerText;
            navigator.clipboard.writeText(pass);
            alert('WiFi Password copied to clipboard!');
        }

        // Star rating logic
        const stars = document.querySelectorAll('.star-rate');
        const ratingInput = document.getElementById('ratingInput');
        stars.forEach(star => {
            star.addEventListener('click', (e) => {
                const val = parseInt(e.target.dataset.val);
                ratingInput.value = val;
                stars.forEach(s => {
                    if (parseInt(s.dataset.val) <= val) {
                        s.classList.remove('far');
                        s.classList.add('fas');
                    } else {
                        s.classList.remove('fas');
                        s.classList.add('far');
                    }
                });
            });
        });
        
        // Initial setup
        const initialRating = 5;
        stars.forEach(s => {
            if (parseInt(s.dataset.val) <= initialRating) {
                s.classList.remove('far');
                s.classList.add('fas');
            }
        });
        // POS LOGIC
        let posItems = [];
        let posCart = {};
        let posActiveOutlet = null;
        let posOutlets = [];

        async function loadPosMenu() {
            const loading = document.getElementById('posLoadingMsg');
            try {
                const res = await fetch(`/api/guest/pos_menu?id=${bookingId}&token=${token}`);
                const data = await res.json();
                if(data.success) {
                    posItems = data.items || [];
                    posOutlets = data.outlets || [];
                    if(posOutlets.length > 0 && posActiveOutlet === null) posActiveOutlet = posOutlets[0].id;
                    renderPosCategories();
                    renderPosItems();
                } else if (loading) {
                    loading.textContent = data.message || 'Failed to load menu.';
                }
            } catch(e) {
                if (loading) loading.textContent = 'Error connecting to menu.';
            }
        }

        function renderPosCategories() {
            const cat = document.getElementById('posCategories');
            if (!cat) return;
            cat.innerHTML = posOutlets.map(o => `
                <button type="button" data-outlet-id="${o.id}" onclick="posActiveOutlet=${Number(o.id)}; renderPosItems();" class="whitespace-nowrap px-4 py-2 rounded-full text-xs font-bold transition ${Number(posActiveOutlet)===Number(o.id) ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 shadow-sm border border-slate-200 hover:bg-slate-50'}">${escapeHtml(o.name)}</button>
            `).join('');
        }

        function renderPosItems() {
            const container = document.getElementById('posItems');
            if (!container) return;
            const items = posItems.filter(i => i.outlet_id == posActiveOutlet);
            
            if(items.length === 0) {
                container.innerHTML = '<p class="col-span-2 text-center text-gray-500 text-sm py-10">No items available in this category.</p>';
                renderPosCategories();
                return;
            }

            container.innerHTML = items.map(i => {
                const qty = posCart[i.id] || 0;
                const name = escapeHtml(i.name);
                const price = parseFloat(i.selling_price);
                return `
                <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden flex flex-col">
                    ${i.image_url ? `<img src="${escapeHtml(i.image_url)}" alt="" class="w-full h-24 object-cover">` : `<div class="w-full h-24 bg-slate-100 flex items-center justify-center text-slate-300"><i class="fas fa-utensils text-2xl"></i></div>`}
                    <div class="p-3 flex-1 flex flex-col justify-between">
                        <div>
                            <p class="text-xs font-bold text-slate-800 leading-tight mb-1">${name}</p>
                            <p class="text-xs text-brand-600 font-bold mb-3">₹${price.toFixed(2)}</p>
                        </div>
                        ${qty > 0 ? `
                        <div class="flex items-center justify-between bg-slate-100 rounded-lg p-1">
                            <button type="button" onclick="updateCart(${Number(i.id)}, -1)" class="w-6 h-6 rounded bg-white shadow-sm flex items-center justify-center text-slate-700 font-bold">-</button>
                            <span class="text-xs font-bold">${qty}</span>
                            <button type="button" onclick="updateCart(${Number(i.id)}, 1)" class="w-6 h-6 rounded bg-white shadow-sm flex items-center justify-center text-slate-700 font-bold">+</button>
                        </div>
                        ` : `
                        <button type="button" onclick="updateCart(${Number(i.id)}, 1)" class="w-full text-[10px] uppercase font-bold text-slate-600 bg-slate-100 py-2 rounded-lg hover:bg-slate-200 transition">Add to Order</button>
                        `}
                    </div>
                </div>
                `;
            }).join('');
            renderPosCategories();
        }

        function updateCart(itemId, change) {
            posCart[itemId] = Math.max(0, (posCart[itemId] || 0) + change);
            if(posCart[itemId] === 0) delete posCart[itemId];
            renderPosItems();
            updateCartUI();
        }

        function updateCartUI() {
            let total = 0;
            let count = 0;
            for(let id in posCart) {
                const item = posItems.find(i => i.id == id);
                if(item) {
                    total += item.selling_price * posCart[id];
                    count += posCart[id];
                }
            }
            document.getElementById('posCartTotal').textContent = `₹${total.toFixed(2)}`;
            const bar = document.getElementById('posCartBar');
            if(count > 0) {
                bar.classList.remove('translate-y-[150%]');
            } else {
                bar.classList.add('translate-y-[150%]');
            }
        }

        async function loadProfileDocuments() {
            document.getElementById('profile-id-loading').classList.remove('hidden');
            document.getElementById('profile-id-section').classList.add('hidden');
            try {
                const res = await fetch(`/api/guest/profile?booking_id=${bookingId}&token=${token}`);
                const data = await res.json();
                if (data.success && data.data.documents) {
                    const docs = data.data.documents;
                    setupIdPreview('front', docs.id_proof_front || docs.id_proof);
                    setupIdPreview('back', docs.id_proof_back);
                }
            } catch (e) {
                console.error(e);
            } finally {
                document.getElementById('profile-id-loading').classList.add('hidden');
                document.getElementById('profile-id-section').classList.remove('hidden');
            }
        }

        function setupIdPreview(side, url) {
            const previewEl = document.getElementById(`id-${side}-preview`);
            const formEl = document.getElementById(`id-${side}-upload-form`);
            if (url) {
                previewEl.classList.remove('hidden');
                formEl.classList.add('hidden');
                
                // Show PDF placeholder or actual image
                if (url.toLowerCase().endsWith('.pdf')) {
                    previewEl.innerHTML = '<div class="text-center text-gray-500"><i class="fas fa-file-pdf text-4xl mb-2 text-red-500"></i><p class="text-xs font-bold">PDF Document Uploaded</p></div>';
                } else {
                    const absUrl = url.startsWith('http') ? url : `/${url}`;
                    previewEl.innerHTML = `<img src="${absUrl}" class="max-w-full max-h-full object-contain">`;
                }
            } else {
                previewEl.classList.add('hidden');
                formEl.classList.remove('hidden');
            }
        }

        async function uploadProfileId(input, docType) {
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];
            
            // Validate size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                alert('File size exceeds 5MB limit.');
                input.value = '';
                return;
            }

            const formData = new FormData();
            formData.append('id_file', file);
            formData.append('booking_id', bookingId);
            formData.append('token', token);
            formData.append('document_type', docType);
            
            try {
                document.getElementById('profile-id-loading').classList.remove('hidden');
                document.getElementById('profile-id-section').classList.add('hidden');

                const res = await fetch('/api/guest/upload_id', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    await loadProfileDocuments(); // Reload to show preview
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (e) {
                alert('An error occurred during upload.');
            } finally {
                input.value = '';
            }
        }
        
        async function submitPosOrder() {
            const items = [];
            for(let id in posCart) {
                const item = posItems.find(i => i.id == id);
                if(item) {
                    items.push({item_id: item.id, name: item.name, quantity: posCart[id], price_per_unit: item.selling_price});
                }
            }
            if(items.length === 0) return;
            if(!confirm(`Place order to Room?`)) return;

            document.getElementById('posSubmitBtn').disabled = true;
            document.getElementById('posSubmitBtn').innerText = 'Ordering...';

            try {
                const res = await fetch(`/api/guest/pos_order`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({booking_id: bookingId, token: token, outlet_id: posActiveOutlet, items: items})
                });
                const data = await res.json();
                if(data.success) {
                    alert('Order placed successfully! It has been added to your folio.');
                    posCart = {};
                    updateCartUI();
                    renderPosItems();
                    window.location.reload();
                } else {
                    alert('Failed to place order: ' + (data.message || 'Unknown error'));
                }
            } catch(e) {
                alert('An error occurred.');
            } finally {
                document.getElementById('posSubmitBtn').disabled = false;
                document.getElementById('posSubmitBtn').innerHTML = `<span>Order</span> <i class="fas fa-arrow-right"></i>`;
            }
        }

        const folioBalance = <?= json_encode((float)$balance) ?>;
        const guestName = <?= json_encode((string)($booking['guest_name'] ?? 'Guest'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const guestPhone = <?= json_encode((string)($booking['guest_phone'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        async function payWithRazorpay() {
            if (folioBalance <= 0) return;
            try {
                const res = await fetch('/api/guest/create_razorpay_order', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ booking_id: bookingId, token: token, amount: folioBalance })
                });
                const data = await res.json();
                if (!data.success || !data.order_id) {
                    alert(data.message || 'Could not start payment.');
                    return;
                }
                const openCheckout = () => {
                    const rzp = new Razorpay({
                        key: data.key_id,
                        amount: data.amount || Math.round(folioBalance * 100),
                        currency: 'INR',
                        name: 'Stay payment',
                        order_id: data.order_id,
                        prefill: { name: guestName, contact: guestPhone },
                        handler: async function (response) {
                            const rec = await fetch('/api/guest/record_payment', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    booking_id: bookingId,
                                    token: token,
                                    amount: folioBalance,
                                    razorpay_payment_id: response.razorpay_payment_id,
                                    razorpay_order_id: response.razorpay_order_id,
                                    razorpay_signature: response.razorpay_signature
                                })
                            });
                            const recData = await rec.json();
                            if (recData.success) {
                                window.location.reload();
                            } else {
                                alert(recData.message || 'Payment recording failed');
                            }
                        }
                    });
                    rzp.open();
                };
                if (typeof Razorpay === 'undefined') {
                    const s = document.createElement('script');
                    s.src = 'https://checkout.razorpay.com/v1/checkout.js';
                    s.onload = openCheckout;
                    s.onerror = () => alert('Could not load Razorpay checkout.');
                    document.head.appendChild(s);
                } else {
                    openCheckout();
                }
            } catch (e) {
                alert('Payment could not be started.');
            }
        }

        async function payWithPhonePe() {
            alert('Please complete PhonePe payment from the link sent by the front desk, or pay at the desk.');
        }

    </script>
</body>
</html>
