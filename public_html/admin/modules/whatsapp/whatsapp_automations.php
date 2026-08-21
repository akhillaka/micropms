<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../../../pms_core/services/SaaSEntitlementsService.php';
AuthHelper::requireLoginOrRedirect();
AuthHelper::requirePermission('send_whatsapp');
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../../../pms_core/Database.php';
require_once __DIR__ . '/../../../../pms_core/config.php';
$db = Database::getInstance()->getConnection();
load_db_settings($db);

$propertyId = AuthHelper::getPropertyId();
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

$pageTitle = "WhatsApp Automations | MicroPMS";

$propId = AuthHelper::getPropertyId();

// --- SETTINGS DATA ---
$tStmt = $db->prepare("SELECT * FROM wa_templates WHERE property_id = ? ORDER BY name ASC");
$tStmt->execute([$propId]);
$templates = $tStmt->fetchAll(PDO::FETCH_ASSOC);
$templateJs = [];
foreach ($templates as &$t) {
    $t['components'] = json_decode($t['components_json'] ?? '[]', true);
    if($t['status'] === 'APPROVED') {
        $t_clean = $t;
        unset($t_clean['components_json']);
        $templateJs[$t['id']] = $t_clean;
    }
}
unset($t);

// --- AUTOMATIONS DATA ---
$globalVars = [
    'booking_id', 'guest_name', 'guest_phone', 'guest_email', 'guest_id_number',
    'room_number', 'room_type', 'rate_plan_name', 'check_in_date', 'check_out_date',
    'total_amount', 'paid_amount', 'balance_amount', 'booking_status', 'hotel_name', 'invoice_link'
];

$eventSpecificVars = [
    'payment_link' => ['payment_link', 'amount_due'],
    'pre_departure' => ['checkout_time'],
    'guest_review_form' => ['review_link']
];

$eventsStmt = $db->query("SELECT id, event_name, event_key, is_system FROM wa_automation_events ORDER BY id ASC");
$eventsData = [];
while($row = $eventsStmt->fetch(PDO::FETCH_ASSOC)) {
    $row['vars'] = array_merge($globalVars, $eventSpecificVars[$row['event_key']] ?? []);
    $eventsData[$row['event_key']] = $row;
}

$autoStmt = $db->prepare("
    SELECT event_key, wa_template_id AS template_id, wa_mapping_json AS variable_mapping_json,
           IF(is_wa_active = 1 AND wa_template_id IS NOT NULL, 'active', 'inactive') AS status
    FROM automation_rules
    WHERE property_id = ? AND deleted_at IS NULL
");
$autoStmt->execute([$propId]);
$automations = [];
while($row = $autoStmt->fetch(PDO::FETCH_ASSOC)) {
    $automations[$row['event_key']] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars((string)($pageTitle), ENT_QUOTES, 'UTF-8') ?></title>
    <?php include __DIR__ . '/../../components/ui_head.php'; ?>
    <style>
        .settings-tab-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            text-align: left;
            white-space: nowrap;
        }
        .settings-tab-btn.tab-active {
            background-color: #000000;
            color: #FFFFFF !important;
            border: none !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .settings-tab-btn.tab-inactive {
            background-color: transparent;
            color: #475569 !important;
            border: none !important;
        }
        .settings-tab-btn.tab-inactive:hover {
            background-color: #F1F5F9;
            color: #0F172A !important;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-brand-50">
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
        <!-- Header -->
        <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-brand-900/20 sticky top-0">
            <div class="flex items-center gap-3">
                <a href="/admin" class="w-12 h-12 bg-brand-accent border border-brand-200 flex items-center justify-center text-black hover:-translate-y-0.5 active:translate-y-0.5 transition-transform">
                    <i class="ph ph-caret-left text-2xl"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-semibold text-brand-900 tracking-tight">WhatsApp Automations</h1>
                    <p class="text-xs font-medium text-brand-900/60 mt-0.5">Manage credentials, templates, and automation rules</p>
                </div>
            </div>
            <?php include __DIR__ . '/../../components/desktop_nav.php'; ?>
        </header>

        <!-- Layout -->
        <div class="flex flex-col md:flex-row flex-1 w-full max-w-7xl mx-auto p-4 md:p-8 gap-6 md:gap-8 overflow-hidden">
            <!-- Sidebar Tab Navigation -->
            <aside class="w-full md:w-64 flex-shrink-0">
                <nav class="flex overflow-x-auto md:flex-col gap-2 no-scrollbar md:pr-4 pb-2 md:pb-0">
                    <button onclick="switchTab('auto')" id="tab-auto" class="settings-tab-btn tab-active">
                        <i class="ph ph-robot text-lg opacity-80"></i> Event Triggers
                    </button>
                    <button onclick="switchTab('api')" id="tab-api" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-plugs-connected text-lg opacity-80"></i> API Config
                    </button>
                    <button onclick="switchTab('templates')" id="tab-templates" class="settings-tab-btn tab-inactive">
                        <i class="ph ph-file-text text-lg opacity-80"></i> WA Templates
                    </button>

                </nav>
            </aside>

            <!-- Main Content -->
            <main class="flex-1 min-w-0 bg-white border border-brand-900/10 shadow-minimal rounded-3xl p-6 relative overflow-y-auto">
                
                <!-- Tab: Automations -->
                <div id="content-auto" class="space-y-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-brand-900">Automation Rules</h2>
                        <button onclick="document.getElementById('add-event-modal').classList.remove('hidden')" class="bg-brutal-yellow text-brand-900 font-semibold px-4 py-2 rounded-xl border border-brand-200 hover:translate-y-px transition-all flex items-center gap-2">
                            <i class="ph ph-plus-circle text-lg"></i> Custom Event
                        </button>
                    </div>

                    <div class="space-y-6">
                        <?php foreach($eventsData as $eventKey => $eventData): 
                            $curAuto = $automations[$eventKey] ?? null;
                            $curMapping = $curAuto ? json_decode($curAuto['variable_mapping_json'], true) : [];
                        ?>
                        <div class="card-minimal p-5">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <h3 class="text-lg font-semibold text-brand-900 flex items-center gap-2">
                                        <i class="ph ph-lightning text-brutal-cyan"></i> <?= htmlspecialchars((string)($eventData['event_name'])) ?>
                                        <?php if(!$eventData['is_system']): ?>
                                            <span class="bg-brand-100 text-brand-900 text-[10px] uppercase font-bold px-2 py-0.5 rounded border border-brand-900">Custom</span>
                                        <?php endif; ?>
                                    </h3>
                                    <?php if(!$eventData['is_system']): ?>
                                        <div class="mt-2 bg-brand-50 border border-brand-200 p-2 rounded-lg inline-block">
                                            <span class="text-[10px] font-bold text-brand-500 uppercase block mb-1">Webhook URL Trigger:</span>
                                            <code class="text-[11px] font-mono text-brand-900 select-all">POST /api/system/trigger_automation {"event":"<?= htmlspecialchars((string)($eventKey), ENT_QUOTES, 'UTF-8') ?>","booking_id":123}</code>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center gap-4">
                                    <label class="text-xs font-bold flex items-center gap-2 cursor-pointer uppercase tracking-wide">
                                        Active
                                        <input type="checkbox" id="status_<?= htmlspecialchars((string)($eventKey), ENT_QUOTES, 'UTF-8') ?>" <?= htmlspecialchars((string)(($curAuto && $curAuto['status'] === 'active') ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="w-4 h-4 accent-brutal-green cursor-pointer">
                                    </label>
                                    <?php if(!$eventData['is_system']): ?>
                                    <button onclick="deleteEvent(<?= htmlspecialchars((string)($eventData['id']), ENT_QUOTES, 'UTF-8') ?>)" class="text-error-500 hover:text-error-700 transition-colors" title="Delete Custom Event">
                                        <i class="ph ph-trash text-lg"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">Map to Template</label>
                                    <select id="template_<?= htmlspecialchars((string)($eventKey), ENT_QUOTES, 'UTF-8') ?>" onchange="renderMapper('<?= htmlspecialchars((string)($eventKey), ENT_QUOTES, 'UTF-8') ?>')" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-xl font-medium outline-none text-sm focus:bg-white focus:shadow-minimal transition-all">
                                        <option value="">-- Do Not Send --</option>
                                        <?php foreach($templateJs as $t): ?>
                                            <option value="<?= htmlspecialchars((string)($t['id']), ENT_QUOTES, 'UTF-8') ?>" <?= htmlspecialchars((string)(($curAuto && $curAuto['template_id'] == $t['id']) ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>><?= htmlspecialchars((string)($t['name'])) ?> (<?= htmlspecialchars((string)($t['language']), ENT_QUOTES, 'UTF-8') ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="mapping_area_<?= htmlspecialchars((string)($eventKey), ENT_QUOTES, 'UTF-8') ?>" class="space-y-2 bg-brand-50 p-3 rounded-xl border border-brand-200 min-h-[60px] flex flex-col justify-center">
                                    <p class="text-xs text-brand-500 text-center font-medium">Select a template to map variables.</p>
                                </div>
                            </div>
                            <div class="mt-4 flex justify-end">
                                <button onclick="saveAutomation('<?= htmlspecialchars((string)($eventKey), ENT_QUOTES, 'UTF-8') ?>')" class="bg-brand-900 text-white font-bold px-5 py-2 rounded-xl hover:-translate-y-0.5 transition-all text-xs">
                                    Save Setup
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tab: API Configuration -->
                <div id="content-api" class="hidden space-y-6">
                    <h2 class="text-xl font-bold text-brand-900 mb-4">XpressBot API Configuration</h2>
                    <form id="api-form" class="space-y-4" onsubmit="saveApiSettings(event)">
                        <div>
                            <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">XpressBot API Key</label>
                            <input type="text" name="WHATSAPP_TOKEN" value="<?= htmlspecialchars((string)(defined('WHATSAPP_TOKEN') ? WHATSAPP_TOKEN : '')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3.5 rounded-xl text-sm outline-none focus:bg-white focus:shadow-minimal transition-all font-mono">
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">XpressBot Channel ID</label>
                                <input type="text" name="WHATSAPP_PHONE_NUMBER_ID" value="<?= htmlspecialchars((string)(defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3.5 rounded-xl text-sm outline-none focus:bg-white focus:shadow-minimal transition-all font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-brand-900 mb-1 uppercase tracking-wider">XpressBot API Base URL</label>
                                <input type="text" name="WHATSAPP_WABA_ID" value="<?= htmlspecialchars((string)(defined('WHATSAPP_WABA_ID') ? (WHATSAPP_WABA_ID ?: 'https://one.xpressbot.org/api/workspace/v1') : 'https://one.xpressbot.org/api/workspace/v1')) ?>" required class="w-full bg-brand-50 border border-brand-200 p-3.5 rounded-xl text-sm outline-none focus:bg-white focus:shadow-minimal transition-all font-mono">
                            </div>
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="bg-brand-900 text-white font-bold px-6 py-3 rounded-xl hover:bg-brand-800 transition-colors shadow-minimal">
                                Save Credentials
                            </button>
                        </div>
                    </form>

                    <!-- Webhook setup help -->
                    <div class="pt-6 border-t border-brand-100">
                        <h3 class="text-sm font-bold text-brand-900 mb-2 uppercase tracking-wide flex items-center gap-2">
                            <i class="ph ph-link text-success-600 text-lg"></i> Webhook Endpoint
                        </h3>
                        <p class="text-xs text-brand-900/60 mb-4">Paste these details into your XpressBot Dashboard to receive guest replies.</p>
                        
                        <div class="space-y-3 bg-brand-50 p-4 rounded-2xl border border-brand-200">
                            <div>
                                <label class="block text-[10px] font-bold text-brand-500 uppercase">Callback URL</label>
                                <div class="flex gap-2 items-center mt-1">
                                    <input type="text" readonly id="webhook-url" value="<?= htmlspecialchars((string)((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . '/api/whatsapp/webhook'), ENT_QUOTES, 'UTF-8') ?>" class="flex-1 bg-white border border-brand-200 p-2.5 rounded-xl text-xs font-mono outline-none text-brand-900">
                                    <button onclick="copyField('webhook-url')" class="bg-white hover:bg-brand-100 border border-brand-200 p-2.5 rounded-xl text-xs font-bold transition-all">Copy</button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-brand-500 uppercase">Verify Token</label>
                                <div class="flex gap-2 items-center mt-1">
                                    <input type="text" readonly id="webhook-token" value="micropms_wa_secure_token_123" class="flex-1 bg-white border border-brand-200 p-2.5 rounded-xl text-xs font-mono outline-none text-brand-900">
                                    <button onclick="copyField('webhook-token')" class="bg-white hover:bg-brand-100 border border-brand-200 p-2.5 rounded-xl text-xs font-bold transition-all">Copy</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Manage Templates -->
                <div id="content-templates" class="hidden space-y-6">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-brand-900">WhatsApp Templates</h2>
                            <p class="text-xs text-brand-900/60 mt-0.5">Approved templates synced from XpressBot</p>
                        </div>
                        <button onclick="syncTemplates()" id="sync-btn" class="bg-success-400 hover:bg-success-500 text-brand-900 font-semibold px-4 py-2 rounded-xl border border-brand-200 shadow-minimal transition-all flex items-center gap-2 text-sm">
                            <i class="ph ph-arrows-counter-clockwise"></i> Sync Now
                        </button>
                    </div>

                    <?php if (empty($templates)): ?>
                        <div class="p-8 text-center bg-brand-50 border border-brand-200 rounded-3xl font-medium text-brand-500">
                            No templates synced yet. Click "Sync Now" to fetch them.
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <?php foreach ($templates as $t): 
                                $body = '';
                                foreach ($t['components'] as $c) {
                                    if ($c['type'] === 'BODY') {
                                        $body = $c['text'];
                                    }
                                }
                            ?>
                                <div class="card-minimal p-4 flex flex-col justify-between">
                                    <div>
                                        <div class="flex justify-between items-start mb-2">
                                            <span class="font-bold text-brand-900 truncate pr-2" title="<?= htmlspecialchars((string)($t['name'])) ?>"><?= htmlspecialchars((string)($t['name'])) ?></span>
                                            <span class="px-2 py-0.5 text-[9px] font-bold uppercase rounded border bg-brand-100 text-brand-900 border-brand-200"><?= htmlspecialchars((string)($t['language'])) ?></span>
                                        </div>
                                        <p class="text-xs text-brand-900/70 font-mono bg-brand-50 p-2.5 rounded-lg border border-brand-200 whitespace-pre-wrap min-h-[60px]"><?= htmlspecialchars((string)($body)) ?></p>
                                    </div>
                                    <div class="mt-3 flex justify-between items-center">
                                        <span class="text-[9px] font-bold uppercase px-2 py-0.5 rounded border <?= htmlspecialchars((string)($t['status'] === 'APPROVED' ? 'bg-success-100 text-success-700 border-success-200' : 'bg-rose-100 text-rose-700 border-rose-200'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($t['status'])) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <!-- Modals -->
    <div id="add-event-modal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
        <div class="card-minimal w-full max-w-sm overflow-hidden flex flex-col">
            <div class="p-4 border-b border-brand-900/20 bg-brand-50 font-semibold flex justify-between items-center">
                <span>Create Custom Event</span>
                <button onclick="document.getElementById('add-event-modal').classList.add('hidden')" class="text-brand-900 hover:text-error-500"><i class="ph ph-x text-xl"></i></button>
            </div>
            <div class="p-4">
                <label class="block text-xs font-bold text-brand-900 mb-1">Event Name</label>
                <input type="text" id="new_event_name" placeholder="e.g. Feedback Request" class="w-full bg-white border border-brand-200 p-3 rounded-xl text-sm font-medium outline-none focus:shadow-minimal transition-all mb-2">
                <p class="text-[10px] text-brand-500 font-medium leading-tight">This generates a custom Webhook URL you can trigger for any booking.</p>
            </div>
            <div class="p-4 border-t border-brand-900/20 bg-brand-50 flex justify-end">
                <button onclick="createEvent()" class="bg-brand-900 text-white font-bold px-6 py-2.5 rounded-xl hover:-translate-y-0.5 transition-all text-sm">
                    Create
                </button>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabId) {
            const tabs = ['auto', 'api', 'templates'];
            tabs.forEach(t => {
                const btn = document.getElementById(`tab-${t}`);
                const content = document.getElementById(`content-${t}`);
                if (t === tabId) {
                    btn.className = "settings-tab-btn tab-active";
                    content.classList.remove('hidden');
                } else {
                    btn.className = "settings-tab-btn tab-inactive";
                    content.classList.add('hidden');
                }
            });
        }

        // --- AUTOMATIONS LOGIC ---
        const templatesJs = <?= json_encode($templateJs) ?>;
        const eventsData = <?= json_encode($eventsData) ?>;
        const curMappings = <?= json_encode(array_map(function($a) { return json_decode($a['variable_mapping_json'], true); }, $automations)) ?>;

        function renderMapper(eventKey) {
            const tempId = document.getElementById(`template_${eventKey}`).value;
            const container = document.getElementById(`mapping_area_${eventKey}`);
            
            if(!tempId || !templatesJs[tempId]) {
                container.innerHTML = '<p class="text-xs text-brand-500 text-center font-medium">Select a template to map variables.</p>';
                return;
            }

            const template = templatesJs[tempId];
            const bodyComp = template.components.find(c => c.type === 'BODY');
            if(!bodyComp) {
                container.innerHTML = '<p class="text-xs text-brand-500 font-medium">This template has no text body.</p>';
                return;
            }

            const matches = bodyComp.text.match(/\{\{\d+\}\}/g);
            if(!matches) {
                container.innerHTML = '<p class="text-xs text-brand-500 font-medium">This template has no variables to map.</p>';
                return;
            }

            const uniqueVars = [...new Set(matches)];
            const availableVars = eventsData[eventKey].vars;
            const savedMapping = curMappings[eventKey] || {};

            let html = `
                <div class="mb-3 p-2 bg-white rounded border border-brand-200">
                    <h4 class="font-bold text-[9px] uppercase tracking-wider text-brand-500 mb-1">Message Preview</h4>
                    <p class="text-[10px] font-mono text-brand-900 whitespace-pre-wrap leading-tight">${bodyComp.text.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>
                </div>
            `;
            
            uniqueVars.forEach(v => {
                const cleanV = v.replace(/[{}]/g, ''); 
                let options = availableVars.map(av => 
                    `<option value="${av}" ${savedMapping[cleanV] === av ? 'selected' : ''}>${av}</option>`
                ).join('');
                
                html += `
                    <div class="flex items-center gap-1.5 mb-1.5">
                        <div class="w-8 text-center bg-brand-900 text-white font-bold rounded p-0.5 text-[10px]">${v}</div>
                        <i class="ph ph-arrow-right text-brand-400 text-xs"></i>
                        <select id="map_${eventKey}_${cleanV}" class="flex-1 border border-brand-200 p-1 rounded text-[10px] font-bold outline-none bg-white">
                            <option value="">-- Static Text --</option>
                            <optgroup label="Dynamic PMS Data">
                                ${options}
                            </optgroup>
                        </select>
                        <input type="text" id="static_${eventKey}_${cleanV}" placeholder="Static..." value="${(savedMapping[cleanV] && !availableVars.includes(savedMapping[cleanV])) ? savedMapping[cleanV] : ''}" class="w-20 border border-brand-200 p-1 rounded text-[10px] font-medium outline-none">
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        async function saveAutomation(eventKey) {
            const tempId = document.getElementById(`template_${eventKey}`).value;
            const status = document.getElementById(`status_${eventKey}`).checked ? 'active' : 'inactive';
            let mapping = {};
            
            if (tempId && templatesJs[tempId]) {
                const bodyComp = templatesJs[tempId].components.find(c => c.type === 'BODY');
                if (bodyComp) {
                    const matches = bodyComp.text.match(/\{\{\d+\}\}/g);
                    if (matches) {
                        const uniqueVars = [...new Set(matches)];
                        uniqueVars.forEach(v => {
                            const cleanV = v.replace(/[{}]/g, '');
                            const pmsVar = document.getElementById(`map_${eventKey}_${cleanV}`).value;
                            const staticVar = document.getElementById(`static_${eventKey}_${cleanV}`).value;
                            mapping[cleanV] = pmsVar || staticVar || "";
                        });
                    }
                }
            }

            try {
                const res = await fetch('/api/admin/save_wa_automation', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ event_key: eventKey, template_id: tempId, status: status, mapping: mapping })
                });
                const data = await res.json();
                if(data.success) showToast('Automation saved!');
                else showToast('Error: ' + data.error);
            } catch (e) {
                showToast('Connection error');
            }
        }

        async function createEvent() {
            const name = document.getElementById('new_event_name').value.trim();
            if(!name) return;
            try {
                const res = await fetch('/api/admin/add_automation_event', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: name })
                });
                const data = await res.json();
                if(data.success) location.reload();
                else showToast('Error: ' + data.error);
            } catch (e) {
                showToast('Connection error');
            }
        }

        async function deleteEvent(id) {
            if(!await pmsConfirm("Are you sure you want to delete this custom event? Automations using it will fail.", "Delete Event", "danger")) return;
            try {
                const res = await fetch('/api/admin/delete_automation_event', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id: id })
                });
                const data = await res.json();
                if(data.success) location.reload();
                else showToast('Error: ' + data.error);
            } catch (e) {
                showToast('Connection error');
            }
        }

        Object.keys(eventsData).forEach(k => renderMapper(k));

        // --- SETTINGS LOGIC ---
        function copyField(id) {
            const input = document.getElementById(id);
            input.select();
            document.execCommand('copy');
            showToast('Copied to clipboard!');
        }

        async function saveApiSettings(e) {
            e.preventDefault();
            const form = document.getElementById('api-form');
            const data = {
                WHATSAPP_TOKEN: form.WHATSAPP_TOKEN.value.trim(),
                WHATSAPP_PHONE_NUMBER_ID: form.WHATSAPP_PHONE_NUMBER_ID.value.trim(),
                WHATSAPP_WABA_ID: form.WHATSAPP_WABA_ID.value.trim(),
                _csrf_token: document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            };
            try {
                const res = await fetch('/api/admin/save_settings', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(data)
                });
                const resData = await res.json();
                if (resData.success) showToast('Credentials saved successfully!');
                else showToast('Failed to save settings');
            } catch (err) {
                showToast('Error saving settings');
            }
        }

        async function syncTemplates() {
            const btn = document.getElementById('sync-btn');
            const oldHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="ph ph-arrows-counter-clockwise animate-spin"></i> Syncing...';
            try {
                const res = await fetch('/api/admin/sync_wa_templates');
                const data = await res.json();
                if (data.success) {
                    showToast(`Successfully synced ${data.count} templates!`);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Sync failed: ' + (data.error || 'API error'));
                }
            } catch (err) {
                showToast('Network error during sync');
            } finally {
                btn.disabled = false;
                btn.innerHTML = oldHtml;
            }
        }


    </script>
    <?php include __DIR__ . '/../../components/mobile_nav.php'; ?>
</body>
</html>
