<?php
$adminBaseUrl = substr($_SERVER['SCRIPT_NAME'], 0, strpos($_SERVER['SCRIPT_NAME'], '/admin/') + 7);
    $currentPage = basename($_SERVER['PHP_SELF']);
$navItems = [
    'index.php' => ['icon' => 'ph-house', 'label' => 'Home', 'permission' => 'view_dashboard'],
    '../booking_wizard.php' => ['icon' => 'ph-calendar-plus', 'label' => 'Wizard', 'permission' => 'create_booking'],
    'modules/housekeeping/rooms_calendar.php' => ['icon' => 'ph-calendar-blank', 'label' => 'Rooms', 'permission' => 'housekeeping'],
    'modules/housekeeping/service_requests.php' => ['icon' => 'ph-bell', 'label' => 'Requests', 'permission' => 'housekeeping'],
    'finance.php' => ['icon' => 'ph-wallet', 'label' => 'Finance', 'permission' => 'view_finance'],
    'settings.php' => ['icon' => 'ph-gear', 'label' => 'Settings', 'permission' => 'manage_settings'],
    'guest_portal_settings.php' => ['icon' => 'ph-device-mobile', 'label' => 'Portal', 'permission' => 'manage_settings'],
    'settings.php?tab=roles' => ['icon' => 'ph-shield-check', 'label' => 'Roles', 'permission' => 'manage_settings']
];
?>
<!-- Premium Glassmorphism Mobile Nav -->
<nav class="md:hidden fixed bottom-0 left-0 right-0 z-50 bg-white/85 backdrop-blur-xl border-t border-brand-900/10 pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_24px_rgba(0,0,0,0.02)]">
    <div class="flex justify-around items-end p-2 max-w-md mx-auto h-[72px]">
        <?php foreach($navItems as $url => $item): ?>
            <?php 
            if (!AuthHelper::can($item['permission'])) {
                continue;
            }
            $isActive = ($currentPage === basename($url)); 
            $activeContainer = $isActive ? 'text-brand-900' : 'text-slate-400 hover:text-brand-900 scale-95 hover:scale-100';
            $iconWeight = $isActive ? 'ph-fill' : 'ph';
            ?>
            <?php $finalUrl = (strpos($url, '../') === 0) ? str_replace('/admin/', '/', $adminBaseUrl) . substr($url, 3) : $adminBaseUrl . $url; ?>
            <a href="<?= htmlspecialchars((string)($finalUrl)) ?>" class="relative flex flex-col items-center justify-center w-16 h-full transition-all duration-300 <?= htmlspecialchars((string)($activeContainer), ENT_QUOTES, 'UTF-8') ?>">
                
                <!-- Active Indicator Dot / Pill -->
                <?php if($isActive): ?>
                    <span class="absolute top-0 w-8 h-1 bg-brand-900 rounded-b-full"></span>
                <?php endif; ?>
                
                <!-- Icon container with bouncy animation on active -->
                <div class="flex items-center justify-center w-10 h-10 rounded-full <?= htmlspecialchars((string)($isActive ? 'bg-brand-50 text-brand-900 -translate-y-1 shadow-sm' : 'bg-transparent'), ENT_QUOTES, 'UTF-8') ?> transition-all duration-300">
                    <i class="<?= htmlspecialchars((string)($iconWeight), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)($item['icon']), ENT_QUOTES, 'UTF-8') ?> text-2xl"></i>
                </div>
                
                <span class="text-[10px] font-bold tracking-wide transition-all duration-300 <?= htmlspecialchars((string)($isActive ? 'opacity-100 -translate-y-0.5' : 'opacity-70'), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string)($item['label']), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
