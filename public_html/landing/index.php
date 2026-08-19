<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ModuleHost.php';
require_once __DIR__ . '/../../pms_core/saas_plans.php';

$plans = SaaSPlans::get(null);
try {
    require_once __DIR__ . '/../../pms_core/Database.php';
    $db = Database::getInstance()->getConnection();
    $plans = SaaSPlans::get($db);
} catch (\Throwable $e) {
    $plans = SaaSPlans::get(null);
}

$featureLabels = SaaSPlans::featureLabels();
$loginUrl = ModuleHost::url('admin', '/login');
$saasLoginUrl = ModuleHost::url('saas', '/saas-admin/login');
$registerUrl = ModuleHost::url('apex', '/register');

$productFeatures = [
    ['icon' => 'ph-calendar-check', 'title' => 'Front desk & bookings', 'copy' => 'Walk-ins, reservations, stay dates, and room inventory in one board.'],
    ['icon' => 'ph-receipt', 'title' => 'Folio & payments', 'copy' => 'Charges, collections, Razorpay/PhonePe, and WhatsApp payment links.'],
    ['icon' => 'ph-user-circle', 'title' => 'Guest portal', 'copy' => 'Guests find their stay, share ID, and view invoices without a staff login.'],
    ['icon' => 'ph-device-mobile', 'title' => 'Hotel Assistant', 'copy' => 'Phone-first check-in, housekeeping, and collect for the floor team.'],
    ['icon' => 'ph-broom', 'title' => 'Housekeeping', 'copy' => 'Room status, dirty/clean, and service requests tied to the stay.'],
    ['icon' => 'ph-storefront', 'title' => 'POS & reports', 'copy' => 'F&B orders plus occupancy and finance reports for the owner.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MicroPMS — Hotel property management</title>
    <meta name="description" content="MicroPMS is hotel software for bookings, folio, guest portal, and a phone assistant. Plans from SaaS config.">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/css/app_theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #F8FAFC; color: #0F172A; }
    </style>
</head>
<body>
    <header class="border-b border-slate-200 bg-white/90 backdrop-blur sticky top-0 z-20">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between gap-4">
            <a href="/" class="flex items-center gap-2 font-extrabold text-slate-900">
                <span class="w-9 h-9 rounded-xl bg-blue-600 text-white flex items-center justify-center"><i class="ph ph-buildings"></i></span>
                MicroPMS
            </a>
            <nav class="flex items-center gap-2 sm:gap-3">
                <a href="<?= htmlspecialchars($saasLoginUrl, ENT_QUOTES, 'UTF-8') ?>" class="hidden sm:inline text-xs font-bold text-slate-500 hover:text-blue-700">Platform login</a>
                <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-xs font-bold text-slate-700 border border-slate-200 rounded-xl px-3 py-2 hover:bg-slate-50">Login</a>
                <a href="<?= htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-xs font-extrabold text-white bg-blue-600 rounded-xl px-3 py-2 hover:bg-blue-700">Request access</a>
            </nav>
        </div>
    </header>

    <section class="max-w-6xl mx-auto px-4 py-16 sm:py-20 grid lg:grid-cols-2 gap-12 items-center">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-widest text-blue-700 mb-3">Hotel operations software</p>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-slate-900 leading-tight">Run the desk, the folio, and the guest stay from one PMS.</h1>
            <p class="mt-5 text-slate-600 font-medium leading-relaxed">MicroPMS is built for independent hotels: reservations, in-house folio, a guest portal, and a phone assistant for the floor team. Request access, we set up the property in SaaS, then staff sign in on admin.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="<?= htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-xl px-5 py-3 text-sm">
                    <i class="ph ph-user-plus"></i> Request access
                </a>
                <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-800 font-extrabold rounded-xl px-5 py-3 text-sm hover:bg-slate-50">
                    <i class="ph ph-sign-in"></i> Staff login
                </a>
            </div>
            <p class="mt-3 text-xs text-slate-400 font-semibold">Platform operators: <a class="text-blue-700 hover:underline" href="<?= htmlspecialchars($saasLoginUrl, ENT_QUOTES, 'UTF-8') ?>">SaaS control panel</a></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <p class="text-xs font-extrabold uppercase tracking-widest text-slate-400 mb-4">What you get</p>
            <ul class="space-y-4">
                <?php foreach ($productFeatures as $f): ?>
                <li class="flex gap-3">
                    <span class="w-10 h-10 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center shrink-0"><i class="ph <?= htmlspecialchars($f['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                    <div>
                        <p class="font-extrabold text-slate-900 text-sm"><?= htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-sm text-slate-500"><?= htmlspecialchars($f['copy'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="bg-white border-y border-slate-200 py-16" id="pricing">
        <div class="max-w-6xl mx-auto px-4">
            <h2 class="text-2xl font-extrabold text-slate-900">Pricing</h2>
            <p class="text-sm text-slate-500 mt-2 mb-8">Same plans as SaaS admin. Amounts are ₹ per month as stored in plan config.</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($plans as $planKey => $plan):
                    $price = (int)($plan['price'] ?? 0);
                    $priceLabel = $price === 0 ? 'Free' : ('₹' . number_format($price));
                    $features = is_array($plan['features'] ?? null) ? $plan['features'] : [];
                ?>
                <article class="border border-slate-200 rounded-2xl p-5 flex flex-col bg-slate-50/50">
                    <h3 class="font-extrabold text-slate-900"><?= htmlspecialchars((string)($plan['name'] ?? $planKey), ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="mt-2 text-3xl font-extrabold text-blue-700"><?= htmlspecialchars($priceLabel, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mt-1"><?= $price === 0 ? 'Included' : 'per month' ?></p>
                    <p class="text-sm text-slate-600 mt-4 font-semibold"><?= (int)($plan['max_rooms'] ?? 0) ?> rooms · <?= (int)($plan['max_staff'] ?? 0) ?> staff</p>
                    <ul class="mt-4 space-y-2 text-xs font-semibold text-slate-600 flex-1">
                        <?php foreach ($featureLabels as $flag => $label):
                            $on = !empty($features[$flag]);
                        ?>
                        <li class="flex items-center gap-2">
                            <i class="ph <?= $on ? 'ph-check-circle text-emerald-500' : 'ph-x-circle text-slate-300' ?>"></i>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= htmlspecialchars($registerUrl . (str_contains($registerUrl, '?') ? '&' : '?') . 'plan=' . urlencode((string)$planKey), ENT_QUOTES, 'UTF-8') ?>" class="mt-6 text-center text-xs font-extrabold rounded-xl py-2.5 bg-blue-600 text-white hover:bg-blue-700">Request <?= htmlspecialchars((string)($plan['name'] ?? $planKey), ENT_QUOTES, 'UTF-8') ?></a>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <footer class="max-w-6xl mx-auto px-4 py-10 text-xs text-slate-400 font-semibold flex flex-wrap gap-4 justify-between">
        <span>MicroPMS</span>
        <span class="flex gap-4">
            <a class="hover:text-blue-700" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Staff login</a>
            <a class="hover:text-blue-700" href="<?= htmlspecialchars($saasLoginUrl, ENT_QUOTES, 'UTF-8') ?>">Platform login</a>
            <a class="hover:text-blue-700" href="<?= htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') ?>">Request access</a>
        </span>
    </footer>
</body>
</html>
