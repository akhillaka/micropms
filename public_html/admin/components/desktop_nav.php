<?php
/**
 * desktop_nav.php — Main Navigation Menu
 * Shows a hamburger menu with all navigation links
 * Works on both mobile and desktop
 */
?>
<?php $adminBaseUrl = substr($_SERVER['SCRIPT_NAME'], 0, strpos($_SERVER['SCRIPT_NAME'], '/admin/') + 7); ?>
<div class="relative" id="desktop-menu-wrap">
    <button onclick="toggleDesktopMenu()" class="w-10 h-10 rounded-xl flex items-center justify-center text-brand-600 hover:bg-brand-100 border border-brand-200 transition-all" aria-label="Menu">
        <i class="ph ph-list text-xl"></i>
    </button>
    <div id="desktop-menu" class="absolute right-0 top-12 w-64 bg-white rounded-2xl border border-slate-100 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.1)] py-2 z-50 animate-fade-up max-h-[80vh] overflow-y-auto hidden">
        
        <!-- Main Navigation -->
        <?php if(AuthHelper::can('view_dashboard')): ?>
            <a href="<?php echo $adminBaseUrl; ?>index.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph-fill ph-house text-lg text-brand-500"></i> Dashboard</a>
        <?php endif; ?>
        <?php if(AuthHelper::can('create_booking')): ?>
            <a href="<?php echo str_replace('/admin/', '/', $adminBaseUrl); ?>booking_wizard.php" target="_blank" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph-fill ph-calendar text-lg text-indigo-500"></i> New Reservation Wizard <i class="ph ph-arrow-up-right text-xs ml-auto text-slate-400"></i></a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('housekeeping')): ?>
            <a href="<?php echo $adminBaseUrl; ?>modules/housekeeping/rooms_calendar.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-calendar-blank text-lg text-slate-400"></i> Rooms Calendar</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('manage_guests')): ?>
            <a href="<?php echo $adminBaseUrl; ?>guests.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-users text-lg text-slate-400"></i> Guests</a>
        <?php endif; ?>
        
        <!-- Operations Section -->
        <div class="border-t border-slate-100 my-1"></div>
        <p class="px-4 py-1 text-[9px] font-bold text-slate-400 uppercase tracking-widest">Operations</p>
        
        <?php if(AuthHelper::can('send_whatsapp')): ?>
            <a href="<?php echo $adminBaseUrl; ?>modules/whatsapp/whatsapp_automations.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-robot text-lg text-green-500"></i> Automations</a>
            <a href="<?php echo $adminBaseUrl; ?>modules/whatsapp/whatsapp_logs.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-list-magnifying-glass text-lg text-green-500"></i> WA Delivery Logs</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('view_finance')): ?>
            <a href="<?php echo $adminBaseUrl; ?>finance.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-wallet text-lg text-slate-400"></i> Finance</a>
            <a href="<?php echo $adminBaseUrl; ?>modules/pos/pos.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-shopping-bag text-lg text-indigo-500"></i> POS & Inventory</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('view_reports')): ?>
            <a href="<?php echo $adminBaseUrl; ?>reports.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-chart-line-up text-lg text-slate-400"></i> Reports & Analytics</a>
        <?php endif; ?>
        
        <!-- Settings Section -->
        <?php if(AuthHelper::can('manage_settings')): ?>
            <div class="border-t border-slate-100 my-1"></div>
            <p class="px-4 py-1 text-[9px] font-bold text-slate-400 uppercase tracking-widest">Settings</p>
            
            <a href="<?php echo $adminBaseUrl; ?>settings.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-gear text-lg text-indigo-500"></i> Property Configuration</a>
        <?php endif; ?>
        
        <!-- System Section -->
        <div class="border-t border-slate-100 my-1"></div>
        <p class="px-4 py-1 text-[9px] font-bold text-slate-400 uppercase tracking-widest">System</p>
        
        <?php if(AuthHelper::can('view_audit_logs')): ?>
            <a href="<?php echo $adminBaseUrl; ?>audit_logs.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-file-text text-lg text-slate-400"></i> Audit Logs</a>
        <?php endif; ?>
        
        <?php if(AuthHelper::can('view_error_logs')): ?>
            <a href="<?php echo $adminBaseUrl; ?>error_logs.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-warning-circle text-lg text-red-500"></i> Error Logs</a>
        <?php endif; ?>
        
        <a href="<?php echo $adminBaseUrl; ?>api_docs.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors"><i class="ph ph-book text-lg text-slate-400"></i> API Docs</a>
        
        <div class="border-t border-slate-100 my-1"></div>
        <?php if (($_SESSION['access_level'] ?? '') === 'owner'): ?>
            <p class="px-4 py-1 text-[9px] font-bold text-slate-400 uppercase tracking-widest text-emerald-600">SaaS Controls</p>
            <a href="<?php echo $adminBaseUrl; ?>modules/saas/saas_properties.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-emerald-700 hover:bg-emerald-50 transition-colors"><i class="ph ph-shield-check text-lg text-emerald-500"></i> Return to SaaS Panel</a>
            <div class="border-t border-slate-100 my-1"></div>
        <?php endif; ?>
        <a href="<?php echo $adminBaseUrl; ?>logout.php" class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-rose-600 hover:bg-rose-50 transition-colors"><i class="ph ph-sign-out text-lg text-rose-500"></i> Logout</a>
    </div>
</div>

<script>
    function toggleDesktopMenu() {
        const menu = document.getElementById('desktop-menu');
        const isHidden = menu.classList.contains('hidden');
        
        if (isHidden) {
            menu.classList.remove('hidden');
            // Close on outside click
            const closeHandler = (e) => {
                if (!e.target.closest('#desktop-menu-wrap')) {
                    menu.classList.add('hidden');
                    document.removeEventListener('click', closeHandler);
                }
            };
            setTimeout(() => document.addEventListener('click', closeHandler), 10);
        } else {
            menu.classList.add('hidden');
        }
    }
</script>
