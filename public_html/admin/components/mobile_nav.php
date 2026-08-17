<?php
// Always use absolute base — SCRIPT_NAME is '/router.php' when served through the router,
// so dynamic strpos-based detection breaks. Hardcode the admin path.
$adminBaseUrl = '/admin/';
$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ($_SERVER['PHP_SELF'] ?? ''));
$navItems = [
    '/admin'                                         => ['icon' => 'ph-house',              'label' => 'Home',        'permission' => 'view_dashboard'],
    '/booking_wizard'                                => ['icon' => 'ph-calendar-plus',      'label' => 'Wizard',      'permission' => 'create_booking'],
    '/admin/modules/housekeeping/rooms_calendar'     => ['icon' => 'ph-calendar-blank',     'label' => 'Rooms',       'permission' => 'housekeeping'],
    '/admin/modules/housekeeping/service_requests'   => ['icon' => 'ph-bell',               'label' => 'Requests',    'permission' => 'housekeeping'],
    '/admin/guests'                                  => ['icon' => 'ph-users',              'label' => 'Guests',      'permission' => 'manage_guests'],
    '/admin/finance'                                 => ['icon' => 'ph-wallet',             'label' => 'Finance',     'permission' => 'view_finance'],
    '/admin/reports'                                 => ['icon' => 'ph-chart-line-up',      'label' => 'Reports',     'permission' => 'view_reports'],
    '/admin/settings'                                => ['icon' => 'ph-gear',               'label' => 'Settings',    'permission' => 'manage_settings'],
    '/admin/automations'                             => ['icon' => 'ph-paper-plane-tilt',   'label' => 'Automations', 'permission' => 'manage_settings'],
    '/admin/logout'                                  => ['icon' => 'ph-sign-out',           'label' => 'Logout',      'permission' => 'view_dashboard'],
];

?>
<!-- Premium Glassmorphism Mobile Nav -->
<nav class="md:hidden fixed bottom-0 left-0 right-0 z-50 bg-white/75 backdrop-blur-xl border-t border-white/70 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_32px_rgba(37,99,235,0.08)]">
    <div class="flex justify-start items-end p-2 max-w-full mx-auto h-[72px] overflow-x-auto hide-scrollbar">
        <?php foreach($navItems as $url => $item): ?>
            <?php 
            if (!AuthHelper::can($item['permission'])) {
                continue;
            }
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            $isActive = (rtrim((string)$path, '/') === rtrim($url, '/') || ($url === '/admin' && in_array($path, ['/admin', '/admin/', '/admin/index'], true)));
            $activeContainer = $isActive ? 'text-blue-600' : 'text-slate-400 hover:text-blue-600 scale-95 hover:scale-100';
            $iconWeight = $isActive ? 'ph-fill' : 'ph';
            ?>
            <a href="<?= htmlspecialchars((string)($url)) ?>" class="relative flex flex-col items-center justify-center w-16 h-full transition-all duration-300 <?= htmlspecialchars((string)($activeContainer), ENT_QUOTES, 'UTF-8') ?>">
                
                <!-- Active Indicator Dot / Pill -->
                <?php if($isActive): ?>
                    <span class="absolute top-0 w-8 h-1 bg-blue-600 rounded-b-full"></span>
                <?php endif; ?>
                
                <!-- Icon container with bouncy animation on active -->
                <div class="flex items-center justify-center w-10 h-10 rounded-xl <?= htmlspecialchars((string)($isActive ? 'bg-blue-50 text-blue-600 -translate-y-1' : 'bg-transparent'), ENT_QUOTES, 'UTF-8') ?> transition-all duration-300">
                    <i class="<?= htmlspecialchars((string)($iconWeight), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)($item['icon']), ENT_QUOTES, 'UTF-8') ?> text-2xl"></i>
                </div>
                
                <span class="text-[10px] font-bold tracking-wide transition-all duration-300 <?= htmlspecialchars((string)($isActive ? 'opacity-100 -translate-y-0.5' : 'opacity-70'), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string)($item['label']), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
