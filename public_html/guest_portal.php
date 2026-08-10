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
    SELECT b.*, r.room_number, c.name as category_name, g.id as guest_id, g.name as guest_name, g.phone as guest_phone, g.digital_signature, p.name as property_name, p.property_code
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    JOIN room_categories c ON r.category_id = c.id
    JOIN properties p ON b.property_id = p.id
    LEFT JOIN guests g ON b.guest_id = g.id
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
$upsellEnabled = $_dbSetting('GUEST_PORTAL_UPSELL_ENABLED', 'false') === 'true';
$posEnabled = $_dbSetting('GUEST_PORTAL_POS_ENABLED', 'false') === 'true';
$housekeepingEnabled = $_dbSetting('GUEST_PORTAL_HOUSEKEEPING_ENABLED', 'false') === 'true';
$selfCheckoutEnabled = (defined('GUEST_PORTAL_SELF_CHECKOUT_ENABLED') && GUEST_PORTAL_SELF_CHECKOUT_ENABLED === 'true');
$earlyLateFee = floatval(defined('GUEST_PORTAL_EARLY_LATE_FEE') ? GUEST_PORTAL_EARLY_LATE_FEE : '0.00');

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
                
                header("Location: guest_portal.php?id={$bookingId}&token={$token}&msg=signature_success");
                exit;
            } catch (Exception $e) {
                $error = "Failed to save digital signature: " . $e->getMessage();
            }
        }
    } elseif ($action === 'submit_review') {
        $rating = (int)($_POST['rating'] ?? 5);
        $comment = trim($_POST['comment'] ?? '');
        
        try {
            $revStmt = $db->prepare("INSERT INTO guest_reviews (booking_id, property_id, rating, comment) VALUES (?, ?, ?, ?)");
            $revStmt->execute([$bookingId, (int)$booking['property_id'], $rating, $comment]);
            
            AuditLogger::log(0, 'PORTAL_SUBMIT_REVIEW', 'BOOKING', $booking['id'], [
                'rating' => $rating,
                'comment' => $comment
            ], (int)$booking['property_id']);
            
            header("Location: guest_portal.php?id={$bookingId}&token={$token}&msg=review_success");
            exit;
        } catch (Exception $e) {
            $error = "Failed to submit review: " . $e->getMessage();
        }
    } elseif ($action === 'upsell_late_checkout' && $upsellEnabled) {
        try {
            $db->beginTransaction();

            $postStmt = $db->prepare("INSERT INTO folio_ledger (booking_id, description, amount, recorded_at) VALUES (?, 'Late Checkout Fee (Guest Portal Offer)', ?, NOW())");
            $postStmt->execute([$bookingId, $earlyLateFee]);

            $newCheckout = date('Y-m-d H:i:s', strtotime($booking['check_out'] . ' +3 hours'));
            $extStmt = $db->prepare("UPDATE bookings SET check_out = ? WHERE id = ?");
            $extStmt->execute([$newCheckout, $bookingId]);

            AuditLogger::log(0, 'PORTAL_LATE_CHECKOUT_UPSELL', 'BOOKING', $booking['id'], [
                'guest' => $booking['guest_name'],
                'charge' => $earlyLateFee,
                'new_checkout' => $newCheckout
            ], (int)$booking['property_id']);

            $db->commit();
            header("Location: guest_portal.php?id={$bookingId}&token={$token}&msg=late_checkout_success");
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = "Failed to apply late checkout: " . $e->getMessage();
        }
    } elseif ($action === 'self_checkout' && $selfCheckoutEnabled) {
        if ($balance > 0.05) {
            $error = "Cannot checkout: You have a pending balance of ₹" . number_format($balance, 2) . ". Please clear dues at front desk.";
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
                header("Location: guest_portal.php?id={$bookingId}&token={$token}&msg=checkout_success");
                exit;
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Self-checkout failed: " . $e->getMessage();
            }
        }
    }
}

if (($_GET['msg'] ?? '') === 'late_checkout_success') {
    $message = "⚡ Late checkout extended! 3 additional hours have been added to your stay.";
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
$bannersStr = $_dbSetting('GUEST_PORTAL_BANNERS', '[{"title":"Rooftop Happy Hour","subtitle":"(5-7 PM)","action":"#"},{"title":"Yoga Session","subtitle":"(8 AM)","action":"#"}]');
$banners = json_decode($bannersStr, true) ?? [];

$wifiSSID = $_dbSetting('GUEST_PORTAL_WIFI_SSID', 'GrandPalm_Guest');
$wifiPass = $_dbSetting('GUEST_PORTAL_WIFI_PASS', 'staygrand');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
        <div class="flex justify-between items-center mb-4 text-white">
            <button class="w-10 h-10 rounded-full bg-white bg-opacity-20 flex items-center justify-center hover:bg-opacity-30 transition">
                <i class="fas fa-bars text-lg"></i>
            </button>
            <button class="w-10 h-10 rounded-full bg-white bg-opacity-20 flex items-center justify-center hover:bg-opacity-30 transition relative">
                <i class="fas fa-bell text-lg"></i>
                <span class="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full"></span>
            </button>
        </div>

        <!-- Header Titles -->
        <div class="flex justify-between items-end mb-6">
            <div class="text-white">
                <h1 class="text-2xl font-serif font-bold uppercase tracking-widest"><?= htmlspecialchars($booking['property_name']) ?></h1>
                <span class="text-xs bg-white bg-opacity-20 px-2 py-1 rounded-full uppercase tracking-wider inline-block mt-1">Guest Portal</span>
            </div>
            <div class="text-right text-white pb-1">
                <p class="text-xs opacity-80">Welcome,</p>
                <p class="font-bold text-sm"><?= htmlspecialchars($booking['guest_name'] ?: 'Guest') ?></p>
                <p class="text-xs">Room <?= htmlspecialchars($booking['room_number'] ?: 'TBA') ?></p>
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

        <!-- VIEW WRAPPER -->
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
                <a href="guest_pos_menu.php?id=<?= $bookingId ?>&token=<?= $token ?>" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center text-xl">
                        <i class="fas fa-utensils"></i>
                    </div>
                    <span class="text-sm font-semibold">Order Food</span>
                </a>
                <?php endif; ?>
                
                <button onclick="switchTab('services')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xl">
                        <i class="fas fa-broom"></i>
                    </div>
                    <span class="text-sm font-semibold">Request Service</span>
                </button>
                
                <button onclick="alert('Feature coming soon!')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-xl">
                        <i class="fas fa-spa"></i>
                    </div>
                    <span class="text-sm font-semibold">Book Spa</span>
                </button>
                
                <button onclick="alert('Feature coming soon!')" class="neumorphic-card p-4 flex flex-col justify-center items-center text-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-green-100 text-green-600 flex items-center justify-center text-xl">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <span class="text-sm font-semibold">Local Guide</span>
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

            <div class="glass-panel p-5 mb-6">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Checkout Today: <?= $checkout->format('h:i A') ?></p>
                <div class="progress-container">
                    <div class="progress-bar" style="width: 80%"></div>
                </div>
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

                <div class="flex justify-between items-center pt-4 border-t border-gray-200">
                    <span class="text-sm font-bold text-gray-600">Split Bill</span>
                    <!-- Simple UI Toggle -->
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" value="" class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[var(--accent-gold)]"></div>
                    </label>
                </div>
            </div>

            <?php if ($selfCheckoutEnabled && $balance <= 0.05 && $booking['booking_status'] === 'checked_in'): ?>
            <form method="POST" class="mb-6">
                <input type="hidden" name="action" value="self_checkout">
                <button type="submit" class="w-full bg-[var(--accent-gold)] hover:bg-[var(--accent-gold-dark)] text-white font-bold py-4 rounded-xl shadow-lg transition uppercase tracking-widest text-sm flex justify-between items-center px-6">
                    <span>Complete Express Checkout</span>
                    <span>₹<?= number_format($balance, 2) ?></span>
                </button>
            </form>
            <?php elseif ($balance > 0.05): ?>
            <button disabled class="w-full bg-gray-300 text-gray-500 font-bold py-4 rounded-xl shadow transition uppercase tracking-widest text-sm flex justify-between items-center px-6 cursor-not-allowed mb-6">
                <span>Clear dues to Checkout</span>
                <span>₹<?= number_format($balance, 2) ?></span>
            </button>
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
                <a href="#" class="text-xs font-bold text-[var(--accent-gold-dark)] uppercase tracking-wider">Need Help? Chat</a>
            </div>
        </div>

    </div>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="#" onclick="switchTab('home')" class="nav-item active" id="nav-home">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="#" onclick="switchTab('services')" class="nav-item" id="nav-services">
            <i class="fas fa-concierge-bell"></i>
            <span>Services</span>
        </a>
        <a href="#" onclick="switchTab('folio')" class="nav-item" id="nav-folio">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Folio</span>
        </a>
    </nav>

    <script>
        const bookingId = '<?= $bookingId ?>';
        const token = '<?= $token ?>';

        function switchTab(tabId) {
            document.querySelectorAll('.view-section').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            
            document.getElementById('view-' + tabId).classList.remove('hidden');
            document.getElementById('nav-' + tabId).classList.add('active');
            
            if (tabId === 'services') loadActiveRequests();
        }

        async function loadActiveRequests() {
            try {
                const res = await fetch(`/api_endpoints/guest_service_request.php?action=list&booking_id=${bookingId}&token=${token}`);
                const data = await res.json();
                const container = document.getElementById('activeRequestsContainer');
                
                if (data.status === 'success' && data.data.requests.length > 0) {
                    container.innerHTML = data.data.requests.map(req => {
                        const icon = req.service_type === 'Housekeeping' ? 'fa-broom' : 'fa-bell';
                        const statusColor = req.status === 'completed' ? 'text-green-500' : 'text-blue-500';
                        return `
                        <div class="glass-panel p-3 flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded bg-white flex items-center justify-center text-[var(--accent-gold)]">
                                    <i class="fas ${icon}"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-gray-800">${req.service_type}</p>
                                    <p class="text-[10px] text-gray-500">Status: <span class="capitalize">${req.status.replace('_', ' ')}</span></p>
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
            }
        }

        async function submitService(serviceType, category) {
            if (!confirm(`Request ${serviceType}?`)) return;
            try {
                const res = await fetch(`/api_endpoints/guest_service_request.php`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'create', booking_id: bookingId, token: token, service_type: serviceType, category: category})
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('Request sent successfully!');
                    loadActiveRequests();
                } else {
                    alert('Failed to send request: ' + data.message);
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
    </script>
</body>
</html>
