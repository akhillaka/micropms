<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ModuleHost.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/saas_plans.php';
require_once __DIR__ . '/../../pms_core/services/PropertyOnboardService.php';

ModuleHost::startSession();

$db = Database::getInstance()->getConnection();
$plans = SaaSPlans::get($db);
$selectedPlan = trim((string)($_GET['plan'] ?? $_POST['plan'] ?? 'starter'));
if (!isset($plans[$selectedPlan])) {
    $selectedPlan = isset($plans['starter']) ? 'starter' : (string)array_key_first($plans);
}

$error = '';
$loginUrl = ModuleHost::url('admin', '/login');
$landingUrl = ModuleHost::url('apex', '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfToken::validate()) {
        $error = 'Invalid security token. Refresh the page and try again.';
    } else {
        $password = (string)($_POST['admin_password'] ?? '');
        $pin = trim((string)($_POST['admin_pin'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/^\d{4}$/', $pin)) {
            $error = 'PIN must be exactly 4 digits.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            try {
                $created = PropertyOnboardService::create($db, [
                    'name' => $_POST['name'] ?? '',
                    'city' => $_POST['city'] ?? '',
                    'phone' => $_POST['phone'] ?? '',
                    'email' => $email,
                    'plan' => $_POST['plan'] ?? $selectedPlan,
                    'admin_username' => $_POST['admin_username'] ?? '',
                    'admin_password' => $password,
                    'admin_pin' => $pin,
                ]);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $created['user_id'];
                $_SESSION['role'] = 'owner';
                $_SESSION['access_level'] = 'owner';
                $_SESSION['username'] = $created['username'];
                $_SESSION['property_id'] = $created['property_id'];
                $_SESSION['primary_property_id'] = $created['property_id'];
                header('Location: ' . ModuleHost::url('admin', '/admin'));
                exit;
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                $error = 'Could not create the property. Try a different username.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register your hotel | MicroPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/css/app_theme.css" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #F8FAFC; color: #0F172A; }
        .input-premium { border: 1px solid #E2E8F0; border-radius: 0.75rem; padding: 0.75rem 0.9rem; width: 100%; font-weight: 600; font-size: 0.875rem; }
        .input-premium:focus { outline: none; border-color: #2563EB; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12); }
    </style>
</head>
<body class="min-h-screen py-10 px-4">
    <div class="max-w-lg mx-auto bg-white border border-slate-200 rounded-2xl shadow-sm p-8">
        <a href="<?= htmlspecialchars($landingUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-xs font-bold text-slate-400 hover:text-blue-700">← Back</a>
        <h1 class="text-2xl font-extrabold text-slate-900 mt-4">Register your hotel</h1>
        <p class="text-sm text-slate-500 mt-1 font-medium">Creates a property and an owner login on the selected plan, then opens staff admin.</p>

        <?php if ($error !== ''): ?>
        <div class="mt-5 flex items-center gap-2.5 bg-red-50 border border-red-200 text-red-700 text-sm font-semibold rounded-xl p-3.5">
            <i class="ph ph-warning-circle text-lg shrink-0"></i>
            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" class="mt-6 space-y-4">
            <?= CsrfToken::field() ?>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Hotel name</label>
                <input class="input-premium" type="text" name="name" required value="<?= htmlspecialchars((string)($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">City</label>
                    <input class="input-premium" type="text" name="city" value="<?= htmlspecialchars((string)($_POST['city'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Phone</label>
                    <input class="input-premium" type="text" name="phone" value="<?= htmlspecialchars((string)($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Email</label>
                <input class="input-premium" type="email" name="email" required value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Plan</label>
                <select class="input-premium" name="plan">
                    <?php foreach ($plans as $key => $plan): ?>
                    <option value="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedPlan === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)($plan['name'] ?? $key), ENT_QUOTES, 'UTF-8') ?>
                        — <?= ((int)($plan['price'] ?? 0) === 0) ? 'Free' : ('₹' . number_format((int)$plan['price'])) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Owner username</label>
                <input class="input-premium" type="text" name="admin_username" required autocomplete="username" value="<?= htmlspecialchars((string)($_POST['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">Password</label>
                    <input class="input-premium" type="password" name="admin_password" required minlength="8" autocomplete="new-password">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-1.5">4-digit PIN</label>
                    <input class="input-premium" type="text" name="admin_pin" required maxlength="4" pattern="\d{4}" inputmode="numeric">
                </div>
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-xl py-3 text-sm">Create property &amp; sign in</button>
        </form>
        <p class="mt-5 text-center text-xs text-slate-400 font-semibold">Already registered? <a class="text-blue-700" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Staff login</a></p>
    </div>
</body>
</html>
