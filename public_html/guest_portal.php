<?php
declare(strict_types=1);

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';
require_once __DIR__ . '/../pms_core/AuditLogger.php';
require_once __DIR__ . '/../pms_core/GuestAccessToken.php';

$db = Database::getInstance()->getConnection();
load_db_settings($db);

$bookingId = $_GET['id'] ?? $_POST['id'] ?? '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';

if (empty($bookingId) || empty($token)) {
    die("Access Denied: Missing parameters.");
}

GuestAccessToken::assert($bookingId, $token, false);

// Fetch booking & property info
$stmt = $db->prepare("
    SELECT b.*, p.name as property_name, p.logo_url, g.name as guest_name, g.email as guest_email, g.phone as guest_phone, g.digital_signature,
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

if (!$booking || !GuestAccessToken::bookingIsAccessible($booking)) {
    die("Booking not found or this stay link has expired.");
}

// Reload DB settings for this specific property
load_db_settings($db, (int)$booking['property_id']);

// Load configurations
$propId = (int)$booking['property_id'];
$upsellEnabled = (get_db_setting($db, 'GUEST_PORTAL_UPSELL_ENABLED', $propId) === 'true');
$posEnabled = (get_db_setting($db, 'GUEST_PORTAL_POS_ENABLED', $propId) === 'true');
$housekeepingEnabled = (get_db_setting($db, 'GUEST_PORTAL_HOUSEKEEPING_ENABLED', $propId) === 'true');
$selfCheckoutEnabled = (get_db_setting($db, 'GUEST_PORTAL_SELF_CHECKOUT_ENABLED', $propId) === 'true');
$wifiCardEnabled = get_db_setting($db, 'GUEST_PORTAL_WIFI_ENABLED', $propId, 'true') === 'true';
$sightseeingEnabled = get_db_setting($db, 'GUEST_PORTAL_SIGHTSEEING_ENABLED', $propId, 'true') === 'true';
$wakeupEnabled = get_db_setting($db, 'GUEST_PORTAL_WAKEUP_ENABLED', $propId, 'true') === 'true';
$extendStayEnabled = get_db_setting($db, 'GUEST_PORTAL_EXTEND_STAY_ENABLED', $propId, 'true') === 'true';
$upgradeEnabled = get_db_setting($db, 'GUEST_PORTAL_UPGRADE_ENABLED', $propId, 'true') === 'true';
$contactEnabled = get_db_setting($db, 'GUEST_PORTAL_CONTACT_ENABLED', $propId, 'true') === 'true';
$servicesEnabled = $housekeepingEnabled || $wakeupEnabled || $extendStayEnabled || $upgradeEnabled || $upsellEnabled;
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

            $message = "Housekeeping request sent. Staff will be with you shortly.";
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
    $message = "Your late checkout request was sent to the front desk.";
}
if (($_GET['msg'] ?? '') === 'checkout_success') {
    $message = "Checked out successfully. Thank you for staying with us.";
}
if (($_GET['msg'] ?? '') === 'signature_success') {
    $message = "Registration card signed. Identity check is complete.";
}
if (($_GET['msg'] ?? '') === 'review_success') {
    $message = "Review submitted. Thank you for your feedback.";
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
$dbPass = get_db_setting($db, 'GUEST_PORTAL_WIFI_PASSWORD', (int)$booking['property_id']);
if ($dbPass === '') {
    $dbPass = get_db_setting($db, 'GUEST_PORTAL_WIFI_PASS', (int)$booking['property_id']);
}
$wifiPass = $dbPass !== '' ? $dbPass : 'password';

$dbAttractions = get_db_setting($db, 'GUEST_PORTAL_LOCAL_ATTRACTIONS', (int)$booking['property_id']);
$portalLocalAttractions = $dbAttractions !== '' ? $dbAttractions : '';
$attractions = array_filter(array_map('trim', explode("\n", $portalLocalAttractions)));

try {
    $checkin = new DateTime($booking['check_in']);
    $checkout = new DateTime($booking['check_out']);
} catch (Exception $e) {
    $checkin = new DateTime();
    $checkout = new DateTime();
}
$now = new DateTime();
$totalDays = max(1, $checkin->diff($checkout)->days);
$daysRemaining = max(0, $now->diff($checkout)->days);
$progress = $totalDays > 0 ? (1 - ($daysRemaining / $totalDays)) * 100 : 100;
$progress = max(0, min(100, $progress));
$hasActiveGateway = $balance > 0.05 && !empty($activeGateways);
$helpDesk = trim((string)get_db_setting($db, 'GUEST_PORTAL_HELP_DESK_NO', $propId, ''));
$propPhone = $helpDesk !== '' ? $helpDesk : (defined('PROPERTY_PHONE') ? (string)PROPERTY_PHONE : '');
$propEmail = defined('PROPERTY_EMAIL') ? (string)PROPERTY_EMAIL : '';
$waDigits = preg_replace('/[^0-9]/', '', $propPhone);
$waHref = $waDigits !== '' ? 'https://wa.me/' . $waDigits : '';
$telHref = $propPhone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $propPhone) : '';
$statusKey = (string)($booking['booking_status'] ?? '');
$statusLabel = [
    'checked_in' => 'In house',
    'booked' => 'Arriving',
    'checked_out' => 'Checked out',
    'cancelled' => 'Cancelled',
][$statusKey] ?? 'Stay';
$checkoutIso = $checkout->format(DateTime::ATOM);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#F8FAFC">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Guest Portal - <?= htmlspecialchars($booking['property_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/css/guest_theme.css" rel="stylesheet">
</head>
<body>
    <div class="relative z-10 px-4 pt-6 pb-24 max-w-lg mx-auto">
        <header class="gp-header flex items-center gap-3">
            <?php if (!empty($booking['logo_url'])): ?>
            <img src="<?= htmlspecialchars($booking['logo_url']) ?>" alt="" class="w-11 h-11 rounded-xl object-cover border border-slate-200 bg-white">
            <?php else: ?>
            <div class="w-11 h-11 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                <i class="ph ph-buildings text-xl"></i>
            </div>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <h1 class="text-lg font-extrabold text-slate-900 truncate"><?= htmlspecialchars($booking['property_name']) ?></h1>
                    <span class="status-chip <?= $statusKey === 'checked_in' ? 'ok' : ($statusKey === 'booked' ? 'warn' : '') ?>"><?= htmlspecialchars($statusLabel) ?></span>
                </div>
                <p class="text-xs text-slate-500 font-medium truncate">
                    <?= htmlspecialchars($booking['guest_name'] ?: 'Guest') ?>
                    · Room <?= htmlspecialchars($booking['room_number'] ?: 'TBA') ?>
                </p>
                <p class="text-[11px] text-slate-500 font-semibold"><?= $checkin->format('d M') ?> – <?= $checkout->format('d M Y') ?></p>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="banner-ok" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="banner-err" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- POS / DINING TAB -->
        <?php if ($posEnabled): ?>
        <div id="view-pos" class="view-section hidden">
            <div class="flex items-center mb-6">
                <button type="button" onclick="switchTab('home')" class="w-11 h-11 rounded-xl bg-white flex items-center justify-center text-gray-600 mr-3 border border-gray-200">
                    <i class="ph ph-caret-left text-lg"></i>
                </button>
                <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider">Dining</h2>
            </div>
            <div class="relative mb-4">
                <i class="ph ph-magnifying-glass absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                <input type="search" id="posSearch" placeholder="Search menu" oninput="renderPosItems()" class="pos-search" autocomplete="off">
            </div>
            
            <div id="posCategories" class="flex overflow-x-auto gap-2 pb-4 hide-scrollbar mb-2"></div>
            
            <div id="posItems" class="grid grid-cols-2 gap-3 mb-24">
                <p class="col-span-2 text-center text-gray-500 text-sm py-10" id="posLoadingMsg">Loading menu…</p>
            </div>
            
            <div id="posCartBar" class="pos-cart-bar">
                <div>
                    <p class="label">Your order <span class="count" id="posCartCount"></span></p>
                    <p class="total" id="posCartTotal">₹0.00</p>
                </div>
                <button type="button" onclick="submitPosOrder()" class="pos-order-btn" id="posSubmitBtn">
                    <span>Order</span> <i class="ph ph-arrow-right"></i>
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
                    <p class="text-xs text-slate-500 mb-4">Verify your details and upload ID to complete check-in.</p>
                    <p id="checkinError" class="banner-err hidden"></p>
                    <form id="selfCheckinForm" onsubmit="submitSelfCheckin(event)">
                        <div class="mb-3">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Full Name</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($booking['guest_name'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($booking['guest_email'] ?? '') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Phone</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($booking['guest_phone'] ?? '') ?>" required>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">City</label>
                                <input type="text" name="city" required>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">State</label>
                                <input type="text" name="state" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">ID Proof (Front)</label>
                            <input type="file" name="id_front" accept="image/*,application/pdf" class="w-full text-xs" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">ID Proof (Back)</label>
                            <input type="file" name="id_back" accept="image/*,application/pdf" class="w-full text-xs" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Digital Signature</label>
                            <div class="border border-slate-200 rounded-xl bg-white relative">
                                <canvas id="signatureCanvas" class="w-full h-32"></canvas>
                                <button type="button" onclick="clearSignature()" class="absolute top-1 right-1 text-[10px] bg-slate-100 px-2 py-1 rounded-lg">Clear</button>
                            </div>
                            <input type="hidden" name="signature_data" id="signatureData">
                        </div>
                        <button type="submit" id="btnCheckin" class="btn-primary">Complete Check-in</button>
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
                    const errEl = document.getElementById('checkinError');
                    const showErr = (msg) => {
                        errEl.textContent = msg;
                        errEl.classList.remove('hidden');
                    };
                    errEl.classList.add('hidden');
                    if (!window.signaturePad || window.signaturePad.isEmpty()) {
                        showErr('Please provide your digital signature.');
                        return;
                    }
                    
                    document.getElementById('signatureData').value = window.signaturePad.toDataURL("image/jpeg");
                    const form = e.target;
                    const btn = document.getElementById('btnCheckin');
                    btn.disabled = true;
                    btn.innerText = 'Uploading...';
                    
                    try {
                        const formData = new FormData(form);
                        formData.append('booking_id', '<?= htmlspecialchars($bookingId, ENT_QUOTES) ?>');
                        formData.append('token', '<?= htmlspecialchars($token, ENT_QUOTES) ?>');
                        
                        const res = await fetch('/api/guest/self_checkin', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await res.json();
                        if (data.success) {
                            window.location.reload();
                        } else {
                            showErr(data.message || 'Check-in failed.');
                            btn.disabled = false;
                            btn.innerText = 'Complete Check-in';
                        }
                    } catch (err) {
                        showErr('An error occurred during check-in.');
                        btn.disabled = false;
                        btn.innerText = 'Complete Check-in';
                    }
                }
            </script>
        <?php else: ?>
        <div id="view-home" class="view-section">
            <div class="glass-panel p-5 mb-4">
                <div class="flex justify-between items-end mb-4">
                    <div>
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Your stay</p>
                        <p class="text-lg font-bold text-slate-900">
                            <?= $checkin->format('M d') ?> – <?= $checkout->format('M d') ?>
                        </p>
                    </div>
                    <div class="text-right bg-slate-50 px-3 py-2 rounded-xl border border-slate-200">
                        <p class="text-xs font-bold text-slate-500">Nights</p>
                        <p class="text-xl font-bold text-slate-900"><?= (int)$totalDays ?></p>
                    </div>
                </div>
                <p class="text-xs font-medium text-slate-500 mb-2">Stay progress</p>
                <div class="progress-container mb-3">
                    <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                </div>
                <?php if ($statusKey === 'checked_in'): ?>
                <p id="checkoutCountdown" class="text-sm font-bold text-blue-600">Checkout countdown…</p>
                <?php endif; ?>
            </div>

            <div class="flex overflow-x-auto gap-2 hide-scrollbar mb-4 pb-1">
                <?php if ($housekeepingEnabled): ?>
                <button type="button" class="quick-chip" onclick="askService('Extra Towels','housekeeping')"><i class="ph ph-drop"></i> Towels</button>
                <button type="button" class="quick-chip" onclick="askService('Housekeeping','housekeeping')"><i class="ph ph-broom"></i> Cleaning</button>
                <?php endif; ?>
                <?php if ($wakeupEnabled): ?>
                <button type="button" class="quick-chip" onclick="askService('Wake-up Call','Reception')"><i class="ph ph-alarm"></i> Wake-up</button>
                <?php endif; ?>
                <?php if ($upsellEnabled): ?>
                <button type="button" class="quick-chip" onclick="askService('Late Checkout','Reception')"><i class="ph ph-clock-afternoon"></i> Late out</button>
                <?php endif; ?>
                <?php if ($extendStayEnabled): ?>
                <button type="button" class="quick-chip" onclick="askService('Extend Stay','Reception')"><i class="ph ph-calendar-plus"></i> Extend</button>
                <?php endif; ?>
            </div>

            <?php if ($wifiCardEnabled): ?>
            <div class="glass-panel p-4 mb-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="icon-well icon-sky flex-shrink-0"><i class="ph ph-wifi-high"></i></div>
                        <div class="min-w-0">
                            <p class="text-xs text-slate-500">WiFi · <span class="font-bold text-slate-800" id="wifiSsid"><?= htmlspecialchars($wifiSSID) ?></span></p>
                            <p class="text-xs text-slate-500 truncate">Password: <span id="wifiPassHome" class="font-bold text-slate-800"><?= htmlspecialchars($wifiPass) ?></span></p>
                        </div>
                    </div>
                    <button type="button" onclick="copyWifi()" class="btn-secondary text-xs px-3">Copy</button>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($contactEnabled && ($telHref || $waHref)): ?>
            <div class="grid grid-cols-2 gap-3 mb-4">
                <?php if ($telHref): ?>
                <a href="<?= htmlspecialchars($telHref) ?>" class="neumorphic-card shortcut-tile no-underline">
                    <div class="icon-well icon-blue"><i class="ph ph-phone"></i></div>
                    <span class="text-sm font-semibold">Call desk</span>
                </a>
                <?php endif; ?>
                <?php if ($waHref): ?>
                <a href="<?= htmlspecialchars($waHref) ?>" target="_blank" rel="noopener" class="neumorphic-card shortcut-tile no-underline">
                    <div class="icon-well icon-emerald"><i class="ph ph-whatsapp-logo"></i></div>
                    <span class="text-sm font-semibold">WhatsApp</span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 gap-3 mb-8 stagger">
                <?php if ($posEnabled): ?>
                <button type="button" onclick="switchTab('pos')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well icon-amber"><i class="ph ph-fork-knife"></i></div>
                    <span class="text-sm font-semibold">Dining</span>
                </button>
                <?php endif; ?>
                <?php if ($servicesEnabled): ?>
                <button type="button" onclick="switchTab('services')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well icon-emerald"><i class="ph ph-bell-ringing"></i></div>
                    <span class="text-sm font-semibold">Services</span>
                </button>
                <?php endif; ?>
                <button type="button" onclick="switchTab('folio')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well icon-blue"><i class="ph ph-receipt"></i></div>
                    <span class="text-sm font-semibold">Bill</span>
                </button>
                <?php if ($sightseeingEnabled): ?>
                <button type="button" onclick="switchTab('attractions')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well icon-violet"><i class="ph ph-map-trifold"></i></div>
                    <span class="text-sm font-semibold">Sightseeing</span>
                </button>
                <?php endif; ?>
                <button type="button" onclick="switchTab('profile')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well icon-rose"><i class="ph ph-user-circle"></i></div>
                    <span class="text-sm font-semibold">Profile</span>
                </button>
            </div>

            <!-- Today's Highlights -->
            <?php if (!empty($banners)): ?>
            <div class="mb-4 flex justify-between items-center">
                <h3 class="text-sm font-bold text-slate-600 uppercase tracking-wider">Today's Highlights</h3>
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
        <?php if ($sightseeingEnabled): ?>
        <div id="view-attractions" class="view-section hidden">
            <div class="flex items-center mb-6">
                <button type="button" onclick="switchTab('home')" class="w-11 h-11 rounded-xl bg-white flex items-center justify-center text-slate-600 mr-3 border border-slate-200">
                    <i class="ph ph-caret-left text-lg"></i>
                </button>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wider">Sightseeing</h2>
            </div>
            <?php if (!empty($attractions)): ?>
            <div class="relative mb-4">
                <i class="ph ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input type="search" id="attractionSearch" placeholder="Filter places" oninput="filterAttractions()" class="pl-10">
            </div>
            <?php endif; ?>
            
            <?php if (empty($attractions)): ?>
                <div class="glass-panel p-5 text-center text-slate-500">
                    <p class="text-sm">No local attractions listed yet.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3" id="attractionList">
                    <?php foreach ($attractions as $attraction):
                        $q = rawurlencode($attraction);
                    ?>
                        <a class="glass-panel p-4 flex items-center gap-3 no-underline attraction-row" data-name="<?= htmlspecialchars(strtolower($attraction)) ?>" href="https://www.google.com/maps/search/?api=1&query=<?= $q ?>" target="_blank" rel="noopener">
                            <div class="shortcut-icon flex-shrink-0"><i class="ph ph-map-pin"></i></div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($attraction) ?></p>
                                <p class="text-[11px] text-blue-600 font-bold">Open in Maps</p>
                            </div>
                            <i class="ph ph-caret-right text-slate-400"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- SERVICES TAB -->
        <?php if ($servicesEnabled): ?>
        <div id="view-services" class="view-section hidden">
            
            <div class="flex items-center mb-6">
                <button type="button" onclick="switchTab('home')" class="w-11 h-11 rounded-xl bg-white flex items-center justify-center text-slate-600 mr-3 border border-slate-200">
                    <i class="ph ph-caret-left text-lg"></i>
                </button>
                <h2 class="text-sm font-bold text-slate-600 uppercase tracking-wider">Service requests</h2>
            </div>

            <?php if ($housekeepingEnabled): ?>
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Housekeeping</h3>
            <div class="grid grid-cols-2 gap-3 mb-6">
                <?php
                $svcTiles = [
                    ['Extra Towels', 'ph-drop', 'housekeeping', 'icon-sky'],
                    ['Housekeeping', 'ph-broom', 'housekeeping', 'icon-emerald'],
                    ['Toiletries', 'ph-sparkle', 'housekeeping', 'icon-violet'],
                    ['Extra Bed', 'ph-bed', 'housekeeping', 'icon-amber'],
                    ['Blanket', 'ph-moon-stars', 'housekeeping', 'icon-blue'],
                ];
                foreach ($svcTiles as $tile):
                ?>
                <button type="button" onclick="askService('<?= htmlspecialchars($tile[0], ENT_QUOTES) ?>', '<?= $tile[2] ?>')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well <?= $tile[3] ?>"><i class="ph <?= $tile[1] ?>"></i></div>
                    <span class="text-sm font-semibold"><?= htmlspecialchars($tile[0] === 'Housekeeping' ? 'Room Cleaning' : $tile[0]) ?></span>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($wakeupEnabled || $extendStayEnabled || $upgradeEnabled): ?>
            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Concierge</h3>
            <div class="grid grid-cols-2 gap-3 mb-6">
                <?php if ($wakeupEnabled): ?>
                <button type="button" onclick="askService('Wake-up Call', 'Reception')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well icon-amber"><i class="ph ph-alarm"></i></div>
                    <span class="text-sm font-semibold">Wake-up call</span>
                </button>
                <?php endif; ?>
                <?php if ($extendStayEnabled): ?>
                <button type="button" onclick="askService('Extend Stay', 'Reception')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well icon-violet"><i class="ph ph-calendar-plus"></i></div>
                    <span class="text-sm font-semibold">Extend stay</span>
                </button>
                <?php endif; ?>
                <?php if ($upgradeEnabled): ?>
                <button type="button" onclick="askService('Room Upgrade', 'Reception')" class="neumorphic-card shortcut-tile">
                    <div class="icon-well icon-blue"><i class="ph ph-arrow-up"></i></div>
                    <span class="text-sm font-semibold">Upgrade room</span>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">My requests</h3>
            <div id="activeRequestsContainer" class="space-y-3">
                <div class="text-center py-4 text-xs text-slate-400">Loading requests…</div>
            </div>
            
        </div>
        <?php endif; ?>

        <!-- FOLIO TAB -->
        <div id="view-folio" class="view-section hidden">
            <div class="glass-panel p-5 mb-4 text-center">
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Balance</p>
                <p class="text-3xl font-extrabold text-slate-900">₹<?= number_format($balance, 2) ?></p>
            </div>

            <div class="glass-panel p-5 mb-4 text-sm text-slate-700 space-y-2">
                <div class="flex justify-between border-b border-slate-100 pb-2"><span class="font-semibold text-slate-500">Check-in</span> <span class="font-bold"><?= $checkin->format('d M Y') ?></span></div>
                <div class="flex justify-between border-b border-slate-100 pb-2"><span class="font-semibold text-slate-500">Check-out</span> <span class="font-bold"><?= $checkout->format('d M Y') ?></span></div>
                <div class="flex justify-between border-b border-slate-100 pb-2"><span class="font-semibold text-slate-500">Duration</span> <span class="font-bold"><?= $totalDays ?> nights</span></div>
                <div class="flex justify-between"><span class="font-semibold text-slate-500">Room</span> <span class="font-bold"><?= htmlspecialchars(($booking['room_type'] ?? '') . ' ' . ($booking['room_number'] ?? '')) ?></span></div>
            </div>

            <div class="glass-panel p-5 mb-6">
                <p class="font-bold text-slate-800 mb-3">Folio</p>
                <div class="space-y-3 max-h-[300px] overflow-y-auto">
                    <?php if (empty($ledger)): ?>
                        <p class="text-sm text-slate-400 text-center py-4">No charges yet.</p>
                    <?php endif; ?>
                    <?php foreach($ledger as $l):
                        $amt = (float)$l['amount'];
                        $isPay = $amt < 0;
                    ?>
                    <div class="flex justify-between items-start text-sm <?= $isPay ? 'pay-row' : 'charge-row' ?>">
                        <div class="truncate max-w-[200px]">
                            <p class="font-semibold"><?= htmlspecialchars($l['description']) ?></p>
                            <p class="text-[11px] text-slate-400"><?= date('M d', strtotime($l['recorded_at'])) ?> · <?= $isPay ? 'Payment' : 'Charge' ?></p>
                        </div>
                        <div class="font-bold"><?= $isPay ? '−' : '' ?>₹<?= number_format(abs($amt), 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($selfCheckoutEnabled && $balance <= 0.05 && $booking['booking_status'] === 'checked_in'): ?>
            <form method="POST" class="mb-6">
                <input type="hidden" name="action" value="self_checkout">
                <button type="submit" class="btn-primary">Complete express checkout</button>
            </form>
            <?php elseif ($hasActiveGateway): ?>
                    <div class="mb-6 space-y-3">
                        <?php if (isset($activeGateways['razorpay'])): ?>
                        <button type="button" onclick="payWithRazorpay()" class="btn-primary">
                            <i class="ph ph-credit-card"></i> Pay with Razorpay
                        </button>
                        <?php endif; ?>
                        <?php if (isset($activeGateways['phonepe'])): ?>
                        <button type="button" onclick="payWithPhonePe()" class="btn-primary" style="background:#5B2C91">
                            <i class="ph ph-device-mobile"></i> Pay with PhonePe
                        </button>
                        <?php endif; ?>
                    </div>
            <?php elseif ($balance > 0.05): ?>
                    <button disabled class="w-full bg-slate-200 text-slate-500 font-bold py-4 rounded-xl text-sm mb-6 cursor-not-allowed">Pay at desk · ₹<?= number_format($balance, 2) ?></button>
            <?php endif; ?>

            <?php if ($upsellEnabled && $booking['booking_status'] === 'checked_in'): ?>
            <div class="glass-panel p-5 mb-6 bg-white bg-opacity-70 text-center">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Need more time?</p>
                <form method="POST">
                    <input type="hidden" name="action" value="upsell_late_checkout">
                    <button type="submit" class="btn-primary">Request late checkout</button>
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
                        <i class="ph ph-star text-2xl text-amber-400 cursor-pointer star-rate" data-val="1"></i>
                        <i class="ph ph-star text-2xl text-amber-400 cursor-pointer star-rate" data-val="2"></i>
                        <i class="ph ph-star text-2xl text-amber-400 cursor-pointer star-rate" data-val="3"></i>
                        <i class="ph ph-star text-2xl text-amber-400 cursor-pointer star-rate" data-val="4"></i>
                        <i class="ph ph-star text-2xl text-amber-400 cursor-pointer star-rate" data-val="5"></i>
                    </div>
                    <textarea name="comment" class="w-full bg-white bg-opacity-50 border border-transparent rounded p-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent-gold)] transition mb-3" placeholder="Tell us about your stay..." rows="2"></textarea>
                    <button type="submit" class="btn-primary">Submit review</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="text-center pb-8">
                <?php
                $propPhone = defined('PROPERTY_PHONE') ? PROPERTY_PHONE : '';
                $chatHref = $propPhone ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $propPhone) : '#';
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

            <h3 class="text-md font-bold text-slate-800 mb-3">Registration signature</h3>
            <div class="glass-panel p-4 mb-6">
                <?php if (!empty($booking['digital_signature'])): ?>
                    <p class="text-xs text-emerald-700 font-bold mb-2">Signed</p>
                    <img src="<?= htmlspecialchars($booking['digital_signature']) ?>" alt="Signature" class="max-h-24 mx-auto">
                <?php else: ?>
                    <form method="POST" onsubmit="return attachProfileSignature(event)">
                        <input type="hidden" name="action" value="sign_reg_card">
                        <input type="hidden" name="signature_data" id="profileSignatureData">
                        <div class="border border-slate-200 rounded-xl bg-white relative mb-3">
                            <canvas id="profileSignatureCanvas" class="w-full h-32"></canvas>
                            <button type="button" onclick="clearProfileSignature()" class="absolute top-1 right-1 text-[10px] bg-slate-100 px-2 py-1 rounded-lg">Clear</button>
                        </div>
                        <p id="profileSignError" class="banner-err hidden"></p>
                        <button type="submit" class="btn-primary"><i class="ph ph-pen-nib"></i> Save signature</button>
                    </form>
                <?php endif; ?>
            </div>

            <h3 class="text-md font-bold text-slate-800 mb-3">ID Verification</h3>
            <div id="profile-id-loading" class="text-center py-4 text-gray-500 text-sm hidden">
                <i class="ph ph-spinner ph-spin"></i> Loading ID documents…
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
                            <i class="ph ph-camera mr-2"></i> Upload Front ID
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
                            <i class="ph ph-camera mr-2"></i> Upload Back ID
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
        <a href="#" onclick="switchTab('home'); return false;" class="nav-item active" id="nav-home">
            <i class="ph ph-house"></i>
            <span>Home</span>
        </a>
        <?php if ($posEnabled): ?>
        <a href="#" onclick="switchTab('pos'); return false;" class="nav-item" id="nav-pos">
            <i class="ph ph-fork-knife"></i>
            <span>Dining</span>
        </a>
        <?php endif; ?>
        <?php if ($servicesEnabled): ?>
        <a href="#" onclick="switchTab('services'); return false;" class="nav-item" id="nav-services">
            <i class="ph ph-bell-ringing"></i>
            <span>Services</span>
            <span class="nav-badge" id="svcBadge">0</span>
        </a>
        <?php endif; ?>
        <a href="#" onclick="switchTab('folio'); return false;" class="nav-item" id="nav-folio">
            <i class="ph ph-receipt"></i>
            <span>Bill</span>
        </a>
    </nav>
    <?php endif; ?>

    <div id="appToast" class="app-toast" role="status"></div>
    <div id="sheetBackdrop" class="app-sheet-backdrop" onclick="closeSheet()"></div>
    <div id="confirmSheet" class="app-sheet">
        <div class="sheet-handle"></div>
        <p class="font-bold text-slate-900 mb-1" id="sheetTitle">Confirm</p>
        <p class="text-sm text-slate-500 mb-5" id="sheetBody"></p>
        <div class="flex gap-3">
            <button type="button" class="btn-secondary flex-1" onclick="closeSheet()">Cancel</button>
            <button type="button" class="btn-primary flex-1" id="sheetConfirm">Confirm</button>
        </div>
    </div>

    <script>
        const bookingId = <?= json_encode((string)$bookingId) ?>;
        const token = <?= json_encode((string)$token) ?>;
        const checkoutAt = <?= json_encode($checkoutIso) ?>;
        let toastTimer = null;
        let sheetCallback = null;
        let currentTab = 'home';

        function haptic() {
            try { if (navigator.vibrate) navigator.vibrate(10); } catch (e) {}
        }

        function guestToast(msg, kind) {
            const el = document.getElementById('appToast');
            if (!el) return;
            el.textContent = msg;
            el.className = 'app-toast show' + (kind === 'ok' ? ' ok' : kind === 'err' ? ' err' : '');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => el.classList.remove('show'), 2800);
        }

        function openSheet(title, body, onConfirm) {
            document.getElementById('sheetTitle').textContent = title;
            document.getElementById('sheetBody').textContent = body;
            sheetCallback = onConfirm;
            document.getElementById('sheetBackdrop').classList.add('show');
            document.getElementById('confirmSheet').classList.add('show');
        }
        function closeSheet() {
            document.getElementById('sheetBackdrop').classList.remove('show');
            document.getElementById('confirmSheet').classList.remove('show');
            sheetCallback = null;
        }
        document.getElementById('sheetConfirm').addEventListener('click', () => {
            const cb = sheetCallback;
            closeSheet();
            if (cb) cb();
        });

        function switchTab(tabId) {
            const view = document.getElementById('view-' + tabId);
            if (!view) return;
            haptic();
            currentTab = tabId;
            document.querySelectorAll('.view-section').forEach(el => {
                el.classList.add('hidden');
                el.classList.remove('gp-enter');
            });
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            
            view.classList.remove('hidden');
            void view.offsetWidth;
            view.classList.add('gp-enter');
            if(document.getElementById('nav-' + tabId)) {
                document.getElementById('nav-' + tabId).classList.add('active');
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            if (tabId === 'services') loadActiveRequests();
            if (tabId === 'profile') {
                loadProfileDocuments();
                initProfileSignature();
            }
            if (tabId === 'pos') loadPosMenu();
        }

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
        }

        async function loadActiveRequests() {
            const container = document.getElementById('activeRequestsContainer');
            if (!container) return;
            try {
                const res = await fetch(`/api/guest/service_request?action=list&booking_id=${bookingId}&token=${token}`);
                const data = await res.json();
                const requests = data.requests || data.data?.requests || [];
                
                if (data.success === true && requests.length > 0) {
                    container.innerHTML = requests.map(req => {
                        const type = String(req.service_type || '');
                        const icon = type.toLowerCase().includes('house') || type.toLowerCase().includes('towel') || type.toLowerCase().includes('blanket') ? 'ph-broom' : 'ph-bell';
                        const st = String(req.status || 'pending');
                        const chip = st === 'completed' ? 'completed' : (st === 'rejected' ? 'rejected' : 'pending');
                        return `
                        <div class="glass-panel p-3 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="shortcut-icon">
                                    <i class="ph ${icon}"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-slate-800">${escapeHtml(type.replace(/_/g, ' '))}</p>
                                    <p class="text-[10px] text-slate-500">${escapeHtml(req.created_at ? String(req.created_at).slice(0,16) : '')}</p>
                                </div>
                            </div>
                            <span class="req-chip ${chip}">${escapeHtml(st.replace('_', ' '))}</span>
                        </div>
                        `;
                    }).join('');
                    const pending = requests.filter(r => String(r.status || '') === 'pending').length;
                    const badge = document.getElementById('svcBadge');
                    if (badge) {
                        badge.textContent = String(pending);
                        badge.classList.toggle('show', pending > 0);
                    }
                } else {
                    container.innerHTML = '<p class="text-xs text-center text-gray-500">No active requests.</p>';
                    const badge = document.getElementById('svcBadge');
                    if (badge) badge.classList.remove('show');
                }
            } catch (e) {
                console.error(e);
                const container = document.getElementById('activeRequestsContainer');
                if (container) {
                    container.innerHTML = '<p class="text-xs text-center text-red-500">Failed to load requests. Please check your connection.</p>';
                }
            }
        }

        function askService(serviceType, category) {
            haptic();
            openSheet('Request service', `Send a request for ${serviceType}?`, () => submitService(serviceType, category));
        }

        async function submitService(serviceType, category) {
            try {
                const res = await fetch(`/api/guest/service_request`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'create', booking_id: bookingId, token: token, service_type: serviceType, category: category})
                });
                const data = await res.json();
                if (data.success === true) {
                    guestToast('Request sent.', 'ok');
                    loadActiveRequests();
                    switchTab('services');
                } else {
                    guestToast(data.message || 'Failed to send request.', 'err');
                }
            } catch (e) {
                guestToast('Could not send request.', 'err');
            }
        }

        function copyWifi() {
            const ssid = (document.getElementById('wifiSsid') || {}).innerText || '';
            const el = document.getElementById('wifiPassHome') || document.getElementById('wifiPass');
            const pass = el ? el.innerText : '';
            const text = ssid ? `SSID: ${ssid}\nPassword: ${pass}` : pass;
            navigator.clipboard.writeText(text).then(() => guestToast('WiFi details copied.', 'ok'));
        }

        const stars = document.querySelectorAll('.star-rate');
        const ratingInput = document.getElementById('ratingInput');
        stars.forEach(star => {
            star.addEventListener('click', (e) => {
                const val = parseInt(e.target.dataset.val);
                if (ratingInput) ratingInput.value = val;
                stars.forEach(s => {
                    if (parseInt(s.dataset.val) <= val) {
                        s.classList.remove('ph-star');
                        s.classList.add('ph-star-fill');
                    } else {
                        s.classList.remove('ph-star-fill');
                        s.classList.add('ph-star');
                    }
                });
            });
        });
        stars.forEach(s => {
            if (parseInt(s.dataset.val) <= 5) {
                s.classList.remove('ph-star');
                s.classList.add('ph-star-fill');
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
                <button type="button" data-outlet-id="${o.id}" onclick="posActiveOutlet=${Number(o.id)}; renderPosItems();" class="pos-cat ${Number(posActiveOutlet)===Number(o.id) ? 'is-active' : ''}">${escapeHtml(o.name)}</button>
            `).join('');
        }

        function renderPosItems() {
            const container = document.getElementById('posItems');
            if (!container) return;
            const q = (document.getElementById('posSearch')?.value || '').trim().toLowerCase();
            const items = posItems.filter(i => i.outlet_id == posActiveOutlet && (!q || String(i.name || '').toLowerCase().includes(q)));
            
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
                <div class="pos-item-card">
                    ${i.image_url ? `<img src="${escapeHtml(i.image_url)}" alt="" class="pos-item-media">` : `<div class="pos-item-placeholder"><i class="ph ph-fork-knife text-2xl"></i></div>`}
                    <div class="pos-item-body">
                        <div>
                            <p class="pos-item-name">${name}</p>
                            <p class="pos-item-price">₹${price.toFixed(2)}</p>
                        </div>
                        ${qty > 0 ? `
                        <div class="pos-qty">
                            <button type="button" onclick="updateCart(${Number(i.id)}, -1)" aria-label="Decrease">−</button>
                            <span>${qty}</span>
                            <button type="button" onclick="updateCart(${Number(i.id)}, 1)" aria-label="Increase">+</button>
                        </div>
                        ` : `
                        <button type="button" onclick="updateCart(${Number(i.id)}, 1)" class="pos-add-btn">Add to order</button>
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
            const countEl = document.getElementById('posCartCount');
            if (countEl) countEl.textContent = count > 0 ? `· ${count} item${count === 1 ? '' : 's'}` : '';
            const bar = document.getElementById('posCartBar');
            if (!bar) return;
            if (count > 0) {
                bar.classList.add('show');
            } else {
                bar.classList.remove('show');
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
                    previewEl.innerHTML = '<div class="text-center text-slate-500"><i class="ph ph-file-pdf text-4xl mb-2 text-red-500"></i><p class="text-xs font-bold">PDF uploaded</p></div>';
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
                guestToast('File exceeds 5MB.', 'err');
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
                    guestToast(data.message || 'Upload failed.', 'err');
                }
            } catch (e) {
                guestToast('Upload failed.', 'err');
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
            openSheet('Place order', 'Charge this order to your room folio?', async () => {
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
                    guestToast('Order placed and added to your bill.', 'ok');
                    posCart = {};
                    updateCartUI();
                    renderPosItems();
                    window.location.reload();
                } else {
                    guestToast(data.message || 'Could not place order.', 'err');
                }
            } catch(e) {
                guestToast('Could not place order.', 'err');
            } finally {
                document.getElementById('posSubmitBtn').disabled = false;
                document.getElementById('posSubmitBtn').innerHTML = `<span>Order</span> <i class="ph ph-arrow-right"></i>`;
            }
            });
            return;
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
                    guestToast(data.message || 'Could not start payment.', 'err');
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
                                guestToast(recData.message || 'Payment recording failed', 'err');
                            }
                        }
                    });
                    rzp.open();
                };
                if (typeof Razorpay === 'undefined') {
                    const s = document.createElement('script');
                    s.src = 'https://checkout.razorpay.com/v1/checkout.js';
                    s.onload = openCheckout;
                    s.onerror = () => guestToast('Could not load Razorpay checkout.', 'err');
                    document.head.appendChild(s);
                } else {
                    openCheckout();
                }
            } catch (e) {
                guestToast('Payment could not be started.', 'err');
            }
        }

        async function payWithPhonePe() {
            guestToast('Complete PhonePe from the link sent by the desk, or pay at the desk.', 'err');
        }

        function initProfileSignature() {
            const canvas = document.getElementById('profileSignatureCanvas');
            if (!canvas || window.profileSignaturePad) return;
            if (typeof SignaturePad === 'undefined') return;
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);
            window.profileSignaturePad = new SignaturePad(canvas);
        }
        function clearProfileSignature() {
            if (window.profileSignaturePad) window.profileSignaturePad.clear();
        }
        function attachProfileSignature(e) {
            const err = document.getElementById('profileSignError');
            if (!window.profileSignaturePad || window.profileSignaturePad.isEmpty()) {
                e.preventDefault();
                if (err) { err.textContent = 'Please sign before saving.'; err.classList.remove('hidden'); }
                return false;
            }
            document.getElementById('profileSignatureData').value = window.profileSignaturePad.toDataURL('image/png');
            return true;
        }

        function filterAttractions() {
            const q = (document.getElementById('attractionSearch')?.value || '').trim().toLowerCase();
            document.querySelectorAll('.attraction-row').forEach(row => {
                const name = row.getAttribute('data-name') || '';
                row.style.display = !q || name.includes(q) ? '' : 'none';
            });
        }

        function tickCheckout() {
            const el = document.getElementById('checkoutCountdown');
            if (!el || !checkoutAt) return;
            const ms = new Date(checkoutAt).getTime() - Date.now();
            if (ms <= 0) {
                el.textContent = 'Checkout time has passed — please visit the desk if you are still in-house.';
                return;
            }
            const h = Math.floor(ms / 3600000);
            const m = Math.floor((ms % 3600000) / 60000);
            el.textContent = h >= 24
                ? `${Math.floor(h / 24)}d ${h % 24}h until checkout`
                : `${h}h ${m}m until checkout`;
        }
        tickCheckout();
        setInterval(tickCheckout, 30000);
        loadActiveRequests();
        setInterval(() => { if (!document.hidden) loadActiveRequests(); }, 20000);

        (function enableSwipe() {
            const root = document.querySelector('.max-w-lg');
            if (!root) return;
            const tabs = ['home'<?= $posEnabled ? ", 'pos'" : '' ?><?= $servicesEnabled ? ", 'services'" : '' ?>, 'folio'];
            let x0 = 0, y0 = 0;
            root.addEventListener('touchstart', (e) => {
                const t = e.changedTouches[0];
                x0 = t.clientX; y0 = t.clientY;
            }, { passive: true });
            root.addEventListener('touchend', (e) => {
                const t = e.changedTouches[0];
                const dx = t.clientX - x0;
                const dy = t.clientY - y0;
                if (Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 1.4) return;
                const i = tabs.indexOf(currentTab);
                if (i < 0) return;
                if (dx < 0 && i < tabs.length - 1) switchTab(tabs[i + 1]);
                if (dx > 0 && i > 0) switchTab(tabs[i - 1]);
            }, { passive: true });
        })();

    </script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.5/dist/signature_pad.umd.min.js"></script>
</body>
</html>
