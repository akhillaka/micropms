<?php
/**
 * ui_kpi_bar.php — Reusable KPI Bar Component
 * Injects 4 live KPI tiles: Occupancy %, Today's Revenue, Pending Actions, Dirty Rooms.
 * Expects $db (PDO instance) to be defined.
 */

if (!isset($db)) {
    require_once __DIR__ . '/../../../pms_core/Database.php';
    $db = Database::getInstance()->getConnection();
}

$kpiPropertyId = 0;
try {
    require_once __DIR__ . '/../../../pms_core/AuthHelper.php';
    $kpiPropertyId = (int)AuthHelper::getPropertyId();
} catch (\Throwable $e) {
    $kpiPropertyId = 0;
}

// 1. Today's Revenue (folio payments for bookings + non-booking finance income)
if ($kpiPropertyId > 0) {
    $revStmt = $db->prepare("
        SELECT COALESCE(SUM(amt), 0) FROM (
            SELECT ABS(amount) AS amt FROM folio_ledger
            WHERE property_id = ? AND amount < 0 AND DATE(recorded_at) = CURDATE()
            UNION ALL
            SELECT amount FROM finance_transactions
            WHERE property_id = ? AND type = 'income' AND DATE(recorded_at) = CURDATE()
              AND (booking_id IS NULL OR booking_id = 0)
        ) t
    ");
    $revStmt->execute([$kpiPropertyId, $kpiPropertyId]);
    $kpiRevenueToday = $revStmt->fetchColumn() ?: 0;

    $roomStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ?");
    $roomStmt->execute([$kpiPropertyId]);
    $kpiTotalRooms = (int)$roomStmt->fetchColumn();

    $occStmt = $db->prepare("SELECT COUNT(DISTINCT room_id) FROM bookings WHERE booking_status = 'checked_in' AND property_id = ?");
    $occStmt->execute([$kpiPropertyId]);
    $kpiOccupiedCount = (int)$occStmt->fetchColumn();

    $pendStmt = $db->prepare("
        SELECT COUNT(*) FROM bookings
        WHERE property_id = ?
          AND ((booking_status = 'booked' AND DATE(check_in) = CURDATE())
            OR (booking_status = 'checked_in' AND DATE(check_out) = CURDATE()))
    ");
    $pendStmt->execute([$kpiPropertyId]);
    $kpiPendingActions = (int)$pendStmt->fetchColumn();

    $dirtyStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ? AND state = 'dirty'");
    $dirtyStmt->execute([$kpiPropertyId]);
    $kpiDirtyCount = (int)$dirtyStmt->fetchColumn();
} else {
    $kpiRevenueToday = 0;
    $kpiTotalRooms = 0;
    $kpiOccupiedCount = 0;
    $kpiPendingActions = 0;
    $kpiDirtyCount = 0;
}
$kpiOccupancyPct = $kpiTotalRooms > 0 ? round(($kpiOccupiedCount / $kpiTotalRooms) * 100) : 0;
?>

<!-- KPI Strip: Reusable component -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 my-4 font-display">
    <!-- Occupancy -->
    <div class="bg-white border border-slate-200/80 rounded-2xl p-4 flex items-center gap-3 shadow-sm hover:shadow-minimal transition-all">
        <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
            <i class="ph ph-bed text-lg text-indigo-600"></i>
        </div>
        <div>
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Occupancy</p>
            <p class="text-lg font-extrabold text-slate-900 leading-tight"><?= htmlspecialchars((string)($kpiOccupancyPct), ENT_QUOTES, 'UTF-8') ?>%</p>
            <p class="text-[9px] text-slate-500 font-semibold"><?= htmlspecialchars((string)($kpiOccupiedCount), ENT_QUOTES, 'UTF-8') ?>/<?= htmlspecialchars((string)($kpiTotalRooms), ENT_QUOTES, 'UTF-8') ?> rooms</p>
        </div>
    </div>
    
    <!-- Today's Revenue -->
    <div class="bg-white border border-slate-200/80 rounded-2xl p-4 flex items-center gap-3 shadow-sm hover:shadow-minimal transition-all">
        <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center flex-shrink-0">
            <i class="ph ph-currency-inr text-lg text-emerald-600"></i>
        </div>
        <div>
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Today's Rev</p>
            <p class="text-lg font-extrabold text-slate-900 leading-tight">₹<?= htmlspecialchars((string)(number_format($kpiRevenueToday)), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-[9px] text-slate-500 font-semibold">collected today</p>
        </div>
    </div>
    
    <!-- Pending Actions -->
    <div class="bg-white border border-slate-200/80 rounded-2xl p-4 flex items-center gap-3 shadow-sm hover:shadow-minimal transition-all">
        <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
            <i class="ph ph-bell text-lg text-amber-600"></i>
        </div>
        <div>
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Pending</p>
            <p class="text-lg font-extrabold text-slate-900 leading-tight"><?= htmlspecialchars((string)($kpiPendingActions), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-[9px] text-slate-500 font-semibold">arrivals/departures</p>
        </div>
    </div>
    
    <!-- Housekeeping / Dirty -->
    <div class="bg-white border border-slate-200/80 rounded-2xl p-4 flex items-center gap-3 shadow-sm hover:shadow-minimal transition-all">
        <div class="w-10 h-10 rounded-xl bg-rose-50 flex items-center justify-center flex-shrink-0">
            <i class="ph ph-broom text-lg text-rose-600"></i>
        </div>
        <div>
            <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Dirty Rooms</p>
            <p class="text-lg font-extrabold text-slate-900 leading-tight"><?= htmlspecialchars((string)($kpiDirtyCount), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="text-[9px] text-slate-500 font-semibold">rooms to clean</p>
        </div>
    </div>
</div>
