<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
AuthHelper::requirePermission('view_finance');
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../pms_core/Database.php';
$db = Database::getInstance()->getConnection();

$startRaw = $_GET['start'] ?? '';
$endRaw = $_GET['end'] ?? '';

if (!empty($startRaw)) {
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $startRaw, $m)) {
        $startRaw = "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    $start = date('Y-m-d', strtotime($startRaw) ?: time());
} else {
    $start = date('Y-m-d', strtotime('-30 days'));
}

if (!empty($endRaw)) {
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $endRaw, $m)) {
        $endRaw = "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    $end = date('Y-m-d', strtotime($endRaw) ?: time());
} else {
    $end = date('Y-m-d');
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$countQuery = "
    SELECT COUNT(*) FROM (
        SELECT recorded_at FROM folio_ledger WHERE amount > 0 AND DATE(recorded_at) >= :cs1 AND DATE(recorded_at) <= :ce1
        UNION ALL
        SELECT recorded_at FROM finance_transactions WHERE DATE(recorded_at) >= :cs2 AND DATE(recorded_at) <= :ce2
    ) AS cq
";
$countStmt = $db->prepare($countQuery);
$countStmt->execute(['cs1' => $start, 'ce1' => $end, 'cs2' => $start, 'ce2' => $end]);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$query = "
    SELECT 
        recorded_at AS date,
        'due' AS type,
        'Room Booking Due' AS category,
        description AS actual_desc,
        booking_id AS ref_id,
        booking_id AS booking_id,
        ABS(amount) AS amount,
        payment_method
    FROM folio_ledger
    WHERE amount > 0 AND DATE(recorded_at) >= :start1 AND DATE(recorded_at) <= :end1
    
    UNION ALL
    
    SELECT 
        recorded_at AS date,
        CASE 
            WHEN category = 'booking' OR booking_id IS NOT NULL OR description LIKE '%Receipt%' OR description LIKE '%Payment%' THEN 'collection'
            ELSE type 
        END AS type,
        CASE 
            WHEN category = 'booking' OR booking_id IS NOT NULL OR description LIKE '%Receipt%' OR description LIKE '%Payment%' THEN 'Room Received Payment'
            ELSE category 
        END AS category,
        description AS actual_desc,
        id AS ref_id,
        booking_id AS booking_id,
        amount,
        payment_method
    FROM finance_transactions
    WHERE DATE(recorded_at) >= :start2 AND DATE(recorded_at) <= :end2
    
    ORDER BY date DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $db->prepare($query);
$stmt->execute([
    'start1' => $start, 'end1' => $end,
    'start2' => $start, 'end2' => $end
]);
$transactions = $stmt->fetchAll();

$metricsQuery = "
    SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as revenue,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
    FROM finance_transactions
    WHERE DATE(recorded_at) >= :ms2 AND DATE(recorded_at) <= :me2
";
$metricsStmt = $db->prepare($metricsQuery);
$metricsStmt->execute(['ms2' => $start, 'me2' => $end]);
$metrics = $metricsStmt->fetch();
$totalRevenue = $metrics['revenue'] ?: 0;
$totalExpense = $metrics['expense'] ?: 0;
$netProfit = $totalRevenue - $totalExpense;

$duesQuery = "SELECT SUM(amount) FROM folio_ledger";
$totalDues = $db->query($duesQuery)->fetchColumn() ?: 0;

$unpaidQuery = "
    SELECT b.id, g.name as guest_name, r.room_number, SUM(fl.amount) as balance 
    FROM folio_ledger fl 
    JOIN bookings b ON fl.booking_id = b.id 
    LEFT JOIN guests g ON b.guest_id = g.id 
    JOIN rooms r ON b.room_id = r.id 
    GROUP BY b.id 
    HAVING balance > 0 
    ORDER BY balance DESC
";
$unpaidStmt = $db->query($unpaidQuery);
$unpaidBookings = $unpaidStmt->fetchAll(PDO::FETCH_ASSOC);

$pmStmt = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'payment_methods'");
$pmJson = $pmStmt->fetchColumn();
$paymentMethods = $pmJson ? json_decode($pmJson, true) : [];
if (empty($paymentMethods)) {
    $paymentMethods = ["Cash", "UPI", "Online / Gateway"];
}

// Chart.js query for daily income vs expense
$chartQuery = "
    SELECT 
        DATE(recorded_at) as day,
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
    FROM finance_transactions
    WHERE DATE(recorded_at) >= :cs2 AND DATE(recorded_at) <= :ce2
    GROUP BY DATE(recorded_at)
    ORDER BY DATE(recorded_at) ASC
";
$chartStmt = $db->prepare($chartQuery);
$chartStmt->execute([
    'cs2' => $start, 'ce2' => $end
]);
$chartRows = $chartStmt->fetchAll(PDO::FETCH_ASSOC);

$chartLabels = [];
$chartIncome = [];
$chartExpense = [];
foreach ($chartRows as $row) {
    $chartLabels[] = date('d M', strtotime($row['day']));
    $chartIncome[] = (float)$row['income'];
    $chartExpense[] = (float)$row['expense'];
}

// Compute category totals for expenses in the period
$catSummary = [
    'F&B' => 0.0,
    'Laundry' => 0.0,
    'Maintenance' => 0.0,
    'Salary' => 0.0,
    'Misc' => 0.0
];
// Get all transactions in period for calculating category spending
$allPeriodStmt = $db->prepare("
    SELECT category, amount FROM finance_transactions 
    WHERE type = 'expense' AND DATE(recorded_at) >= :start AND DATE(recorded_at) <= :end
");
$allPeriodStmt->execute(['start' => $start, 'end' => $end]);
$periodExpenses = $allPeriodStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($periodExpenses as $pe) {
    $cat = $pe['category'];
    if (isset($catSummary[$cat])) {
        $catSummary[$cat] += (float)$pe['amount'];
    } else {
        $catSummary['Misc'] += (float)$pe['amount'];
    }
}
$totalCatExpenses = array_sum($catSummary);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @media print {
            header, nav, .fixed, #filterForm, .lg\:col-span-1, .flex-wrap, .relative, select, input, button {
                display: none !important;
            }
            main {
                padding: 0 !important;
            }
            .card-minimal {
                border: none !important;
                box-shadow: none !important;
            }
            .hidden.md\:block {
                display: block !important;
            }
        }
    </style>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Money Manager | MicroPMS</title>
    
        

    
        <?php include __DIR__ . '/components/mobile_nav.php'; ?>

    <?php include __DIR__ . '/components/ui_head.php'; ?>

</head>
<body class="flex flex-col min-h-screen bg-slate-50">
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
        
        <!-- App Bar -->
        <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0 mb-6">
            <div class="flex items-center gap-3">
                <a href="index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                    <i class="ph ph-caret-left text-2xl text-slate-800"></i>
                </a>
                <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Money Manager</h1>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="text-indigo-600 bg-indigo-50 p-2 rounded-full hover:bg-indigo-100 transition-colors" title="Print Report">
                    <i class="ph ph-printer text-xl"></i>
                </button>
                <a href="/api/admin/export_finance?start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>" class="text-amber-600 bg-amber-50 p-2 rounded-full hover:bg-amber-100 transition-colors" title="Export CSV">
                    <i class="ph ph-download-simple text-xl"></i>
                </a>
                <?php include __DIR__ . '/components/desktop_nav.php'; ?>
            </div>
        </header>
        
        <main class="flex-1 p-4 pb-24 space-y-6">
            
            <!-- Metrics -->
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <i class="ph ph-trend-up text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-brand-900/70 text-xs font-bold uppercase tracking-wider mb-1">Total Revenue</h3>
                        <p class="text-2xl font-semibold text-brand-900">₹<?= number_format($totalRevenue, 2) ?></p>
                    </div>
                </div>
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-error-50 text-error-600 flex items-center justify-center">
                        <i class="ph ph-trend-down text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-brand-900/70 text-xs font-bold uppercase tracking-wider mb-1">Total Expenses</h3>
                        <p class="text-2xl font-semibold text-error-600">₹<?= number_format($totalExpense, 2) ?></p>
                    </div>
                </div>
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center">
                        <i class="ph ph-coins text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-brand-900/70 text-xs font-bold uppercase tracking-wider mb-1">Net Profit</h3>
                        <p class="text-2xl font-semibold <?= $netProfit >= 0 ? 'text-blue-600' : 'text-error-600' ?>">₹<?= number_format($netProfit, 2) ?></p>
                    </div>
                </div>
                <div class="card-minimal p-5 flex items-center gap-4 cursor-pointer hover:bg-orange-50/50 transition-colors group" onclick="openUnpaidModal()">
                    <div class="w-12 h-12 rounded-full bg-orange-50 text-orange-600 flex items-center justify-center group-hover:bg-orange-100 transition-colors">
                        <i class="ph ph-hand-coins text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-brand-900/70 text-xs font-bold uppercase tracking-wider mb-1 flex items-center gap-1">Total Dues <i class="ph ph-info text-orange-600/70"></i></h3>
                        <p class="text-2xl font-semibold text-orange-600">₹<?= number_format(max(0, $totalDues), 2) ?></p>
                    </div>
                </div>
            </div>

            <!-- Middle Row: Chart & Filters -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Interactive Chart & Toggle -->
                <div class="lg:col-span-2 card-minimal p-5 flex flex-col">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                            <i class="ph ph-chart-bar text-lg text-indigo-600"></i> <span id="chartTitle">Daily Cash Flow</span>
                        </h3>
                        <div class="flex bg-brand-100 p-0.5 rounded-lg text-xs font-bold self-end sm:self-auto">
                            <button onclick="toggleChartType('bar')" id="btnChartBar" class="px-3 py-1.5 rounded-md bg-white text-brand-900 shadow-sm transition-all">Cash Flow</button>
                            <button onclick="toggleChartType('doughnut')" id="btnChartDoughnut" class="px-3 py-1.5 rounded-md text-brand-900/60 hover:text-brand-900 transition-all">Expense Breakdown</button>
                        </div>
                    </div>
                    <div class="h-64 w-full flex-1 relative">
                        <canvas id="financeChart"></canvas>
                    </div>
                </div>

                <!-- Right column: Filters & Category Breakdown -->
                <div class="lg:col-span-1 flex flex-col gap-6">
                    <!-- Filters -->
                    <div class="card-minimal p-5 flex flex-col justify-center">
                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider mb-4 flex items-center gap-2">
                            <i class="ph ph-calendar-blank text-lg text-brand-400"></i> Filter Period
                        </h3>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <button onclick="setDates('<?= date('Y-m-d') ?>', '<?= date('Y-m-d') ?>')" class="px-3 py-1.5 text-xs font-bold rounded-lg bg-brand-100 hover:bg-brand-200 text-brand-900 transition-colors flex-1 text-center">Today</button>
                            <button onclick="setDates('<?= date('Y-m-d', strtotime('-1 day')) ?>', '<?= date('Y-m-d', strtotime('-1 day')) ?>')" class="px-3 py-1.5 text-xs font-bold rounded-lg bg-brand-100 hover:bg-brand-200 text-brand-900 transition-colors flex-1 text-center">Yesterday</button>
                            <button onclick="setDates('<?= date('Y-m-d', strtotime('-7 days')) ?>', '<?= date('Y-m-d') ?>')" class="px-3 py-1.5 text-xs font-bold rounded-lg bg-brand-100 hover:bg-brand-200 text-brand-900 transition-colors flex-1 text-center">7 Days</button>
                        </div>
                        <div class="flex flex-wrap gap-2 mb-4">
                            <button onclick="setDates('<?= date('Y-m-01') ?>', '<?= date('Y-m-t') ?>')" class="px-3 py-1.5 text-xs font-bold rounded-lg bg-brand-100 hover:bg-brand-200 text-brand-900 transition-colors flex-1 text-center">This Month</button>
                            <button onclick="setDates('<?= date('Y-01-01') ?>', '<?= date('Y-12-31') ?>')" class="px-3 py-1.5 text-xs font-bold rounded-lg bg-brand-100 hover:bg-brand-200 text-brand-900 transition-colors flex-1 text-center">YTD</button>
                        </div>
                        <form class="flex flex-col gap-3 w-full" id="filterForm">
                            <div class="flex items-center gap-2 w-full">
                                <input type="date" id="dateStart" name="start" value="<?= $start ?>" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-xl text-sm outline-none focus:border-brand-900 font-medium">
                                <span class="text-brand-400 font-bold">→</span>
                                <input type="date" id="dateEnd" name="end" value="<?= $end ?>" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-xl text-sm outline-none focus:border-brand-900 font-medium">
                            </div>
                            <button type="submit" class="w-full bg-brand-900 text-white px-6 py-2.5 rounded-xl text-sm font-bold active:scale-95 transition-transform hover:bg-brand-800 flex items-center justify-center gap-2">
                                <i class="ph ph-funnel"></i> Apply Filter
                            </button>
                        </form>
                    </div>

                    <!-- Expense Distribution Summary -->
                    <div class="card-minimal p-5 space-y-4">
                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                            <i class="ph ph-chart-pie text-lg text-rose-500"></i> Expense Breakdown
                        </h3>
                        <div class="space-y-3.5">
                            <?php foreach($catSummary as $cat => $amt): 
                                $pct = $totalCatExpenses > 0 ? round(($amt / $totalCatExpenses) * 100) : 0;
                            ?>
                            <div>
                                <div class="flex justify-between items-center text-xs font-bold text-brand-900 mb-1">
                                    <span><?= htmlspecialchars($cat) ?></span>
                                    <span>₹<?= number_format($amt, 0) ?> (<?= $pct ?>%)</span>
                                </div>
                                <div class="w-full bg-brand-50 h-2 rounded-full overflow-hidden">
                                    <div class="bg-rose-500 h-full rounded-full transition-all duration-500" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ledger Table (Responsive Cards on Mobile) -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mt-6 mb-4 px-1">
                <h2 class="text-xl font-bold text-brand-900 tracking-tight">Ledger</h2>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="relative min-w-[150px]">
                        <select id="filterType" onchange="filterLedger()" class="w-full bg-white border border-brand-200 rounded-lg px-3 py-1.5 text-xs font-bold focus:outline-none focus:shadow-minimal text-brand-900 appearance-none pr-8">
                            <option value="all">All Types</option>
                            <option value="collection">Room Payments</option>
                            <option value="due">Room Dues</option>
                            <option value="income">Misc Income</option>
                            <option value="expense">Expenses</option>
                        </select>
                        <i class="ph ph-caret-down absolute right-3 top-2 text-brand-400 pointer-events-none"></i>
                    </div>
                    <div class="relative min-w-[150px]">
                        <select id="filterMethod" onchange="filterLedger()" class="w-full bg-white border border-brand-200 rounded-lg px-3 py-1.5 text-xs font-bold focus:outline-none focus:shadow-minimal text-brand-900 appearance-none pr-8">
                            <option value="all">All Methods</option>
                            <?php foreach($paymentMethods as $pm): ?>
                                <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                            <?php endforeach; ?>
                            <option value="-">-</option>
                        </select>
                        <i class="ph ph-caret-down absolute right-3 top-2 text-brand-400 pointer-events-none"></i>
                    </div>
                    <div class="relative w-full sm:w-48">
                        <input type="text" id="ledgerSearch" onkeyup="filterLedger()" placeholder="Search..." class="w-full bg-white border border-brand-200 rounded-lg pl-9 pr-3 py-1.5 text-xs focus:outline-none focus:shadow-minimal transition-all">
                        <i class="ph ph-magnifying-glass absolute left-3 top-2 text-brand-400"></i>
                    </div>
                </div>
            </div>
            
            <div class="card-minimal overflow-hidden">
                <div class="overflow-x-auto rounded-2xl border border-slate-100">
                    <table class="w-full text-left border-collapse bg-white whitespace-nowrap">
                        <thead class="bg-slate-50 border-b border-slate-100">
                            <tr>
                                <th class="px-6 py-4 font-bold text-slate-500 uppercase tracking-widest text-[10px]">Date</th>
                                <th class="px-6 py-4 font-bold text-slate-500 uppercase tracking-widest text-[10px]">Category</th>
                                <th class="px-6 py-4 font-bold text-slate-500 uppercase tracking-widest text-[10px]">Description</th>
                                <th class="px-6 py-4 font-bold text-slate-500 uppercase tracking-widest text-[10px]">Mode</th>
                                <th class="px-6 py-4 font-bold text-slate-500 uppercase tracking-widest text-[10px] text-right">Amount</th>
                                <th class="px-6 py-4 font-bold text-slate-500 uppercase tracking-widest text-[10px] text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50" id="desktopLedger">
                            <?php if(empty($transactions)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-brand-900/50 font-medium text-sm">
                                    <i class="ph ph-receipt text-3xl mb-2 block opacity-50"></i>
                                    No ledger entries found for this period.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($transactions as $t): ?>
                            <tr class="hover:bg-brand-50 transition-colors desktop-row" data-type="<?= htmlspecialchars($t['type']) ?>" data-method="<?= htmlspecialchars($t['payment_method'] ?? '') ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-brand-900/70 font-medium text-xs">
                                    <?= date('M j, Y g:i A', strtotime($t['date'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 text-[10px] font-bold rounded-full uppercase tracking-wider
                                        <?= ($t['category'] === 'Room Received Payment' || $t['type'] === 'collection') ? 'bg-emerald-100 text-emerald-800 font-extrabold' : ($t['type'] === 'due' ? 'bg-orange-50 text-orange-700' : ($t['type'] === 'income' ? 'bg-emerald-50 text-emerald-700' : 'bg-error-50 text-error-700')) ?>">
                                        <?= htmlspecialchars($t['category']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-brand-800 font-medium">
                                    <?php 
                                    $hasFolioLink = !empty($t['booking_id']) || $t['category'] === 'booking' || $t['type'] === 'due' || $t['type'] === 'collection';
                                    $bId = $t['booking_id'] ?: $t['ref_id'];
                                    ?>
                                    <?php if($hasFolioLink && $bId): ?>
                                        <a href="folio.php?id=<?= $bId ?>" class="text-brand-accent hover:underline flex items-center gap-1 font-bold">
                                            Folio #<?= htmlspecialchars((string)$bId) ?>
                                            <i class="ph ph-arrow-square-out text-sm"></i>
                                        </a>
                                        <div class="text-xs text-brand-900/70 mt-1"><?= htmlspecialchars($t['actual_desc']) ?></div>
                                    <?php else: ?>
                                        <?= htmlspecialchars($t['actual_desc']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if(!empty($t['payment_method'])): ?>
                                        <span class="px-3 py-1 text-[10px] font-bold rounded bg-brand-100 text-brand-900 uppercase tracking-wider">
                                            <?= htmlspecialchars($t['payment_method']) ?>
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right font-semibold text-lg <?= $t['type'] === 'expense' ? 'text-error-600' : ($t['type'] === 'due' ? 'text-orange-600' : 'text-emerald-600') ?>">
                                    <?= $t['type'] === 'expense' ? '-' : ($t['type'] === 'due' ? '' : '+') ?>₹<?= number_format($t['amount'], 2) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right space-x-1">
                                    <?php if($hasFolioLink && $bId): ?>
                                        <a href="folio.php?id=<?= $bId ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand-accentLight text-brand-accent hover:bg-brand-accentLight font-bold text-xs transition-colors" title="View & Modify in Room Folio">
                                            Modify in Folio <i class="ph ph-arrow-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <button onclick="openEditFinance(<?= $t['ref_id'] ?>, '<?= htmlspecialchars(addslashes($t['actual_desc'] ?? '')) ?>', <?= $t['amount'] ?>, '<?= htmlspecialchars(addslashes($t['category'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($t['payment_method'] ?? '')) ?>')" class="w-11 h-11 inline-flex items-center justify-center rounded-lg bg-brand-100 hover:bg-brand-200 text-brand-900 transition-colors" title="Edit Record"><i class="ph ph-pencil-simple text-base"></i></button>
                                        <button onclick="deleteFinance(<?= $t['ref_id'] ?>)" class="w-11 h-11 inline-flex items-center justify-center rounded-lg bg-error-50 hover:bg-error-100 text-error-600 transition-colors" title="Delete Record"><i class="ph ph-trash text-base"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php if($totalPages > 1): ?>
            <div class="flex items-center justify-center gap-2 mt-6">
                <?php if($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>" class="px-3 py-1.5 text-sm font-bold rounded-lg bg-white border border-brand-200 text-brand-900 hover:bg-brand-50">Previous</a>
                <?php endif; ?>
                <span class="px-3 py-1.5 text-sm font-bold text-brand-900/70">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>&start=<?= urlencode($start) ?>&end=<?= urlencode($end) ?>" class="px-3 py-1.5 text-sm font-bold rounded-lg bg-white border border-brand-200 text-brand-900 hover:bg-brand-50">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
        </main>

        <!-- Floating Action Buttons -->
        <div class="fixed bottom-24 md:bottom-6 right-4 md:right-6 flex flex-col gap-4 z-40">
            <button onclick="openModal('expense')" class="w-14 h-14 bg-error-600 text-white rounded-full  shadow-rose-200 flex items-center justify-center hover:bg-error-700 active:scale-95 transition-transform">
                <i class="ph ph-minus text-2xl font-bold"></i>
            </button>
            <button onclick="openModal('income')" class="w-14 h-14 bg-emerald-600 text-white rounded-full  shadow-emerald-200 flex items-center justify-center hover:bg-emerald-700 active:scale-95 transition-transform">
                <i class="ph ph-plus text-2xl font-bold"></i>
            </button>
        </div>

        <!-- Overlay -->
        <div id="modalOverlay" class="fixed inset-0 bg-brand-900/40 z-40 hidden transition-opacity opacity-0 backdrop-blur-sm" onclick="closeModal()"></div>

        <!-- Bottom Sheet Modal -->
        <div id="financeModal" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto bg-white shadow-[0_-10px_40px_-15px_rgba(0,0,0,0.1)] rounded-t-3xl border-t border-slate-100 z-50 transform translate-y-full transition-transform duration-300 ease-out">
            <div class="p-6">
                <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
                <h3 id="modalTitle" class="text-2xl font-semibold mb-6 text-brand-900 tracking-tight">Record Transaction</h3>
                <form onsubmit="submitFinance(event, this)" class="space-y-4">
                    <input type="hidden" id="trans_type" name="type" value="income">
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Category</label>
                        <div class="relative">
                            <select name="category" id="trans_cat" class="w-full bg-brand-50/50 rounded-xl border border-brand-200 focus:bg-white focus:shadow-minimal transition-all p-3.5 outline-none font-bold text-brand-900 text-lg appearance-none">
                                <option value="F&B">Food & Beverage</option>
                                <option value="Laundry">Laundry</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Salary">Salary</option>
                                <option value="Misc">Miscellaneous</option>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none text-lg"></i>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Amount (₹)</label>
                        <input type="number" name="amount" required min="1" step="0.01" class="w-full bg-brand-50/50 rounded-xl border border-brand-200 focus:bg-white focus:shadow-minimal transition-all p-3.5 outline-none font-semibold text-brand-900 text-xl">
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Payment Method</label>
                        <div class="relative">
                            <select name="payment_method" class="w-full bg-brand-50/50 rounded-xl border border-brand-200 focus:bg-white focus:shadow-minimal transition-all p-3.5 outline-none font-bold text-brand-900 text-lg appearance-none">
                                <?php foreach($paymentMethods as $pm): ?>
                                    <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none text-lg"></i>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Description</label>
                        <input type="text" name="description" required class="w-full bg-brand-50/50 rounded-xl border border-brand-200 focus:bg-white focus:shadow-minimal transition-all p-3.5 outline-none font-medium text-brand-900 text-lg" placeholder="e.g. Plumber for Room 101">
                    </div>
                    
                    <button type="submit" class="w-full bg-brand-900 text-white font-bold py-4 mt-2 rounded-xl active:scale-95 transition-transform text-lg">Save Record</button>
                </form>
            </div>
        </div>

        <!-- Edit Finance Modal -->
        <div id="editFinanceModal" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto bg-white shadow-[0_-10px_40px_-15px_rgba(0,0,0,0.1)] rounded-t-3xl border-t border-slate-100 z-50 transform translate-y-full transition-transform duration-300 ease-out">
            <div class="p-6">
                <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
                <h3 class="text-2xl font-semibold mb-6 text-brand-900 tracking-tight">Edit Transaction</h3>
                <form onsubmit="submitEditFinance(event, this)" class="space-y-4">
                    <input type="hidden" id="edit_f_id" name="id">
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Category</label>
                        <div class="relative">
                            <select name="category" id="edit_f_cat" class="w-full bg-brand-50/50 rounded-xl border border-brand-200 focus:bg-white focus:shadow-minimal transition-all p-3.5 outline-none font-bold text-brand-900 text-lg appearance-none">
                                <option value="F&B">Food & Beverage</option>
                                <option value="Laundry">Laundry</option>
                                <option value="Maintenance">Maintenance</option>
                                <option value="Salary">Salary</option>
                                <option value="Misc">Miscellaneous</option>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none text-lg"></i>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Amount (₹)</label>
                        <input type="number" id="edit_f_amt" name="amount" required min="1" step="0.01" class="w-full bg-brand-50/50 rounded-xl border border-brand-200 focus:bg-white focus:shadow-minimal transition-all p-3.5 outline-none font-semibold text-brand-900 text-xl">
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Payment Method</label>
                        <div class="relative">
                            <select name="payment_method" id="edit_f_method" class="w-full bg-brand-50/50 rounded-xl border border-brand-200 focus:bg-white focus:shadow-minimal transition-all p-3.5 outline-none font-bold text-brand-900 text-lg appearance-none">
                                <?php foreach($paymentMethods as $pm): ?>
                                    <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-4 text-brand-400 pointer-events-none text-lg"></i>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-brand-900/70 mb-1.5 uppercase tracking-wider">Description</label>
                        <input type="text" id="edit_f_desc" name="description" required class="w-full bg-brand-50/50 rounded-xl border border-brand-200 focus:bg-white focus:shadow-minimal transition-all p-3.5 outline-none font-medium text-brand-900 text-lg">
                    </div>
                    
                    <button type="submit" class="w-full bg-brand-900 text-white font-bold py-4 mt-2 rounded-xl active:scale-95 transition-transform text-lg">Save Changes</button>
                </form>
            </div>
        </div>

        <!-- Unpaid Bookings Modal -->
        <div id="unpaidModal" class="fixed bottom-0 left-0 right-0 max-w-md mx-auto bg-white shadow-[0_-10px_40px_-15px_rgba(0,0,0,0.1)] rounded-t-3xl border-t border-slate-100 z-50 transform translate-y-full transition-transform duration-300 ease-out flex flex-col max-h-[85vh]">
            <div class="p-6 flex-shrink-0 border-b border-slate-100">
                <div class="w-12 h-1.5 bg-brand-200 rounded-full mx-auto mb-6"></div>
                <h3 class="text-2xl font-semibold text-brand-900 tracking-tight">Outstanding Dues</h3>
                <p class="text-xs text-brand-900/70 mt-1">Bookings with pending payments.</p>
            </div>
            <div class="p-6 overflow-y-auto flex-1 space-y-4">
                <?php if (empty($unpaidBookings)): ?>
                    <p class="text-brand-900/70 text-sm text-center py-6">No outstanding dues.</p>
                <?php else: ?>
                    <?php foreach ($unpaidBookings as $ub): ?>
                    <div class="flex items-center justify-between p-4 bg-brand-50 rounded-xl hover:bg-brand-100 transition-colors">
                        <div>
                            <a href="folio.php?id=<?= $ub['id'] ?>" class="font-bold text-brand-900 hover:text-brand-accent transition-colors flex items-center gap-1">
                                <?= htmlspecialchars($ub['guest_name'] ?? 'Unknown Guest') ?>
                                <i class="ph ph-arrow-square-out text-sm"></i>
                            </a>
                            <p class="text-xs text-brand-900/70 mt-1">Room <?= htmlspecialchars($ub['room_number']) ?> (Folio #<?= $ub['id'] ?>)</p>
                        </div>
                        <div class="text-right">
                            <span class="text-lg font-bold text-orange-600">₹<?= number_format($ub['balance'], 2) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function setDates(start, end) {
            document.getElementById('dateStart').value = start;
            document.getElementById('dateEnd').value = end;
            document.getElementById('filterForm').submit();
        }
        
        function filterLedger() {
            const searchInput = document.getElementById('ledgerSearch').value.toLowerCase();
            const typeFilter = document.getElementById('filterType').value.toLowerCase();
            const methodFilter = document.getElementById('filterMethod').value.toLowerCase();
            
            const desktopRows = document.querySelectorAll('.desktop-row');
            desktopRows.forEach(row => {
                const text = row.innerText.toLowerCase();
                const type = row.dataset.type ? row.dataset.type.toLowerCase() : '';
                const method = row.dataset.method ? row.dataset.method.toLowerCase() : '';
                
                const searchMatch = text.includes(searchInput);
                const typeMatch = (typeFilter === 'all' || type === typeFilter);
                const methodMatch = (methodFilter === 'all' || method === methodFilter || (methodFilter === '-' && !method));
                
                row.style.display = (searchMatch && typeMatch && methodMatch) ? '' : 'none';
            });
            
            const mobileRows = document.querySelectorAll('.mobile-row');
            mobileRows.forEach(row => {
                const text = row.innerText.toLowerCase();
                const type = row.dataset.type ? row.dataset.type.toLowerCase() : '';
                const method = row.dataset.method ? row.dataset.method.toLowerCase() : '';
                
                const searchMatch = text.includes(searchInput);
                const typeMatch = (typeFilter === 'all' || type === typeFilter);
                const methodMatch = (methodFilter === 'all' || method === methodFilter || (methodFilter === '-' && !method));
                
                row.style.display = (searchMatch && typeMatch && methodMatch) ? '' : 'none';
            });
        }
        
        function openModal(type) {
            document.getElementById('trans_type').value = type;
            document.getElementById('modalTitle').innerText = type === 'income' ? 'Record Misc Income' : 'Record Expense';
            
            const modal = document.getElementById('financeModal');
            const overlay = document.getElementById('modalOverlay');
            
            overlay.classList.remove('hidden');
            void overlay.offsetWidth; // trigger reflow
            overlay.classList.remove('opacity-0');
            modal.classList.remove('translate-y-full');
        }

        function openUnpaidModal() {
            const modal = document.getElementById('unpaidModal');
            const overlay = document.getElementById('modalOverlay');
            
            overlay.classList.remove('hidden');
            void overlay.offsetWidth; // trigger reflow
            overlay.classList.remove('opacity-0');
            modal.classList.remove('translate-y-full');
        }

        function closeModal() {
            const modal = document.getElementById('financeModal');
            const editModal = document.getElementById('editFinanceModal');
            const unpaidModal = document.getElementById('unpaidModal');
            const overlay = document.getElementById('modalOverlay');
            
            overlay.classList.add('opacity-0');
            modal.classList.add('translate-y-full');
            editModal.classList.add('translate-y-full');
            if (unpaidModal) unpaidModal.classList.add('translate-y-full');
            
            setTimeout(() => {
                overlay.classList.add('hidden');
            }, 300);
        }
        
        function openEditFinance(id, desc, amt, cat, method) {
            document.getElementById('edit_f_id').value = id;
            document.getElementById('edit_f_desc').value = desc;
            document.getElementById('edit_f_amt').value = amt;
            document.getElementById('edit_f_cat').value = cat;
            if(method) document.getElementById('edit_f_method').value = method;
            
            const editModal = document.getElementById('editFinanceModal');
            const overlay = document.getElementById('modalOverlay');
            
            overlay.classList.remove('hidden');
            void overlay.offsetWidth; // trigger reflow
            overlay.classList.remove('opacity-0');
            editModal.classList.remove('translate-y-full');
        }

        async function submitFinance(e, form) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = 'Saving...';
            btn.classList.add('opacity-75');
            btn.disabled = true;
            
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            
            try {
                const res = await fetch('/api/admin/add_finance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                
                if (result.success) {
                    closeModal();
                    setTimeout(() => location.reload(), 300);
                } else {
                    showToast(result.message);
                    btn.innerText = originalText;
                    btn.classList.remove('opacity-75');
                    btn.disabled = false;
                }
            } catch(err) {
                showToast("Request failed");
                btn.innerText = originalText;
                btn.classList.remove('opacity-75');
                btn.disabled = false;
            }
        }
        
        async function submitEditFinance(e, form) {
            e.preventDefault();
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerText;
            btn.innerText = 'Saving...';
            btn.classList.add('opacity-75');
            btn.disabled = true;
            
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            
            try {
                const res = await fetch('/api/admin/edit_finance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                
                if (result.success) {
                    closeModal();
                    setTimeout(() => location.reload(), 300);
                } else {
                    showToast(result.message);
                    btn.innerText = originalText;
                    btn.classList.remove('opacity-75');
                    btn.disabled = false;
                }
            } catch(err) {
                showToast("Request failed");
                btn.innerText = originalText;
                btn.classList.remove('opacity-75');
                btn.disabled = false;
            }
        }
        
        async function deleteFinance(id) {
            const confirmed = await pmsConfirm("Are you sure you want to delete this transaction? This will permanently affect your cash ledger.");
            if(!confirmed) return;
            try {
                const res = await fetch('/api/admin/delete_finance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const result = await res.json();
                if (result.success) {
                    location.reload();
                } else {
                    showToast(result.message);
                }
            } catch (err) {
                showToast("Request failed");
            }
        }

        // ChartJS for finance
        const ctx = document.getElementById('financeChart').getContext('2d');
        const labels = <?= json_encode($chartLabels) ?>;
        const incomeData = <?= json_encode($chartIncome) ?>;
        const expenseData = <?= json_encode($chartExpense) ?>;

        const doughnutLabels = <?= json_encode(array_keys($catSummary)) ?>;
        const doughnutData = <?= json_encode(array_values($catSummary)) ?>;

        const chartConfig = {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Income / Collections',
                        data: incomeData,
                        backgroundColor: '#10B981',
                        borderRadius: 6,
                    },
                    {
                        label: 'Expenses',
                        data: expenseData,
                        backgroundColor: '#EF4444',
                        borderRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: { family: 'Inter', weight: '600' }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== undefined && context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(context.parsed.y);
                                } else if (context.parsed !== undefined && context.parsed !== null) {
                                    label += new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(context.parsed);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value;
                            }
                        }
                    }
                }
            }
        };

        let myChart = new Chart(ctx, chartConfig);

        function toggleChartType(type) {
            const btnBar = document.getElementById('btnChartBar');
            const btnDoughnut = document.getElementById('btnChartDoughnut');
            const chartTitle = document.getElementById('chartTitle');

            if (type === 'bar') {
                btnBar.className = "px-3 py-1.5 rounded-md bg-white text-brand-900 shadow-sm transition-all";
                btnDoughnut.className = "px-3 py-1.5 rounded-md text-brand-900/60 hover:text-brand-900 transition-all";
                chartTitle.innerText = "Daily Cash Flow";

                myChart.destroy();
                chartConfig.type = 'bar';
                chartConfig.data = {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Income / Collections',
                            data: incomeData,
                            backgroundColor: '#10B981',
                            borderRadius: 6,
                        },
                        {
                            label: 'Expenses',
                            data: expenseData,
                            backgroundColor: '#EF4444',
                            borderRadius: 6,
                        }
                    ]
                };
                chartConfig.options.scales = {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) { return '₹' + value; }
                        }
                    }
                };
                myChart = new Chart(ctx, chartConfig);
            } else {
                btnDoughnut.className = "px-3 py-1.5 rounded-md bg-white text-brand-900 shadow-sm transition-all";
                btnBar.className = "px-3 py-1.5 rounded-md text-brand-900/60 hover:text-brand-900 transition-all";
                chartTitle.innerText = "Expense Breakdown";

                myChart.destroy();
                chartConfig.type = 'doughnut';
                chartConfig.data = {
                    labels: doughnutLabels,
                    datasets: [{
                        data: doughnutData,
                        backgroundColor: ['#F59E0B', '#3B82F6', '#10B981', '#6366F1', '#EC4899'],
                    }]
                };
                chartConfig.options.scales = {
                    x: { display: false },
                    y: { display: false }
                };
                myChart = new Chart(ctx, chartConfig);
            }
        }
    </script>
</body>
</html>
