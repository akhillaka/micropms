<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../../../pms_core/services/SaaSEntitlementsService.php';
AuthHelper::requireLoginOrRedirect();
if (!AuthHelper::can('send_whatsapp')) {
    header('Location: /admin');
    exit;
}
require_once __DIR__ . '/../../../../pms_core/Database.php';

$propertyId = AuthHelper::getPropertyId();
$db = Database::getInstance()->getConnection();
$waEnabled = SaaSEntitlementsService::isFeatureEnabled($db, $propertyId, 'whatsapp_module');
if (!$waEnabled) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>WhatsApp Automations Upgrade Required | StayFlexi</title>
        <?php include __DIR__ . '/../../components/ui_head.php'; ?>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Karla:wght@400;700&display=swap');
            body { font-family: 'Karla', sans-serif; background-color: #f8fafc; color: #1e3a8a; }
        </style>
    </head>
    <body class="flex flex-col min-h-screen items-center justify-center p-6 text-center">
        <div class="max-w-md w-full bg-white border border-slate-200 p-8 rounded-2xl shadow-md space-y-5">
            <div class="w-16 h-16 bg-amber-50 rounded-full flex items-center justify-center mx-auto border border-amber-200 text-amber-600">
                <i class="ph ph-lock text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold tracking-tight text-slate-800">WhatsApp Module Upgrade Needed</h2>
            <p class="text-xs text-slate-500 font-semibold leading-relaxed">
                Your current subscription tier does not have the **WhatsApp Automations & Broadcast Messaging** module enabled. 
                Upgrade to our Enterprise plan to enable custom triggers, automated template flows, and direct delivery verification metrics.
            </p>
            <div class="pt-2 flex flex-col gap-2">
                <a href="/admin/settings?tab=subscription" class="px-5 py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition shadow cursor-pointer">Upgrade Subscription Plan</a>
                <a href="/admin" class="px-5 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer">Back to Dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$pageTitle = "WhatsApp Delivery Logs | MicroPMS";
$db = Database::getInstance()->getConnection();

$limit = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$filterStatus = $_GET['status'] ?? '';
$filterEvent = $_GET['event'] ?? '';
$searchPhone = $_GET['phone'] ?? '';

$whereClauses = [];
$params = [];

if (!empty($filterStatus)) {
    $whereClauses[] = "status = :status";
    $params['status'] = $filterStatus;
}
if (!empty($filterEvent)) {
    $whereClauses[] = "event_key = :event";
    $params['event'] = $filterEvent;
}
if (!empty($searchPhone)) {
    $whereClauses[] = "phone_number LIKE :phone";
    $params['phone'] = '%' . $searchPhone . '%';
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);
}

$stmt = $db->prepare("SELECT * FROM wa_delivery_logs $whereSql ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
foreach($params as $k => $v) {
    $stmt->bindValue(":$k", $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM wa_delivery_logs $whereSql");
foreach($params as $k => $v) {
    $totalStmt->bindValue(":$k", $v);
}
$totalStmt->execute();
$totalLogs = $totalStmt->fetchColumn();
$totalPages = max(1, ceil($totalLogs / $limit));

$uniqueEvents = $db->query("SELECT DISTINCT event_key FROM wa_delivery_logs ORDER BY event_key")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars((string)($pageTitle), ENT_QUOTES, 'UTF-8') ?></title>
    <?php include __DIR__ . '/../../components/ui_head.php'; ?>
</head>
<body class="bg-brand-50 text-brand-900 font-sans antialiased min-h-screen flex flex-col md:flex-row">


    <main class="flex-1 min-w-0 flex flex-col h-screen overflow-hidden">
        
        <header class="bg-white border-b-4 border-brand-900 p-4 sticky top-0 z-40 shrink-0">
            <div class="max-w-7xl mx-auto flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <a href="/admin" class="w-10 h-10 bg-brand-50 rounded-xl border border-brand-200 flex items-center justify-center text-brand-900 hover:bg-brand-100 transition-colors">
                        <i class="ph ph-arrow-left text-xl font-bold"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-brand-900 leading-none">Delivery Logs</h1>
                        <p class="text-sm font-medium text-brand-900/60 mt-1">Track automation triggers & errors</p>
                    </div>
                </div>
                <?php include __DIR__ . '/../../components/desktop_nav.php'; ?>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 md:p-8">
            <div class="max-w-7xl mx-auto space-y-4">
                
                <!-- Filters -->
                <form method="GET" class="card-minimal p-4 flex flex-col md:flex-row gap-4 mb-4">
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Search Phone Number</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars((string)($searchPhone)) ?>" placeholder="e.g. 919876543210" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-lg text-sm font-medium outline-none focus:bg-white focus:shadow-minimal transition-all">
                    </div>
                    <div class="w-full md:w-48">
                        <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Status</label>
                        <select name="status" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-lg text-sm font-medium outline-none focus:bg-white focus:shadow-minimal transition-all">
                            <option value="">All Statuses</option>
                            <option value="success" <?= htmlspecialchars((string)($filterStatus === 'success' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Success</option>
                            <option value="skipped" <?= htmlspecialchars((string)($filterStatus === 'skipped' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Skipped (inactive)</option>
                            <option value="failed" <?= htmlspecialchars((string)($filterStatus === 'failed' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Failed</option>
                        </select>
                    </div>
                    <div class="w-full md:w-48">
                        <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Event Key</label>
                        <select name="event" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-lg text-sm font-medium outline-none focus:bg-white focus:shadow-minimal transition-all">
                            <option value="">All Events</option>
                            <?php foreach($uniqueEvents as $ue): ?>
                                <option value="<?= htmlspecialchars((string)($ue)) ?>" <?= htmlspecialchars((string)($filterEvent === $ue ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>><?= htmlspecialchars((string)($ue)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="bg-brand-900 text-white font-bold px-5 py-2.5 rounded-lg hover:-translate-y-0.5 hover:shadow-lg transition-all text-sm h-11">
                            Filter
                        </button>
                        <a href="/admin/modules/whatsapp/whatsapp_logs" class="bg-brand-100 text-brand-900 font-bold px-4 py-2.5 rounded-lg hover:bg-brand-200 transition-all text-sm h-11 flex items-center justify-center">
                            Clear
                        </a>
                    </div>
                </form>

                <div class="card-minimal overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse whitespace-nowrap">
                            <thead>
                                <tr class="bg-brand-100 border-b-2 border-brand-900 text-xs uppercase tracking-wider text-brand-900 font-bold">
                                    <th class="p-4">Date</th>
                                    <th class="p-4">Event Key</th>
                                    <th class="p-4">Template</th>
                                    <th class="p-4">Phone</th>
                                    <th class="p-4">Status</th>
                                    <th class="p-4">Meta Status</th>
                                    <th class="p-4 w-full">Error Details</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-200 text-sm font-medium">
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="7" class="p-12 text-center text-brand-900/50 font-medium text-sm">
                                            <i class="ph ph-chat-circle-slash text-4xl mb-3 block opacity-50"></i>
                                            No delivery logs found matching your criteria.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                    <tr class="hover:bg-brand-50/50 transition-colors">
                                        <td class="p-4 text-brand-900/70"><?= htmlspecialchars((string)(date('M j, Y h:i A', strtotime($log['created_at']))), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="p-4"><span class="bg-brand-100 border border-brand-200 px-2 py-1 rounded text-xs"><?= htmlspecialchars((string)($log['event_key'])) ?></span></td>
                                        <td class="p-4"><?= htmlspecialchars((string)($log['template_name'])) ?></td>
                                        <td class="p-4 font-mono text-xs"><?= htmlspecialchars((string)($log['phone_number'])) ?></td>
                                        <td class="p-4">
                                            <?php if ($log['status'] === 'success'): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-brutal-green/20 text-green-800 border border-brutal-green">
                                                    <i class="ph-fill ph-check-circle"></i> Sent to API
                                                </span>
                                            <?php elseif ($log['status'] === 'skipped'): ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800 border border-amber-300">
                                                    <i class="ph-fill ph-minus-circle"></i> Skipped
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-brutal-red/20 text-red-800 border border-brutal-red">
                                                    <i class="ph-fill ph-warning-circle"></i> API Failed
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4">
                                            <?php if ($log['status'] === 'success' && $log['message_id']): ?>
                                                <?php 
                                                    $ms = strtolower((string)$log['meta_status']);
                                                    $needsSync = false;
                                                    if (empty($ms) || $ms === 'sent' || $ms === 'delivered') {
                                                        $needsSync = true;
                                                    }
                                                ?>
                                                <span class="meta-status-badge inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border <?= htmlspecialchars((string)($ms === 'read' ? 'bg-blue-100 text-blue-800 border-blue-300' : ($ms === 'delivered' ? 'bg-green-100 text-green-800 border-green-300' : ($ms === 'failed' ? 'bg-red-100 text-red-800 border-red-300' : 'bg-slate-100 text-slate-700 border-slate-300'))), ENT_QUOTES, 'UTF-8') ?>"
                                                      data-log-id="<?= htmlspecialchars((string)($log['id']), ENT_QUOTES, 'UTF-8') ?>" 
                                                      data-needs-sync="<?= htmlspecialchars((string)($needsSync ? 'true' : 'false'), ENT_QUOTES, 'UTF-8') ?>">
                                                    
                                                    <?php if ($needsSync): ?>
                                                        <i class="ph ph-spinner animate-spin"></i> Syncing...
                                                    <?php else: ?>
                                                        <?php if ($ms === 'read'): ?><i class="ph-fill ph-checks"></i> Read
                                                        <?php elseif ($ms === 'delivered'): ?><i class="ph-fill ph-check-circle"></i> Delivered
                                                        <?php elseif ($ms === 'failed'): ?><i class="ph-fill ph-warning-circle"></i> Failed
                                                        <?php else: ?><i class="ph ph-clock"></i> <?= htmlspecialchars((string)(ucfirst($ms)), ENT_QUOTES, 'UTF-8') ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                </span>
                                            <?php else: ?>
                                                <span class="text-brand-300">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4 whitespace-normal">
                                            <?php if ($log['status'] === 'failed' && $log['error_message']): ?>
                                                <?php
                                                    $code = $log['error_code'];
                                                    $friendlyExplanation = '';
                                                    switch ((string)$code) {
                                                        case '131026': $friendlyExplanation = "Message Undeliverable. This usually means the phone number is invalid, not on WhatsApp, or the recipient blocked your business."; break;
                                                        case '131009': $friendlyExplanation = "Template parameter mismatch. The variables passed do not match the approved template's format."; break;
                                                        case '131008': $friendlyExplanation = "A required parameter is missing in the template."; break;
                                                        case '132001': $friendlyExplanation = "Template does not exist or has not been approved in this language."; break;
                                                        case '132000': $friendlyExplanation = "Template is paused or deleted."; break;
                                                        case '133010': $friendlyExplanation = "Phone number is not registered on WhatsApp."; break;
                                                        case '190': $friendlyExplanation = "Your WhatsApp Access Token has expired or is invalid. Please update it in Settings."; break;
                                                        case '100': $friendlyExplanation = "Invalid parameter. Often means the phone number is incorrectly formatted."; break;
                                                    }
                                                ?>
                                                <div class="text-xs bg-red-50 p-3 rounded-lg border border-red-200 shadow-minimal">
                                                    <div class="flex items-start gap-2">
                                                        <i class="ph-fill ph-warning-circle text-red-600 text-lg shrink-0 mt-0.5"></i>
                                                        <div>
                                                            <div class="font-bold text-red-900 mb-1">
                                                                Error Code: <?= htmlspecialchars((string)($code ?: 'Unknown')) ?>
                                                            </div>
                                                            <div class="text-red-700 font-medium mb-1">
                                                                <?= htmlspecialchars((string)($log['error_message'])) ?>
                                                            </div>
                                                            <?php if ($friendlyExplanation): ?>
                                                                <div class="mt-2 text-red-800 bg-red-100/50 p-2 rounded text-[11px] leading-relaxed border border-red-100">
                                                                    <strong>💡 Explanation:</strong> <?= htmlspecialchars((string)($friendlyExplanation), ENT_QUOTES, 'UTF-8') ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-brand-300">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($totalPages > 1): 
                    $queryParams = $_GET;
                    unset($queryParams['page']);
                    $queryStr = empty($queryParams) ? '' : '&' . http_build_query($queryParams);
                ?>
                <div class="flex justify-center gap-2 mt-6">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?= htmlspecialchars((string)($i), ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars((string)($queryStr), ENT_QUOTES, 'UTF-8') ?>" class="w-10 h-10 flex items-center justify-center rounded-xl font-bold text-sm border <?= htmlspecialchars((string)($page === $i ? 'bg-brand-900 text-white border-brand-900' : 'bg-white text-brand-900 border-brand-200 hover:bg-brand-50'), ENT_QUOTES, 'UTF-8') ?> transition-colors">
                            <?= htmlspecialchars((string)($i), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </main>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const badges = document.querySelectorAll('.meta-status-badge[data-needs-sync="true"]');
            
            badges.forEach(badge => {
                const logId = badge.getAttribute('data-log-id');
                fetch(`/api/whatsapp/sync_status?log_id=${logId}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.meta_status) {
                            const ms = data.meta_status.toLowerCase();
                            badge.className = 'meta-status-badge inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border transition-colors duration-300';
                            
                            if (ms === 'read') {
                                badge.classList.add('bg-blue-100', 'text-blue-800', 'border-blue-300');
                                badge.innerHTML = '<i class="ph-fill ph-checks"></i> Read';
                            } else if (ms === 'delivered') {
                                badge.classList.add('bg-green-100', 'text-green-800', 'border-green-300');
                                badge.innerHTML = '<i class="ph-fill ph-check-circle"></i> Delivered';
                            } else if (ms === 'failed') {
                                badge.classList.add('bg-red-100', 'text-red-800', 'border-red-300');
                                badge.innerHTML = '<i class="ph-fill ph-warning-circle"></i> Failed';
                            } else if (ms === 'sent') {
                                badge.classList.add('bg-slate-100', 'text-slate-800', 'border-slate-300');
                                badge.innerHTML = '<i class="ph ph-check"></i> Sent';
                            } else {
                                badge.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-300');
                                badge.innerHTML = `<i class="ph ph-clock"></i> ${ms}`;
                            }
                        } else {
                            badge.innerHTML = '<i class="ph ph-warning"></i> Error';
                            badge.classList.add('bg-yellow-100', 'text-yellow-800', 'border-yellow-300');
                        }
                    })
                    .catch(err => {
                        badge.innerHTML = '<i class="ph ph-warning"></i> Error';
                        badge.classList.add('bg-yellow-100', 'text-yellow-800', 'border-yellow-300');
                    });
            });
        });
    </script>
    <?php include __DIR__ . '/../../components/mobile_nav.php'; ?>
</body>
</html>
