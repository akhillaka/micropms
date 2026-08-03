<?php
declare(strict_types=1);

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../pms_core/InvoiceLink.php';
require_once __DIR__ . '/../pms_core/ErrorPage.php';
$db = Database::getInstance()->getConnection();

$id = $_GET['id'] ?? null;
$token = $_GET['token'] ?? $_GET['hash'] ?? null; // Support both new token and legacy hash

if (!$id || !$token) {
    render_error_page('Invalid Invoice Link', 'This invoice link is missing required parameters.', 400);
}

$stmt = $db->prepare("
    SELECT b.*, 
           r.room_number, 
           c.name as category_name, 
           g.name as guest_name, 
           g.phone as guest_phone, 
           g.city, 
           g.state, 
           g.country, 
           g.pincode,
           comp.name as company_name,
           comp.contact_details as company_contact
    FROM bookings b 
    JOIN rooms r ON b.room_id = r.id 
    JOIN room_categories c ON r.category_id = c.id 
    LEFT JOIN guests g ON b.guest_id = g.id 
    LEFT JOIN companies comp ON b.company_id = comp.id
    WHERE b.id = :id
");
$stmt->execute(['id' => $id]);
$booking = $stmt->fetch();

if (!$booking) {
    render_error_page('Invoice Not Found', 'The requested invoice does not exist.', 404);
}

// Reload DB settings for this specific property
load_db_settings($db, (int)$booking['property_id']);

// Validate token (HMAC-SHA256 with 24hr expiry)
$isValid = InvoiceLink::validate($token, (int)$booking['id']);

// Backward compatibility: support legacy md5 hash for existing links
if (!$isValid && isset($_GET['hash'])) {
    $legacyHash = md5((string)$booking['id'] . (string)$booking['created_at']);
    if ($_GET['hash'] === $legacyHash) {
        $isValid = true; // Accept legacy links
    }
}

if (!$isValid) {
    render_error_page('Link Expired', 'This invoice link has expired or is invalid. Please request a new one from the hotel.', 403);
}

// Fetch all ledger transactions
$ledgerStmt = $db->prepare("SELECT * FROM folio_ledger WHERE booking_id = :id ORDER BY recorded_at ASC");
$ledgerStmt->execute(['id' => $id]);
$ledger = $ledgerStmt->fetchAll();

// Calculate totals
$subtotalCharges = 0;
$totalPayments = 0;

foreach ($ledger as $l) {
    $amount = (float)$l['amount'];
    if ($amount > 0) {
        $subtotalCharges += $amount;
    } else {
        $totalPayments += abs($amount);
    }
}

// Load taxation settings
$taxEnabled = defined('TAX_ENABLED') && TAX_ENABLED === 'true';
$taxRate = defined('TAX_RATE') ? (float)TAX_RATE : 0.0;
$taxLabel = defined('TAX_LABEL') ? TAX_LABEL : 'Tax';

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
$balance = $totalCharges - $totalPayments;

// Format duration
$inDt = new DateTime($booking['check_in']);
$outDt = new DateTime($booking['check_out']);
$diff = $inDt->diff($outDt);
$hours = ($diff->days * 24) + $diff->h;
if ($diff->i > 0) $hours++;
$durationStr = $hours . " Hours";
if ($hours >= 24) {
    $days = floor($hours / 24);
    $remHours = $hours % 24;
    $durationStr = $days . " Day" . ($days > 1 ? "s" : "");
    if ($remHours > 0) $durationStr .= " " . $remHours . " Hr" . ($remHours > 1 ? "s" : "");
}

$hotelName = defined('PROPERTY_NAME') ? PROPERTY_NAME : 'MicroPMS Hotel';
$invoiceNo = "REC-" . date('Y', strtotime($booking['created_at'])) . "-" . str_pad((string)$booking['id'], 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= htmlspecialchars($invoiceNo) ?></title>
    <?php include __DIR__ . '/admin/components/ui_head.php'; ?>
    <style>
        /* Mobile-friendly specific print styles */
        @media print {
            body { font-size: 12px; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="min-h-screen py-8 px-4 sm:px-6 lg:px-8 bg-slate-50 print:bg-white print:py-0 print:px-0 text-slate-900 font-sans">
    
    <!-- Control Bar (No Print) -->
    <div class="max-w-3xl mx-auto mb-8 flex flex-col sm:flex-row justify-between items-center gap-4 print:hidden bg-white border border-slate-200 p-4 rounded-xl shadow-sm">
        <div class="text-sm font-medium text-slate-600">
            Invoice for <?= htmlspecialchars($booking['guest_name']) ?>
        </div>
        <div class="flex gap-3 w-full sm:w-auto">
            <button onclick="window.print()" class="w-full sm:w-auto bg-slate-900 hover:bg-slate-800 text-white font-medium px-4 py-2 rounded-lg transition-colors flex items-center justify-center gap-2 shadow-sm">
                <i class="ph ph-printer text-lg"></i> Download / Print PDF
            </button>
        </div>
    </div>

    <!-- Receipt Sheet -->
    <div class="max-w-3xl mx-auto bg-white p-6 sm:p-16 shadow-sm border border-slate-200 print:border-none print:shadow-none print:p-0 relative rounded-xl">

        <!-- Header -->
        <div class="flex flex-col sm:flex-row print:flex-row justify-between items-start pb-8 sm:pb-12 print:pb-4 border-b border-slate-200">
            <div class="flex flex-col">
                <?php if (defined('PROPERTY_LOGO_BASE64') && !empty(PROPERTY_LOGO_BASE64)): ?>
                    <img src="data:image/png;base64,<?= htmlspecialchars(PROPERTY_LOGO_BASE64) ?>" class="w-24 sm:w-28 print:w-24 h-auto object-contain object-left -ml-1 sm:-ml-2 -mt-1 sm:-mt-2 mb-2">
                <?php else: ?>
                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 sm:h-12 sm:w-12 bg-slate-900 rounded-lg flex items-center justify-center text-white shrink-0 mb-2">
                            <i class="ph ph-buildings text-xl sm:text-2xl"></i>
                        </div>
                        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 mb-2"><?= htmlspecialchars(defined('PROPERTY_NAME') && !empty(PROPERTY_NAME) ? PROPERTY_NAME : $hotelName) ?></h1>
                    </div>
                <?php endif; ?>
                <div class="text-sm text-slate-500 leading-relaxed max-w-xs mt-1">
                    <?php if (defined('PROPERTY_ADDRESS') && !empty(PROPERTY_ADDRESS)): ?>
                        <?= nl2br(htmlspecialchars(PROPERTY_ADDRESS)) ?><br>
                    <?php endif; ?>
                    <?php if (defined('PROPERTY_EMAIL') && !empty(PROPERTY_EMAIL)): ?>
                        <?= htmlspecialchars(PROPERTY_EMAIL) ?><br>
                    <?php endif; ?>
                    <?php if (defined('PROPERTY_PHONE') && !empty(PROPERTY_PHONE)): ?>
                        <?= htmlspecialchars(PROPERTY_PHONE) ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="text-left sm:text-right print:text-right mt-6 sm:mt-0 print:mt-0 relative w-full sm:w-auto">
                <h2 class="text-2xl sm:text-3xl font-light text-slate-300 uppercase tracking-widest mb-4">Invoice</h2>
                
                <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-left sm:text-right print:text-right">
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-wider mb-0.5">Booking ID</p>
                        <p class="text-sm font-semibold text-slate-900 font-mono"><?= htmlspecialchars($booking['display_id'] ?: 'BKG-'.(int)$id) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 uppercase tracking-wider mb-0.5">Receipt ID</p>
                        <p class="text-sm font-semibold text-slate-900 font-mono"><?= htmlspecialchars(SequenceGenerator::generate(defined('SEQ_RECEIPT_FORMAT') ? SEQ_RECEIPT_FORMAT : 'RCPT-{YY}{MM}-{ID}', (int)$id)) ?></p>
                    </div>
                    <div class="col-span-2 mt-2">
                        <p class="text-xs text-slate-400 uppercase tracking-wider mb-0.5">Date of Issue</p>
                        <p class="text-sm font-medium text-slate-900"><?= date('F d, Y') ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 print:grid-cols-2 gap-8 sm:gap-12 print:gap-6 py-8 sm:py-10 print:py-4 border-b border-slate-200 break-inside-avoid">
            <!-- Billed To -->
            <div>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Billed To</p>
                <?php if (!empty($booking['company_name'])): ?>
                    <div class="mb-3 px-3 py-1.5 bg-slate-100 border border-slate-200 rounded-lg inline-block text-[10px] font-bold text-slate-600 uppercase tracking-wider">
                        🏢 Corporate Billing (City Ledger)
                    </div>
                    <p class="text-lg font-bold text-slate-900 mb-1"><?= htmlspecialchars($booking['company_name']) ?></p>
                    <?php if (!empty($booking['company_contact'])): ?>
                        <p class="text-sm text-slate-500 mb-2"><?= htmlspecialchars($booking['company_contact']) ?></p>
                    <?php endif; ?>
                    <div class="h-px bg-slate-200 my-2"></div>
                    <p class="text-xs text-slate-400 uppercase tracking-wider mb-1">Guest Representative</p>
                <?php endif; ?>
                <p class="text-base font-medium text-slate-900 mb-1"><?= htmlspecialchars($booking['guest_name']) ?></p>
                <p class="text-sm text-slate-600 mb-1"><?= htmlspecialchars($booking['guest_phone']) ?></p>
                <?php if($booking['city'] || $booking['state']): ?>
                    <p class="text-sm text-slate-600">
                        <?= htmlspecialchars($booking['city'] ?? '') ?><?php if($booking['city'] && $booking['state']): ?>, <?php endif; ?><?= htmlspecialchars($booking['state'] ?? '') ?>
                        <?php if($booking['pincode']): ?><br><?= htmlspecialchars($booking['pincode']) ?><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- Stay Details -->
            <div>
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-4">Stay Details</p>
                <div class="grid grid-cols-2 gap-y-4 gap-x-8">
                    <div>
                        <p class="text-xs text-slate-500 mb-1">Room</p>
                        <p class="text-sm font-medium text-slate-900"><?= htmlspecialchars($booking['room_number']) ?></p>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars($booking['category_name']) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 mb-1">Duration</p>
                        <p class="text-sm font-medium text-slate-900"><?= $durationStr ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 mb-1">Check-In</p>
                        <p class="text-sm font-medium text-slate-900"><?= date('M d, Y h:i A', strtotime($booking['check_in'])) ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500 mb-1">Check-Out</p>
                        <p class="text-sm font-medium text-slate-900"><?= date('M d, Y h:i A', strtotime($booking['check_out'])) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ledger -->
        <div class="py-8 sm:py-10 print:py-4 overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[300px]">
                <thead>
                    <tr>
                        <th class="py-3 border-b border-slate-200 text-[10px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider">Date</th>
                        <th class="py-3 border-b border-slate-200 text-[10px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider">Description</th>
                        <th class="py-3 border-b border-slate-200 text-[10px] sm:text-xs font-semibold text-slate-500 uppercase tracking-wider text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="text-sm">
                    <?php foreach($ledger as $l): 
                        $amt = (float)$l['amount'];
                    ?>
                    <tr class="break-inside-avoid">
                        <td class="py-4 print:py-2 border-b border-slate-100 text-slate-500 font-mono text-[10px] sm:text-xs w-1/4 align-top">
                            <?= date('M d, Y h:i A', strtotime($l['recorded_at'])) ?>
                        </td>
                        <td class="py-4 print:py-2 border-b border-slate-100 align-top">
                            <span class="font-medium text-slate-900 block text-xs sm:text-sm"><?= htmlspecialchars($l['description']) ?></span>
                            <?php if(!empty($l['payment_method'])): ?>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded text-[9px] sm:text-[10px] font-medium uppercase tracking-wider bg-slate-100 text-slate-500"><?= htmlspecialchars($l['payment_method']) ?></span>
                            <?php endif; ?>
                            <?php if($amt < 0): ?>
                                <span class="inline-block mt-1 text-[10px] sm:text-xs text-emerald-600 font-medium">Payment Received</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-4 print:py-2 border-b border-slate-100 text-right font-mono text-slate-900 align-top text-xs sm:text-sm">
                            <?= $amt > 0 ? '₹' . number_format($amt, 2) : '- ₹' . number_format(abs($amt), 2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($ledger)): ?>
                    <tr>
                        <td colspan="3" class="py-8 text-center border-b border-slate-100 text-slate-500 text-sm">
                            No transactions recorded.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="flex justify-end pt-4 print:pt-2 pb-12 print:pb-4 break-inside-avoid relative">
            
            <!-- Stamp at the bottom near totals -->
            <?php if ($balance > 0): ?>
                <div class="absolute top-0 right-1/4 sm:right-64 print:right-64 border-[4px] sm:border-[6px] border-error-500 text-error-500 text-3xl sm:text-5xl print:text-4xl font-black uppercase tracking-widest py-2 px-6 sm:py-4 sm:px-10 print:py-2 print:px-8 rounded-2xl transform -rotate-12 opacity-80 print:opacity-100 print:border-4 z-50 pointer-events-none">DUE</div>
            <?php else: ?>
                <div class="absolute top-0 right-1/4 sm:right-64 print:right-64 border-[4px] sm:border-[6px] border-emerald-500 text-emerald-500 text-3xl sm:text-5xl print:text-4xl font-black uppercase tracking-widest py-2 px-6 sm:py-4 sm:px-10 print:py-2 print:px-8 rounded-2xl transform -rotate-12 opacity-80 print:opacity-100 print:border-4 z-50 pointer-events-none">PAID</div>
            <?php endif; ?>

            <div class="w-full sm:w-1/2 lg:w-1/3 print:w-1/2 relative z-10">
                <div class="space-y-3 text-sm">
                    <?php if ($taxEnabled): ?>
                    <div class="flex justify-between text-slate-500">
                        <span>Subtotal</span>
                        <span class="font-mono text-slate-900">₹<?= number_format($taxPref === 'inclusive' ? $subtotalCharges - $taxAmount : $subtotalCharges, 2) ?></span>
                    </div>
                    <?php if ($taxPref !== 'exempt'): ?>
                    <div class="flex justify-between text-slate-500">
                        <span><?= htmlspecialchars($taxLabel) ?> (<?= htmlspecialchars((string)$taxRate) ?>%)</span>
                        <span class="font-mono text-slate-900">₹<?= number_format($taxAmount, 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    
                    <div class="flex justify-between text-slate-500">
                        <span>Total Charges</span>
                        <span class="font-mono text-slate-900">₹<?= number_format($totalCharges, 2) ?></span>
                    </div>
                    
                    <?php if ($balance > 0): ?>
                        <div class="flex justify-between text-slate-500 border-b border-slate-200 pb-3">
                            <span>Total Paid</span>
                            <span class="font-mono text-slate-900">₹<?= number_format($totalPayments, 2) ?></span>
                        </div>
                        <div class="flex justify-between items-center pt-2">
                            <span class="font-medium text-slate-900">Amount Due</span>
                            <span class="text-xl sm:text-2xl print:text-xl font-bold font-mono text-slate-900">₹<?= number_format(max(0, $balance), 2) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="flex justify-between text-slate-500 border-b border-slate-200 pb-3">
                            <span>Amount Due</span>
                            <span class="font-mono text-slate-900">₹0.00</span>
                        </div>
                        <div class="flex justify-between items-center pt-2">
                            <span class="font-medium text-emerald-600">Total Paid</span>
                            <span class="text-xl sm:text-2xl print:text-xl font-bold font-mono text-emerald-600">₹<?= number_format($totalPayments, 2) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="pt-8 print:pt-4 border-t border-slate-200 text-center break-inside-avoid">
            <p class="text-[10px] sm:text-xs font-medium text-slate-400 mb-1">Thank you for staying with us!</p>
            <p class="text-[10px] sm:text-xs text-slate-400">If you have any questions regarding this receipt, please contact the front desk.</p>
        </div>

    </div>

</body>
</html>
