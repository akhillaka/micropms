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
$upsellEnabled = (defined('GUEST_PORTAL_UPSELL_ENABLED') && GUEST_PORTAL_UPSELL_ENABLED === 'true');
$housekeepingEnabled = (defined('GUEST_PORTAL_HOUSEKEEPING_ENABLED') && GUEST_PORTAL_HOUSEKEEPING_ENABLED === 'true');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Portal | <?= htmlspecialchars($booking['property_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;950&family=Fira+Code:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { 
            font-family: 'Outfit', sans-serif; 
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #0f172a;
        }
        .font-mono {
            font-family: 'Fira Code', monospace;
        }
        .glass-header {
            background: rgba(15, 23, 42, 0.9);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .card-premium {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 1.25rem;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.04), 0 2px 8px -1px rgba(15, 23, 42, 0.02);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-premium:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -4px rgba(15, 23, 42, 0.06), 0 4px 12px -2px rgba(15, 23, 42, 0.03);
            border-color: rgba(59, 82, 255, 0.15);
        }
        .signature-canvas {
            border: 2px dashed #cbd5e1;
            background: #ffffff;
            cursor: crosshair;
        }
        .tab-btn {
            padding: 0.65rem 1rem;
            font-weight: 600;
            font-size: 0.875rem;
            color: #64748b;
            border-radius: 0.75rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            white-space: nowrap;
        }
        .tab-btn:hover {
            color: #0f172a;
        }
        .tab-btn.active {
            color: #4f46e5;
            background: #ffffff;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
        }
        .tab-content {
            display: none;
            animation: slideUp 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .tab-content.active {
            display: block;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .gradient-brand {
            background: linear-gradient(135deg, #4f46e5 0%, #312e81 100%);
        }
        .gradient-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .gradient-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
    </style>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body class="text-slate-800 pb-20">

    <!-- HERO HEADER -->
    <header class="glass-header text-white px-6 py-10 relative overflow-hidden shadow-xl">
        <!-- Abstract gradient glows behind the glass header -->
        <div class="absolute -top-24 -left-20 w-56 h-56 rounded-full bg-blue-600/30 blur-[100px] pointer-events-none"></div>
        <div class="absolute -bottom-20 -right-20 w-48 h-48 rounded-full bg-indigo-500/20 blur-[80px] pointer-events-none"></div>
        
        <div class="max-w-xl mx-auto space-y-4 relative z-10">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 bg-blue-600/25 border border-blue-500/30 rounded-lg flex items-center justify-center">
                        <i class="ph-bold ph-buildings text-blue-400"></i>
                    </div>
                    <span class="text-xs font-black uppercase tracking-wider text-slate-300"><?= htmlspecialchars($booking['property_name']) ?></span>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-extrabold border border-indigo-500/30 bg-indigo-500/10 text-indigo-300 shadow-sm flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-indigo-400 animate-pulse"></span>
                    Room <?= htmlspecialchars($booking['room_number']) ?>
                </span>
            </div>
            
            <div class="space-y-1">
                <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-white leading-none">Hello, <?= htmlspecialchars($booking['guest_name']) ?></h1>
                <p class="text-xs font-medium text-slate-400 flex items-center gap-1"><i class="ph ph-sparkle text-indigo-400"></i> Welcome to your digital guest dashboard.</p>
            </div>
        </div>
    </header>

    <?php if ($booking['booking_status'] === 'booked'): ?>
        
        <!-- SELF CHECK-IN WIZARD -->
        <main class="max-w-xl mx-auto px-4 mt-6 space-y-6 animate-fade-in pb-20">
            <div class="card-premium p-6 space-y-6">
                <div class="text-center space-y-2 pb-4 border-b border-slate-100">
                    <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-2">
                        <i class="ph ph-user-check text-2xl"></i>
                    </div>
                    <h2 class="text-xl font-extrabold text-slate-800 tracking-tight">Self Check-In</h2>
                    <p class="text-xs font-medium text-slate-500">Please verify your details and provide ID proof to check in.</p>
                </div>

                <form id="checkInForm" onsubmit="submitSelfCheckIn(event)" class="space-y-6">
                    <input type="hidden" id="checkin_booking_id" value="<?= htmlspecialchars($bookingId) ?>">
                    <input type="hidden" id="checkin_token" value="<?= htmlspecialchars($token) ?>">
                    
                    <div class="space-y-4">
                        <h3 class="text-xs font-bold uppercase tracking-widest text-slate-400">1. Guest Details</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Full Name</label>
                                <input type="text" id="checkin_name" value="<?= htmlspecialchars($booking['guest_name']) ?>" required class="w-full bg-slate-50 border border-slate-200 p-3 rounded-xl text-xs font-semibold text-slate-700 outline-none focus:border-blue-500 focus:bg-white transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Email</label>
                                <input type="email" id="checkin_email" required class="w-full bg-slate-50 border border-slate-200 p-3 rounded-xl text-xs font-semibold text-slate-700 outline-none focus:border-blue-500 focus:bg-white transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Phone</label>
                                <input type="text" id="checkin_phone" value="<?= htmlspecialchars($booking['guest_phone'] ?? '') ?>" required class="w-full bg-slate-50 border border-slate-200 p-3 rounded-xl text-xs font-semibold text-slate-700 outline-none focus:border-blue-500 focus:bg-white transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">City</label>
                                <input type="text" id="checkin_city" required class="w-full bg-slate-50 border border-slate-200 p-3 rounded-xl text-xs font-semibold text-slate-700 outline-none focus:border-blue-500 focus:bg-white transition">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">State / Province</label>
                                <input type="text" id="checkin_state" required class="w-full bg-slate-50 border border-slate-200 p-3 rounded-xl text-xs font-semibold text-slate-700 outline-none focus:border-blue-500 focus:bg-white transition">
                            </div>
                        </div>
                    </div>

                    <?php 
                    $preArrivalDoc = ($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_PRE_ARRIVAL_DOC'")->fetchColumn() ?: 'true') === 'true';
                    if ($preArrivalDoc): 
                    ?>
                    <div class="space-y-4 pt-4 border-t border-slate-100">
                        <h3 class="text-xs font-bold uppercase tracking-widest text-slate-400">2. Identity Proof</h3>
                        <p class="text-[10px] text-slate-500 font-medium">Please upload a clear photo of your government-issued ID.</p>
                        
                        <div class="space-y-3">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">ID Front Side</label>
                                <input type="file" id="id_front" accept="image/*" required class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition cursor-pointer">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">ID Back Side</label>
                                <input type="file" id="id_back" accept="image/*" required class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition cursor-pointer">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php 
                    $preArrivalSig = ($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_PRE_ARRIVAL_SIGNATURE'")->fetchColumn() ?: 'true') === 'true';
                    if ($preArrivalSig): 
                    ?>
                    <div class="space-y-4 pt-4 border-t border-slate-100">
                        <h3 class="text-xs font-bold uppercase tracking-widest text-slate-400">Digital Signature</h3>
                        <p class="text-[10px] text-slate-500 font-medium">Please sign below to authorize your check-in.</p>
                        <div class="border border-slate-200 rounded-xl overflow-hidden bg-white">
                            <canvas id="signature-pad" class="w-full h-32 touch-none cursor-crosshair"></canvas>
                        </div>
                        <button type="button" onclick="clearSignature()" class="text-[10px] font-bold text-slate-400 uppercase tracking-widest hover:text-rose-500 transition">Clear Signature</button>
                    </div>
                    <?php endif; ?>

                    <div class="space-y-4 pt-4 border-t border-slate-100">
                        <h3 class="text-xs font-bold uppercase tracking-widest text-slate-400">Confirmation</h3>
                        <label class="flex items-start gap-3 p-3 bg-slate-50 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-100 transition">
                            <input type="checkbox" id="checkin_agree" required class="mt-1 w-4 h-4 text-blue-600 rounded focus:ring-blue-500">
                            <span class="text-[11px] font-medium text-slate-600">I confirm that all details provided are true and correct. I agree to the hotel's policies and terms of stay.</span>
                        </label>
                    </div>
                    
                    <div id="checkin_error" class="hidden bg-rose-50 text-rose-600 px-4 py-3 rounded-xl text-xs font-bold text-center border border-rose-200"></div>

                    <button type="submit" id="btn_complete_checkin" class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-sm uppercase tracking-wider rounded-xl transition shadow-lg flex items-center justify-center gap-2 cursor-pointer">
                        <i class="ph ph-check-circle text-lg"></i> Complete Check-In
                    </button>
                </form>
            </div>
        </main>
        
        <script>
            let sigPad = document.getElementById('signature-pad');
            let sigCtx = null;
            let isDrawing = false;
            
            if (sigPad) {
                sigCtx = sigPad.getContext('2d');
                // Resize for display vs logical size
                const rect = sigPad.parentElement.getBoundingClientRect();
                sigPad.width = rect.width;
                sigPad.height = rect.height;

                sigPad.addEventListener('pointerdown', (e) => {
                    isDrawing = true;
                    sigCtx.beginPath();
                    const r = sigPad.getBoundingClientRect();
                    sigCtx.moveTo(e.clientX - r.left, e.clientY - r.top);
                });
                sigPad.addEventListener('pointermove', (e) => {
                    if (isDrawing) {
                        const r = sigPad.getBoundingClientRect();
                        sigCtx.lineTo(e.clientX - r.left, e.clientY - r.top);
                        sigCtx.stroke();
                    }
                });
                sigPad.addEventListener('pointerup', () => isDrawing = false);
                sigPad.addEventListener('pointerout', () => isDrawing = false);
            }

            function clearSignature() {
                if (sigCtx) sigCtx.clearRect(0, 0, sigPad.width, sigPad.height);
            }

            async function submitSelfCheckIn(e) {
                e.preventDefault();
                const btn = document.getElementById('btn_complete_checkin');
                const errBox = document.getElementById('checkin_error');
                
                if(!document.getElementById('checkin_agree').checked) {
                    errBox.textContent = "You must agree to the terms.";
                    errBox.classList.remove('hidden');
                    return;
                }

                btn.disabled = true;
                btn.innerHTML = '<i class="ph ph-spinner animate-spin text-lg"></i> Uploading & Processing...';
                errBox.classList.add('hidden');

                const formData = new FormData();
                formData.append('booking_id', document.getElementById('checkin_booking_id').value);
                formData.append('token', document.getElementById('checkin_token').value);
                formData.append('name', document.getElementById('checkin_name').value.trim());
                formData.append('email', document.getElementById('checkin_email').value.trim());
                formData.append('phone', document.getElementById('checkin_phone').value.trim());
                formData.append('city', document.getElementById('checkin_city').value.trim());
                formData.append('state', document.getElementById('checkin_state').value.trim());
                
                const idFront = document.getElementById('id_front');
                if (idFront && idFront.files[0]) formData.append('id_front', idFront.files[0]);
                
                const idBack = document.getElementById('id_back');
                if (idBack && idBack.files[0]) formData.append('id_back', idBack.files[0]);
                
                if (sigPad) {
                    formData.append('signature_data', sigPad.toDataURL('image/png'));
                }

                try {
                    const res = await fetch('/api/guest/self_checkin', {
                        method: 'POST',
                        body: formData // FormData sets multipart automatically
                    });
                    const data = await res.json();
                    
                    if (data.success) {
                        btn.classList.replace('bg-emerald-600', 'bg-blue-600');
                        btn.innerHTML = '<i class="ph ph-check-circle text-lg"></i> Success! Reloading...';
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        throw new Error(data.message || 'Check-in failed.');
                    }
                } catch(err) {
                    errBox.textContent = err.message || 'Network error.';
                    errBox.classList.remove('hidden');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="ph ph-check-circle text-lg"></i> Complete Check-In';
                }
            }
        </script>

    <?php else: ?>

    <!-- FLOATING TAB BAR -->
    <div class="sticky top-0 z-40 bg-slate-50/80 backdrop-blur-md border-b border-slate-200/50 py-3 shadow-sm overflow-x-auto no-scrollbar">
        <div class="max-w-xl mx-auto px-4">
            <div class="flex bg-slate-200/60 p-1 rounded-2xl gap-1" id="tabs-container">
                <button class="tab-btn active flex-1 rounded-xl flex items-center justify-center gap-2 py-2.5 transition" onclick="switchTab('overview')">
                    <i class="ph-bold ph-house text-base"></i> Overview
                </button>
                <button class="tab-btn flex-1 rounded-xl flex items-center justify-center gap-2 py-2.5 transition" onclick="switchTab('billing')">
                    <i class="ph-bold ph-receipt text-base"></i> Billing
                </button>
                <button class="tab-btn flex-1 rounded-xl flex items-center justify-center gap-2 py-2.5 transition" onclick="switchTab('services')">
                    <i class="ph-bold ph-bell-ringing text-base"></i> Services
                </button>
            </div>
        </div>
    </div>

    <main class="max-w-xl mx-auto px-4 mt-6 space-y-6">

        <!-- ALERTS -->
        <?php if ($message): ?>
            <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl text-xs font-bold flex items-start gap-2 shadow-sm animate-fade-in">
                <i class="ph ph-check-circle text-lg shrink-0 text-emerald-600"></i>
                <div><?= $message ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="p-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl text-xs font-bold flex items-start gap-2 shadow-sm animate-fade-in">
                <i class="ph ph-warning-circle text-lg shrink-0 text-rose-500"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <!-- ================= TAB: OVERVIEW ================= -->
        <div id="tab-overview" class="tab-content active space-y-6">
            
            <?php 
            $loyaltyEnabled = ($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_LOYALTY_ENABLED'")->fetchColumn() ?: 'true') === 'true';
            if ($loyaltyEnabled):
                $loyaltyGold = intval($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_LOYALTY_GOLD'")->fetchColumn() ?: '5');
                $loyaltyPlatinum = intval($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_LOYALTY_PLATINUM'")->fetchColumn() ?: '10');
                
                $staysCount = 0;
                if (!empty($booking['guest_id'])) {
                    $staysStmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE guest_id = ? AND booking_status = 'checked_out'");
                    $staysStmt->execute([$booking['guest_id']]);
                    $staysCount = (int)$staysStmt->fetchColumn();
                }
                
                if ($staysCount >= $loyaltyPlatinum) {
                    $tierName = "Platinum VIP";
                    $tierColor = "from-violet-500 to-purple-600";
                    $badgeText = "text-purple-600 bg-purple-50 border-purple-200";
                    $nextTierText = "Max Tier Achieved!";
                    $progressPct = 100;
                    $perks = ["Free Airport Pickups", "Complimentary Room Upgrades", "Late Check-out priority", "VIP Welcome Drinks"];
                } elseif ($staysCount >= $loyaltyGold) {
                    $tierName = "Gold Elite";
                    $tierColor = "from-amber-400 to-amber-600";
                    $badgeText = "text-amber-700 bg-amber-50 border-amber-200";
                    $nextTierText = ($loyaltyPlatinum - $staysCount) . " stays to Platinum VIP";
                    $progressPct = round(($staysCount / $loyaltyPlatinum) * 100);
                    $perks = ["Late Check-out requests", "Complimentary Buffet Breakfast", "Welcome Drinks"];
                } else {
                    $tierName = "Silver Explorer";
                    $tierColor = "from-slate-400 to-slate-500";
                    $badgeText = "text-slate-600 bg-slate-50 border-slate-200";
                    $nextTierText = ($loyaltyGold - $staysCount) . " stays to Gold Elite";
                    $progressPct = round(($staysCount / $loyaltyGold) * 100);
                    $perks = ["High-speed WiFi", "Priority Room allocation"];
                }
            ?>
            <!-- LOYALTY PROFILE CARD -->
            <div class="card-premium p-5 space-y-4 overflow-hidden relative border-l-4 border-l-indigo-500">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-2">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <i class="ph ph-crown text-xl"></i>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-slate-400 uppercase block mb-0.5">Loyalty Member</span>
                            <span class="font-extrabold text-slate-800 text-sm"><?= htmlspecialchars($booking['guest_name']) ?></span>
                        </div>
                    </div>
                    <span class="px-2.5 py-1 text-[10px] font-black uppercase tracking-wider rounded-lg border <?= $badgeText ?>"><?= $tierName ?></span>
                </div>
                <div class="space-y-2">
                    <div class="flex justify-between text-xs font-semibold">
                        <span class="text-slate-500"><?= $staysCount ?> Completed Stays</span>
                        <span class="text-indigo-600"><?= $nextTierText ?></span>
                    </div>
                    <div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                        <div class="bg-gradient-to-r <?= $tierColor ?> h-full rounded-full" style="width: <?= $progressPct ?>%"></div>
                    </div>
                </div>
                <div class="pt-3 border-t border-dashed border-slate-100">
                    <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest block mb-2">Tier Privileges</span>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($perks as $p): ?>
                            <span class="text-[10px] font-bold text-indigo-700 bg-indigo-50/70 border border-indigo-100 rounded-md px-2 py-0.5"><i class="ph ph-circle-wavy-check inline-block text-[10px] align-middle mr-0.5"></i> <?= $p ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            $upsellEnabled = ($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_UPSELL_ENABLED'")->fetchColumn() ?: 'true') === 'true';
            if ($upsellEnabled):
                $breakfastFee = floatval($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_UPSELL_BREAKFAST_PRICE'")->fetchColumn() ?: '350.00');
                $transferFee = floatval($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_UPSELL_TRANSFER_PRICE'")->fetchColumn() ?: '1200.00');
                $lateCheckoutFee = floatval($db->query("SELECT key_value FROM system_settings WHERE property_id = " . $booking['property_id'] . " AND key_name = 'GUEST_PORTAL_EARLY_LATE_FEE'")->fetchColumn() ?: '800.00');
            ?>
            <!-- CURATED OFFERS & UPSELLS -->
            <div class="card-premium p-5 space-y-4">
                <div class="flex items-center gap-2">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <i class="ph ph-shopping-bag text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500 font-display">Special Upsell Upgrades</h3>
                        <p class="text-[10px] text-slate-400">Enhance your stay experience with exclusive add-ons</p>
                    </div>
                </div>

                <div class="space-y-3 pt-2">
                    <!-- Breakfast Buffet -->
                    <div class="flex justify-between items-center p-3 bg-slate-50/70 border border-slate-200/50 rounded-2xl">
                        <div class="space-y-0.5">
                            <span class="text-xs font-extrabold text-slate-800">Breakfast Buffet Package</span>
                            <span class="text-[10px] text-slate-500 block">Unlimited breakfast during your stay.</span>
                            <span class="text-xs font-bold text-indigo-600">₹<?= number_format($breakfastFee, 2) ?></span>
                        </div>
                        <button onclick="purchaseUpsell('Breakfast Buffet Add-on', <?= $breakfastFee ?>)" class="py-1.5 px-3.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-bold rounded-xl text-xs transition">Add</button>
                    </div>

                    <!-- Cab Transfer -->
                    <div class="flex justify-between items-center p-3 bg-slate-50/70 border border-slate-200/50 rounded-2xl">
                        <div class="space-y-0.5">
                            <span class="text-xs font-extrabold text-slate-800">Airport Cab Pickup/Drop</span>
                            <span class="text-[10px] text-slate-500 block">Secure airport transfers to/from the property.</span>
                            <span class="text-xs font-bold text-indigo-600">₹<?= number_format($transferFee, 2) ?></span>
                        </div>
                        <button onclick="purchaseUpsell('Airport Cab Transfer', <?= $transferFee ?>)" class="py-1.5 px-3.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-bold rounded-xl text-xs transition">Book</button>
                    </div>

                    <!-- Late Checkout -->
                    <div class="flex justify-between items-center p-3 bg-slate-50/70 border border-slate-200/50 rounded-2xl">
                        <div class="space-y-0.5">
                            <span class="text-xs font-extrabold text-slate-800">Extended Checkout (3 Hours)</span>
                            <span class="text-[10px] text-slate-500 block">Need more time? Extend your checkout time.</span>
                            <span class="text-xs font-bold text-indigo-600">₹<?= number_format($lateCheckoutFee, 2) ?></span>
                        </div>
                        <button onclick="purchaseUpsell('Late Checkout Fee', <?= $lateCheckoutFee ?>)" class="py-1.5 px-3.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-bold rounded-xl text-xs transition">Extend</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- WIFI CREDENTIALS CARD -->
            <div class="card-premium p-5 space-y-3 relative overflow-hidden border-l-4 border-l-blue-500">
                <div class="flex items-center gap-2 text-blue-600">
                    <i class="ph ph-wifi-high text-xl"></i>
                    <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">Complimentary WiFi</h3>
                </div>
                <div class="grid grid-cols-2 gap-4 pt-2 text-xs">
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase block mb-0.5">Network (SSID)</span>
                        <span class="font-bold text-slate-800"><?= htmlspecialchars(defined('PROPERTY_WIFI_NAME') ? PROPERTY_WIFI_NAME : 'Hotel_Guest_WiFi') ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase block mb-0.5">Password</span>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="font-mono font-bold text-slate-800" id="wifi_pass_val"><?= htmlspecialchars(defined('PROPERTY_WIFI_PASS') ? PROPERTY_WIFI_PASS : 'Welcome2026') ?></span>
                            <button onclick="navigator.clipboard.writeText(document.getElementById('wifi_pass_val').textContent); showToast('WiFi password copied!');" class="p-1.5 bg-slate-100 text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition cursor-pointer">
                                <i class="ph ph-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STAY TIMELINE CARD -->
            <div class="card-premium p-5 space-y-4">
                <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest flex items-center gap-2"><i class="ph ph-calendar text-blue-600"></i> Stay Duration</h3>
                <div class="grid grid-cols-2 gap-4 text-xs">
                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 shadow-inner">
                        <span class="text-[9px] font-bold text-slate-500 uppercase block mb-1">Check-In</span>
                        <span class="font-bold text-slate-800 block text-sm"><?= date('d M Y', strtotime($booking['check_in'])) ?></span>
                        <span class="text-[10px] text-slate-500"><?= date('g:i A', strtotime($booking['check_in'])) ?></span>
                    </div>
                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 shadow-inner">
                        <span class="text-[9px] font-bold text-slate-500 uppercase block mb-1">Check-Out</span>
                        <span class="font-bold text-slate-800 block text-sm"><?= date('d M Y', strtotime($booking['check_out'])) ?></span>
                        <span class="text-[10px] text-slate-500"><?= date('g:i A', strtotime($booking['check_out'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- DIGITAL SIGNATURE SIGN-OFF -->
            <?php if (empty($booking['digital_signature'])): ?>
                <div class="bg-amber-50 rounded-2xl border border-amber-200 p-5 flex flex-col sm:flex-row items-center justify-between gap-4 shadow-sm">
                    <div class="space-y-1 text-center sm:text-left w-full sm:w-auto">
                        <span class="text-sm font-extrabold text-amber-900 block flex items-center justify-center sm:justify-start gap-1"><i class="ph ph-warning text-amber-600"></i> Signature Needed</span>
                        <p class="text-[10px] text-amber-700 font-medium">Please sign your check-in registration card.</p>
                    </div>
                    <button onclick="openSignatureModal()" class="w-full sm:w-auto shrink-0 px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xl text-xs transition-colors shadow-sm">
                        Sign Now
                    </button>
                </div>
            <?php else: ?>
                <div class="card-premium p-5 space-y-3">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest">Registration Card</h3>
                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <div class="p-2 border border-slate-200 rounded-lg bg-white shadow-sm">
                            <img src="<?= htmlspecialchars($booking['digital_signature']) ?>" class="h-10 object-contain">
                        </div>
                        <div class="text-xs text-slate-600">
                            <span class="font-bold text-emerald-600 block flex items-center gap-1"><i class="ph ph-check-circle-fill"></i> Verified</span>
                            Your digital signature is on file.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- GUEST RATING/FEEDBACK PANEL -->
            <?php if (!$hasReviewed && ($booking['booking_status'] === 'checked_in' || $booking['booking_status'] === 'checked_out')): ?>
                <div class="card-premium p-5 space-y-4 bg-gradient-to-br from-white to-blue-50">
                    <h3 class="text-xs font-bold text-blue-800 uppercase tracking-widest flex items-center gap-1.5"><i class="ph ph-star-fill text-amber-400"></i> Rate Your Stay</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="submit_review">
                        
                        <div>
                            <div class="flex items-center justify-center gap-3 p-3 bg-white rounded-xl border border-blue-100 shadow-sm">
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?> class="sr-only peer">
                                        <i class="ph-fill ph-star text-3xl text-slate-200 peer-checked:text-amber-400 hover:text-amber-300 transition-colors drop-shadow-sm"></i>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div>
                            <textarea name="comment" rows="2" placeholder="Tell us about your experience..." class="w-full p-3 bg-white border border-slate-200 rounded-xl text-xs text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition shadow-sm"></textarea>
                        </div>

                        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-md transition cursor-pointer">
                            Submit Feedback
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- PROPERTY CONTACT CARD -->
            <div class="p-5 bg-white rounded-2xl border border-slate-200 text-center space-y-3 shadow-sm">
                <div class="w-12 h-12 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-2">
                    <i class="ph ph-headset text-2xl"></i>
                </div>
                <h3 class="font-bold text-slate-800 text-sm">Need assistance?</h3>
                <p class="text-xs text-slate-500 px-4">Contact our front desk directly from your phone.</p>
                <a href="tel:<?= htmlspecialchars($booking['guest_phone'] ?: '9999999999') ?>" class="inline-flex items-center justify-center w-full gap-2 bg-slate-800 hover:bg-slate-700 text-white px-4 py-3 rounded-xl font-bold transition shadow cursor-pointer text-xs mt-2">
                    <i class="ph ph-phone text-sm"></i> Call Front Desk
                </a>
            </div>

        </div>

        <!-- ================= TAB: BILLING ================= -->
        <div id="tab-billing" class="tab-content space-y-6">
            
            <div class="card-premium p-5 space-y-5">
                <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest flex items-center gap-2"><i class="ph ph-wallet text-blue-600 text-lg"></i> Account Balance</h3>
                
                <div class="space-y-3">
                    <div class="flex justify-between text-sm font-semibold text-slate-600">
                        <span>Total Charges</span>
                        <span>₹<?= number_format($totalCharges, 2) ?></span>
                    </div>
                    <div class="flex justify-between text-sm font-semibold text-emerald-600">
                        <span>Payments Settled</span>
                        <span>- ₹<?= number_format($totalPayments, 2) ?></span>
                    </div>
                    
                    <div class="pt-4 border-t border-dashed border-slate-200">
                        <div class="flex justify-between items-center text-base font-black text-slate-800">
                            <span>Pending Due</span>
                            <span class="<?= $balance > 0.05 ? 'text-rose-600' : 'text-emerald-600' ?> font-mono text-xl">₹<?= number_format($balance, 2) ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($balance > 0.05): ?>
                    <?php if (count($activeGateways) > 1): ?>
                        <div class="space-y-2 mt-3">
                            <label class="block text-[9px] font-black text-slate-400 uppercase tracking-wider">Select Payment Method</label>
                            <div class="grid grid-cols-2 gap-3">
                                <?php if (isset($activeGateways['razorpay'])): ?>
                                    <button onclick="payWithGateway('razorpay', <?= $balance ?>)" class="py-3 px-4 border border-slate-200 bg-white hover:bg-slate-50 text-slate-800 font-extrabold rounded-xl text-xs flex flex-col items-center justify-center gap-1.5 transition">
                                        <i class="ph ph-credit-card text-lg text-indigo-600"></i> Razorpay (Cards/UPI)
                                    </button>
                                <?php endif; ?>
                                <?php if (isset($activeGateways['phonepe'])): ?>
                                    <button onclick="payWithGateway('phonepe', <?= $balance ?>)" class="py-3 px-4 border border-slate-200 bg-white hover:bg-slate-50 text-slate-800 font-extrabold rounded-xl text-xs flex flex-col items-center justify-center gap-1.5 transition">
                                        <i class="ph ph-lightning text-lg text-violet-600"></i> PhonePe (UPI/QR)
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php elseif (count($activeGateways) === 1): ?>
                        <?php 
                        $singleGateway = key($activeGateways); 
                        $btnColor = $singleGateway === 'phonepe' ? 'bg-violet-600 hover:bg-violet-500 shadow-violet-500/20' : 'bg-emerald-600 hover:bg-emerald-500 shadow-emerald-500/20';
                        ?>
                        <button onclick="payWithGateway('<?= $singleGateway ?>', <?= $balance ?>)" class="w-full py-3.5 <?= $btnColor ?> text-white font-black rounded-xl text-xs uppercase tracking-wider transition shadow-lg flex items-center justify-center gap-2 mt-2">
                            <i class="ph ph-credit-card text-lg"></i> Pay ₹<?= number_format($balance, 2) ?> Now
                        </button>
                    <?php else: ?>
                        <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-xl p-3 text-center font-bold mt-2">
                            ⚠️ Online payments are currently unavailable. Please contact the front desk.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Ledger Accordion Details -->
            <div class="card-premium overflow-hidden">
                <details class="group">
                    <summary class="font-bold text-sm text-slate-700 cursor-pointer list-none flex justify-between items-center p-5 bg-slate-50 hover:bg-slate-100 transition">
                        <span class="flex items-center gap-2"><i class="ph ph-list-numbers text-slate-400"></i> Itemized Invoice</span>
                        <span class="w-6 h-6 rounded-full bg-white flex items-center justify-center shadow-sm border border-slate-200 group-open:rotate-180 transition-transform"><i class="ph ph-caret-down text-xs"></i></span>
                    </summary>
                    <div class="p-5 space-y-3 bg-white border-t border-slate-100">
                        <?php if (empty($ledger)): ?>
                            <p class="text-center text-xs text-slate-400 italic">No charges recorded yet.</p>
                        <?php else: ?>
                            <?php foreach($ledger as $l): ?>
                                <div class="flex justify-between items-center text-sm py-1 border-b border-slate-50 last:border-0">
                                    <span class="text-slate-600 flex-1 pr-4"><?= htmlspecialchars($l['description']) ?></span>
                                    <span class="font-bold font-mono whitespace-nowrap <?= (float)$l['amount'] > 0 ? 'text-slate-800' : 'text-emerald-600' ?>">
                                        <?= (float)$l['amount'] > 0 ? '' : '-' ?>₹<?= number_format(abs((float)$l['amount']), 2) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </details>
            </div>

        </div>

        <!-- ================= TAB: SERVICES ================= -->
        <div id="tab-services" class="tab-content space-y-6">
            
            <?php if ($booking['booking_status'] === 'checked_in'): ?>
                
                <h2 class="text-sm font-black text-slate-800 tracking-tight">Express Services</h2>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <!-- 1. Cleaning Request -->
                    <?php if ($housekeepingEnabled): ?>
                        <form method="POST" class="h-full">
                            <input type="hidden" name="action" value="request_cleaning">
                            <button type="submit" class="w-full h-full flex flex-col items-center justify-center p-5 bg-white border border-slate-200 hover:border-blue-400 hover:shadow-md rounded-2xl shadow-sm group transition cursor-pointer text-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <i class="ph ph-broom text-2xl"></i>
                                </div>
                                <div>
                                    <span class="font-bold text-sm text-slate-800 block">Housekeeping</span>
                                    <p class="text-[10px] text-slate-500 mt-1">Request room cleaning</p>
                                </div>
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- 2. Late Checkout Upsell -->
                    <?php if ($upsellEnabled): ?>
                        <form method="POST" class="h-full">
                            <input type="hidden" name="action" value="upsell_late_checkout">
                            <button type="submit" onclick="return confirm('Apply Late Checkout option for a flat charge of ₹<?= $earlyLateFee ?>?')" class="w-full h-full flex flex-col items-center justify-center p-5 bg-white border border-slate-200 hover:border-amber-400 hover:shadow-md rounded-2xl shadow-sm group transition cursor-pointer text-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                                    <i class="ph ph-clock-counter-clockwise text-2xl"></i>
                                </div>
                                <div>
                                    <span class="font-bold text-sm text-slate-800 block">Late Checkout</span>
                                    <p class="text-[10px] text-slate-500 mt-1">Add 3 hours for ₹<?= number_format($earlyLateFee, 0) ?></p>
                                </div>
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- 3. Self Checkout -->
                    <?php if ($selfCheckoutEnabled): ?>
                        <form method="POST" class="h-full sm:col-span-2">
                            <input type="hidden" name="action" value="self_checkout">
                            <button type="submit" onclick="return confirm('Are you sure you want to checkout online?')" class="w-full flex items-center justify-between p-4 bg-white border border-slate-200 hover:border-rose-400 hover:shadow-md rounded-2xl shadow-sm group transition cursor-pointer">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center">
                                        <i class="ph ph-door-open text-xl"></i>
                                    </div>
                                    <div class="text-left">
                                        <span class="font-bold text-sm text-slate-800 block">Express Self-Checkout</span>
                                        <p class="text-[10px] text-slate-500">Leave key in room and checkout instantly</p>
                                    </div>
                                </div>
                                <i class="ph ph-caret-right text-slate-400 group-hover:translate-x-1 transition-transform"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- 4. Room Service Outlet Catalog ordering -->
                <div class="card-premium p-5 mt-6 space-y-4">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-widest flex items-center gap-2"><i class="ph ph-fork-knife text-blue-600 text-lg"></i> Room Service Menu</h3>
                    
                    <!-- Outlet Select (Pills) -->
                    <div id="guest-outlets-container" class="flex overflow-x-auto gap-2 pb-2 custom-scrollbar">
                        <!-- Loaded by JS -->
                    </div>

                    <!-- Products Catalogue list -->
                    <div id="guest-menu-container" class="grid grid-cols-2 gap-4 pt-2">
                        <div class="col-span-full py-8 text-center flex flex-col items-center justify-center text-slate-400 border-2 border-dashed border-slate-200 rounded-xl">
                            <i class="ph ph-storefront text-3xl mb-2 text-slate-300"></i>
                            <p class="text-xs font-medium">Select an outlet above to browse items.</p>
                        </div>
                    </div>

                    <!-- Cart Summary & Placement -->
                    <div id="guest-cart-panel" class="hidden pt-4 mt-2 border-t-2 border-dashed border-slate-200 space-y-4">
                        <h4 class="text-sm font-bold text-slate-800 flex items-center gap-2"><i class="ph ph-shopping-cart text-slate-400"></i> Your Order</h4>
                        <div id="guest-cart-items" class="space-y-2 max-h-[200px] overflow-y-auto pr-2 custom-scrollbar"></div>
                        <div class="flex justify-between items-center text-base font-black text-slate-800 pt-3 border-t border-slate-200">
                            <span>Total Bill</span>
                            <span id="guest-cart-total" class="font-mono text-blue-700">₹0.00</span>
                        </div>
                        <p class="text-[9px] text-center text-slate-500 uppercase tracking-wider">Charges will be added to your room folio</p>
                        <button onclick="placeGuestOrder()" class="w-full py-3.5 bg-slate-800 hover:bg-slate-700 text-white font-bold text-sm rounded-xl shadow transition cursor-pointer">
                            Place Order
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <div class="text-center py-10 px-4 bg-white rounded-2xl border border-slate-200 shadow-sm">
                    <i class="ph ph-door text-4xl text-slate-300 mb-3"></i>
                    <h3 class="text-sm font-bold text-slate-800">Services Unavailable</h3>
                    <p class="text-xs text-slate-500 mt-2">Room services are only available during an active stay (Checked In).</p>
                </div>
            <?php endif; ?>

        </div>

    </main>

    <!-- DIGITAL SIGNATURE MODAL -->
    <div id="signatureModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4 transition-opacity">
        <div class="card-premium w-full max-w-sm p-6 space-y-5 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-sm font-black text-slate-800 uppercase tracking-wider">Sign Registration</h3>
                <button onclick="closeSignatureModal()" class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 hover:text-slate-800 flex items-center justify-center font-bold cursor-pointer transition"><i class="ph ph-x"></i></button>
            </div>
            
            <p class="text-[11px] text-slate-500 font-medium">Please sign inside the box below to verify your check-in registration card.</p>
            
            <div class="bg-slate-50 p-1 border border-slate-200 rounded-xl">
                <canvas id="signatureCanvas" width="300" height="150" class="signature-canvas w-full rounded-lg bg-white"></canvas>
            </div>
            
            <form method="POST" id="signatureForm" class="flex gap-3 pt-2">
                <input type="hidden" name="action" value="sign_reg_card">
                <input type="hidden" name="signature_data" id="signatureInput">
                
                <button type="button" onclick="clearCanvas()" class="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition cursor-pointer">
                    Clear
                </button>
                <button type="submit" onclick="submitSignature(event)" class="flex-1 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow-sm transition cursor-pointer">
                    Save & Sign
                </button>
            </form>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="fixed bottom-4 right-4 max-w-sm w-full bg-white rounded-xl shadow-2xl pointer-events-none transform translate-y-full opacity-0 transition-all duration-300 z-50 overflow-hidden">
        <div class="p-4 flex items-start gap-3">
            <div id="toast-icon" class="flex-shrink-0 mt-0.5"></div>
            <div class="flex-1 min-w-0">
                <p id="toast-message" class="text-sm font-medium text-slate-800"></p>
            </div>
        </div>
        <div id="toast-progress" class="h-1 bg-indigo-500 w-full transform origin-left transition-transform duration-[3000ms] ease-linear"></div>
    </div>

    <!-- SCRIPTS -->
    <script>
        // --- Upsell Logic ---
        async function purchaseUpsell(description, amount) {
            if (!confirm(`Are you sure you want to add ${description} to your bill for ₹${amount}?`)) return;
            
            try {
                const res = await fetch('/api/guest/add_folio_charge', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        booking_id: '<?= htmlspecialchars($bookingId) ?>',
                        token: '<?= htmlspecialchars($token) ?>',
                        description: description,
                        amount: amount
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Successfully added to your bill!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message || 'Failed to add charge', 'error');
                }
            } catch (e) {
                showToast('Connection error', 'error');
            }
        }

        // --- Toast Logic ---
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toast-message');
            const toastIcon = document.getElementById('toast-icon');
            const toastProgress = document.getElementById('toast-progress');
            
            toastMessage.textContent = message;
            let iconHtml = '';
            let progressColor = '';
            if (type === 'success') {
                iconHtml = '<svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
                progressColor = 'bg-emerald-500';
            } else if (type === 'error') {
                iconHtml = '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
                progressColor = 'bg-red-500';
            } else {
                iconHtml = '<svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
                progressColor = 'bg-indigo-500';
            }
            
            toastIcon.innerHTML = iconHtml;
            toastProgress.className = `h-1 w-full transform origin-left transition-transform duration-[3000ms] ease-linear ${progressColor}`;
            
            toast.classList.remove('translate-y-full', 'opacity-0');
            toastProgress.style.transform = 'scaleX(1)';
            requestAnimationFrame(() => {
                toastProgress.style.transform = 'scaleX(0)';
            });
            setTimeout(() => {
                toast.classList.add('translate-y-full', 'opacity-0');
            }, 3000);
        }


        // --- Tabs Logic ---
        function switchTab(tabId) {
            // Update buttons
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.currentTarget.classList.add('active');
            
            // Update content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            
            // Smooth scroll to top of tabs
            document.getElementById('tabs-container').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // --- Signature Logic ---
        const canvas = document.getElementById('signatureCanvas');
        const ctx = canvas.getContext('2d');
        let drawing = false;

        // Mouse events
        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mousemove', draw);

        // Touch events for mobile support
        canvas.addEventListener('touchstart', startDrawing, {passive: false});
        canvas.addEventListener('touchend', stopDrawing);
        canvas.addEventListener('touchmove', draw, {passive: false});

        function getPosition(e) {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            
            return {
                x: (clientX - rect.left) * scaleX,
                y: (clientY - rect.top) * scaleY
            };
        }

        function startDrawing(e) {
            e.preventDefault();
            drawing = true;
            ctx.beginPath();
            const pos = getPosition(e);
            ctx.moveTo(pos.x, pos.y);
        }

        function stopDrawing(e) {
            if (e) e.preventDefault();
            drawing = false;
        }

        function draw(e) {
            if (!drawing) return;
            e.preventDefault();
            const pos = getPosition(e);
            ctx.lineTo(pos.x, pos.y);
            ctx.lineWidth = 3;
            ctx.lineCap = 'round';
            ctx.strokeStyle = '#1E293B';
            ctx.stroke();
        }

        function clearCanvas() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }

        function openSignatureModal() {
            document.getElementById('signatureModal').classList.remove('hidden');
            // small delay to fix canvas rendering bug on modal open
            setTimeout(() => {
                const rect = canvas.getBoundingClientRect();
                canvas.width = rect.width;
                canvas.height = rect.height;
                ctx.fillStyle = "white";
                ctx.fillRect(0,0, canvas.width, canvas.height);
            }, 10);
        }

        function closeSignatureModal() {
            document.getElementById('signatureModal').classList.add('hidden');
        }

        function submitSignature(e) {
            e.preventDefault();
            const dataURL = canvas.toDataURL('image/png');
            document.getElementById('signatureInput').value = dataURL;
            document.getElementById('signatureForm').submit();
        }

        // --- Payment Logic ---
        // --- Payment Logic ---
        async function payWithGateway(gateway, amount) {
            const bookingId = "<?= $bookingId ?>";
            const token = "<?= $token ?>";
            
            if (gateway === 'phonepe') {
                try {
                    const res = await fetch('/api/guest/create_phonepe_payment', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ booking_id: bookingId, token: token, amount: amount })
                    });
                    const data = await res.json();
                    if (data.success && data.redirect_url) {
                        showToast('Redirecting to PhonePe securely...');
                        window.location.href = data.redirect_url;
                    } else {
                        showToast('PhonePe Error: ' + (data.message || 'Failed to initiate payment.'));
                    }
                } catch (e) {
                    showToast('Connection failure to PhonePe gateway.');
                }
                return;
            }
            
            try {
                // 1. Create order on the backend
                const orderRes = await fetch('/api/guest/create_razorpay_order', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ booking_id: bookingId, token: token, amount: amount })
                });
                const orderData = await orderRes.json();
                
                if (!orderData.success) {
                    showToast('Error creating payment order: ' + orderData.message);
                    return;
                }
                
                // 2. Open Razorpay Checkout overlay
                const options = {
                    key: orderData.key_id,
                    amount: amount * 100,
                    currency: 'INR',
                    name: '<?= htmlspecialchars(addslashes($booking['property_name'])) ?>',
                    description: 'Clear Folio Balance Dues',
                    order_id: orderData.order_id,
                    handler: async function (response) {
                        // 3. Record payment upon success
                        try {
                            const recRes = await fetch('/api/guest/record_payment', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    booking_id: bookingId,
                                    token: token,
                                    amount: amount,
                                    payment_ref: response.razorpay_payment_id
                                })
                            });
                            const recData = await recRes.json();
                            if (recData.success) {
                                showToast('Payment successful! Your folio has been updated.');
                                location.reload();
                            } else {
                                showToast('Payment verification failed: ' + recData.message);
                            }
                        } catch (err) {
                            showToast('Network error verifying payment.');
                        }
                    },
                    prefill: {
                        name: '<?= htmlspecialchars(addslashes($booking['guest_name'])) ?>',
                        contact: '<?= htmlspecialchars(addslashes($booking['guest_phone'] ?? '')) ?>'
                    },
                    theme: {
                        color: '#1E3A8A' // Updated to match brand
                    }
                };
                
                const rzp = new Razorpay(options);
                rzp.open();
            } catch (err) {
                showToast('Connection failure generating payment order.');
            }
        }

        // --- POS Catalog Logic ---
        let guestMenuData = { outlets: [], items: [] };
        let guestCart = [];

        async function initGuestShopPOS() {
            const bookingId = "<?= $bookingId ?>";
            const token = "<?= $token ?>";
            try {
                const res = await fetch(`/api/guest/pos_menu?id=${bookingId}&token=${token}&_t=${Date.now()}`);
                const data = await res.json();
                if (data.success) {
                    guestMenuData = data;
                    const container = document.getElementById('guest-outlets-container');
                    let html = `<button onclick="selectGuestOutlet('0')" id="outlet-btn-0" class="outlet-btn px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap bg-slate-800 text-white shadow-md transition cursor-pointer">General Shop</button>`;
                    
                    data.outlets.forEach(o => {
                        html += `<button onclick="selectGuestOutlet('${o.id}')" id="outlet-btn-${o.id}" class="outlet-btn px-4 py-2 rounded-xl text-xs font-bold whitespace-nowrap bg-slate-100 text-slate-600 hover:bg-slate-200 transition cursor-pointer">${o.name}</button>`;
                    });
                    container.innerHTML = html;
                    
                    selectGuestOutlet('0');
                }
            } catch(e) {
                console.error("Failed to load POS outlets", e);
            }
        }

        let currentGuestOutlet = "0";

        function selectGuestOutlet(outletId) {
            currentGuestOutlet = outletId;
            document.querySelectorAll('.outlet-btn').forEach(btn => {
                if (btn.id === `outlet-btn-${outletId}`) {
                    btn.classList.remove('bg-slate-100', 'text-slate-600');
                    btn.classList.add('bg-slate-800', 'text-white', 'shadow-md');
                } else {
                    btn.classList.remove('bg-slate-800', 'text-white', 'shadow-md');
                    btn.classList.add('bg-slate-100', 'text-slate-600');
                }
            });
            loadGuestMenu();
        }

        function loadGuestMenu() {
            const container = document.getElementById('guest-menu-container');

            const items = guestMenuData.items.filter(i => {
                if (currentGuestOutlet === "0") return !i.outlet_id;
                return i.outlet_id == currentGuestOutlet;
            });

            if (items.length === 0) {
                container.innerHTML = '<p class="col-span-full text-center text-[11px] text-slate-500 py-6 bg-slate-50 rounded-xl border border-slate-100">No products available in this shop right now.</p>';
                return;
            }

            container.innerHTML = items.map(item => {
                const img = item.image_url ? `<img src="${item.image_url}" class="w-full h-24 object-cover rounded-xl mb-3 shadow-sm">` : `<div class="w-full h-24 bg-slate-100 rounded-xl flex items-center justify-center mb-3 shadow-sm border border-slate-200"><i class="ph ph-package text-2xl text-slate-300"></i></div>`;
                return `
                    <div class="bg-white border border-slate-200 rounded-2xl p-3 flex flex-col justify-between hover:border-blue-300 hover:shadow-md transition">
                        <div>
                            ${img}
                            <span class="text-xs font-black text-slate-800 block leading-tight mb-1">${item.name}</span>
                            <span class="text-xs font-extrabold text-blue-600 block">₹${parseFloat(item.selling_price).toFixed(2)}</span>
                        </div>
                        <button onclick="addGuestCart(${item.id})" class="mt-3 w-full py-1.5 bg-blue-50 text-blue-700 hover:bg-blue-600 hover:text-white border border-blue-100 rounded-lg text-[10px] font-bold transition">
                            Add to Order
                        </button>
                    </div>
                `;
            }).join('');
        }

        function addGuestCart(itemId) {
            const item = guestMenuData.items.find(i => i.id === itemId);
            if (!item) return;

            const existing = guestCart.find(c => c.id === itemId);
            if (existing) {
                if (existing.quantity >= item.stock_qty) {
                    showToast('Limit exceeded stock level');
                    return;
                }
                existing.quantity++;
            } else {
                guestCart.push({ ...item, quantity: 1 });
            }
            renderGuestCart();
        }

        function changeGuestCartQty(itemId, val) {
            const item = guestCart.find(c => c.id === itemId);
            if (!item) return;

            item.quantity += val;
            if (item.quantity <= 0) {
                guestCart = guestCart.filter(c => c.id !== itemId);
            } else {
                const dbItem = guestMenuData.items.find(i => i.id === itemId);
                if (dbItem && item.quantity > dbItem.stock_qty) {
                    showToast('Limit exceeded stock level');
                    item.quantity = dbItem.stock_qty;
                }
            }
            renderGuestCart();
        }

        function renderGuestCart() {
            const panel = document.getElementById('guest-cart-panel');
            const itemsCont = document.getElementById('guest-cart-items');
            const totalEl = document.getElementById('guest-cart-total');

            if (guestCart.length === 0) {
                panel.classList.add('hidden');
                return;
            }

            panel.classList.remove('hidden');

            let total = 0;
            itemsCont.innerHTML = guestCart.map(c => {
                const lineTotal = c.quantity * parseFloat(c.selling_price);
                total += lineTotal;
                return `
                    <div class="flex items-center justify-between text-xs py-2 border-b border-slate-50 last:border-0">
                        <div class="flex flex-col">
                            <span class="font-bold text-slate-700">${c.name}</span>
                            <span class="text-[10px] text-slate-500">₹${parseFloat(c.selling_price).toFixed(2)} x ${c.quantity}</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-slate-800 font-mono w-12 text-right">₹${lineTotal.toFixed(2)}</span>
                            <div class="flex items-center gap-1 bg-slate-100 rounded-lg p-0.5 border border-slate-200">
                                <button onclick="changeGuestCartQty(${c.id}, -1)" class="w-6 h-6 flex items-center justify-center rounded-md text-slate-500 hover:bg-white hover:shadow-sm transition"><i class="ph ph-minus"></i></button>
                                <span class="w-4 text-center font-bold text-slate-800 text-[10px]">${c.quantity}</span>
                                <button onclick="changeGuestCartQty(${c.id}, 1)" class="w-6 h-6 flex items-center justify-center rounded-md text-slate-500 hover:bg-white hover:shadow-sm transition"><i class="ph ph-plus"></i></button>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            totalEl.innerText = `₹${total.toFixed(2)}`;
        }

        async function placeGuestOrder() {
            if (guestCart.length === 0) return;
            const outletId = parseInt(currentGuestOutlet) || 0;

            if(!confirm('Place this order and charge it to your room?')) return;

            const payload = {
                booking_id: parseInt("<?= $bookingId ?>"),
                token: "<?= $token ?>",
                outlet_id: outletId,
                items: guestCart.map(c => ({ id: c.id, quantity: c.quantity }))
            };

            try {
                const res = await fetch('/api/guest/pos_order', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Order placed successfully! It has been charged to your room.');
                    guestCart = [];
                    renderGuestCart();
                    loadGuestMenu(); // refresh stock
                } else {
                    showToast('Order failed: ' + data.message);
                }
            } catch(e) {
                showToast('Connection error while placing order.');
            }
        }

        // Boot
        <?php if ($booking['booking_status'] === 'checked_in'): ?>
            initGuestShopPOS();
        <?php endif; ?>
    </script>
    <?php endif; ?>
</body>
</html>
