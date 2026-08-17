<?php
/**
 * desktop_nav.php — Main Navigation Menu
 * Shows a hamburger menu with all navigation links
 * Works on both mobile and desktop
 */
?>
<?php
// Always use absolute base — SCRIPT_NAME is '/router.php' when served through the router,
// so dynamic strpos-based detection breaks. Hardcode the admin path.
$adminBaseUrl = '/admin/';
?>
<div class="flex items-center gap-3">
    <!-- Notification Bell -->
    <div class="relative" id="notifications-wrap">
        <button onclick="toggleNotifications()" class="relative w-10 h-10 rounded-xl flex items-center justify-center text-brand-600 hover:bg-brand-100 border border-brand-200 transition-all" aria-label="Notifications">
            <i class="ph ph-bell text-xl"></i>
            <span id="notif-badge" class="hidden absolute top-1 right-1 w-3 h-3 bg-red-500 border-2 border-white rounded-full"></span>
        </button>
        <div id="notifications-menu" class="absolute right-0 top-12 w-80 bg-white rounded-2xl border border-slate-100 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.1)] py-2 z-50 animate-fade-up max-h-[80vh] overflow-y-auto hidden">
            <div class="px-4 py-3 border-b border-slate-50 flex justify-between items-center">
                <h3 class="font-bold text-sm text-slate-800">Notifications</h3>
                <div class="flex gap-2">
                    <button onclick="markAllNotificationsRead()" class="text-xs text-brand-600 hover:text-brand-800 font-medium">Mark all read</button>
                    <button onclick="deleteAllNotifications()" class="text-xs text-red-500 hover:text-red-700 font-medium ml-2 border-l pl-2 border-slate-200">Clear All</button>
                </div>
            </div>
            <div id="notif-list" class="divide-y divide-slate-50">
                <div class="px-4 py-8 text-center text-sm text-slate-400">Loading...</div>
            </div>
        </div>
    </div>

    <!-- Main Navigation -->
    <div class="relative" id="desktop-menu-wrap">
        <button onclick="toggleDesktopMenu()" class="w-10 h-10 rounded-xl flex items-center justify-center text-brand-600 hover:bg-brand-100 border border-brand-200 transition-all" aria-label="Menu">
            <i class="ph ph-list text-xl"></i>
        </button>
        <div id="desktop-menu" class="absolute right-0 top-12 w-64 bg-white rounded-2xl border border-slate-100 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.1)] py-2 z-50 animate-fade-up max-h-[80vh] overflow-y-auto hidden">
        
        <!-- Main Navigation -->
        <?php if(AuthHelper::can('view_dashboard')): ?>
            <a href="/admin" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph-fill ph-house text-lg text-brand-500"></i> Dashboard</a>
        <?php endif; ?>
        <?php if(AuthHelper::can('create_booking')): ?>
            <a href="/booking_wizard" target="_blank" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph-fill ph-calendar text-lg text-blue-600"></i> New Reservation Wizard <i class="ph ph-arrow-up-right text-xs ml-auto text-slate-400"></i></a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('housekeeping')): ?>
            <a href="/admin/modules/housekeeping/rooms_calendar" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-calendar-blank text-lg text-slate-400"></i> Rooms Calendar</a>
            <a href="/admin/modules/housekeeping/service_requests" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-bell text-lg text-amber-500"></i> Service Requests</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('manage_guests')): ?>
            <a href="/admin/guests" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-users text-lg text-slate-400"></i> Guests</a>
        <?php endif; ?>
        
        <!-- Operations Section -->
        <div class="border-t border-slate-100 my-1"></div>
        <p class="px-4 py-1 text-[9px] font-bold text-slate-400 uppercase tracking-widest">Operations</p>
        
        <?php if(AuthHelper::can('send_whatsapp')): ?>
            <a href="/admin/modules/whatsapp/whatsapp_automations" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-robot text-lg text-green-500"></i> Automations</a>
            <a href="/admin/modules/whatsapp/whatsapp_logs" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-list-magnifying-glass text-lg text-green-500"></i> WA Delivery Logs</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('view_finance')): ?>
            <a href="/admin/finance" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-wallet text-lg text-slate-400"></i> Finance</a>
        <?php endif; ?>
        <?php if(AuthHelper::can('manage_pos')): ?>
            <a href="/admin/modules/pos/pos" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-shopping-bag text-lg text-blue-600"></i> POS & Inventory</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('run_night_audit') && !AuthHelper::can('manage_settings')): ?>
            <a href="/admin/settings?tab=night-audit" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-moon-stars text-lg text-indigo-500"></i> Night Audit</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('view_reports')): ?>
            <a href="/admin/reports" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-chart-line-up text-lg text-slate-400"></i> Reports & Analytics</a>
        <?php endif; ?>
        
        <!-- Settings Section -->
        <?php if(AuthHelper::can('manage_settings')): ?>
            <div class="border-t border-slate-100 my-1"></div>
            <p class="px-4 py-1 text-[9px] font-bold text-slate-400 uppercase tracking-widest">Settings</p>
            
            <a href="/admin/settings" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-gear text-lg text-indigo-500"></i> Property Configuration</a>
            <a href="/admin/automations" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-paper-plane-tilt text-lg text-blue-500"></i> Notification Automations</a>
        <?php endif; ?>
        
        <!-- System Section -->
        <div class="border-t border-slate-100 my-1"></div>
        <p class="px-4 py-1 text-[9px] font-bold text-slate-400 uppercase tracking-widest">System</p>
        
        <?php if(AuthHelper::can('view_audit_logs')): ?>
            <a href="/admin/audit_logs" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-file-text text-lg text-slate-400"></i> Audit Logs</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('view_error_logs')): ?>
            <a href="/admin/error_logs" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-warning-circle text-lg text-red-500"></i> Error Logs</a>
        <?php endif; ?>
        
        <a href="/admin/api_docs" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-book text-lg text-slate-400"></i> API Docs</a>
        
        <div class="border-t border-slate-100 my-1"></div>
        <?php if (AuthHelper::isSuperAdmin()): ?>
            <p class="px-4 py-1 text-[9px] font-bold text-slate-400 uppercase tracking-widest text-emerald-600">SaaS Controls</p>
            <a href="/saas-admin" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-emerald-700 hover:bg-emerald-50 transition-colors"><i class="ph ph-shield-check text-lg text-emerald-500"></i> Return to SaaS Panel</a>
            <div class="border-t border-slate-100 my-1"></div>
        <?php endif; ?>
        <a href="/admin/logout" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-rose-600 hover:bg-rose-50 transition-colors"><i class="ph ph-sign-out text-lg text-rose-500"></i> Logout</a>
    </div>
</div>

<script>
    let desktopMenuCloseHandler = null;

    function toggleDesktopMenu() {
        const menu = document.getElementById('desktop-menu');
        const isHidden = menu.classList.contains('hidden');
        
        if (desktopMenuCloseHandler) {
            document.removeEventListener('click', desktopMenuCloseHandler);
            desktopMenuCloseHandler = null;
        }

        if (isHidden) {
            menu.classList.remove('hidden');
            // Close on outside click
            desktopMenuCloseHandler = (e) => {
                if (!e.target.closest('#desktop-menu-wrap')) {
                    menu.classList.add('hidden');
                    if (desktopMenuCloseHandler) {
                        document.removeEventListener('click', desktopMenuCloseHandler);
                        desktopMenuCloseHandler = null;
                    }
                }
            };
            setTimeout(() => document.addEventListener('click', desktopMenuCloseHandler), 10);
        } else {
            menu.classList.add('hidden');
        }
    }

    let notificationsCloseHandler = null;

    function toggleNotifications() {
        const menu = document.getElementById('notifications-menu');
        const isHidden = menu.classList.contains('hidden');
        
        if (notificationsCloseHandler) {
            document.removeEventListener('click', notificationsCloseHandler);
            notificationsCloseHandler = null;
        }

        if (isHidden) {
            menu.classList.remove('hidden');
            fetchNotifications();
            // Close on outside click
            notificationsCloseHandler = (e) => {
                if (!e.target.closest('#notifications-wrap')) {
                    menu.classList.add('hidden');
                    if (notificationsCloseHandler) {
                        document.removeEventListener('click', notificationsCloseHandler);
                        notificationsCloseHandler = null;
                    }
                }
            };
            setTimeout(() => document.addEventListener('click', notificationsCloseHandler), 10);
        } else {
            menu.classList.add('hidden');
        }
    }

    function playNotificationSound() {
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            
            // Note 1 (D5)
            const osc1 = audioCtx.createOscillator();
            const gain1 = audioCtx.createGain();
            osc1.connect(gain1);
            gain1.connect(audioCtx.destination);
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(587.33, audioCtx.currentTime);
            gain1.gain.setValueAtTime(0.12, audioCtx.currentTime);
            gain1.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.4);
            
            osc1.start();
            osc1.stop(audioCtx.currentTime + 0.4);
            
            // Note 2 (A5, delayed)
            setTimeout(() => {
                const osc2 = audioCtx.createOscillator();
                const gain2 = audioCtx.createGain();
                osc2.connect(gain2);
                gain2.connect(audioCtx.destination);
                osc2.type = 'sine';
                osc2.frequency.setValueAtTime(880.00, audioCtx.currentTime);
                gain2.gain.setValueAtTime(0.12, audioCtx.currentTime);
                gain2.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.5);
                
                osc2.start();
                osc2.stop(audioCtx.currentTime + 0.5);
            }, 100);
        } catch (e) {
            console.warn('AudioContext failed:', e);
        }
    }

    let lastMaxNotifId = null;

    // XSS-safe HTML escaping helper for dynamic content
    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = String(str ?? '');
        return d.innerHTML;
    }

    function fetchNotifications() {

        fetch('<?php echo $adminBaseUrl; ?>api/notifications.php?action=list')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const badge = document.getElementById('notif-badge');
                    if (data.unread_count > 0) {
                        badge.classList.remove('hidden');
                    } else {
                        badge.classList.add('hidden');
                    }
                    
                    const list = document.getElementById('notif-list');
                    if (data.notifications.length === 0) {
                        list.innerHTML = '<div class="px-4 py-8 text-center text-sm text-slate-400">No new notifications</div>';
                        lastMaxNotifId = 0;
                        return;
                    }
                    
                    const getNotificationLink = (nType) => {
                        if (nType === 'service_request' || nType === 'housekeeping') {
                            return '/admin/modules/housekeeping/service_requests';
                        } else if (nType === 'booking') {
                            return '/admin';
                        }
                        return '#';
                    };

                    // Filter unread notification IDs
                    const unreadNotifications = data.notifications.filter(n => n.is_read == 0);
                    if (unreadNotifications.length > 0) {
                        const maxUnreadId = Math.max(...unreadNotifications.map(n => parseInt(n.id)));
                        if (lastMaxNotifId !== null && maxUnreadId > lastMaxNotifId) {
                            playNotificationSound();
                            // Find the new notification to show a toast
                            const latestNotif = unreadNotifications.find(n => parseInt(n.id) === maxUnreadId);
                            if (latestNotif) {
                                const notifLink = getNotificationLink(latestNotif.type);
                                if (typeof showToast === 'function') {
                                    showToast(`New Alert: ${latestNotif.title}`, 'info', 4200, notifLink !== '#' ? notifLink : null);
                                }
                                if ('Notification' in window && Notification.permission === 'granted') {
                                    const nativeNotif = new Notification(latestNotif.title, { body: latestNotif.message });
                                    nativeNotif.onclick = () => {
                                        window.focus();
                                        if (notifLink && notifLink !== '#') {
                                            window.location.href = notifLink;
                                        }
                                    };
                                }
                            }
                        }
                        lastMaxNotifId = maxUnreadId;
                    } else {
                        lastMaxNotifId = 0;
                    }
                    
                    list.innerHTML = data.notifications.map(n => {
                        let icon = 'ph-bell';
                        let color = 'text-brand-500';
                        if (n.type === 'housekeeping') { icon = 'ph-broom'; color = 'text-amber-500'; }
                        else if (n.type === 'booking') { icon = 'ph-calendar-check'; color = 'text-emerald-500'; }
                        else if (n.type === 'system' || n.type === 'error') { icon = 'ph-warning'; color = 'text-red-500'; }
                        else if (n.type === 'warning') { icon = 'ph-warning-circle'; color = 'text-amber-500'; }
                        else if (n.type === 'success') { icon = 'ph-check-circle'; color = 'text-emerald-500'; }
                        
                        const link = getNotificationLink(n.type);
                        
                        return `
                        <div class="px-4 py-3 hover:bg-slate-50 transition-colors cursor-pointer flex items-start gap-3 ${n.is_read == 0 ? 'bg-slate-50 border-l-2 border-brand-500' : ''}" onclick="handleNotificationClick(${n.id}, '${escHtml(link)}')">
                            <div class="mt-0.5"><i class="ph ${icon} ${color} text-lg"></i></div>
                            <div>
                                <h4 class="text-sm font-bold text-slate-800">${escHtml(n.title)}</h4>
                                <p class="text-xs text-slate-500 mt-1 line-clamp-2">${escHtml(n.message)}</p>
                                <span class="text-[10px] text-slate-400 mt-2 block">${new Date(n.created_at).toLocaleString()}</span>
                            </div>
                        </div>
                    `}).join('');
                }
            });
    }

    // Request Native Notification Permission
    document.addEventListener('DOMContentLoaded', () => {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    });

    function markNotificationRead(id) {
        const fd = new FormData();
        fd.append('id', id);
        fetch('<?php echo $adminBaseUrl; ?>api/notifications.php?action=mark_read', {
            method: 'POST',
            body: fd
        }).then(() => fetchNotifications());
    }

    function handleNotificationClick(id, link) {
        markNotificationRead(id);
        if (link && link !== '#') {
            window.location.href = link;
        }
    }

    function markAllNotificationsRead() {
        fetch('<?php echo $adminBaseUrl; ?>api/notifications.php?action=mark_read', {
            method: 'POST'
        }).then(() => fetchNotifications());
    }

    function deleteAllNotifications() {
        if (!confirm('Are you sure you want to delete all read notifications?')) return;
        fetch('<?php echo $adminBaseUrl; ?>api/notifications.php?action=delete_all', {
            method: 'POST'
        }).then(() => {
            if (typeof showToast === 'function') showToast('Notifications cleared');
            fetchNotifications();
        });
    }

    // Auto-poll notifications every 10 seconds
    setInterval(fetchNotifications, 10000);
    // Initial fetch
    setTimeout(fetchNotifications, 1000);
</script>
