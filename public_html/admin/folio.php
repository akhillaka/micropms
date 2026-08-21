<?php
declare(strict_types=1);
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/ErrorPage.php';
AuthHelper::requireLoginOrRedirect();
if (!AuthHelper::can('view_folio')) {
    header('Location: /admin');
    exit;
}
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/GuestAccessToken.php';
require_once __DIR__ . '/../../pms_core/services/StayPolicy.php';
$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfToken::requireValid();
    $data = CsrfToken::getJsonPayload();
    if (($data['action'] ?? '') === 'update_folio_id') {
        header('Content-Type: application/json');
        if (!AuthHelper::can('edit_folio')) {
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }
        try {
            $newFolioId = trim($data['offline_folio_id'] ?? '');
            $bookingId = (int)($data['booking_id'] ?? 0);
            if ($bookingId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
                exit;
            }
            
            $propId = AuthHelper::getPropertyId();
            $stmt = $db->prepare("UPDATE bookings SET offline_folio_id = ? WHERE id = ? AND property_id = ?");
            $stmt->execute([$newFolioId, $bookingId, $propId]);
            
            echo json_encode(['success' => true, 'message' => 'Folio ID updated successfully']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') render_error_page('Missing Booking ID', 'A booking ID is required to view the folio.', 400);

require_once __DIR__ . '/../../pms_core/config.php';
$activePropId = AuthHelper::getPropertyId();
$booking = find_property_booking($db, (int)$activePropId, $id);

if (!$booking) render_error_page('Booking Not Found', 'The requested booking does not exist or has been deleted.', 404);

$publicId = booking_public_id($booking);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $publicId !== '' && $publicId !== $id) {
    header('Location: /admin/folio?id=' . rawurlencode($publicId), true, 302);
    exit;
}

$bookingPk = (int)$booking['id'];
$id = $bookingPk;

$propertyId = (int)$booking['property_id'];
$paymentMethods = get_payment_methods($db, $propertyId);
$activeGateways = get_active_payment_gateways($db, $propertyId);
$stayPolicy = StayPolicy::ui($booking);
$stayEditable = $stayPolicy['stay_open'];
$checkInLocked = !$stayPolicy['check_in'];
$checkOutLocked = !$stayPolicy['check_out'];

$paymentCategories = get_payment_categories($db, $propertyId);

$ledgerStmt = $db->prepare("SELECT * FROM folio_ledger WHERE booking_id = :id ORDER BY recorded_at ASC");
$ledgerStmt->execute(['id' => $bookingPk]);
$ledger = $ledgerStmt->fetchAll();
foreach ($ledger as &$ledgerRow) {
    $ledgerRow['amount'] = money_float($ledgerRow['amount'] ?? 0);
}
unset($ledgerRow);

$taxEnabled = defined('TAX_ENABLED') && TAX_ENABLED === 'true';
$taxRate = defined('TAX_RATE') ? (float)TAX_RATE : 0.0;
$taxLabel = defined('TAX_LABEL') ? TAX_LABEL : 'Tax';

$subtotalCharges = 0;
$totalPayments = 0;
$refundsIssued = 0;

foreach($ledger as $l) {
    $val = money_float($l['amount']);
    $isRefund = ((int)($l['is_refund'] ?? 0) === 1)
        || str_contains(strtolower((string)($l['description'] ?? '')), 'refund');
    if ($isRefund) {
        $refundsIssued += abs($val);
    } elseif ($val > 0) {
        $subtotalCharges += $val;
    } else {
        $totalPayments += abs($val);
    }
}
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
    } elseif ($taxPref === 'exempt') {
        $taxAmount = 0.0;
        $totalCharges = $subtotalCharges;
    }
}

$balance = $totalCharges - $totalPayments + $refundsIssued;

// Fetch all rooms for dropdown
$roomsStmt = $db->prepare("SELECT r.*, c.name as cat_name FROM rooms r JOIN room_categories c ON r.category_id = c.id WHERE r.property_id = :pid ORDER BY r.room_number");
$roomsStmt->execute(['pid' => $propertyId]);
$allRooms = $roomsStmt->fetchAll();

// Fetch all distinct rate plans
$ratePlansStmt = $db->prepare("SELECT DISTINCT category_id, rate_plan_name FROM sliding_rates WHERE property_id = :pid");
$ratePlansStmt->execute(['pid' => $propertyId]);
$ratePlansRaw = $ratePlansStmt->fetchAll();
$catRatePlans = [];
foreach($ratePlansRaw as $rp) {
    $catRatePlans[$rp['category_id']][] = $rp['rate_plan_name'] ?: 'Base Rate';
}

// Calculate duration
$inDt = new DateTime($booking['check_in']);
$outDt = new DateTime($booking['check_out']);
$diff = $inDt->diff($outDt);
$nights = $diff->days ?: 1;

$hours = ($diff->days * 24) + $diff->h;
if ($diff->i > 0) $hours++;
$durationStr = $hours . " Hours";
if ($hours >= 24) {
    $days = floor($hours / 24);
    $remHours = $hours % 24;
    $durationStr = $days . " Day" . ($days > 1 ? "s" : "");
    if ($remHours > 0) $durationStr .= " " . $remHours . " Hour" . ($remHours > 1 ? "s" : "");
}

require_once __DIR__ . '/../../pms_core/PricingEngine.php';
$ratePlanName = $booking['rate_plan_name'] ?? null;
if (!$ratePlanName) $ratePlanName = 'Base Rate';

// Get booking status from database
$bookingStatus = $booking['booking_status'] ?? 'booked';
$statusMap = [
    'booked' => ['label' => 'Booked', 'color' => 'bg-amber-100 text-amber-800 border-amber-200'],
    'checked_in' => ['label' => 'Checked In', 'color' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
    'checked_out' => ['label' => 'Checked Out', 'color' => 'bg-slate-100 text-slate-800 border-slate-200'],
    'cancelled' => ['label' => 'Cancelled', 'color' => 'bg-rose-100 text-rose-800 border-rose-200']
];
$status = $statusMap[$bookingStatus]['label'];
$statusColor = $statusMap[$bookingStatus]['color'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Booking #<?= htmlspecialchars((string)($booking['display_id'] ?? $id), ENT_QUOTES, 'UTF-8') ?> | MicroPMS</title>
    
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <link rel="stylesheet" href="../css/style.css">
    
    <style>
        .stayflexi-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: 800;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid #E2E8F0;
            background: #FFF;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            min-height: 40px;
            transition: transform 0.2s cubic-bezier(0.16,1,0.3,1), box-shadow 0.2s ease, background 0.2s ease;
        }
        .pill-btn:hover {
            background: #FFF;
            border-color: #BFDBFE;
            color: #1E293B;
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(37,99,235,0.12);
        }
        .pill-btn-primary {
            background: linear-gradient(135deg, #2563EB, #1E3A8A);
            border-color: transparent;
            color: #FFFFFF;
            box-shadow: 0 8px 18px rgba(37,99,235,0.35);
        }
        .pill-btn-primary:hover {
            filter: brightness(1.05);
            color: #FFFFFF;
        }
        
        .tab-item {
            padding: 10px 4px;
            font-size: 12px;
            font-weight: 700;
            color: #94A3B8;
            border-bottom: 2px solid transparent;
            transition: all 0.15s ease;
        }
        .tab-item:hover {
            color: #64748B;
        }
        .tab-item.active {
            color: #4F46E5;
            border-bottom-color: #4F46E5;
        }
        
        .folio-grid-col {
            padding-bottom: 12px;
        }
    </style>
</head>
<body class="bg-slate-50/50 flex flex-col min-h-screen">
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto pb-24 md:pb-6">
        
        <!-- App Bar / Navigation -->
        <header class="bg-white px-6 py-4 flex items-center justify-between border-b border-slate-100 sticky top-0 z-50 shadow-sm mb-6">
            <div class="flex items-center gap-3">
                <a href="/admin" class="p-2 -ml-2 rounded-full hover:bg-slate-100 transition-colors"><i class="ph ph-caret-left text-2xl text-slate-700"></i></a>
                <div>
                    <h1 class="text-base font-bold text-slate-900 leading-none">MicroPMS Folio</h1>
                    <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mt-1 inline-block">Room Operations</span>
                </div>
            </div>
            <?php include __DIR__ . '/components/desktop_nav.php'; ?>
        </header>

        <main class="px-6 space-y-6">
            
            <!-- StayFlexi Top Header Block -->
            <div class="bg-white border border-slate-100 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row justify-between gap-6">
                <div class="space-y-4 flex-1">
                    <!-- Guest Title & Status Row -->
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight font-display"><?= htmlspecialchars((string)($booking['guest_name'])) ?></h2>
                        <span class="text-[10px] font-bold text-slate-500 border border-slate-200 px-2 py-0.5 rounded-md">Booking ID: <?= htmlspecialchars((string)($booking['display_id'] ?? $id)) ?></span>
                        <span class="text-[10px] font-bold text-slate-500 border border-slate-200 px-2 py-0.5 rounded-md inline-flex items-center gap-1">
                            Folio ID: <span id="offline_folio_id_display"><?= htmlspecialchars((string)($booking['offline_folio_id'] ?? 'N/A')) ?></span>
                            <button onclick="editOfflineFolioId(<?= htmlspecialchars((string)($id), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars((string)($booking['offline_folio_id'] ?? '')) ?>')" class="text-indigo-600 hover:text-indigo-900 ml-1" title="Edit Folio ID">
                                <i class="ph ph-pencil-simple text-xs"></i>
                            </button>
                        </span>
                        <span class="stayflexi-badge <?= htmlspecialchars((string)($statusColor), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($status), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php 
                        $bSource = $booking['booking_source'] ?? 'Walk-in';
                        $sourceBg = 'bg-brand-900 text-white';
                        if (in_array(strtolower($bSource), ['goibibo', 'makemytrip', 'booking.com', 'agoda', 'hotelzify'])) {
                            $sourceBg = 'bg-brand-900 text-white';
                        } elseif (strtolower($bSource) === 'whatsapp') {
                            $sourceBg = 'bg-emerald-600 text-white';
                        }
                        ?>
                        <span class="stayflexi-badge <?= htmlspecialchars((string)($sourceBg), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($bSource)) ?></span>
                    </div>
                    
                    <!-- Metadata Metrics Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-y-4 gap-x-2 pt-2 text-xs border-t border-slate-100">
                        <div class="folio-grid-col">
                            <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Booked</span>
                            <span class="font-bold text-slate-800 mt-1 block"><?= htmlspecialchars((string)(date('d M Y, g:i A', strtotime($booking['created_at'] ?? $booking['check_in']))), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="folio-grid-col col-span-2">
                            <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Stay <i class="ph ph-clock text-[10px] inline ml-0.5 text-slate-400"></i></span>
                            <span class="font-bold text-slate-800 mt-1 block text-[11px] leading-relaxed">
                                <span class="text-emerald-600">→</span> <?= htmlspecialchars((string)(date('d M Y g:i A', strtotime($booking['check_in']))), ENT_QUOTES, 'UTF-8') ?><br>
                                <span class="text-rose-500">→</span> <?= htmlspecialchars((string)(date('d M Y g:i A', strtotime($booking['check_out']))), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="folio-grid-col">
                            <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Rooms</span>
                            <span class="font-bold text-slate-800 mt-1 block">1 Room</span>
                        </div>
                        <div class="folio-grid-col">
                            <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Duration</span>
                            <span class="font-bold text-slate-800 mt-1 block"><?= htmlspecialchars((string)($durationStr), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="folio-grid-col">
                            <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Rate Plan</span>
                            <span class="font-bold text-indigo-600 mt-1 block cursor-pointer hover:underline text-xs"><?= htmlspecialchars((string)($ratePlanName)) ?></span>
                        </div>
                    </div>
                    
                    <!-- Stay Progress Timeline -->
                    <?php
                    $now = new DateTime();
                    $stayIn  = new DateTime($booking['check_in']);
                    $stayOut = new DateTime($booking['check_out']);
                    $totalSecs = max(1, $stayOut->getTimestamp() - $stayIn->getTimestamp());
                    $elapsedSecs = max(0, min($totalSecs, $now->getTimestamp() - $stayIn->getTimestamp()));
                    $progressPct = round(($elapsedSecs / $totalSecs) * 100);
                    $timeLeft = $stayOut->getTimestamp() - time();
                    $timeLeftStr = $timeLeft > 0 ? gmdate('H\h i\m', min($timeLeft, 86400*3)) . ' left' : 'Overdue';
                    ?>
                    <?php if($bookingStatus === 'checked_in'): ?>
                    <div class="pt-3">
                        <div class="flex justify-between text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">
                            <span>Check-in</span>
                            <span class="<?= htmlspecialchars((string)($timeLeft < 3600 ? 'text-rose-500' : 'text-slate-400'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($timeLeftStr), ENT_QUOTES, 'UTF-8') ?></span>
                            <span>Check-out</span>
                        </div>
                        <div class="timeline-track">
                            <div class="timeline-progress" style="width:<?= htmlspecialchars((string)($progressPct), ENT_QUOTES, 'UTF-8') ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Room Type Row -->
                    <div class="pt-2 text-xs flex items-center gap-1.5">
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Room Type:</span>
                        <span class="font-bold text-slate-700 bg-slate-100 px-2.5 py-0.5 rounded-lg border border-slate-200/50"><?= htmlspecialchars((string)($booking['category_name'])) ?> (Room <?= htmlspecialchars((string)($booking['room_number'])) ?>)</span>
                    </div>
                </div>

                <!-- Right Top Stats (Financial summary) -->
                <?php
                if ($balance <= 0) {
                    $balanceColor = 'text-emerald-600';
                    $balanceBg    = 'bg-emerald-50 border border-emerald-100 rounded-xl px-3 py-1';
                } elseif ($balance <= $totalCharges * 0.5) {
                    $balanceColor = 'text-amber-600';
                    $balanceBg    = 'bg-amber-50 border border-amber-100 rounded-xl px-3 py-1';
                } else {
                    $balanceColor = 'text-rose-600';
                    $balanceBg    = 'bg-rose-50 border border-rose-100 rounded-xl px-3 py-1';
                }
                ?>
                <div class="flex flex-col justify-between items-end text-right border-l border-slate-100 pl-6 min-w-[200px]">
                    <div class="grid grid-cols-3 gap-4 w-full">
                        <div>
                            <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Total</span>
                            <span class="font-extrabold text-slate-800 text-sm mt-1 block">₹<?= htmlspecialchars(format_inr($totalCharges), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div>
                            <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Due</span>
                            <span class="font-extrabold <?= htmlspecialchars((string)($balanceColor), ENT_QUOTES, 'UTF-8') ?> text-sm mt-1 block <?= htmlspecialchars((string)($balance <= 0 ? 'line-through opacity-60' : ''), ENT_QUOTES, 'UTF-8') ?>">₹<?= htmlspecialchars(format_inr(abs($balance)), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if($balance <= 0): ?>
                            <span class="text-[8px] font-bold text-emerald-600 uppercase">Settled</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span class="block text-[9px] font-bold text-slate-500 uppercase tracking-wider">Payments</span>
                            <span class="font-extrabold text-emerald-600 text-sm mt-1 block">₹<?= htmlspecialchars(format_inr($totalPayments), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    
                    <?php if($bookingStatus === 'booked'): ?>
                    <button onclick="showStatusModal('check_in')" class="mt-4 inline-flex items-center gap-1.5 px-4.5 py-2 rounded-xl border border-emerald-200 text-emerald-700 bg-emerald-50/50 hover:bg-emerald-50 font-bold text-xs active:scale-[0.98] transition-all shadow-sm">
                        Check-in <i class="ph ph-sign-in text-sm"></i>
                    </button>
                    <?php elseif($bookingStatus === 'checked_in'): ?>
                    <button onclick="checkout(this)" class="mt-4 inline-flex items-center gap-1.5 px-4.5 py-2 rounded-xl border border-rose-200 text-rose-700 bg-rose-50/50 hover:bg-rose-50 font-bold text-xs active:scale-[0.98] transition-all shadow-sm">
                        Check-out <i class="ph ph-sign-out text-sm"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- StayFlexi Action Buttons Row -->
            <div class="flex flex-wrap items-center gap-2">
                <button onclick="UI.showModal('edit-guest-modal')" class="pill-btn"><i class="ph ph-user text-sm"></i> Edit Profile</button>
                <button onclick="UI.showModal('collect-payment-modal')" class="pill-btn pill-btn-primary"><i class="ph ph-credit-card text-sm"></i> Collect Payment</button>
                <button onclick="UI.showModal('whatsapp-triggers-modal')" class="pill-btn text-emerald-700 border-emerald-100 hover:bg-emerald-50/50">
                    <i class="ph ph-whatsapp-logo text-sm"></i> Send WhatsApp
                </button>
                <?php if($bookingStatus === 'booked'): ?>
                    <button onclick="showStatusModal('cancel')" class="pill-btn text-rose-600 border-rose-100 hover:bg-rose-50/50"><i class="ph ph-x-circle text-sm"></i> Cancel Booking</button>
                    <button onclick="showStatusModal('check_in')" class="pill-btn"><i class="ph ph-sign-in text-sm"></i> Check In</button>
                <?php elseif($bookingStatus === 'checked_in'): ?>
                    <button onclick="showStatusModal('rollback_to_booked')" class="pill-btn"><i class="ph ph-arrow-counter-clockwise text-sm"></i> Rollback to Booked</button>
                <?php elseif($bookingStatus === 'checked_out'): ?>
                    <button onclick="showStatusModal('rollback_to_checked_in')" class="pill-btn"><i class="ph ph-arrow-counter-clockwise text-sm"></i> Rollback to In-House</button>
                <?php endif; ?>
                
                <button onclick="triggerWhatsAppAutomation('guest_invoice', this)" class="pill-btn text-emerald-700 border-emerald-100 hover:bg-emerald-50/50">
                    <i class="ph ph-whatsapp-logo text-sm"></i> Send Invoice
                </button>
                <?php
                $secureToken = GuestAccessToken::generateForBooking((int)$id, (int)$propertyId);
                $proto = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
                $guestPortalUrl = "{$proto}://{$host}/guest-portal?id={$id}&token={$secureToken}";
                ?>
                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars((string)($guestPortalUrl), ENT_QUOTES, 'UTF-8') ?>'); alert('Copied Guest Portal Link!');" class="pill-btn text-indigo-700 border-indigo-100 hover:bg-indigo-50/50">
                    <i class="ph ph-share-network text-sm"></i> Share Guest Link
                </button>
                <a href="/admin/invoice?id=<?= htmlspecialchars((string)($id), ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="pill-btn pill-btn-primary">
                    <i class="ph ph-printer text-sm"></i> Print Invoice
                </a>
            </div>

            <!-- Tab Menu -->
            <div class="border-b border-slate-200 flex gap-6">
                <button onclick="switchTab('summary')" id="tab-summary-btn" class="tab-item active flex items-center gap-1"><i class="ph ph-list-bullets text-base"></i> Summary</button>
                <button onclick="switchTab('modify')" id="tab-modify-btn" class="tab-item flex items-center gap-1"><i class="ph ph-gear text-base"></i> Modify Stay</button>
                <button onclick="switchTab('guest')" id="tab-guest-btn" class="tab-item flex items-center gap-1"><i class="ph ph-user-focus text-base"></i> Guest Details & Docs</button>
                <button onclick="switchTab('audit')" id="tab-audit-btn" class="tab-item flex items-center gap-1"><i class="ph ph-clock-counter-clockwise text-base"></i> Audit Log</button>
            </div>

            <!-- Summary Tab Panel -->
            <div id="panel-summary" class="space-y-6">
                
                <!-- Quick Charge Presets -->
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm px-4 py-3">
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-wider mb-2">Quick Charges</p>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <?php
                        $quickCharges = get_folio_quick_charges($db, (int)$activePropId);
                        foreach($quickCharges as $qc):
                        ?>
                        <button class="charge-pill" title="<?= htmlspecialchars((string)($qc['desc'] ?? '')) ?>" onclick="prefillCharge('<?= htmlspecialchars((string)($qc['name'])) ?>', <?= htmlspecialchars((string)($qc['amount']), ENT_QUOTES, 'UTF-8') ?>)">
                            <i class="ph <?= htmlspecialchars((string)($qc['icon'] ?? 'ph-receipt')) ?>"></i> <?= htmlspecialchars((string)($qc['name'])) ?> <span class="text-slate-400 font-normal text-[10px]">₹<?= htmlspecialchars((string)($qc['amount']), ENT_QUOTES, 'UTF-8') ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <!-- Post Incidental Charge Bar -->
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="flex-1">
                            <input type="text" id="incidental_name" placeholder="Incidental description (e.g. Breakfast, Laundry)" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs font-semibold outline-none focus:ring-0 focus:shadow-minimal transition-all">
                        </div>
                        <div class="w-full sm:w-48">
                            <select id="incidental_category" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs font-semibold outline-none focus:ring-0 focus:shadow-minimal transition-all">
                                <?php foreach ($paymentCategories as $pc): ?>
                                <option value="<?= htmlspecialchars((string)$pc) ?>"><?= htmlspecialchars((string)$pc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="w-full sm:w-40">
                            <input type="number" id="incidental_amount" placeholder="Amount (₹)" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs font-semibold outline-none focus:ring-0 focus:shadow-minimal transition-all">
                        </div>
                        <button onclick="postCharge(this)" class="bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs uppercase tracking-wider py-3 px-6 rounded-xl transition-all shadow-sm flex items-center gap-2">
                            <i class="ph ph-plus-circle"></i> Post Charge
                        </button>
                    </div>
                </div>

                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden">
                    <div class="bg-slate-50/50 px-5 py-3 border-b border-slate-100 flex items-center justify-between">
                        <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider">Summary Details</h3>
                        <button onclick="UI.showModal('collect-payment-modal')" class="text-xs font-bold text-indigo-600 hover:underline">Post Transaction</button>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="table-brutal w-full">
                            <thead>
                                <tr>
                                    <th class="px-5 py-3 text-left">#</th>
                                    <th class="px-5 py-3 text-left">Date</th>
                                    <th class="px-5 py-3 text-left">Room</th>
                                    <th class="px-5 py-3 text-left">Description</th>
                                    <th class="px-5 py-3 text-left">Category</th>
                                    <th class="px-5 py-3 text-left">Type</th>
                                    <th class="px-5 py-3 text-left">Method</th>
                                    <th class="px-5 py-3 text-left">Transaction ID</th>
                                    <th class="px-5 py-3 text-right">Net</th>
                                    <th class="px-5 py-3 text-right">Tax</th>
                                    <th class="px-5 py-3 text-right">Gross</th>
                                    <th class="px-5 py-3 text-right">Balance</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="folio-ledger-body">
                                <?php if (empty($ledger)): ?>
                                <tr>
                                    <td colspan="13" class="px-5 py-12 text-center text-slate-500">
                                        <div class="pms-empty-state">
                                            <i class="ph ph-notebook"></i>
                                            <p>No folio charges yet. Post a transaction to start the ledger.</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php 
                                $runningBalance = 0;
                                $srNo = 1;
                                foreach($ledger as $l): 
                                    $lineAmt = money_float($l['amount']);
                                    $isDebit = $lineAmt > 0;
                                    $typeLabel = $isDebit ? 'DEBIT' : 'CREDIT';
                                    $typeColor = $isDebit ? 'text-rose-600 bg-rose-50' : 'text-emerald-600 bg-emerald-50';
                                    $runningBalance += $lineAmt;
                                    $runBalColor = $runningBalance > 0 ? 'text-rose-600' : 'text-emerald-600';
                                    
                                    // Formatting the Category
                                    $rawCat = strtolower(trim($l['category'] ?? ''));
                                    if (empty($rawCat) && $l['transaction_type'] === 'ROOM_CHARGE') {
                                        $rawCat = 'booking';
                                    }
                                    if (empty($rawCat) && stripos($l['description'], 'Room') !== false) {
                                        $rawCat = 'booking';
                                    }
                                    
                                    if ($rawCat === 'booking' || $rawCat === 'room') {
                                        $displayCat = $isDebit ? 'Room Booking Due' : 'Room Received Payment';
                                    } elseif ($rawCat === 'f&b') {
                                        $displayCat = $isDebit ? 'F&B Due' : 'F&B Payments';
                                    } elseif ($rawCat === 'laundry') {
                                        $displayCat = $isDebit ? 'Laundry Due' : 'Laundry Payments';
                                    } elseif ($rawCat === 'misc') {
                                        $displayCat = $isDebit ? 'Misc Due' : 'Misc Payments';
                                    } elseif ($rawCat === 'pos') {
                                        $displayCat = $isDebit ? 'POS Due' : 'POS Payments';
                                    } else {
                                        $displayCat = $rawCat ? (ucfirst($rawCat) . ($isDebit ? ' Due' : ' Payments')) : '-';
                                    }
                                ?>
                                <tr class="hover:bg-slate-50/50 transition-colors" data-display-id="<?= htmlspecialchars((string)($l['display_id'] ?? '')) ?>" data-category="<?= htmlspecialchars((string)($l['category'] ?? '')) ?>" data-amount="<?= htmlspecialchars((string)(abs($lineAmt)), ENT_QUOTES, 'UTF-8') ?>">
                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-slate-500 font-bold"><?= htmlspecialchars((string)($srNo++), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-5 py-3 whitespace-nowrap text-xs text-slate-500 font-semibold"><?= htmlspecialchars((string)(date('d M Y g:i A', strtotime($l['recorded_at']))), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-5 py-3 whitespace-nowrap text-xs font-bold text-slate-700">Room <?= htmlspecialchars((string)($booking['room_number'])) ?></td>
                                    <td class="px-5 py-3 text-xs font-bold text-slate-800"><?= htmlspecialchars((string)($l['description'])) ?></td>
                                    <td class="px-5 py-3 text-xs font-bold text-slate-500 uppercase"><?= htmlspecialchars((string)($displayCat)) ?></td>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-0.5 rounded text-[9px] font-bold <?= htmlspecialchars((string)($typeColor), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($typeLabel), ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-xs font-semibold text-slate-500">
                                        <?= htmlspecialchars((string)($l['payment_method'] ?? '-')) ?>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-xs font-semibold text-slate-500">
                                        <?= htmlspecialchars((string)($l['transaction_ref'] ?? '—')) ?>
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-right text-xs font-semibold text-slate-700">₹<?= htmlspecialchars(format_inr(abs($lineAmt)), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-5 py-3 whitespace-nowrap text-right text-xs font-semibold text-slate-400">—</td>
                                    <td class="px-5 py-3 whitespace-nowrap text-right text-xs font-bold text-slate-800">₹<?= htmlspecialchars(format_inr(abs($lineAmt)), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-5 py-3 whitespace-nowrap text-right text-xs font-bold <?= htmlspecialchars((string)($runBalColor), ENT_QUOTES, 'UTF-8') ?>">₹<?= htmlspecialchars(format_inr(abs($runningBalance)), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-5 py-3 whitespace-nowrap text-right text-xs">
                                        <?php if (!empty($l['transaction_ref']) && str_starts_with($l['transaction_ref'], 'pay_')): ?>
                                            <button onclick="refundRazorpay(<?= htmlspecialchars((string)($l['id']), ENT_QUOTES, 'UTF-8') ?>)" class="px-2.5 py-1 rounded bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold border border-amber-200 transition-colors">Refund</button>
                                        <?php elseif (preg_match('/Order #(\d+)/', $l['description'], $matches) && strpos($l['description'], 'Reverse') === false): ?>
                                            <a href="/admin/modules/pos/pos?edit_order=<?= htmlspecialchars((string)($matches[1]), ENT_QUOTES, 'UTF-8') ?>" class="px-2.5 py-1 rounded bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-bold border border-indigo-200 transition-colors text-[10px] uppercase tracking-wider inline-flex items-center gap-1" title="Edit POS Order"><i class="ph-bold ph-pencil-simple text-[10px]"></i> POS Order</a>
                                        <?php else: ?>
                                            <button onclick="openEditLedger(<?= htmlspecialchars((string)($l['id']), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars((string)(addslashes($l['description'] ?? ''))) ?>', <?= htmlspecialchars((string)(abs($lineAmt)), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars((string)(addslashes($l['payment_method'] ?? ''))) ?>', '<?= htmlspecialchars((string)(addslashes($l['display_id'] ?? ''))) ?>', '<?= htmlspecialchars((string)(addslashes($l['category'] ?? ''))) ?>', '<?= htmlspecialchars((string)(date('Y-m-d\TH:i', strtotime((string)$l['recorded_at']))), ENT_QUOTES, 'UTF-8') ?>')" class="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg inline-flex items-center justify-center transition-all"><i class="ph ph-pencil-simple text-sm"></i></button>
                                            <button onclick="deleteLedger(<?= htmlspecialchars((string)($l['id']), ENT_QUOTES, 'UTF-8') ?>)" class="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg inline-flex items-center justify-center transition-all"><i class="ph ph-trash text-sm"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Folio Summary Footer Section -->
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Left: Charges -->
                    <div>
                        <h4 class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-4"><i class="ph ph-list-plus text-xs inline mr-1"></i> Charges</h4>
                        <div class="space-y-2.5 text-xs">
                            <div class="flex justify-between">
                                <span class="font-semibold text-slate-500">Subtotal (Ex. Tax)</span>
                                <span class="font-bold text-slate-800">₹<?= htmlspecialchars(format_inr($taxPref === 'inclusive' ? $subtotalCharges - $taxAmount : $subtotalCharges), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php if ($taxEnabled && $taxPref !== 'exempt'): ?>
                            <div class="flex justify-between">
                                <span class="font-semibold text-slate-500"><?= htmlspecialchars((string)($taxLabel)) ?> (<?= htmlspecialchars((string)($taxRate)) ?>%)</span>
                                <span class="font-bold text-slate-800">₹<?= htmlspecialchars(format_inr($taxAmount), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between border-t border-slate-100 pt-2.5 text-sm font-bold text-slate-800">
                                <span>Total Charges</span>
                                <span>₹<?= htmlspecialchars(format_inr($totalCharges), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Center: Payments & Deposits -->
                    <div>
                        <h4 class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-4"><i class="ph ph-wallet text-xs inline mr-1"></i> Payments & Deposits</h4>
                        <div class="space-y-2.5 text-xs">
                            <div class="flex justify-between">
                                <span class="font-semibold text-slate-500">Payments Received</span>
                                <span class="font-bold text-slate-800">₹<?= htmlspecialchars(format_inr($totalPayments), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="flex justify-between text-rose-600">
                                <span class="font-semibold">Refunds Issued</span>
                                <span class="font-bold">₹<?= htmlspecialchars(format_inr($refundsIssued), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="flex justify-between border-t border-slate-100 pt-2.5 font-bold text-slate-800">
                                <span>Deposits Held</span>
                                <span>₹0.00</span>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Balance Due Box -->
                    <div class="bg-emerald-50/30 border border-emerald-100/50 rounded-2xl p-5 flex flex-col justify-between items-center text-center">
                        <div>
                            <span class="text-[9px] font-bold text-slate-500 uppercase tracking-wider block"><?= $balance < 0 ? 'Overpaid' : 'Balance Due' ?></span>
                            <h3 class="text-3xl font-extrabold <?= $balance < 0 ? 'text-emerald-700' : 'text-slate-900' ?> mt-1 block">₹<?= htmlspecialchars(format_inr(abs($balance)), ENT_QUOTES, 'UTF-8') ?></h3>
                            <span class="text-[10px] font-bold <?= htmlspecialchars((string)($balance <= 0 ? 'text-emerald-700 bg-emerald-50 border-emerald-100' : 'text-amber-700 bg-amber-50 border-amber-100'), ENT_QUOTES, 'UTF-8') ?> px-2.5 py-0.5 rounded-full inline-block mt-2 border">
                                <?= htmlspecialchars((string)($balance < 0 ? 'Overpaid' : ($balance === 0.0 ? 'Settled' : 'Unpaid Balance')), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="w-full mt-4">
                            <button onclick="UI.showModal('collect-payment-modal')" class="w-full bg-brand-900 hover:bg-brand-800 text-white font-bold text-xs uppercase tracking-wider py-3 rounded-xl transition-all shadow-md shadow-indigo-100">
                                Collect Payment <i class="ph ph-caret-down text-[10px] inline ml-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modify Stay Panel -->
            <div id="panel-modify" class="hidden space-y-6">
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6 max-w-xl">
                    <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-4">Modify Stay Details</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Room <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? '<span class="text-rose-500">(Locked)</span>' : ''), ENT_QUOTES, 'UTF-8') ?></label>
                            <select id="edit_room_id" onchange="updateRatePlanDropdown()" <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? 'disabled' : ''), ENT_QUOTES, 'UTF-8') ?> class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs font-semibold outline-none <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? 'opacity-60 cursor-not-allowed' : ''), ENT_QUOTES, 'UTF-8') ?>">
                                <?php foreach($allRooms as $r): ?>
                                    <option value="<?= htmlspecialchars((string)($r['id']), ENT_QUOTES, 'UTF-8') ?>" data-cat="<?= htmlspecialchars((string)($r['category_id']), ENT_QUOTES, 'UTF-8') ?>" <?= htmlspecialchars((string)($r['id'] == $booking['room_id'] ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>
                                        Room <?= htmlspecialchars((string)($r['room_number'])) ?> (<?= htmlspecialchars((string)($r['cat_name'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Rate Plan <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? '<span class="text-rose-500">(Locked)</span>' : ''), ENT_QUOTES, 'UTF-8') ?></label>
                            <select id="edit_rate_plan" <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? 'disabled' : ''), ENT_QUOTES, 'UTF-8') ?> class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs font-semibold outline-none <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? 'opacity-60 cursor-not-allowed' : ''), ENT_QUOTES, 'UTF-8') ?>">
                                <!-- Populated by JS -->
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Check In <?= htmlspecialchars((string)($checkInLocked ? '<span class="text-rose-500">(Locked)</span>' : ''), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="datetime-local" id="edit_check_in" step="60" value="<?= htmlspecialchars((string)(date('Y-m-d\TH:i', strtotime($booking['check_in']))), ENT_QUOTES, 'UTF-8') ?>" <?= htmlspecialchars((string)($checkInLocked ? 'disabled' : ''), ENT_QUOTES, 'UTF-8') ?> class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs font-semibold outline-none <?= htmlspecialchars((string)($checkInLocked ? 'opacity-60 cursor-not-allowed' : ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Check Out <?= htmlspecialchars((string)($checkOutLocked ? '<span class="text-rose-500">(Locked)</span>' : ''), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="datetime-local" id="edit_check_out" step="60" value="<?= htmlspecialchars((string)(date('Y-m-d\TH:i', strtotime($booking['check_out']))), ENT_QUOTES, 'UTF-8') ?>" <?= htmlspecialchars((string)($checkOutLocked ? 'disabled' : ''), ENT_QUOTES, 'UTF-8') ?> class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs font-semibold outline-none <?= htmlspecialchars((string)($checkOutLocked ? 'opacity-60 cursor-not-allowed' : ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <?php if ($taxEnabled): ?>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Tax Preference</label>
                            <select id="edit_tax_pref" <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? 'disabled' : ''), ENT_QUOTES, 'UTF-8') ?> class="w-full bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs font-semibold outline-none <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? 'opacity-60 cursor-not-allowed' : ''), ENT_QUOTES, 'UTF-8') ?>">
                                <option value="exclusive" <?= htmlspecialchars((string)(($booking['tax_preference'] ?? 'exclusive') === 'exclusive' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Exclusive (Tax Added to Total)</option>
                                <option value="inclusive" <?= htmlspecialchars((string)(($booking['tax_preference'] ?? 'exclusive') === 'inclusive' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Inclusive (Tax Included in Total)</option>
                                <option value="exempt" <?= htmlspecialchars((string)(($booking['tax_preference'] ?? 'exclusive') === 'exempt' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Exempt (No Tax)</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <button onclick="editBooking(this)" <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? 'disabled' : ''), ENT_QUOTES, 'UTF-8') ?> class="w-full bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-bold py-3.5 rounded-xl text-xs uppercase tracking-wider transition-colors shadow-sm <?= htmlspecialchars((string)(in_array($bookingStatus, ['checked_out', 'cancelled']) ? 'opacity-60 cursor-not-allowed' : ''), ENT_QUOTES, 'UTF-8') ?>">Update Stay Details</button>
                    </div>
                </div>

                <!-- Quick Extend Widget -->
                <?php if ($stayPolicy['check_out']): ?>
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6 max-w-xl">
                    <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-4">Quick Extend</h3>
                    <div class="grid grid-cols-3 gap-3">
                        <button onclick="extendStay(this, 3)" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-bold py-3.5 rounded-xl text-xs uppercase tracking-wider transition-colors shadow-sm">+ 3 Hours</button>
                        <button onclick="extendStay(this, 6)" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-bold py-3.5 rounded-xl text-xs uppercase tracking-wider transition-colors shadow-sm">+ 6 Hours</button>
                        <button onclick="extendStay(this, 24)" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-600 font-bold py-3.5 rounded-xl text-xs uppercase tracking-wider transition-colors shadow-sm">+ 24 Hours</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Guest Details Tab Panel -->
            <div id="panel-guest" class="hidden space-y-6">
                <?php
                    $idFrontUrl = pms_document_url($booking['id_proof_front'] ?? '');
                    $idBackUrl = pms_document_url($booking['id_proof_back'] ?? '');
                    $guestPhotoUrl = pms_document_url($booking['guest_photo'] ?? '');
                ?>
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6">
                    <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-4">Guest Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="flex items-center gap-4">
                            <div class="w-16 h-16 rounded-full bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center text-slate-700 text-2xl font-bold">
                                <?php if($guestPhotoUrl): ?>
                                    <img src="<?= htmlspecialchars($guestPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <?= htmlspecialchars((string)(strtoupper(substr($booking['guest_name'], 0, 1)))) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="text-lg font-bold text-slate-800"><?= htmlspecialchars((string)($booking['guest_name'])) ?></p>
                                <p class="text-xs text-slate-400 mt-0.5">WhatsApp: <?= htmlspecialchars((string)($booking['guest_phone'])) ?></p>
                                <?php if($booking['age']): ?>
                                <p class="text-xs text-slate-500 mt-0.5">Age: <?= htmlspecialchars((string)($booking['age'])) ?> years</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-xs space-y-2 border-l border-slate-100 pl-6">
                            <p class="font-bold text-slate-400 uppercase tracking-wider mb-2">Location & Address</p>
                            <p class="font-semibold text-slate-700">City: <span class="text-slate-900"><?= htmlspecialchars((string)($booking['city'] ?? 'N/A')) ?></span></p>
                            <p class="font-semibold text-slate-700">State: <span class="text-slate-900"><?= htmlspecialchars((string)($booking['state'] ?? 'N/A')) ?></span></p>
                            <p class="font-semibold text-slate-700">Country: <span class="text-slate-900"><?= htmlspecialchars((string)($booking['country'] ?? 'India')) ?></span></p>
                            <p class="font-semibold text-slate-700">Pincode: <span class="text-slate-900"><?= htmlspecialchars((string)($booking['pincode'] ?? 'N/A')) ?></span></p>
                        </div>
                    </div>
                </div>

                <!-- Documents and Proof uploads -->
                <div class="bg-white border border-slate-100 rounded-2xl shadow-sm p-6">
                    <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2">Verification Documents</h3>
                    <p class="text-[10px] text-slate-500 font-semibold mb-4">Fill the card frame. Avoid glare. Hold still. Guest photo: center the face.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- ID Front -->
                        <div class="border border-dashed border-slate-200 rounded-2xl p-6 flex flex-col items-center justify-center text-center hover:bg-slate-50 transition-colors relative overflow-hidden group min-h-[140px]">
                            <?php if($idFrontUrl): ?>
                                <img src="<?= htmlspecialchars($idFrontUrl, ENT_QUOTES, 'UTF-8') ?>" class="absolute inset-0 w-full h-full object-cover z-0 cursor-pointer" onclick="UI.viewImage('<?= htmlspecialchars($idFrontUrl, ENT_QUOTES, 'UTF-8') ?>')">
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity z-10 flex items-center justify-center gap-2 flex-wrap p-2">
                                    <button onclick="UI.viewImage('<?= htmlspecialchars($idFrontUrl, ENT_QUOTES, 'UTF-8') ?>')" class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold flex items-center gap-1"><i class="ph ph-eye"></i> View</button>
                                    <button type="button" onclick="captureDoc('id_proof_front')" class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold">Camera</button>
                                    <label class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold">Replace<input type="file" accept="image/*" class="hidden" onchange="uploadDoc('id_proof_front', this)"></label>
                                </div>
                            <?php else: ?>
                                <i class="ph ph-identification-card text-3xl text-slate-400 mb-2"></i>
                                <p class="text-xs font-bold text-slate-700">ID Proof (Front)</p>
                                <div class="mt-2.5 flex gap-2">
                                    <button type="button" onclick="captureDoc('id_proof_front')" class="cursor-pointer bg-indigo-50 text-indigo-700 px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-100">Camera</button>
                                    <label class="cursor-pointer bg-indigo-50 text-indigo-700 px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-100 transition-colors">Upload<input type="file" accept="image/*" class="hidden" onchange="uploadDoc('id_proof_front', this)"></label>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- ID Back -->
                        <div class="border border-dashed border-slate-200 rounded-2xl p-6 flex flex-col items-center justify-center text-center hover:bg-slate-50 transition-colors relative overflow-hidden group min-h-[140px]">
                            <?php if($idBackUrl): ?>
                                <img src="<?= htmlspecialchars($idBackUrl, ENT_QUOTES, 'UTF-8') ?>" class="absolute inset-0 w-full h-full object-cover z-0 cursor-pointer" onclick="UI.viewImage('<?= htmlspecialchars($idBackUrl, ENT_QUOTES, 'UTF-8') ?>')">
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity z-10 flex items-center justify-center gap-2 flex-wrap p-2">
                                    <button onclick="UI.viewImage('<?= htmlspecialchars($idBackUrl, ENT_QUOTES, 'UTF-8') ?>')" class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold flex items-center gap-1"><i class="ph ph-eye"></i> View</button>
                                    <button type="button" onclick="captureDoc('id_proof_back')" class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold">Camera</button>
                                    <label class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold">Replace<input type="file" accept="image/*" class="hidden" onchange="uploadDoc('id_proof_back', this)"></label>
                                </div>
                            <?php else: ?>
                                <i class="ph ph-identification-card text-3xl text-slate-400 mb-2"></i>
                                <p class="text-xs font-bold text-slate-700">ID Proof (Back)</p>
                                <div class="mt-2.5 flex gap-2">
                                    <button type="button" onclick="captureDoc('id_proof_back')" class="cursor-pointer bg-indigo-50 text-indigo-700 px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-100">Camera</button>
                                    <label class="cursor-pointer bg-indigo-50 text-indigo-700 px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-100 transition-colors">Upload<input type="file" accept="image/*" class="hidden" onchange="uploadDoc('id_proof_back', this)"></label>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Photo -->
                        <div class="border border-dashed border-slate-200 rounded-2xl p-6 flex flex-col items-center justify-center text-center hover:bg-slate-50 transition-colors relative overflow-hidden group min-h-[140px]">
                            <?php if($guestPhotoUrl): ?>
                                <img src="<?= htmlspecialchars($guestPhotoUrl, ENT_QUOTES, 'UTF-8') ?>" class="absolute inset-0 w-full h-full object-cover z-0 cursor-pointer" onclick="UI.viewImage('<?= htmlspecialchars($guestPhotoUrl, ENT_QUOTES, 'UTF-8') ?>')">
                                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity z-10 flex items-center justify-center gap-2 flex-wrap p-2">
                                    <button onclick="UI.viewImage('<?= htmlspecialchars($guestPhotoUrl, ENT_QUOTES, 'UTF-8') ?>')" class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold flex items-center gap-1"><i class="ph ph-eye"></i> View</button>
                                    <button type="button" onclick="captureDoc('guest_photo')" class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold">Camera</button>
                                    <label class="cursor-pointer bg-white text-slate-950 px-3.5 py-2 rounded-xl text-xs font-bold">Replace<input type="file" accept="image/*" class="hidden" onchange="uploadDoc('guest_photo', this)"></label>
                                </div>
                            <?php else: ?>
                                <i class="ph ph-camera text-3xl text-slate-400 mb-2"></i>
                                <p class="text-xs font-bold text-slate-700">Guest Photo</p>
                                <div class="mt-2.5 flex gap-2">
                                    <button type="button" onclick="captureDoc('guest_photo')" class="cursor-pointer bg-indigo-50 text-indigo-700 px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-100">Camera</button>
                                    <label class="cursor-pointer bg-indigo-50 text-indigo-700 px-4 py-2 rounded-xl text-xs font-bold hover:bg-indigo-100 transition-colors">Upload<input type="file" accept="image/*" class="hidden" onchange="uploadDoc('guest_photo', this)"></label>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Audit Log Tab Panel -->
            <div id="panel-audit" class="hidden bg-white border border-slate-100 rounded-2xl shadow-sm p-6 max-h-[500px] overflow-y-auto space-y-4">
                <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-4">Activity Logs</h3>
                <?php 
                $logStmt = $db->prepare("SELECT a.*, COALESCE(s.username, 'System/Guest') as username FROM audit_logs a LEFT JOIN staff_users s ON a.staff_id = s.id WHERE a.property_id = :prop_id AND a.entity_id = :id AND a.entity_type IN ('BOOKING', 'FOLIO') ORDER BY a.created_at DESC");
                $logStmt->execute(['id' => $id, 'prop_id' => $activePropId]);
                $folioLogs = $logStmt->fetchAll();
                
                if (empty($folioLogs)): ?>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-widest text-center py-4">No recent activity</p>
                <?php else: 
                    foreach($folioLogs as $log): ?>
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-full bg-slate-100 flex items-center justify-center text-slate-600 shrink-0">
                            <i class="ph ph-clock-counter-clockwise"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars((string)($log['action'])) ?></p>
                            <p class="text-[10px] text-slate-400 mt-0.5">by <?= htmlspecialchars((string)($log['username'])) ?> &bull; <?= htmlspecialchars((string)(date('M j, Y g:i A', strtotime($log['created_at']))), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                <?php 
                    endforeach;
                endif; ?>
            </div>

        </main>
        
        <!-- Edit Guest Modal -->
        <div id="edit-guest-modal" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="UI.hideModal('edit-guest-modal')"></div>
            <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl max-h-[90vh] overflow-y-auto transform transition-transform translate-y-full max-w-lg mx-auto p-6">
                <div class="w-12 h-1 bg-slate-200 rounded-full mx-auto mb-6"></div>
                <h2 class="text-lg font-bold text-slate-800 mb-6 font-display">Edit Guest Details</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Guest Name</label>
                        <input type="text" id="edit_g_name" value="<?= htmlspecialchars((string)($booking['guest_name'])) ?>" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">WhatsApp Number</label>
                        <input type="text" id="edit_g_phone" value="<?= htmlspecialchars((string)($booking['guest_phone'])) ?>" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Age</label>
                        <input type="number" id="edit_g_age" value="<?= htmlspecialchars((string)($booking['age'] ?? '')) ?>" min="1" max="120" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">City</label>
                            <input type="text" id="edit_g_city" value="<?= htmlspecialchars((string)($booking['city'] ?? '')) ?>" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">State</label>
                            <input type="text" id="edit_g_state" value="<?= htmlspecialchars((string)($booking['state'] ?? '')) ?>" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Country</label>
                            <input type="text" id="edit_g_country" value="<?= htmlspecialchars((string)($booking['country'] ?? 'India')) ?>" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Pincode</label>
                            <input type="text" id="edit_g_pincode" value="<?= htmlspecialchars((string)($booking['pincode'] ?? '')) ?>" maxlength="6" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                        </div>
                    </div>
                    <button onclick="saveGuestEdit(this)" class="w-full btn-glass mt-4 text-xs font-bold uppercase tracking-wider active:scale-[0.98]">Save Details</button>
                </div>
            </div>
        </div>
        
        <!-- Edit Ledger Modal -->
        <div id="edit-ledger-modal" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="UI.hideModal('edit-ledger-modal')"></div>
            <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl transform transition-transform translate-y-full max-w-lg mx-auto p-6">
                <div class="w-12 h-1 bg-slate-200 rounded-full mx-auto mb-6"></div>
                <h2 class="text-lg font-bold text-slate-800 mb-6 font-display">Edit Ledger Entry</h2>
                <div class="space-y-4">
                    <input type="hidden" id="edit_l_id">
                    <div><label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Description</label><input type="text" id="edit_l_desc" class="w-full input-glass rounded-xl p-3 text-sm font-semibold"></div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Amount (₹)</label>
                        <input type="number" id="edit_l_amount" min="0" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Date & Time</label>
                        <input type="datetime-local" id="edit_l_date" step="60" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Payment Method</label>
                        <select id="edit_l_method" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                            <option value="">N/A (Charge)</option>
                            <?php foreach($paymentMethods as $pm): ?>
                                <option value="<?= htmlspecialchars((string)$pm) ?>"><?= htmlspecialchars((string)$pm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="edit_l_category_wrap">
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Payment Category</label>
                        <select id="edit_l_category" class="w-full input-glass rounded-xl p-3 text-sm font-semibold">
                            <?php foreach($paymentCategories as $pc): ?>
                                <option value="<?= htmlspecialchars((string)($pc)) ?>"><?= htmlspecialchars((string)($pc)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex items-center gap-2 my-2" id="edit_l_split_toggle_container">
                        <input type="checkbox" id="edit_l_split_toggle" onchange="toggleEditSplitPayment(this.checked)" class="rounded text-indigo-600 focus:ring-indigo-500">
                        <label for="edit_l_split_toggle" class="text-xs font-bold text-slate-600 uppercase tracking-wider cursor-pointer">Split Payment Category-wise</label>
                    </div>

                    <div id="edit_l_splits_container" class="hidden space-y-3 mt-3 border-t border-slate-100 pt-3">
                        <div class="text-[10px] font-bold text-slate-400 uppercase mb-2">Allocate amounts (Sum must equal Total Amount)</div>
                        <div class="space-y-2" id="edit_l_split_rows">
                            <?php foreach($paymentCategories as $pc): ?>
                            <div class="flex gap-2 items-center">
                                <span class="text-xs font-bold text-slate-500 w-24 truncate" title="<?= htmlspecialchars((string)($pc)) ?>"><?= htmlspecialchars((string)($pc)) ?>:</span>
                                <input type="number" data-category="<?= htmlspecialchars((string)($pc)) ?>" class="flex-1 input-glass rounded-xl p-2 text-xs font-bold edit-l-split-amount" placeholder="0.00" value="0" oninput="validateEditSplitSum()">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="edit_l_split_validation_msg" class="text-[10px] font-bold text-rose-500 hidden">Allocated sum does not match total amount.</div>
                    </div>

                    <button onclick="saveLedgerEdit(this)" class="w-full btn-glass mt-4 text-xs font-bold uppercase tracking-wider active:scale-[0.98]">Save Changes</button>
                </div>
            </div>
        </div>
        
        <!-- Collect Payment Modal -->
        <div id="collect-payment-modal" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="UI.hideModal('collect-payment-modal')"></div>
            <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl transform transition-transform translate-y-full max-w-lg mx-auto p-6">
                <div class="w-12 h-1 bg-slate-200 rounded-full mx-auto mb-6"></div>
                <h2 class="text-lg font-bold text-slate-800 mb-2 font-display">Collect Payment</h2>
                <p class="text-slate-400 font-semibold text-xs mb-6">Balance Due: ₹<span id="cp_amount_display"><?= htmlspecialchars(format_inr($balance), ENT_QUOTES, 'UTF-8') ?></span></p>
                
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Amount to Collect (₹)</label>
                            <input type="number" id="cp_amount" min="0" value="<?= htmlspecialchars((string)($balance), ENT_QUOTES, 'UTF-8') ?>" class="w-full input-glass rounded-xl p-3 text-lg font-bold text-slate-800">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Category (Single Payment)</label>
                            <select id="cp_single_category" class="w-full input-glass rounded-xl p-3 text-sm font-bold text-slate-800 appearance-none">
                                <?php foreach($paymentCategories as $pc): ?>
                                    <option value="<?= htmlspecialchars((string)($pc)) ?>"><?= htmlspecialchars((string)($pc)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Payment Date & Time</label>
                            <input type="datetime-local" id="cp_date" class="w-full input-glass rounded-xl p-3 text-sm text-slate-800" value="<?= htmlspecialchars((string)(date('Y-m-d\TH:i')), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-2 my-2">
                        <input type="checkbox" id="cp_split_toggle" onchange="toggleSplitPayment(this.checked)" class="rounded text-indigo-600 focus:ring-indigo-500">
                        <label for="cp_split_toggle" class="text-xs font-bold text-slate-600 uppercase tracking-wider cursor-pointer">Split Payment Category-wise</label>
                    </div>

                    <div id="cp_splits_container" class="hidden space-y-3 mt-3 border-t border-slate-100 pt-3">
                        <div class="text-[10px] font-bold text-slate-400 uppercase mb-2">Allocate amounts (Sum must equal Total Amount)</div>
                        <div class="space-y-2" id="cp_split_rows">
                            <?php foreach($paymentCategories as $pc): ?>
                            <div class="flex gap-2 items-center">
                                <span class="text-xs font-bold text-slate-500 w-24 truncate" title="<?= htmlspecialchars((string)($pc)) ?>"><?= htmlspecialchars((string)($pc)) ?>:</span>
                                <input type="number" data-category="<?= htmlspecialchars((string)($pc)) ?>" class="flex-1 input-glass rounded-xl p-2 text-xs font-bold cp-split-amount" placeholder="0.00" value="0" oninput="validateSplitSum()">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="split_validation_msg" class="text-[10px] font-bold text-rose-500 hidden">Allocated sum does not match total amount.</div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mt-4">
                        <?php foreach($paymentMethods as $pm): 
                            $pmLower = strtolower($pm);
                            $icon = 'ph-money';
                            if (strpos($pmLower, 'upi') !== false) $icon = 'ph-qr-code';
                            if (strpos($pmLower, 'card') !== false) $icon = 'ph-credit-card';
                            if (strpos($pmLower, 'bank') !== false) $icon = 'ph-bank';
                        ?>
                        <button onclick="recordManualPayment(this, '<?= htmlspecialchars((string)(addslashes($pm))) ?>')" class="bg-slate-50 hover:bg-slate-100/80 text-slate-700 border border-slate-200 rounded-2xl py-4 font-bold active:scale-[0.98] transition-all flex flex-col items-center justify-center gap-1 shadow-sm">
                            <i class="ph <?= htmlspecialchars((string)($icon), ENT_QUOTES, 'UTF-8') ?> text-xl text-indigo-600"></i>
                            <span class="text-xs"><?= htmlspecialchars((string)($pm)) ?></span>
                        </button>
                        <?php endforeach; ?>

                        <?php if (!empty($activeGateways['razorpay'])): ?>
                        <button onclick="payViaGateway(this, 'razorpay')" class="bg-brand-900 text-white border border-indigo-700 rounded-2xl py-4 font-bold active:scale-[0.98] transition-all flex flex-col items-center justify-center gap-1 shadow-md shadow-indigo-100">
                            <i class="ph ph-credit-card text-xl text-indigo-200"></i>
                            <span class="text-xs">Razorpay Gateway</span>
                        </button>
                        <?php endif; ?>

                        <?php if (!empty($activeGateways['phonepe'])): ?>
                        <button onclick="payViaGateway(this, 'phonepe')" class="bg-indigo-700 text-white border border-indigo-800 rounded-2xl py-4 font-bold active:scale-[0.98] transition-all flex flex-col items-center justify-center gap-1 shadow-md">
                            <i class="ph ph-device-mobile text-xl text-indigo-100"></i>
                            <span class="text-xs">PhonePe Gateway</span>
                        </button>
                        <?php endif; ?>

                        <?php if ($activeGateways !== []): ?>
                        <button onclick="sendPaymentLink(this)" class="bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-2xl py-4 font-bold active:scale-[0.98] transition-all flex flex-col items-center justify-center gap-1 shadow-sm">
                            <i class="ph ph-whatsapp-logo text-xl"></i>
                            <span class="text-xs">WhatsApp Link</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- WhatsApp Automation Triggers Modal -->
        <div id="whatsapp-triggers-modal" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="UI.hideModal('whatsapp-triggers-modal')"></div>
            <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl transform transition-transform translate-y-full max-w-lg mx-auto p-6">
                <div class="w-12 h-1 bg-slate-200 rounded-full mx-auto mb-6"></div>
                <h2 class="text-lg font-bold text-slate-800 mb-2 font-display">Send WhatsApp Notification</h2>
                <p class="text-slate-400 font-semibold text-xs mb-6">Select an automated template to send to the guest's phone number.</p>
                
                <div class="space-y-3">
                    <button onclick="triggerWhatsAppAutomation('booking_confirmed', this)" class="w-full flex items-center justify-between bg-slate-50 hover:bg-slate-100/80 text-slate-700 border border-slate-200 rounded-2xl py-3.5 px-4 font-bold active:scale-[0.98] transition-all text-xs">
                        <span class="flex items-center gap-2"><i class="ph ph-paper-plane text-emerald-600 text-base"></i> Booking Confirmation</span>
                        <i class="ph ph-caret-right text-slate-400"></i>
                    </button>

                    <button onclick="triggerWhatsAppAutomation('payment_link', this)" class="w-full flex items-center justify-between bg-slate-50 hover:bg-slate-100/80 text-slate-700 border border-slate-200 rounded-2xl py-3.5 px-4 font-bold active:scale-[0.98] transition-all text-xs">
                        <span class="flex items-center gap-2"><i class="ph ph-link text-amber-600 text-base"></i> Send Payment Link</span>
                        <i class="ph ph-caret-right text-slate-400"></i>
                    </button>
                    <button onclick="triggerWhatsAppAutomation('guest_review_form', this)" class="w-full flex items-center justify-between bg-slate-50 hover:bg-slate-100/80 text-slate-700 border border-slate-200 rounded-2xl py-3.5 px-4 font-bold active:scale-[0.98] transition-all text-xs">
                        <span class="flex items-center gap-2"><i class="ph ph-star text-indigo-600 text-base"></i> Guest Review Form</span>
                        <i class="ph ph-caret-right text-slate-400"></i>
                    </button>
                    <button onclick="triggerWhatsAppAutomation('booking_cancelled', this)" class="w-full flex items-center justify-between bg-slate-50 hover:bg-slate-100/80 text-slate-700 border border-slate-200 rounded-2xl py-3.5 px-4 font-bold active:scale-[0.98] transition-all text-xs text-rose-700">
                        <span class="flex items-center gap-2"><i class="ph ph-x-circle text-rose-600 text-base"></i> Cancellation Notice</span>
                        <i class="ph ph-caret-right text-slate-400"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Booking Status Change Modal -->
        <div id="status-change-modal" class="fixed inset-0 z-50 hidden">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="UI.hideModal('status-change-modal')"></div>
            <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl transform transition-transform translate-y-full max-w-lg mx-auto p-6">
                <div class="w-12 h-1 bg-slate-200 rounded-full mx-auto mb-6"></div>
                <h2 id="status_modal_title" class="text-lg font-bold text-slate-800 mb-2 font-display">Confirm Action</h2>
                <p id="status_modal_desc" class="text-slate-400 text-xs font-semibold mb-6"></p>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 uppercase tracking-wider mb-1.5">Reason <span id="reason_required" class="text-rose-500">(Required)</span></label>
                        <textarea id="status_reason" rows="3" placeholder="Enter reason for this action..." class="w-full input-glass rounded-xl p-3 text-sm font-semibold resize-none"></textarea>
                    </div>
                    <button onclick="submitStatusChange(this)" class="w-full btn-glass mt-4 text-xs font-bold uppercase tracking-wider active:scale-[0.98]">Confirm</button>
                </div>
            </div>
        </div>
        
        <!-- Image Viewer Modal -->
        <div id="image-viewer-modal" class="fixed inset-0 z-[60] hidden">
            <div class="absolute inset-0 bg-black/90 backdrop-blur-sm" onclick="UI.closeImageViewer()"></div>
            <div class="absolute inset-0 flex items-center justify-center p-4">
                <button onclick="UI.closeImageViewer()" class="absolute top-4 right-4 w-12 h-12 bg-white/10 hover:bg-white/20 text-white rounded-full flex items-center justify-center transition-colors z-10">
                    <i class="ph ph-x text-2xl"></i>
                </button>
                <img id="viewer-image" src="" class="max-w-full max-h-full object-contain rounded-lg shadow-2xl">
            </div>
        </div>
    </div>

    <script>
        const FOLIO_DATA = {
            bookingId: <?= (int)$bookingPk ?>,
            publicId: <?= json_encode($publicId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            balance: <?= htmlspecialchars((string)($balance), ENT_QUOTES, 'UTF-8') ?>,
            catRatePlans: <?= json_encode($catRatePlans) ?>,
            ratePlanName: <?= json_encode($ratePlanName) ?>,
            razorpayKeyId: <?= json_encode((string)($activeGateways['razorpay']['key_id'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            activeGateways: <?= json_encode(array_keys($activeGateways), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            guestName: <?= json_encode($booking["guest_name"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            guestPhone: <?= json_encode($booking["guest_phone"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>

        };

        // Tab Switching Logic
        function switchTab(tab) {
            const panels = ['summary', 'modify', 'guest', 'audit'];
            panels.forEach(p => {
                const panel = document.getElementById('panel-' + p);
                const btn = document.getElementById('tab-' + p + '-btn');
                if (p === tab) {
                    panel.classList.remove('hidden');
                    btn.classList.add('active');
                } else {
                    panel.classList.add('hidden');
                    btn.classList.remove('active');
                }
            });
        }

        async function editOfflineFolioId(bookingId, currentVal) {
            const newVal = prompt("Enter new Folio ID:", currentVal);
            if (newVal === null) return;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            try {
                const res = await fetch('folio.php?id=' + encodeURIComponent(FOLIO_DATA.publicId || bookingId), {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ action: 'update_folio_id', booking_id: bookingId, offline_folio_id: newVal })
                });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('offline_folio_id_display').textContent = newVal || 'N/A';
                    showToast('Folio ID updated successfully!');
                } else {
                    alert('Error: ' + data.message);
                }
            } catch(e) {
                alert('Connection error');
            }
        }
    </script>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script src="../js/folio.js"></script>
</body>
</html>
