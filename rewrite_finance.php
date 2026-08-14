<?php
require_once __DIR__ . '/pms_core/HttpScriptGuard.php';
$content = file_get_contents("public_html/admin/finance.php");

// 1. Replace the top metrics SQL blocks
$metricsSearch = <<<'OLD'
$metricsQuery = "
    SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as revenue,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
    FROM finance_transactions
    WHERE property_id = :p3 AND DATE(recorded_at) >= :ms2 AND DATE(recorded_at) <= :me2
";
$metricsStmt = $db->prepare($metricsQuery);
$metricsStmt->execute(['ms2' => $start, 'me2' => $end, 'p3' => $propertyId]);
$metrics = $metricsStmt->fetch();
$totalRevenue = $metrics['revenue'] ?: 0;
$totalExpense = $metrics['expense'] ?: 0;
$netProfit = $totalRevenue - $totalExpense;

$duesStmt = $db->prepare("SELECT SUM(fl.amount) FROM folio_ledger fl JOIN bookings b ON fl.booking_id = b.id WHERE b.property_id = ?");
$duesStmt->execute([(int)$propertyId]);
$totalDues = $duesStmt->fetchColumn() ?: 0;
OLD;

$metricsReplace = <<<'NEW'
$billedStmt = $db->prepare("
    SELECT SUM(fl.amount) as billed, SUM(fl.cgst_amount + fl.sgst_amount) as tax
    FROM folio_ledger fl 
    JOIN bookings b ON fl.booking_id = b.id 
    WHERE b.property_id = :p 
    AND fl.amount > 0 
    AND DATE(fl.recorded_at) >= :s 
    AND DATE(fl.recorded_at) <= :e
");
$billedStmt->execute(['p' => $propertyId, 's' => $start, 'e' => $end]);
$billedData = $billedStmt->fetch(PDO::FETCH_ASSOC);
$totalBilled = $billedData['billed'] ?: 0;
$totalTax = $billedData['tax'] ?: 0;

$folioCollStmt = $db->prepare("
    SELECT SUM(ABS(fl.amount)) as coll 
    FROM folio_ledger fl 
    JOIN bookings b ON fl.booking_id = b.id 
    WHERE b.property_id = :p 
    AND fl.amount < 0 
    AND DATE(fl.recorded_at) >= :s 
    AND DATE(fl.recorded_at) <= :e
");
$folioCollStmt->execute(['p' => $propertyId, 's' => $start, 'e' => $end]);
$folioColl = $folioCollStmt->fetchColumn() ?: 0;

$financeMetricsStmt = $db->prepare("
    SELECT 
        SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as expense
    FROM finance_transactions
    WHERE property_id = :p 
    AND DATE(recorded_at) >= :s 
    AND DATE(recorded_at) <= :e
");
$financeMetricsStmt->execute(['p' => $propertyId, 's' => $start, 'e' => $end]);
$finMetrics = $financeMetricsStmt->fetch(PDO::FETCH_ASSOC);
$totalMiscIncome = $finMetrics['income'] ?: 0;
$totalExpense = $finMetrics['expense'] ?: 0;

$totalCollections = $folioColl + $totalMiscIncome;
$netProfit = $totalCollections - $totalExpense;

$duesStmt = $db->prepare("SELECT SUM(fl.amount) FROM folio_ledger fl JOIN bookings b ON fl.booking_id = b.id WHERE b.property_id = ?");
$duesStmt->execute([(int)$propertyId]);
$totalDues = $duesStmt->fetchColumn() ?: 0;

$payMethodQuery = "
    SELECT payment_method, SUM(amount) as total FROM (
        SELECT payment_method, ABS(amount) as amount FROM folio_ledger fl JOIN bookings b ON fl.booking_id = b.id WHERE b.property_id = :p1 AND fl.amount < 0 AND DATE(fl.recorded_at) >= :s1 AND DATE(fl.recorded_at) <= :e1
        UNION ALL
        SELECT payment_method, amount FROM finance_transactions WHERE property_id = :p2 AND type = 'income' AND DATE(recorded_at) >= :s2 AND DATE(recorded_at) <= :e2
    ) as combined
    WHERE payment_method IS NOT NULL AND payment_method != ''
    GROUP BY payment_method
";
$pmStmtData = $db->prepare($payMethodQuery);
$pmStmtData->execute(['p1' => $propertyId, 's1' => $start, 'e1' => $end, 'p2' => $propertyId, 's2' => $start, 'e2' => $end]);
$paymentMethodBreakdown = $pmStmtData->fetchAll(PDO::FETCH_ASSOC);

$incCatQuery = "
    SELECT category, SUM(amount) as total FROM (
        SELECT COALESCE(fl.category, 'Room') as category, ABS(amount) as amount FROM folio_ledger fl JOIN bookings b ON fl.booking_id = b.id WHERE b.property_id = :p1 AND fl.amount < 0 AND DATE(fl.recorded_at) >= :s1 AND DATE(fl.recorded_at) <= :e1
        UNION ALL
        SELECT COALESCE(category, 'Misc') as category, amount FROM finance_transactions WHERE property_id = :p2 AND type = 'income' AND DATE(recorded_at) >= :s2 AND DATE(recorded_at) <= :e2
    ) as combined
    GROUP BY category
";
$incCatStmt = $db->prepare($incCatQuery);
$incCatStmt->execute(['p1' => $propertyId, 's1' => $start, 'e1' => $end, 'p2' => $propertyId, 's2' => $start, 'e2' => $end]);
$incomeCategoryBreakdown = $incCatStmt->fetchAll(PDO::FETCH_ASSOC);
NEW;
$content = str_replace($metricsSearch, $metricsReplace, $content);

// 2. Replace the ledger query category column
$ledgerSearch = "CASE WHEN fl.amount > 0 THEN 'Room Booking Due' ELSE 'Room Received Payment' END AS category,";
$ledgerReplace = "COALESCE(fl.category, CASE WHEN fl.amount > 0 THEN 'Room Booking Due' ELSE 'Room Received Payment' END) AS category,";
$content = str_replace($ledgerSearch, $ledgerReplace, $content);

// 3. Replace the metrics cards UI
$cardsSearch = <<<'OLD'
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <i class="ph ph-trend-up text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-brand-900/70 text-xs font-bold uppercase tracking-wider mb-1">Total Revenue</h3>
                        <p class="text-2xl font-semibold text-brand-900">₹<?= htmlspecialchars(number_format($totalRevenue, 2)) ?></p>
                    </div>
                </div>
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-error-50 text-error-600 flex items-center justify-center">
                        <i class="ph ph-trend-down text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-brand-900/70 text-xs font-bold uppercase tracking-wider mb-1">Total Expenses</h3>
                        <p class="text-2xl font-semibold text-error-600">₹<?= htmlspecialchars(number_format($totalExpense, 2)) ?></p>
                    </div>
                </div>
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center">
                        <i class="ph ph-coins text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-brand-900/70 text-xs font-bold uppercase tracking-wider mb-1">Net Profit</h3>
                        <p class="text-2xl font-semibold <?= $netProfit >= 0 ? 'text-blue-600' : 'text-error-600' ?>">₹<?= htmlspecialchars(number_format($netProfit, 2)) ?></p>
                    </div>
                </div>
                <div class="card-minimal p-5 flex items-center gap-4 cursor-pointer hover:bg-orange-50/50 transition-colors group" onclick="openUnpaidModal()">
                    <div class="w-12 h-12 rounded-full bg-orange-50 text-orange-600 flex items-center justify-center group-hover:bg-orange-100 transition-colors">
                        <i class="ph ph-hand-coins text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-brand-900/70 text-xs font-bold uppercase tracking-wider mb-1 flex items-center gap-1">Total Dues <i class="ph ph-info text-orange-600/70"></i></h3>
                        <p class="text-2xl font-semibold text-orange-600">₹<?= htmlspecialchars(number_format(max(0, $totalDues), 2)) ?></p>
                    </div>
                </div>
            </div>
OLD;

$cardsReplace = <<<'NEW'
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="card-minimal p-4 flex flex-col justify-center">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center">
                            <i class="ph ph-receipt text-xl"></i>
                        </div>
                        <h3 class="text-brand-900/70 text-[10px] font-bold uppercase tracking-wider">Total Billed</h3>
                    </div>
                    <p class="text-2xl font-semibold text-purple-700">₹<?= htmlspecialchars(number_format($totalBilled, 2)) ?></p>
                </div>
                <div class="card-minimal p-4 flex flex-col justify-center">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center">
                            <i class="ph ph-percent text-xl"></i>
                        </div>
                        <h3 class="text-brand-900/70 text-[10px] font-bold uppercase tracking-wider">Taxes Billed</h3>
                    </div>
                    <p class="text-2xl font-semibold text-slate-700">₹<?= htmlspecialchars(number_format($totalTax, 2)) ?></p>
                </div>
                <div class="card-minimal p-4 flex flex-col justify-center">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i class="ph ph-trend-up text-xl"></i>
                        </div>
                        <h3 class="text-brand-900/70 text-[10px] font-bold uppercase tracking-wider">Collections</h3>
                    </div>
                    <p class="text-2xl font-semibold text-emerald-700">₹<?= htmlspecialchars(number_format($totalCollections, 2)) ?></p>
                </div>
                <div class="card-minimal p-4 flex flex-col justify-center">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center">
                            <i class="ph ph-coins text-xl"></i>
                        </div>
                        <h3 class="text-brand-900/70 text-[10px] font-bold uppercase tracking-wider">Net Profit</h3>
                    </div>
                    <p class="text-2xl font-semibold <?= $netProfit >= 0 ? 'text-blue-600' : 'text-error-600' ?>">₹<?= htmlspecialchars(number_format($netProfit, 2)) ?></p>
                </div>
                <div class="card-minimal p-4 flex flex-col justify-center cursor-pointer hover:bg-orange-50/50 transition-colors group" onclick="openUnpaidModal()">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-10 h-10 rounded-full bg-orange-50 text-orange-600 flex items-center justify-center group-hover:bg-orange-100 transition-colors">
                            <i class="ph ph-hand-coins text-xl"></i>
                        </div>
                        <h3 class="text-brand-900/70 text-[10px] font-bold uppercase tracking-wider flex items-center gap-1">Total Dues <i class="ph ph-info text-orange-600/70"></i></h3>
                    </div>
                    <p class="text-2xl font-semibold text-orange-600">₹<?= htmlspecialchars(number_format(max(0, $totalDues), 2)) ?></p>
                </div>
            </div>
NEW;
$content = str_replace($cardsSearch, $cardsReplace, $content);

// 4. Add new breakdown widgets next to Expense Breakdown
$expenseBreakdownSearch = <<<'OLD'
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ledger Table (Responsive Cards on Mobile) -->
OLD;

$expenseBreakdownReplace = <<<'NEW'
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Income Distribution Summary -->
                    <div class="card-minimal p-5 space-y-4">
                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                            <i class="ph ph-chart-pie-slice text-lg text-emerald-500"></i> Income Breakdown
                        </h3>
                        <div class="space-y-3.5">
                            <?php foreach($incomeCategoryBreakdown as $row): 
                                $cat = $row['category'];
                                $amt = $row['total'];
                                $pct = $totalCollections > 0 ? round(($amt / $totalCollections) * 100) : 0;
                            ?>
                            <div>
                                <div class="flex justify-between items-center text-xs font-bold text-brand-900 mb-1">
                                    <span><?= htmlspecialchars($cat) ?></span>
                                    <span>₹<?= number_format($amt, 2) ?> (<?= $pct ?>%)</span>
                                </div>
                                <div class="w-full bg-brand-50 h-2 rounded-full overflow-hidden">
                                    <div class="bg-emerald-500 h-full rounded-full transition-all duration-500" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Payment Method Breakdown -->
                    <div class="card-minimal p-5 space-y-4">
                        <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                            <i class="ph ph-wallet text-lg text-indigo-500"></i> Till Recon (Methods)
                        </h3>
                        <div class="space-y-3.5">
                            <?php foreach($paymentMethodBreakdown as $row): 
                                $pm = $row['payment_method'];
                                $amt = $row['total'];
                                $pct = $totalCollections > 0 ? round(($amt / $totalCollections) * 100) : 0;
                            ?>
                            <div>
                                <div class="flex justify-between items-center text-xs font-bold text-brand-900 mb-1">
                                    <span><?= htmlspecialchars($pm) ?></span>
                                    <span>₹<?= number_format($amt, 2) ?></span>
                                </div>
                                <div class="w-full bg-brand-50 h-2 rounded-full overflow-hidden">
                                    <div class="bg-indigo-500 h-full rounded-full transition-all duration-500" style="width: <?= $pct ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Ledger Table (Responsive Cards on Mobile) -->
NEW;
$content = str_replace($expenseBreakdownSearch, $expenseBreakdownReplace, $content);

file_put_contents("public_html/admin/finance.php", $content);
echo "File updated.";
?>
