<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/Database.php';

AuthHelper::requirePermission('view_error_logs');
CsrfToken::checkTimeout();

$db = Database::getInstance()->getConnection();

$critUnresolved = 0;
$errorsToday = 0;
$resolvedCount = 0;
$categories = [];
$migrationNeeded = false;

try {
    // Fetch summary metrics
    $critUnresolved = (int)$db->query("SELECT COUNT(*) FROM error_logs WHERE severity = 'critical' AND resolved = 0")->fetchColumn();
    $errorsToday = (int)$db->query("SELECT COUNT(*) FROM error_logs WHERE DATE(created_at) = CURRENT_DATE()")->fetchColumn();
    $resolvedCount = (int)$db->query("SELECT COUNT(*) FROM error_logs WHERE resolved = 1")->fetchColumn();

    // Unique categories for filtering
    $categories = $db->query("SELECT DISTINCT category FROM error_logs ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {
    $migrationNeeded = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Error Monitoring | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
    <script>
        const CSRF_TOKEN = '<?= CsrfToken::generate() ?>';
    </script>
</head>
<body class="flex flex-col min-h-screen bg-slate-50">
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
        
        <!-- App Bar -->
        <header class="bg-white px-6 py-4 flex items-center justify-between sticky top-0 z-40 border-b border-slate-200">
            <div class="flex items-center gap-3">
                <a href="index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                    <i class="ph ph-caret-left text-2xl text-slate-800"></i>
                </a>
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900 tracking-tight leading-none">Error Monitoring</h1>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mt-1">Live System Health Feed</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <?php include __DIR__ . '/components/desktop_nav.php'; ?>
                <button onclick="loadErrorLogs()" class="p-2 rounded-lg hover:bg-slate-100 transition-colors text-slate-600" title="Refresh">
                    <i class="ph ph-arrows-clockwise text-xl"></i>
                </button>
            </div>
        </header>

        <main class="flex-1 p-4 md:p-6 space-y-6">
            
            <?php if ($migrationNeeded): ?>
                <div class="bg-amber-50 border border-amber-200 text-amber-900 p-6 rounded-2xl shadow-sm text-center space-y-4 animate-fade-up">
                    <div class="w-12 h-12 bg-amber-100 flex items-center justify-center text-amber-600 rounded-full mx-auto">
                        <i class="ph ph-database text-2xl"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold">Database Migration Required</h2>
                        <p class="text-xs font-semibold text-slate-500 mt-1">The upgraded role and error logs tables have not been created yet.</p>
                    </div>
                    <a href="run_migration.php" class="inline-flex items-center gap-1.5 bg-brand-accent text-white px-5 py-2.5 rounded-xl font-bold text-xs hover:bg-brand-accentHover active:scale-95 transition-all shadow-sm">
                        <i class="ph ph-play text-sm"></i> Run Database Migration
                    </a>
                </div>
            <?php endif; ?>
            
            <!-- Stats Dashboard Banner -->
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-col justify-between">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Critical Unresolved</span>
                    <span id="crit-unresolved-count" class="text-2xl font-extrabold text-red-600 mt-2"><?= $critUnresolved ?></span>
                </div>
                <div class="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-col justify-between">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Errors Today</span>
                    <span id="errors-today-count" class="text-2xl font-extrabold text-amber-600 mt-2"><?= $errorsToday ?></span>
                </div>
                <div class="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-col justify-between">
                    <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Resolved</span>
                    <span id="resolved-count" class="text-2xl font-extrabold text-emerald-600 mt-2"><?= $resolvedCount ?></span>
                </div>
            </div>

            <!-- Controls Panel -->
            <div class="bg-white border border-slate-200 p-4 rounded-2xl shadow-sm flex flex-wrap gap-4 items-center justify-between">
                <div class="flex flex-wrap gap-3 items-center">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Severity</label>
                        <select id="filter-severity" onchange="loadErrorLogs(1)" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-700 outline-none focus:border-amber-600">
                            <option value="">All Severities</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Category</label>
                        <select id="filter-category" onchange="loadErrorLogs(1)" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-700 outline-none focus:border-amber-600">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars(ucfirst($cat)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Status</label>
                        <select id="filter-resolved" onchange="loadErrorLogs(1)" class="bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-xs font-bold text-slate-700 outline-none focus:border-amber-600">
                            <option value="0" selected>Unresolved Only</option>
                            <option value="1">Resolved Only</option>
                            <option value="">All Statuses</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button onclick="triggerBulkResolve()" class="bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs px-4 py-2.5 rounded-xl transition-all shadow-sm active:scale-95 flex items-center gap-1.5">
                        <i class="ph ph-check-square-offset text-base"></i> Resolve Category
                    </button>
                </div>
            </div>

            <!-- Error Logs Table Section -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50/75 border-b border-slate-200">
                                <th class="p-4 text-xs font-bold text-slate-500 uppercase tracking-wider w-[120px]">Time</th>
                                <th class="p-4 text-xs font-bold text-slate-500 uppercase tracking-wider w-[100px]">Severity</th>
                                <th class="p-4 text-xs font-bold text-slate-500 uppercase tracking-wider w-[120px]">Category</th>
                                <th class="p-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Message</th>
                                <th class="p-4 text-xs font-bold text-slate-500 uppercase tracking-wider w-[120px]">Staff / IP</th>
                                <th class="p-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right w-[110px]">Action</th>
                            </tr>
                        </thead>
                        <tbody id="logs-tbody" class="divide-y divide-slate-100">
                            <!-- Loaded via AJAX -->
                        </tbody>
                    </table>
                </div>

                <!-- Empty State -->
                <div id="logs-empty" class="hidden p-12 text-center text-slate-400 font-medium">
                    <i class="ph ph-sparkle text-4xl text-emerald-400 mb-2"></i>
                    <p class="text-sm">System is clean! No unresolved errors found.</p>
                </div>

                <!-- Table Footer / Pagination -->
                <div class="p-4 border-t border-slate-200 flex items-center justify-between bg-slate-50/50">
                    <span id="pagination-info" class="text-xs font-semibold text-slate-500">Showing page 1 of 1</span>
                    <div class="flex gap-1">
                        <button id="btn-prev" onclick="changePage(-1)" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50 active:scale-95 transition-all">Prev</button>
                        <button id="btn-next" onclick="changePage(1)" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50 active:scale-95 transition-all">Next</button>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- Error Detail Modal -->
    <div id="error-modal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
        <div class="bg-white rounded-2xl w-full max-w-lg border border-slate-200 shadow-2xl flex flex-col max-h-[85vh] overflow-hidden">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <span id="modal-severity-badge"></span>
                    <span id="modal-title">Error Details</span>
                </h3>
                <button onclick="hideModal('error-modal')" class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-slate-100 transition-colors">
                    <i class="ph ph-x text-lg"></i>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-6 space-y-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Message</label>
                    <div id="modal-message" class="text-sm font-semibold text-slate-900 bg-slate-50 border border-slate-100 p-3 rounded-xl break-words"></div>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Business Context & Stack</label>
                    <pre id="modal-context" class="text-xs font-mono text-slate-700 bg-slate-900 text-slate-100 p-4 rounded-xl overflow-x-auto whitespace-pre-wrap max-h-[300px]"></pre>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">IP Address</label>
                        <span id="modal-ip" class="text-xs font-bold text-slate-700"></span>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Timestamp</label>
                        <span id="modal-time" class="text-xs font-bold text-slate-700"></span>
                    </div>
                </div>
            </div>
            <div class="p-6 border-t border-slate-100 flex gap-3">
                <button id="modal-resolve-btn" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-2.5 rounded-xl transition-all shadow-sm">Mark Resolved</button>
                <button onclick="hideModal('error-modal')" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold py-2.5 rounded-xl transition-colors">Close</button>
            </div>
        </div>
    </div>

    <!-- Bulk Resolve Modal -->
    <div id="bulk-modal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
        <div class="bg-white rounded-2xl w-full max-w-sm border border-slate-200 shadow-2xl p-6">
            <h3 class="text-base font-extrabold text-slate-900 mb-4">Resolve Entire Category</h3>
            <p class="text-xs font-semibold text-slate-500 mb-4">Select which category of errors you would like to mark as resolved in bulk.</p>
            <div class="space-y-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-wider mb-1">Select Category</label>
                    <select id="bulk-category-select" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs font-bold text-slate-700 outline-none">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars(ucfirst($cat)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-3">
                    <button onclick="hideModal('bulk-modal')" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold py-2.5 rounded-xl transition-colors">Cancel</button>
                    <button onclick="submitBulkResolve()" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-2.5 rounded-xl transition-all">Resolve All</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        let totalPages = 1;
        let currentLogs = [];

        document.addEventListener('DOMContentLoaded', () => {
            loadErrorLogs();
            // Auto refresh every 60 seconds
            setInterval(loadErrorLogs, 60000);
        });

        async function loadErrorLogs(page = currentPage) {
            currentPage = page;
            const severity = document.getElementById('filter-severity').value;
            const category = document.getElementById('filter-category').value;
            const resolved = document.getElementById('filter-resolved').value;

            try {
                const res = await fetch(`/api/admin/error_logs?action=list&severity=${severity}&category=${category}&resolved=${resolved}&page=${page}`);
                const data = await res.json();
                
                if (data.success) {
                    currentLogs = data.logs;
                    totalPages = data.pagination.total_pages || 1;
                    renderLogsTable(data.logs);
                    renderPagination(data.pagination);
                    
                    // Update stats counts dynamically
                    updateStatsHeader();
                } else {
                    showToast('Failed to load error logs: ' + data.message, 'error');
                }
            } catch(e) {
                showToast('Failed to connect to API', 'error');
            }
        }

        function renderLogsTable(logs) {
            const tbody = document.getElementById('logs-tbody');
            const empty = document.getElementById('logs-empty');
            tbody.innerHTML = '';
            
            if (logs.length === 0) {
                empty.classList.remove('hidden');
                return;
            }
            empty.classList.add('hidden');

            const severityColors = {
                critical: 'bg-red-50 text-red-700 border-red-200',
                error: 'bg-orange-50 text-orange-700 border-orange-200',
                warning: 'bg-amber-50 text-amber-700 border-amber-200',
                info: 'bg-blue-50 text-blue-700 border-blue-200'
            };

            logs.forEach(log => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-50 transition-colors cursor-pointer';
                tr.onclick = (e) => {
                    if (e.target.closest('button')) return;
                    showErrorDetail(log);
                };

                const date = new Date(log.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' ' + new Date(log.created_at).toLocaleDateString([], { month: 'short', day: 'numeric' });
                const sevBadge = `<span class="inline-flex items-center px-2 py-0.5 rounded-lg border text-[10px] font-black uppercase ${severityColors[log.severity] || 'bg-slate-100 text-slate-700'}">${log.severity}</span>`;
                const catText = `<span class="text-xs font-bold text-slate-900">${log.category.toUpperCase()}</span>`;
                const msgText = `<div class="text-xs font-semibold text-slate-800 truncate max-w-[400px]">${escapeHtml(log.message)}</div>`;
                const ipText = `<div class="text-[10px] font-semibold text-slate-400">${log.ip_address}</div>`;
                
                const resolveBtn = log.resolved == 1 
                    ? `<span class="text-xs font-bold text-emerald-600"><i class="ph ph-check"></i> Resolved</span>`
                    : `<button onclick="resolveError(${log.id})" class="px-2.5 py-1 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-700 text-slate-600 rounded-lg text-[10px] font-black uppercase transition-all">Resolve</button>`;

                tr.innerHTML = `
                    <td class="p-4 text-xs font-semibold text-slate-500">${date}</td>
                    <td class="p-4">${sevBadge}</td>
                    <td class="p-4">${catText}</td>
                    <td class="p-4">${msgText}</td>
                    <td class="p-4">${ipText}</td>
                    <td class="p-4 text-right">${resolveBtn}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function renderPagination(p) {
            document.getElementById('pagination-info').innerText = `Showing page ${p.current_page} of ${p.total_pages}`;
            document.getElementById('btn-prev').disabled = p.current_page <= 1;
            document.getElementById('btn-next').disabled = p.current_page >= p.total_pages;
        }

        function changePage(delta) {
            const target = currentPage + delta;
            if (target >= 1 && target <= totalPages) {
                loadErrorLogs(target);
            }
        }

        async function updateStatsHeader() {
            try {
                const res = await fetch(`/api/admin/error_logs?action=list&resolved=0`);
                const data = await res.json();
                if (data.success) {
                    const unresolved = data.logs.filter(l => l.resolved == 0);
                    const critCount = unresolved.filter(l => l.severity === 'critical').length;
                    document.getElementById('crit-unresolved-count').innerText = critCount;
                }
            } catch(e) {}
        }

        function showErrorDetail(log) {
            const severityColors = {
                critical: 'bg-red-50 text-red-700 border-red-200',
                error: 'bg-orange-50 text-orange-700 border-orange-200',
                warning: 'bg-amber-50 text-amber-700 border-amber-200',
                info: 'bg-blue-50 text-blue-700 border-blue-200'
            };

            const modal = document.getElementById('error-modal');
            document.getElementById('modal-severity-badge').className = `inline-flex items-center px-2.5 py-0.5 rounded-lg border text-[10px] font-black uppercase ${severityColors[log.severity]}`;
            document.getElementById('modal-severity-badge').innerText = log.severity;
            document.getElementById('modal-title').innerText = `${log.category.toUpperCase()} Error`;
            document.getElementById('modal-message').innerText = log.message;
            
            // Format context prettily
            const contextData = log.context || {};
            document.getElementById('modal-context').innerText = JSON.stringify(contextData, null, 2);
            document.getElementById('modal-ip').innerText = log.ip_address;
            document.getElementById('modal-time').innerText = new Date(log.created_at).toLocaleString();

            const resolveBtn = document.getElementById('modal-resolve-btn');
            if (log.resolved == 1) {
                resolveBtn.classList.add('hidden');
            } else {
                resolveBtn.classList.remove('hidden');
                resolveBtn.onclick = () => {
                    resolveError(log.id);
                    hideModal('error-modal');
                };
            }

            showModal('error-modal');
        }

        async function resolveError(id) {
            try {
                const res = await fetch('/api/admin/error_logs', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({ action: 'resolve', id: id })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Error resolved!', 'success');
                    loadErrorLogs();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Request failed', 'error');
            }
        }

        function triggerBulkResolve() {
            showModal('bulk-modal');
        }

        async function submitBulkResolve() {
            const cat = document.getElementById('bulk-category-select').value;
            hideModal('bulk-modal');
            try {
                const res = await fetch('/api/admin/error_logs', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({ action: 'bulk_resolve', category: cat })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`Resolved ${data.resolved_count} errors in category ${cat.toUpperCase()}!`, 'success');
                    loadErrorLogs();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Request failed', 'error');
            }
        }

        function showModal(id) {
            document.getElementById(id).classList.remove('hidden');
        }

        function hideModal(id) {
            document.getElementById(id).classList.add('hidden');
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    </script>
</body>
</html>
