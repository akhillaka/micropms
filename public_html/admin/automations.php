<?php
declare(strict_types=1);
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLogin();
if (!AuthHelper::can('send_whatsapp') && !AuthHelper::can('manage_settings')) {
    header("Location: /admin");
    exit;
}

$activePropertyId = AuthHelper::getPropertyId();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'components/ui_head.php'; ?>
    <title>Notification Automations</title>
    <!-- TinyMCE CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js"></script>
    <style>
        .channel-tab.active {
            border-bottom: 2px solid #3b82f6;
            color: #1e3a8a;
            font-weight: 700;
        }
        .tox-tinymce { border-radius: 0.75rem !important; border: 1px solid #e2e8f0 !important; }
    </style>
</head>
<body class="flex min-h-screen text-slate-800 bg-slate-50">

    <?php include 'components/mobile_nav.php'; ?>


    <main class="flex-1 flex flex-col min-h-screen overflow-hidden">
        <!-- Top Nav -->
        <header class="bg-white border-b border-slate-100 px-4 md:px-8 py-4 flex items-center justify-between shrink-0 sticky top-0 z-30">
            <h1 class="text-xl md:text-2xl font-bold text-slate-800 flex items-center gap-2">
                <i class="ph ph-robot text-brand-600"></i> Notification Automations
            </h1>
            <?php include 'components/desktop_nav.php'; ?>
        </header>

        <div class="flex-1 overflow-y-auto p-4 md:p-8 relative scroll-smooth bg-slate-50">
            <div class="max-w-5xl mx-auto space-y-6">
                
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 md:p-8">
                    <h2 class="text-lg font-bold text-slate-800 mb-2">Automated Notifications</h2>
                    <p class="text-sm text-slate-500 mb-6">Each event ships with a guest email and a staff Telegram note. Open Configure, edit if you want, then enable the channel and save. WhatsApp still uses your approved Meta templates.</p>
                    
                    <div id="events-container" class="space-y-4">
                        <div class="text-center py-8 text-slate-400">Loading events...</div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Modal for Editing Automation -->
    <div id="automation-modal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden animate-fade-up">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 id="modal-title" class="font-bold text-lg text-slate-800">Edit Automation</h3>
                <button onclick="closeModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-200 text-slate-600 hover:bg-slate-300">
                    <i class="ph ph-x text-lg"></i>
                </button>
            </div>
            
            <div class="flex flex-col flex-1 overflow-hidden">
                <!-- Channel Tabs -->
                <div class="flex border-b border-slate-200 px-6">
                    <button class="channel-tab active py-3 px-4 text-sm font-medium text-slate-500 hover:text-slate-800" onclick="switchTab('email', this)">
                        <i class="ph ph-envelope mr-1"></i> Email
                    </button>
                    <button class="channel-tab py-3 px-4 text-sm font-medium text-slate-500 hover:text-slate-800" onclick="switchTab('whatsapp', this)">
                        <i class="ph ph-whatsapp-logo mr-1"></i> WhatsApp
                    </button>
                    <button class="channel-tab py-3 px-4 text-sm font-medium text-slate-500 hover:text-slate-800" onclick="switchTab('telegram', this)">
                        <i class="ph ph-telegram-logo mr-1"></i> Telegram
                    </button>
                </div>
                
                <!-- Tab Contents -->
                <div class="flex-1 overflow-y-auto p-6 bg-white" id="modal-body">
                    <form id="automation-form" onsubmit="saveAutomation(event)">
                        <input type="hidden" id="event_key" name="event_key">
                        
                        <!-- EMAIL TAB -->
                        <div id="tab-email" class="tab-content space-y-4">
                            <label class="flex items-center gap-2 cursor-pointer mb-4">
                                <input type="checkbox" id="is_email_active" name="is_email_active" value="1" class="w-5 h-5 rounded text-blue-600 focus:ring-blue-500 border-slate-300">
                                <span class="font-bold text-slate-800">Enable Email Automation</span>
                            </label>
                            <p class="text-xs text-slate-500 -mt-2 mb-4">Sent to the guest’s email on file. Default copy is already in the editor.</p>
                            
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-semibold text-slate-700">Email Subject</label>
                                    <button type="button" onclick="loadDefaultChannel('email')" class="text-xs font-bold text-brand-600 hover:text-brand-800">Load default</button>
                                </div>
                                <input type="text" id="email_subject" name="email_subject" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500" placeholder="e.g. Booking Confirmation - {hotel_name}">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Email Body</label>
                                <textarea id="email_body_html" name="email_body_html" class="w-full"></textarea>
                            </div>
                        </div>
                        
                        <!-- WHATSAPP TAB -->
                        <div id="tab-whatsapp" class="tab-content space-y-4 hidden">
                            <label class="flex items-center gap-2 cursor-pointer mb-4">
                                <input type="checkbox" id="is_wa_active" name="is_wa_active" value="1" class="w-5 h-5 rounded text-green-600 focus:ring-green-500 border-slate-300">
                                <span class="font-bold text-slate-800">Enable WhatsApp Automation</span>
                            </label>
                            
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Select Meta Template</label>
                                <select id="wa_template_id" name="wa_template_id" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-green-500 focus:ring-1 focus:ring-green-500" onchange="renderWaVariables()">
                                    <option value="">Select a template...</option>
                                </select>
                            </div>
                            
                            <div id="wa-variables-container" class="space-y-3 bg-slate-50 p-4 rounded-xl border border-slate-100 hidden">
                                <h4 class="text-sm font-bold text-slate-700">Map Variables</h4>
                                <div id="wa-variables-list" class="space-y-2"></div>
                            </div>
                        </div>

                        <!-- TELEGRAM TAB -->
                        <div id="tab-telegram" class="tab-content space-y-4 hidden">
                            <label class="flex items-center gap-2 cursor-pointer mb-4">
                                <input type="checkbox" id="is_telegram_active" name="is_telegram_active" value="1" class="w-5 h-5 rounded text-blue-500 focus:ring-blue-400 border-slate-300">
                                <span class="font-bold text-slate-800">Enable Telegram Automation</span>
                            </label>
                            <p class="text-xs text-slate-500 -mt-2 mb-4">Posted to your property Telegram chat — written for the desk, not the guest. HTML: <code class="font-mono">&lt;b&gt;</code>, <code class="font-mono">&lt;i&gt;</code>, <code class="font-mono">&lt;a href=""&gt;</code>.</p>
                            
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-semibold text-slate-700">Telegram Message</label>
                                    <button type="button" onclick="loadDefaultChannel('telegram')" class="text-xs font-bold text-brand-600 hover:text-brand-800">Load default</button>
                                </div>
                                <textarea id="telegram_body_text" name="telegram_body_text" rows="8" class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 font-mono text-sm leading-relaxed" placeholder="e.g. <b>New Booking</b>&#10;Name: {guest_name}"></textarea>
                            </div>
                        </div>
                    </form>
                    
                    <div class="mt-6 p-4 bg-blue-50 border border-blue-100 rounded-xl text-xs text-blue-800 leading-relaxed">
                        <strong class="block mb-1">Available Variables:</strong>
                        <div id="variables-grid" class="grid grid-cols-2 md:grid-cols-4 gap-2 font-mono mt-2">
                            <span>{hotel_name}</span>
                            <span>{guest_name}</span>
                            <span>{first_name}</span>
                            <span>{guest_phone}</span>
                            <span>{guest_email}</span>
                            <span>{booking_id}</span>
                            <span>{check_in_date}</span>
                            <span>{check_out_date}</span>
                            <span>{room_type}</span>
                            <span>{room_number}</span>
                            <span>{total_amount}</span>
                            <span>{paid_amount}</span>
                            <span>{balance_amount}</span>
                            <span>{invoice_link}</span>
                        </div>
                    </div>
                </div>
                
                <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end gap-3 shrink-0">
                    <button type="button" onclick="closeModal()" class="px-5 py-2.5 rounded-xl text-sm font-bold text-slate-600 hover:bg-slate-200">Cancel</button>
                    <button type="button" onclick="document.getElementById('automation-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))" class="px-5 py-2.5 rounded-xl text-sm font-bold bg-brand-600 text-white hover:bg-brand-700 shadow-md">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        let waTemplates = [];
        let automationsData = [];
        let currentWaMapping = [];
        let currentEventIndex = 0;

        const GLOBAL_VARS = [
            'hotel_name', 'guest_name', 'first_name', 'guest_phone', 'guest_email',
            'booking_id', 'check_in_date', 'check_out_date', 'room_type', 'room_number',
            'total_amount', 'paid_amount', 'balance_amount', 'invoice_link'
        ];

        document.addEventListener('DOMContentLoaded', () => {
            // Initialize TinyMCE for Email inside DOMContentLoaded so the
            // textarea exists in the DOM before TinyMCE tries to attach.
            tinymce.init({
                selector: '#email_body_html',
                height: 400,
                menubar: false,
                plugins: [
                    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                    'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                    'insertdatetime', 'media', 'table', 'help', 'wordcount'
                ],
                toolbar: 'undo redo | blocks fontfamily fontsize | ' +
                'bold italic forecolor backcolor | alignleft aligncenter ' +
                'alignright alignjustify | bullist numlist outdent indent | ' +
                'removeformat | help',
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 14px }',
                branding: false,
                promotion: false,
                convert_urls: false,
                relative_urls: false,
                remove_script_host: false
            });

            fetchWaTemplates();
            fetchAutomations();
        });

        function fetchWaTemplates() {
            fetch('/api/admin/get_wa_templates')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        waTemplates = data.templates;
                        const sel = document.getElementById('wa_template_id');
                        sel.innerHTML = '<option value="">Select a template...</option>';
                        waTemplates.forEach(t => {
                            sel.innerHTML += `<option value="${t.id}">${t.name} (${t.language})</option>`;
                        });
                    }
                })
                .catch(() => {
                    console.warn('Failed to load WhatsApp templates');
                });
        }

        function fetchAutomations() {
            document.getElementById('events-container').innerHTML = '<div class="text-center py-8 text-slate-400">Loading events...</div>';
            fetch('/api/admin/get_automations')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        automationsData = data.data;
                        renderEventsList();
                    } else {
                        document.getElementById('events-container').innerHTML = '<div class="text-center py-8 text-red-400">Failed to load automations. Please refresh.</div>';
                    }
                })
                .catch(() => {
                    document.getElementById('events-container').innerHTML = '<div class="text-center py-8 text-red-400">Network error. Please refresh the page.</div>';
                });
        }

        function renderEventsList() {
            const container = document.getElementById('events-container');
            if (!automationsData.length) {
                container.innerHTML = '<div class="text-center py-8 text-slate-400">No automation events configured.</div>';
                return;
            }
            container.innerHTML = automationsData.map((ev, index) => {
                const isEmailOn = ev.is_email_active === 1;
                const isWaOn = ev.is_wa_active === 1;
                const isTgOn = ev.is_telegram_active === 1;
                const defaultHint = (ev.using_default_email || ev.using_default_telegram)
                    ? '<span class="ml-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">defaults ready</span>'
                    : '';
                
                return `
                <div class="flex items-center justify-between p-4 bg-slate-50 border border-slate-100 rounded-2xl hover:border-brand-200 transition-colors">
                    <div>
                        <h3 class="font-bold text-slate-800">${ev.event_name}${defaultHint}</h3>
                        <p class="text-xs text-slate-500 font-mono mt-1">${ev.event_key}</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="flex gap-2">
                            <span class="w-8 h-8 flex items-center justify-center rounded-full ${isEmailOn ? 'bg-blue-100 text-blue-600' : 'bg-slate-200 text-slate-400'}" title="Email">
                                <i class="ph ph-envelope"></i>
                            </span>
                            <span class="w-8 h-8 flex items-center justify-center rounded-full ${isWaOn ? 'bg-green-100 text-green-600' : 'bg-slate-200 text-slate-400'}" title="WhatsApp">
                                <i class="ph ph-whatsapp-logo"></i>
                            </span>
                            <span class="w-8 h-8 flex items-center justify-center rounded-full ${isTgOn ? 'bg-sky-100 text-sky-600' : 'bg-slate-200 text-slate-400'}" title="Telegram">
                                <i class="ph ph-telegram-logo"></i>
                            </span>
                        </div>
                        <button onclick="openModal(${index})" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 rounded-lg text-sm font-bold hover:bg-slate-50">
                            Configure
                        </button>
                    </div>
                </div>
                `;
            }).join('');
        }

        function switchTab(tab, el) {
            document.querySelectorAll('.channel-tab').forEach(e => e.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(e => e.classList.add('hidden'));
            el.classList.add('active');
            document.getElementById(`tab-${tab}`).classList.remove('hidden');
        }

        function openModal(index) {
            currentEventIndex = index;
            const ev = automationsData[index];
            document.getElementById('modal-title').innerText = `Configure: ${ev.event_name}`;
            document.getElementById('event_key').value = ev.event_key;
            
            // Populate Email
            document.getElementById('is_email_active').checked = ev.is_email_active === 1;
            document.getElementById('email_subject').value = ev.email_subject || '';
            const tinyEditor = tinymce.get('email_body_html');
            if (tinyEditor) tinyEditor.setContent(ev.email_body_html || '');
            
            // Populate Telegram
            document.getElementById('is_telegram_active').checked = ev.is_telegram_active === 1;
            document.getElementById('telegram_body_text').value = ev.telegram_body_text || '';
            
            // Populate WhatsApp
            document.getElementById('is_wa_active').checked = ev.is_wa_active === 1;
            document.getElementById('wa_template_id').value = ev.wa_template_id || '';
            
            try {
                currentWaMapping = JSON.parse(ev.wa_mapping_json || '[]');
            } catch(e) {
                currentWaMapping = [];
            }
            renderWaVariables();
            renderVariableChips(ev);
            
            // Reset to Email tab
            document.querySelector('.channel-tab').click();
            document.getElementById('automation-modal').classList.remove('hidden');
        }

        function loadDefaultChannel(channel) {
            const ev = automationsData[currentEventIndex];
            if (!ev || !ev.defaults) return;
            if (channel === 'email') {
                document.getElementById('email_subject').value = ev.defaults.email_subject || '';
                const tinyEditor = tinymce.get('email_body_html');
                if (tinyEditor) tinyEditor.setContent(ev.defaults.email_body_html || '');
            }
            if (channel === 'telegram') {
                document.getElementById('telegram_body_text').value = ev.defaults.telegram_body_text || '';
            }
        }

        function renderVariableChips(ev) {
            const grid = document.getElementById('variables-grid');
            if (!grid) return;
            const extras = Array.isArray(ev.extra_variables) ? ev.extra_variables : [];
            const all = [...GLOBAL_VARS, ...extras.filter(v => !GLOBAL_VARS.includes(v))];
            grid.innerHTML = all.map(v => `<span>{${v}}</span>`).join('');
        }

        function closeModal() {
            document.getElementById('automation-modal').classList.add('hidden');
        }

        function renderWaVariables() {
            const tplId = document.getElementById('wa_template_id').value;
            const container = document.getElementById('wa-variables-container');
            const list = document.getElementById('wa-variables-list');
            
            if (!tplId) {
                container.classList.add('hidden');
                return;
            }
            
            const tpl = waTemplates.find(t => t.id == tplId);
            if (!tpl) return;
            
            let components = [];
            try {
                components = JSON.parse(tpl.components_json);
            } catch(e){}
            
            const bodyComp = components.find(c => c.type === 'BODY');
            if (!bodyComp || !bodyComp.text) {
                container.classList.add('hidden');
                return;
            }
            
            const matches = bodyComp.text.match(/\{\{(\d+)\}\}/g);
            if (!matches) {
                list.innerHTML = '<p class="text-xs text-slate-500">This template has no variables.</p>';
                container.classList.remove('hidden');
                return;
            }
            
            const varCount = [...new Set(matches)].length;
            
            let html = '';
            for(let i=1; i<=varCount; i++) {
                const val = currentWaMapping[i-1] || '';
                html += `
                <div class="flex items-center gap-2">
                    <span class="w-16 text-xs font-bold text-slate-500 text-right">{{${i}}} =</span>
                    <input type="text" class="wa-var-input flex-1 px-3 py-1.5 text-sm rounded-lg border border-slate-200 focus:border-green-500 focus:ring-1 focus:ring-green-500" value="${val}" placeholder="{guest_name}">
                </div>
                `;
            }
            list.innerHTML = html;
            container.classList.remove('hidden');
        }

        function saveAutomation(e) {
            e.preventDefault();
            
            // Sync TinyMCE to textarea
            tinymce.triggerSave();
            
            const fd = new FormData(document.getElementById('automation-form'));
            
            // Build WA mapping
            const waInputs = document.querySelectorAll('.wa-var-input');
            const mapping = Array.from(waInputs).map(inp => inp.value);
            fd.append('wa_mapping_json', JSON.stringify(mapping));
            
            // Ensure unchecked checkboxes send '0' instead of null
            if (!fd.has('is_email_active')) fd.append('is_email_active', '0');
            if (!fd.has('is_wa_active')) fd.append('is_wa_active', '0');
            if (!fd.has('is_telegram_active')) fd.append('is_telegram_active', '0');

            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
            fd.append('csrf_token', csrfToken);

            fetch('/api/admin/save_automation', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    if (typeof showToast === 'function') showToast('Automation saved successfully', 'success');
                    closeModal();
                    fetchAutomations();
                } else {
                    if (typeof showToast === 'function') {
                        showToast('Error: ' + (data.error || 'Unknown error'), 'error');
                    } else {
                        alert('Error: ' + data.error);
                    }
                }
            })
            .catch(err => {
                if (typeof showToast === 'function') {
                    showToast('Network error. Please try again.', 'error');
                } else {
                    alert('Network error occurred');
                }
                console.error(err);
            });
        }
    </script>
</body>
</html>
