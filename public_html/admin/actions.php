<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
CsrfToken::checkTimeout();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Actions Center | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
</head>
<body class="flex flex-col min-h-screen bg-slate-50">
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
        
        <!-- App Bar -->
        <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0 mb-6">
            <div class="flex items-center gap-3">
                <a href="index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                    <i class="ph ph-caret-left text-2xl text-slate-800"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight leading-none">Actions Center</h1>
                    <p id="actions-subtitle" class="text-xs font-bold text-slate-500 uppercase tracking-widest mt-0.5">Loading...</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="loadActions()" class="p-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors" title="Refresh">
                    <i class="ph ph-arrow-clockwise text-slate-500 text-xl" id="refresh-icon"></i>
                </button>
                <?php include __DIR__ . '/components/desktop_nav.php'; ?>
            </div>
        </header>

        <!-- Filter Tabs -->
        <div class="flex overflow-x-auto no-scrollbar border-b border-slate-100 bg-white sticky top-[76px] z-10 px-2">
            <button onclick="filterActions('all')" id="filter-all" class="px-4 py-3 whitespace-nowrap text-sm font-bold border-b-2 border-indigo-600 text-indigo-600 transition-all">All</button>
            <button onclick="filterActions('critical')" id="filter-critical" class="px-4 py-3 whitespace-nowrap text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-900 transition-all">Critical</button>
            <button onclick="filterActions('warning')" id="filter-warning" class="px-4 py-3 whitespace-nowrap text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-900 transition-all">Warnings</button>
            <button onclick="filterActions('info')" id="filter-info" class="px-4 py-3 whitespace-nowrap text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-900 transition-all">Info</button>
        </div>

        <main class="flex-1 p-4 pb-24">
            <div id="actions-list" class="space-y-3">
                <div class="flex items-center justify-center py-12 text-slate-400">
                    <i class="ph ph-spinner animate-spin mr-2 text-xl"></i> Loading actions...
                </div>
            </div>
        </main>
    </div>

    <script>
        const severityConfig = {
            critical: { bg: 'bg-red-50', border: 'border-red-200', icon: 'text-red-500', badge: 'bg-red-100 text-red-700', label: 'Critical' },
            warning: { bg: 'bg-amber-50', border: 'border-amber-200', icon: 'text-amber-500', badge: 'bg-amber-100 text-amber-700', label: 'Warning' },
            info: { bg: 'bg-blue-50', border: 'border-blue-200', icon: 'text-blue-500', badge: 'bg-blue-100 text-blue-700', label: 'Info' }
        };

        let allActions = [];
        let currentFilter = 'all';

        async function loadActions() {
            const icon = document.getElementById('refresh-icon');
            icon.classList.add('animate-spin');

            try {
                const res = await fetch('../api/admin_actions.php');
                const data = await res.json();

                if (data.success) {
                    allActions = data.actions;
                    document.getElementById('actions-subtitle').textContent = data.count + ' action(s) needed';
                    renderActions();
                }
            } catch(e) {
                document.getElementById('actions-list').innerHTML = '<div class="text-center py-12 text-red-400">Failed to load actions</div>';
            }

            setTimeout(() => icon.classList.remove('animate-spin'), 500);
        }

        function filterActions(filter) {
            currentFilter = filter;
            document.querySelectorAll('[id^="filter-"]').forEach(btn => {
                btn.className = 'px-4 py-3 whitespace-nowrap text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-900 transition-all';
            });
            document.getElementById('filter-' + filter).className = 'px-4 py-3 whitespace-nowrap text-sm font-bold border-b-2 border-indigo-600 text-indigo-600 transition-all';
            renderActions();
        }

        function renderActions() {
            const container = document.getElementById('actions-list');
            const filtered = currentFilter === 'all' ? allActions : allActions.filter(a => a.severity === currentFilter);

            if (filtered.length === 0) {
                container.innerHTML = '<div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-8 text-center"><i class="ph ph-check-circle text-emerald-500 text-4xl"></i><p class="text-lg font-bold text-emerald-700 mt-3">All caught up!</p><p class="text-sm text-emerald-600 mt-1">No actions needed right now.</p></div>';
                return;
            }

            const grouped = { critical: [], warning: [], info: [] };
            filtered.forEach(a => (grouped[a.severity] || grouped.info).push(a));

            let html = '';
            for (const [severity, actions] of Object.entries(grouped)) {
                if (actions.length === 0) continue;
                const c = severityConfig[severity];
                html += `<div class="mb-2"><span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold ${c.badge}">${c.label} (${actions.length})</span></div>`;
                
                actions.forEach(a => {
                    html += `
                        <a href="${a.action_url}" class="block ${c.bg} border ${c.border} rounded-2xl p-4 hover:shadow-sm active:scale-[0.99] transition-all">
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5"><i class="ph ${a.icon} ${c.icon} text-xl"></i></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-bold ${c.badge} inline-block px-2 py-0.5 rounded mb-1">${a.title}</p>
                                    <p class="text-sm font-medium text-slate-900 leading-snug">${a.message}</p>
                                </div>
                                <div class="flex-shrink-0 ml-2">
                                    <span class="text-xs font-bold text-slate-500 bg-white border border-slate-200 rounded-lg px-3 py-1.5 hover:bg-slate-50 transition-colors inline-block">${a.action_label}</span>
                                </div>
                            </div>
                        </a>
                    `;
                });
            }

            container.innerHTML = html;
        }

        loadActions();
        setInterval(loadActions, 15000);

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) loadActions();
        });
    </script>
</body>
</html>
