<?php
require_once __DIR__ . '/../../../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../../../pms_core/services/SaaSEntitlementsService.php';
require_once __DIR__ . '/../../../../pms_core/Database.php';

AuthHelper::requireLoginOrRedirect();
CsrfToken::checkTimeout();

$db = Database::getInstance()->getConnection();
$propertyId = AuthHelper::getPropertyId();

$hkEnabled = SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'housekeeping_module');
if (!$hkEnabled) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Housekeeping Upgrade Required | StayFlexi</title>
        <?php include __DIR__ . '/../../components/ui_head.php'; ?>
    </head>
    <body class="flex flex-col min-h-screen items-center justify-center p-6 text-center">
        <div class="max-w-md w-full bg-white border border-slate-200 p-8 rounded-2xl shadow-md space-y-5">
            <h2 class="text-xl font-bold tracking-tight text-slate-800">Housekeeping Module Upgrade Needed</h2>
            <div class="pt-2 flex flex-col gap-2">
                <a href="../../settings.php?tab=subscription" class="px-5 py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition shadow cursor-pointer">Upgrade Subscription Plan</a>
                <a href="../../index.php" class="px-5 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer">Back to Dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Requests | StayFlexi</title>
    <?php include __DIR__ . '/../../components/ui_head.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 flex flex-col min-h-screen font-sans">
    <!-- Top Nav (Mobile) -->
    <?php include __DIR__ . '/../../components/mobile_nav.php'; ?>
    
    <!-- App Bar -->
    <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0">
        <div class="flex items-center gap-3">
            <a href="../../index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                <i class="ph ph-caret-left text-2xl text-slate-800"></i>
            </a>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Service Requests</h1>
        </div>
        <?php include __DIR__ . '/../../components/desktop_nav.php'; ?>
    </header>

    <div class="flex flex-1 overflow-hidden">
        <main class="flex-1 overflow-y-auto p-4 lg:p-8">
            <div class="max-w-5xl mx-auto space-y-6">
                
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Track and fulfill guest requests from the portal.</p>
                    </div>
                    <div>
                        <button onclick="loadRequests()" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium hover:bg-gray-50 shadow-sm transition">
                            <i class="ph ph-arrows-clockwise mr-1"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Board container -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    
                    <!-- Pending Column -->
                    <div class="bg-gray-100/50 border border-gray-200 rounded-xl p-4 flex flex-col h-[70vh]">
                        <h3 class="font-semibold text-gray-700 mb-4 flex items-center justify-between">
                            Pending <span id="count-pending" class="bg-gray-200 text-xs px-2 py-0.5 rounded-full text-gray-600 font-bold">0</span>
                        </h3>
                        <div id="col-pending" class="flex-1 overflow-y-auto space-y-3 pr-1 hide-scrollbar">
                            <!-- Cards go here -->
                        </div>
                    </div>

                    <!-- In Progress Column -->
                    <div class="bg-blue-50/50 border border-blue-100 rounded-xl p-4 flex flex-col h-[70vh]">
                        <h3 class="font-semibold text-blue-700 mb-4 flex items-center justify-between">
                            In Progress <span id="count-in_progress" class="bg-blue-200 text-xs px-2 py-0.5 rounded-full text-blue-800 font-bold">0</span>
                        </h3>
                        <div id="col-in_progress" class="flex-1 overflow-y-auto space-y-3 pr-1 hide-scrollbar">
                            <!-- Cards go here -->
                        </div>
                    </div>

                    <!-- Completed Column -->
                    <div class="bg-green-50/50 border border-green-100 rounded-xl p-4 flex flex-col h-[70vh]">
                        <h3 class="font-semibold text-green-700 mb-4 flex items-center justify-between">
                            Completed Today <span id="count-completed" class="bg-green-200 text-xs px-2 py-0.5 rounded-full text-green-800 font-bold">0</span>
                        </h3>
                        <div id="col-completed" class="flex-1 overflow-y-auto space-y-3 pr-1 hide-scrollbar">
                            <!-- Cards go here -->
                        </div>
                    </div>

                </div>

            </div>
        </main>
    </div>

    <style>
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>

    <script>
        const propertyId = <?= $propertyId ?>;
        const apiBase = '/admin/api/service_requests_api.php';

        async function loadRequests() {
            try {
                const res = await fetch(`${apiBase}?action=list`);
                const data = await res.json();
                
                if (data.success === true) {
                    renderBoard(data.data.requests);
                } else {
                    console.error('Failed to load:', data.message);
                }
            } catch (e) {
                console.error(e);
            }
        }

        function renderBoard(requests) {
            const cols = { pending: [], in_progress: [], completed: [] };
            
            requests.forEach(req => {
                if (cols[req.status]) {
                    cols[req.status].push(req);
                }
            });

            for (const [status, reqs] of Object.entries(cols)) {
                document.getElementById(`count-${status}`).innerText = reqs.length;
                
                const html = reqs.map(req => {
                    let nextAction = '';
                    if (status === 'pending') {
                        nextAction = `<button onclick="updateStatus(${req.id}, 'in_progress')" class="w-full text-xs font-semibold bg-blue-100 text-blue-700 hover:bg-blue-200 py-2 rounded-lg transition mt-3">Start Request</button>`;
                    } else if (status === 'in_progress') {
                        nextAction = `<button onclick="updateStatus(${req.id}, 'completed')" class="w-full text-xs font-semibold bg-green-100 text-green-700 hover:bg-green-200 py-2 rounded-lg transition mt-3">Mark Complete</button>`;
                    }

                    return `
                    <div class="bg-white border border-gray-200 shadow-sm rounded-xl p-4 hover:shadow-md transition">
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-sm font-bold text-gray-800">${req.service_type}</span>
                            <span class="text-[10px] font-medium text-gray-400 bg-gray-50 border border-gray-100 px-2 py-1 rounded-full">${formatTime(req.created_at)}</span>
                        </div>
                        <p class="text-xs text-gray-600 font-medium mb-1"><i class="ph ph-door text-gray-400 mr-1"></i> Room ${req.room_number || 'TBA'}</p>
                        <p class="text-[11px] text-gray-500 mb-2 truncate"><i class="ph ph-user text-gray-400 mr-1"></i> ${req.guest_name}</p>
                        ${nextAction}
                    </div>
                    `;
                }).join('');

                document.getElementById(`col-${status}`).innerHTML = html || `<div class="text-center text-xs text-gray-400 py-6">No requests</div>`;
            }
        }

        async function updateStatus(id, newStatus) {
            try {
                const formData = new FormData();
                formData.append('action', 'update_status');
                formData.append('id', id);
                formData.append('status', newStatus);

                const res = await fetch(apiBase, {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                
                if (data.status === 'success') {
                    loadRequests(); // Refresh
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (e) {
                alert('An error occurred.');
            }
        }

        function formatTime(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        // Initial load & poll every 30 seconds
        loadRequests();
        setInterval(loadRequests, 30000);
    </script>
</body>
</html>
