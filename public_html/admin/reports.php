<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
AuthHelper::requirePermission('view_reports');
CsrfToken::checkTimeout();
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Reports Dashboard | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <!-- Chart.js loaded before main JS block -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        @media print {
            body { background: white !important; color: black !important; font-size: 12px !important; }
            header, .no-print, button, #mobile-nav, .mobile-nav-bar { display: none !important; }
            .card-minimal { border: none !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
            #report_container { display: block !important; }
            canvas { max-height: 250px !important; }
            th, td { padding: 6px 8px !important; }
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-slate-50">
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto pb-24">
        
        <!-- App Bar -->
        <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0 mb-6 no-print">
            <div class="flex items-center gap-3">
                <a href="index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                    <i class="ph ph-caret-left text-2xl text-slate-800"></i>
                </a>
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900 tracking-tight leading-none">Analytics Dashboard</h1>
                    <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Business Intelligence</span>
                </div>
            </div>
            <?php include __DIR__ . '/components/desktop_nav.php'; ?>
        </header>

        <main class="flex-1 p-4 md:p-8 space-y-6">
            
            <!-- Global Date & KPI Ribbon -->
            <div class="space-y-4 no-print">
                <!-- Date Controls -->
                <div class="flex flex-col xl:flex-row gap-4 items-start xl:items-center justify-between bg-white p-4 rounded-xl border border-brand-200 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-bold text-brand-900 uppercase tracking-wider mr-2">Quick Pick:</span>
                        <button onclick="setDates('today')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-100 text-brand-900 hover:bg-brand-200 transition-colors">Today</button>
                        <button onclick="setDates('last7')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-100 text-brand-900 hover:bg-brand-200 transition-colors">Last 7 Days</button>
                        <button onclick="setDates('thisMonth')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-900 text-white hover:bg-brand-800 transition-colors">This Month</button>
                        <button onclick="setDates('lastMonth')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-100 text-brand-900 hover:bg-brand-200 transition-colors">Last Month</button>
                        <button onclick="setDates('yearToDate')" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-100 text-brand-900 hover:bg-brand-200 transition-colors">Year To Date</button>
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="date" id="global_start" value="<?= $monthStart ?>" class="bg-brand-50 border border-brand-200 rounded-lg px-3 py-1.5 text-sm font-bold text-brand-900 outline-none focus:border-brand-500">
                        <span class="text-brand-500 font-bold">to</span>
                        <input type="date" id="global_end" value="<?= $monthEnd ?>" class="bg-brand-50 border border-brand-200 rounded-lg px-3 py-1.5 text-sm font-bold text-brand-900 outline-none focus:border-brand-500">
                        <button onclick="applyGlobalDates()" class="bg-brand-accent text-brand-900 px-4 py-1.5 rounded-lg text-sm font-bold shadow-minimal hover:-translate-y-0.5 transition-transform flex items-center gap-2">
                            <i class="ph-bold ph-arrows-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- KPI Ribbon -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4" id="kpi_ribbon">
                    <div class="card-minimal p-4 bg-gradient-to-br from-brand-900 to-brand-800 text-white">
                        <div class="text-[10px] font-bold text-brand-200 uppercase tracking-widest mb-1">Total Room Revenue</div>
                        <div class="text-2xl font-black" id="kpi_revenue">₹0</div>
                    </div>
                    <div class="card-minimal p-4">
                        <div class="text-[10px] font-bold text-brand-500 uppercase tracking-widest mb-1">Avg Occupancy</div>
                        <div class="text-2xl font-black text-brand-900" id="kpi_occ">0%</div>
                    </div>
                    <div class="card-minimal p-4">
                        <div class="text-[10px] font-bold text-brand-500 uppercase tracking-widest mb-1">Avg Daily Rate (ADR)</div>
                        <div class="text-2xl font-black text-brand-900" id="kpi_adr">₹0</div>
                    </div>
                    <div class="card-minimal p-4">
                        <div class="text-[10px] font-bold text-brand-500 uppercase tracking-widest mb-1">RevPAR</div>
                        <div class="text-2xl font-black text-brand-900" id="kpi_revpar">₹0</div>
                    </div>
                </div>
            </div>

            <!-- Report Selector -->
            <div class="card-minimal p-4 no-print flex flex-col md:flex-row items-center justify-between gap-4 border-l-4 border-brand-900">
                <div class="flex-1 w-full">
                    <select id="report_type" onchange="loadSpecificReport()" class="w-full bg-transparent p-2 outline-none font-black text-brand-900 text-lg md:text-xl appearance-none cursor-pointer">
                        <option value="daily_manager">Daily Manager's Report</option>
                        <option value="business_insights">Business Intelligence & Insights</option>
                        <option value="room_performance">Room Performance & Popularity</option>
                        <option value="revpar">RevPAR & ADR Timeline</option>
                        <option value="occupancy">Day-wise Occupancy Timeline</option>
                        <option value="payment_matrix">Payment Collection Matrix</option>
                        <option value="expense_report">Total Expense Report</option>
                        <option value="rate_plan_revenue">Rate Plan Revenue</option>
                        <option value="police_register">Police / Guest Registration</option>
                        <option disabled>──────────</option>
                        <option value="pos_revenue">POS Revenue by Outlet</option>
                        <option value="pos_items">Top Selling POS Items</option>
                        <option value="pos_inventory">POS Inventory Report</option>
                        <option value="pos_pl">POS Profit & Loss</option>
                    </select>
                </div>
                <div class="flex gap-2 w-full md:w-auto">
                    <button onclick="exportCSV()" class="flex-1 md:flex-none btn-minimal bg-white px-4 py-2 flex items-center justify-center gap-2 text-sm">
                        <i class="ph-bold ph-download-simple"></i> CSV
                    </button>
                    <button onclick="window.print()" class="flex-1 md:flex-none btn-minimal bg-brand-900 text-white px-4 py-2 flex items-center justify-center gap-2 text-sm">
                        <i class="ph-bold ph-printer"></i> PDF
                    </button>
                </div>
            </div>

            <!-- Specific Report Output Area - always visible -->
            <div class="card-minimal overflow-hidden" id="report_container">
                <!-- Empty state on first load -->
                <div id="report_empty_state" class="p-12 text-center flex flex-col items-center gap-3 text-brand-400">
                    <i class="ph-fill ph-chart-bar text-5xl"></i>
                    <p class="font-bold text-brand-900">Loading report…</p>
                </div>
                
                <div class="p-6 border-b border-brand-900/20 bg-brand-50 items-center justify-between hidden" id="report_header">
                    <div>
                        <h2 class="text-xl font-black text-brand-900 tracking-tight" id="report_title">Report Title</h2>
                        <p class="text-sm text-brand-500 font-bold mt-1" id="report_subtitle">Jan 1 to Jan 31</p>
                    </div>
                </div>
                
                <div class="p-6 hidden" id="chart_container">
                    <div class="h-[300px] w-full relative">
                        <canvas id="reportChart"></canvas>
                    </div>
                </div>

                <!-- Custom Grid for Business Insights - uses style not class for display -->
                <div id="insights_grid" style="display:none"></div>

                <div class="overflow-x-auto w-full hidden" id="table_container">
                    <table class="table-brutal w-full" id="report_table">
                        <thead class="bg-white border-b border-brand-900/20 text-brand-500 font-bold text-[10px] uppercase tracking-widest" id="table_head">
                        </thead>
                        <tbody class="divide-y divide-brand-100 text-brand-900 font-medium text-sm" id="table_body">
                        </tbody>
                    </table>
                </div>

                <!-- Error state -->
                <div id="report_error" class="hidden p-8 text-center">
                    <div class="inline-flex items-center gap-3 bg-error-50 border border-error-200 text-error-700 px-6 py-4 rounded-xl">
                        <i class="ph-fill ph-warning text-2xl"></i>
                        <span class="font-bold" id="report_error_msg">Failed to load report.</span>
                    </div>
                </div>
            </div>
            
        </main>
    </div>

    <script>
        let currentData = null;
        let reportChart = null;
        let insightsCharts = [];

        // Formatting utilities
        const formatMoney = (val) => {
            const num = parseFloat(val);
            if(isNaN(num)) return val;
            return `₹${num.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
        };

        const formatReportDate = (dateStr) => {
            // Parse as local date to avoid UTC timezone shift
            const parts = String(dateStr).split('-');
            if (parts.length === 3) {
                return `${parts[2]}/${parts[1]}`; // e.g. 15/07
            }
            return dateStr;
        };

        const formatHour = (h) => {
            const hour = parseInt(h);
            if (hour === 0) return '12:00 AM';
            if (hour < 12) return `${hour}:00 AM`;
            if (hour === 12) return '12:00 PM';
            return `${hour - 12}:00 PM`;
        };

        function setDates(preset) {
            const startInput = document.getElementById('global_start');
            const endInput = document.getElementById('global_end');
            const today = new Date();
            
            const formatDateStr = (d) => {
                const tzOffset = d.getTimezoneOffset() * 60000;
                return (new Date(d - tzOffset)).toISOString().split('T')[0];
            };

            if (preset === 'today') {
                startInput.value = formatDateStr(today);
                endInput.value = formatDateStr(today);
            } else if (preset === 'last7') {
                const last7 = new Date(today);
                last7.setDate(today.getDate() - 6);
                startInput.value = formatDateStr(last7);
                endInput.value = formatDateStr(today);
            } else if (preset === 'thisMonth') {
                const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
                const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                startInput.value = formatDateStr(firstDay);
                endInput.value = formatDateStr(lastDay);
            } else if (preset === 'lastMonth') {
                const firstDay = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                const lastDay = new Date(today.getFullYear(), today.getMonth(), 0);
                startInput.value = formatDateStr(firstDay);
                endInput.value = formatDateStr(lastDay);
            } else if (preset === 'yearToDate') {
                const firstDay = new Date(today.getFullYear(), 0, 1);
                startInput.value = formatDateStr(firstDay);
                endInput.value = formatDateStr(today);
            }
            
            applyGlobalDates();
        }

        async function applyGlobalDates() {
            const start = document.getElementById('global_start').value;
            const end = document.getElementById('global_end').value;
            
            // 1. Update KPIs
            updateKPIs(start, end);
            
            // 2. Load the specific selected report
            loadSpecificReport();
        }

        async function updateKPIs(start, end) {
            // Add skeleton loaders to KPIs
            const ids = ['kpi_revenue', 'kpi_occ', 'kpi_adr', 'kpi_revpar'];
            ids.forEach(id => document.getElementById(id).innerHTML = `<div class="skeleton h-6 w-1/2 mt-1"></div>`);

            try {
                const res = await fetch(`/api/admin/reports?type=revpar&start_date=${start}&end_date=${end}`);
                const json = await res.json();
                if(json.success && json.data) {
                    let tRev = 0, tRooms = 0, tOccRooms = 0;
                    json.data.forEach(d => {
                        tRev += parseFloat(d.room_revenue);
                        tRooms += parseInt(d.total_rooms);
                        tOccRooms += parseInt(d.occupied_rooms);
                    });
                    
                    const occPct = tRooms > 0 ? (tOccRooms / tRooms) * 100 : 0;
                    const adr = tOccRooms > 0 ? (tRev / tOccRooms) : 0;
                    const revpar = tRooms > 0 ? (tRev / tRooms) : 0;

                    document.getElementById('kpi_revenue').innerText = formatMoney(tRev);
                    document.getElementById('kpi_occ').innerText = occPct.toFixed(1) + '%';
                    document.getElementById('kpi_adr').innerText = formatMoney(adr);
                    document.getElementById('kpi_revpar').innerText = formatMoney(revpar);
                }
            } catch (e) {
                console.error("Failed to update KPIs", e);
            }
        }

        async function loadSpecificReport() {
            const type = document.getElementById('report_type').value;
            const start = document.getElementById('global_start').value;
            const end = document.getElementById('global_end').value;
            
            const sel = document.getElementById('report_type');
            const title = sel.options[sel.selectedIndex].text;

            // Show report area, hide empty state
            document.getElementById('report_empty_state').classList.add('hidden');
            document.getElementById('report_error').classList.add('hidden');
            document.getElementById('report_header').classList.remove('hidden');
            document.getElementById('report_header').classList.add('flex');
            document.getElementById('report_title').innerText = title;
            document.getElementById('report_subtitle').innerText = `${start} to ${end}`;

            // Cleanup previous charts
            document.getElementById('chart_container').classList.add('hidden');
            const ig = document.getElementById('insights_grid');
            ig.style.display = 'none';
            ig.classList.add('hidden');
            document.getElementById('table_container').classList.add('hidden');
            if(reportChart) { try { reportChart.destroy(); } catch(e){} reportChart = null; }
            insightsCharts.forEach(c => { try { c.destroy(); } catch(e){} });
            insightsCharts = [];

            // Show skeleton loading
            document.getElementById('table_container').classList.remove('hidden');
            const skeletonRow = '<tr>' + Array(5).fill('<td class="px-6 py-4"><div class="skeleton h-4 w-full"></div></td>').join('') + '</tr>';
            document.getElementById('table_head').innerHTML = '<tr>' + Array(5).fill('<th class="px-6 py-4"><div class="skeleton h-3 w-24"></div></th>').join('') + '</tr>';
            document.getElementById('table_body').innerHTML = Array(5).fill(skeletonRow).join('');

            try {
                console.log(`[Reports] Fetching: type=${type} start=${start} end=${end}`);
                const res = await fetch(`/api/admin/reports?type=${type}&start_date=${start}&end_date=${end}`);
                const json = await res.json();
                console.log(`[Reports] Response:`, json);
                
                if(!json.success) {
                    document.getElementById('table_container').classList.add('hidden');
                    document.getElementById('report_error_msg').innerText = json.message || 'Failed to load report';
                    document.getElementById('report_error').classList.remove('hidden');
                    return;
                }
                
                currentData = json.data;

                if (type === 'business_insights') {
                    document.getElementById('table_container').classList.add('hidden');
                    renderBusinessInsights(currentData);
                } else {
                    renderTable(type, currentData);
                    const chartTypes = ['occupancy', 'revpar', 'payment_matrix', 'rate_plan_revenue', 'expense_report', 'room_performance', 'pos_revenue', 'pos_items', 'pos_pl'];
                    if (chartTypes.includes(type)) {
                        renderChart(type, currentData);
                    }
                }
                
            } catch(e) {
                console.error('[Reports] Error:', e);
                document.getElementById('table_container').classList.add('hidden');
                document.getElementById('report_error_msg').innerText = 'Network error: ' + e.message;
                document.getElementById('report_error').classList.remove('hidden');
            }
        }

        function renderTable(type, data) {
            const thead = document.getElementById('table_head');
            const tbody = document.getElementById('table_body');
            
            if(!data || data.length === 0) {
                thead.innerHTML = '';
                tbody.innerHTML = '<tr><td colspan="9" class="p-12 text-center"><div class="flex flex-col items-center justify-center opacity-50"><i class="ph-fill ph-ghost text-5xl mb-3"></i><p class="font-bold">No data found</p></div></td></tr>';
                return;
            }
            
            let htmlHead = '<tr>';
            const keys = Object.keys(data[0]);
            keys.forEach(k => {
                htmlHead += `<th class="px-6 py-4 font-bold">${k.replace(/_/g, ' ')}</th>`;
            });
            htmlHead += '</tr>';
            
            let htmlBody = '';
            data.forEach(row => {
                const isTotals = row.booking_id === 'TOTALS';
                const rowClass = isTotals ? 'bg-brand-900 text-white font-bold' : 'hover:bg-brand-50 transition-colors';
                htmlBody += `<tr class="${rowClass}">`;
                keys.forEach(k => {
                    let val = row[k];
                    if (val === null || val === undefined) val = '-';
                    const lowerKey = k.toLowerCase();
                    
                    const isCurrency = (lowerKey.includes('total') && !lowerKey.includes('rooms') && !lowerKey.includes('bookings') && !lowerKey.includes('checkins')) || 
                                       lowerKey.includes('amount') || lowerKey.includes('revenue') || lowerKey === 'adr' || lowerKey === 'revpar' ||
                                       lowerKey.includes('charge') || lowerKey.includes('dues') || lowerKey.includes('addons') ||
                                       (type === 'payment_matrix' && k !== 'Date') || 
                                       (type === 'daily_manager' && !['booking_id', 'room_number', 'check_in', 'check_out', 'duration'].includes(lowerKey));
                    
                    if (isCurrency && !isNaN(val) && val !== '' && val !== '-') {
                        const num = parseFloat(val);
                        const txtColor = isTotals ? 'text-white' : (num >= 0 ? 'text-slate-900' : 'text-error-600');
                        val = `<span class="font-bold ${txtColor}">${num < 0 ? '-' : ''}${formatMoney(Math.abs(num))}</span>`;
                    } else if (k === 'occupancy_percent' && !isTotals) {
                        const pct = parseFloat(val) || 0;
                        const barColor = pct >= 80 ? '#22C55E' : pct >= 50 ? '#F59E0B' : '#EF4444';
                        val = `<div class="flex items-center gap-2"><div class="w-24 h-1.5 bg-brand-100 rounded-full overflow-hidden"><div class="h-full rounded-full" style="width:${pct}%; background:${barColor}"></div></div><span class="text-xs font-bold w-8 text-right">${pct}%</span></div>`;
                    } else if ((k === 'category' || k === 'rate_plan_name' || k === 'payment_method') && !isTotals) {
                        val = `<span class="bg-brand-100 text-brand-900 px-2 py-1 rounded-md text-[10px] font-black uppercase tracking-wider">${val}</span>`;
                    }

                    htmlBody += `<td class="px-6 py-4 whitespace-nowrap">${val}</td>`;
                });
                htmlBody += '</tr>';
            });
            
            thead.innerHTML = htmlHead;
            tbody.innerHTML = htmlBody;
        }

        // Shared Brutalist Chart Options
        const getChartOptions = (hideLegend = false) => ({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: !hideLegend,
                    position: 'bottom',
                    labels: { color: '#0f172a', font: { family: 'inherit', weight: 'bold', size: 11 }, padding: 20 }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#64748b', font: { weight: 'bold' } } },
                y: { grid: { color: '#f1f5f9', borderDash: [5, 5] }, ticks: { color: '#64748b', font: { weight: 'bold' } } }
            },
            elements: {
                line: { tension: 0.2, borderWidth: 3 },
                point: { radius: 0, hitRadius: 10, hoverRadius: 6 },
                bar: { borderRadius: 4, borderWidth: 2 }
            }
        });

        function renderChart(type, data) {
            document.getElementById('chart_container').classList.remove('hidden');
            const ctx = document.getElementById('reportChart').getContext('2d');
            let chartConfig = {};

            if (type === 'occupancy') {
                chartConfig = {
                    type: 'line',
                    data: {
                        labels: data.map(d => formatReportDate(d.date)),
                        datasets: [{
                            label: 'Occupancy %',
                            data: data.map(d => d.occupancy_percent),
                            borderColor: '#0f172a', backgroundColor: 'rgba(15, 23, 42, 0.05)', fill: true
                        }]
                    },
                    options: { ...getChartOptions(true), scales: { ...getChartOptions().scales, y: { ...getChartOptions().scales.y, max: 100, min: 0 } } }
                };
            } 
            else if (type === 'revpar') {
                chartConfig = {
                    type: 'line',
                    data: {
                        labels: data.map(d => formatReportDate(d.date)),
                        datasets: [
                            { label: 'RevPAR', data: data.map(d => d.revpar), borderColor: '#3b82f6', backgroundColor: '#3b82f6', tension: 0.3 },
                            { label: 'ADR', data: data.map(d => d.adr), borderColor: '#10b981', backgroundColor: '#10b981', tension: 0.3, borderDash: [5,5] }
                        ]
                    },
                    options: getChartOptions()
                };
            }
            else if (type === 'payment_matrix') {
                const totals = {};
                data.forEach(r => Object.keys(r).forEach(k => { if(k!=='Date' && k!=='Total') totals[k] = (totals[k]||0) + parseFloat(r[k]); }));
                chartConfig = {
                    type: 'doughnut',
                    data: {
                        labels: Object.keys(totals),
                        datasets: [{ data: Object.values(totals), backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ec4899', '#8b5cf6'], borderWidth: 0 }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '70%',
                        plugins: {
                            legend: {
                                display: true,
                                position: 'bottom',
                                labels: { color: '#0f172a', font: { family: 'inherit', weight: 'bold', size: 11 }, padding: 20 }
                            }
                        }
                    }
                };
            } 
            else if (type === 'rate_plan_revenue') {
                chartConfig = {
                    type: 'bar',
                    data: {
                        labels: data.map(d => d.rate_plan_name || 'Standard'),
                        datasets: [{ label: 'Revenue', data: data.map(d => d.total_revenue), backgroundColor: '#0f172a' }]
                    },
                    options: getChartOptions(true)
                };
            } 
            else if (type === 'room_performance') {
                chartConfig = {
                    type: 'bar',
                    data: {
                        labels: data.map(d => `Room ${d.room_number}`),
                        datasets: [{ label: 'Revenue', data: data.map(d => d.total_revenue), backgroundColor: '#3b82f6' }]
                    },
                    options: getChartOptions(true)
                };
            }
            else if (type === 'expense_report') {
                const cats = {};
                data.forEach(d => cats[d.category] = (cats[d.category]||0) + parseFloat(d.amount));
                chartConfig = {
                    type: 'pie',
                    data: {
                        labels: Object.keys(cats),
                        datasets: [{ data: Object.values(cats), backgroundColor: ['#ef4444', '#f97316', '#eab308', '#06b6d4'], borderWidth: 0 }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'bottom',
                                labels: { color: '#0f172a', font: { family: 'inherit', weight: 'bold', size: 11 }, padding: 20 }
                            }
                        }
                    }
                };
            }
            else if (type === 'pos_revenue') {
                chartConfig = {
                    type: 'doughnut',
                    data: {
                        labels: data.map(d => d.outlet_name),
                        datasets: [{ data: data.map(d => d.total_revenue), backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ec4899', '#8b5cf6'], borderWidth: 0 }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutout: '70%',
                        plugins: { legend: { display: true, position: 'bottom', labels: { color: '#0f172a', font: { family: 'inherit', weight: 'bold', size: 11 }, padding: 20 } } }
                    }
                };
            }
            else if (type === 'pos_items') {
                chartConfig = {
                    type: 'bar',
                    data: {
                        labels: data.map(d => d.item_name).slice(0, 10), // Top 10 for chart
                        datasets: [{ label: 'Qty Sold', data: data.map(d => d.quantity_sold).slice(0, 10), backgroundColor: '#3b82f6' }]
                    },
                    options: getChartOptions(true)
                };
            }
            else if (type === 'pos_pl') {
                chartConfig = {
                    type: 'bar',
                    data: {
                        labels: data.map(d => d.item_name).slice(0, 10),
                        datasets: [
                            { label: 'Revenue', data: data.map(d => d.total_revenue).slice(0, 10), backgroundColor: '#10b981' },
                            { label: 'Cost', data: data.map(d => d.total_cost).slice(0, 10), backgroundColor: '#ef4444' }
                        ]
                    },
                    options: getChartOptions()
                };
            }

            reportChart = new Chart(ctx, chartConfig);
        }

        function renderBusinessInsights(data) {
            const ig = document.getElementById('insights_grid');
            ig.style.display = 'grid';
            ig.style.gridTemplateColumns = 'repeat(auto-fit, minmax(300px, 1fr))';
            ig.style.gap = '1.5rem';
            ig.style.padding = '1.5rem';
            ig.classList.remove('hidden');
            
            // 1. Retention
            const newG = parseInt(data.retention?.new_guests || 0);
            const retG = parseInt(data.retention?.returning_guests || 0);
            const totalG = newG + retG;
            const retPct = totalG > 0 ? Math.round((retG / totalG) * 100) : 0;
            
            // 2. Busiest Days prep — fill all 7 days even if some have 0
            const allDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            const fullDayNames = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
            const dayMap = {};
            (data.busiest_days || []).forEach(d => { dayMap[d.day_of_week] = parseInt(d.total_checkins); });
            const daysLabels = allDays;
            const daysData = fullDayNames.map(d => dayMap[d] || 0);

            // 3. Peak checkins html
            let checkinHtml = (data.peak_checkins || []).map(d => `
                <div class="flex justify-between items-center py-2 border-b border-brand-100 last:border-0">
                    <span class="font-bold text-brand-900">${formatHour(d.checkin_hour)}</span>
                    <span class="bg-brand-100 text-brand-900 px-2 py-1 rounded text-xs font-black">${d.checkin_count} check-ins</span>
                </div>
            `).join('') || '<p class="text-sm text-brand-500 font-medium py-2">No data in this period</p>';

            let checkoutHtml = (data.peak_checkouts || []).map(d => `
                <div class="flex justify-between items-center py-2 border-b border-brand-100 last:border-0">
                    <span class="font-bold text-brand-900">${formatHour(d.checkout_hour)}</span>
                    <span class="bg-orange-100 text-orange-900 px-2 py-1 rounded text-xs font-black">${d.checkout_count} check-outs</span>
                </div>
            `).join('') || '<p class="text-sm text-brand-500 font-medium py-2">No data in this period</p>';

            ig.innerHTML = `
                <!-- Retention Card -->
                <div class="bg-white p-6 rounded-2xl border border-brand-200 shadow-sm flex flex-col">
                    <h3 class="text-sm font-black text-brand-900 uppercase tracking-wider mb-6">Guest Loyalty</h3>
                    <div class="flex-1 flex items-center justify-center relative">
                        <div class="absolute inset-0 flex items-center justify-center flex-col">
                            <span class="text-3xl font-black text-brand-900">${retPct}%</span>
                            <span class="text-[10px] font-bold text-brand-500 uppercase tracking-widest">Returning</span>
                        </div>
                        <div class="w-48 h-48 relative">
                            <canvas id="chart_retention"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Busiest Days Card -->
                <div class="bg-white p-6 rounded-2xl border border-brand-200 shadow-sm flex flex-col">
                    <h3 class="text-sm font-black text-brand-900 uppercase tracking-wider mb-4">Check-ins by Day of Week</h3>
                    <div class="flex-1 min-h-[200px] relative">
                        <canvas id="chart_days"></canvas>
                    </div>
                </div>

                <!-- Peak Timings Card -->
                <div class="bg-white p-6 rounded-2xl border border-brand-200 shadow-sm md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <h3 class="text-sm font-black text-brand-900 uppercase tracking-wider mb-4 flex items-center gap-2"><i class="ph-bold ph-sign-in text-brand-accent text-lg"></i> Peak Check-in Hours</h3>
                        <div class="bg-brand-50 rounded-xl p-4 border border-brand-100">
                            ${checkinHtml}
                        </div>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-brand-900 uppercase tracking-wider mb-4 flex items-center gap-2"><i class="ph-bold ph-sign-out text-orange-500 text-lg"></i> Peak Check-out Hours</h3>
                        <div class="bg-brand-50 rounded-xl p-4 border border-brand-100">
                            ${checkoutHtml}
                        </div>
                    </div>
                </div>
            `;

            // Draw Charts - need setTimeout to ensure DOM updated
            setTimeout(() => {
                const retEl = document.getElementById('chart_retention');
                const daysEl = document.getElementById('chart_days');
                if (retEl) {
                    const ctxRet = retEl.getContext('2d');
                    insightsCharts.push(new Chart(ctxRet, {
                        type: 'doughnut',
                        data: {
                            labels: ['Returning', 'New'],
                            datasets: [{ data: [retG, newG], backgroundColor: ['#0f172a', '#e2e8f0'], borderWidth: 0 }]
                        },
                        options: { responsive: true, maintainAspectRatio: false, cutout: '80%', plugins: { legend: { display: false } } }
                    }));
                }
                if (daysEl) {
                    const ctxDays = daysEl.getContext('2d');
                    insightsCharts.push(new Chart(ctxDays, {
                        type: 'bar',
                        data: {
                            labels: daysLabels,
                            datasets: [{ data: daysData, backgroundColor: '#3b82f6', borderRadius: 4 }]
                        },
                        options: { ...getChartOptions(true), scales: { x: { grid: {display:false} }, y: { display:false } } }
                    }));
                }
            }, 50);
        }

        function exportCSV() {
            const type = document.getElementById('report_type').value;
            if(type === 'business_insights') {
                return showToast("Export not available for Business Intelligence view. Please use Print to PDF.");
            }
            if(!currentData || currentData.length === 0) return showToast("No data to export");
            
            const keys = Object.keys(currentData[0]);
            let csv = keys.join(',') + '\n';
            
            currentData.forEach(row => {
                let r = keys.map(k => {
                    let val = row[k] === null ? '' : row[k].toString();
                    val = val.replace(/"/g, '""');
                    if (val.search(/("|,|\n)/g) >= 0) val = `"${val}"`;
                    return val;
                });
                csv += r.join(',') + '\n';
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const start = document.getElementById('global_start').value;
            
            link.setAttribute('href', url);
            link.setAttribute('download', `${type}_${start}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', applyGlobalDates);
    </script>
</body>
</html>
