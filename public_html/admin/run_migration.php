<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/MigrationRunner.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = Database::getInstance()->getConnection();

// Allow bootstrapping when no users exist yet (first-time setup)
$userCount = 0;
try {
    $userCount = (int)$db->query("SELECT COUNT(*) FROM staff_users")->fetchColumn();
} catch (\Throwable $e) {
    // Table doesn't exist yet — that's fine for first run
}

// Property owners and superadmins can apply schema updates
if ($userCount > 0) {
    AuthHelper::requireLoginOrRedirect();
    $role = AuthHelper::getRole();
    $canMigrate = AuthHelper::isSuperAdmin() || in_array($role, ['owner', 'admin'], true);
    if (!$canMigrate) {
        header('Location: /admin');
        exit;
    }
}

$runner = new MigrationRunner($db);
$status = '';
$error = '';
$results = null;

if (isset($_POST['run_migration'])) {
    CsrfToken::requireValid();
    $results = $runner->migrate();
    
    if (!empty($results['errors'])) {
        $error = "Migration failed: " . $results['errors'][0]['error'];
    } else {
        $appliedCount = count($results['applied']);
        $skippedCount = count($results['skipped']);
        $status = "Migrations complete! {$appliedCount} applied, {$skippedCount} already up-to-date.";
    }
}

$migrationStatus = $runner->getStatus();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white p-8 rounded-2xl w-full max-w-lg border border-slate-100 shadow-sm">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-indigo-600 flex items-center justify-center text-white rounded-xl">
                <i class="ph ph-database text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-bold text-slate-900 leading-none">Database Migration</h1>
                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mt-1 block">Schema Management</span>
            </div>
        </div>

        <?php if (!empty($status)): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-6 text-sm font-medium">
                <i class="ph ph-check-circle text-lg inline-block align-middle mr-1"></i>
                <?= htmlspecialchars((string)($status)) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-xl mb-6 text-sm font-medium">
                <i class="ph ph-warning-circle text-lg inline-block align-middle mr-1"></i>
                <?= htmlspecialchars((string)($error)) ?>
            </div>
        <?php endif; ?>

        <!-- Migration Status -->
        <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-bold text-slate-900">Migration Status</h2>
                <span class="text-xs font-bold px-2.5 py-1 rounded-lg <?= htmlspecialchars((string)($migrationStatus['pending'] > 0 ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'), ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string)($migrationStatus['pending'] > 0 ? $migrationStatus['pending'] . ' pending' : 'All applied'), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            
            <div class="space-y-2">
                <?php foreach ($migrationStatus['migrations'] as $m): ?>
                    <div class="flex items-center gap-3 p-3 rounded-xl border border-slate-100 <?= htmlspecialchars((string)($m['status'] === 'applied' ? 'bg-slate-50' : 'bg-amber-50'), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center <?= htmlspecialchars((string)($m['status'] === 'applied' ? 'bg-emerald-100 text-emerald-600' : 'bg-amber-100 text-amber-600'), ENT_QUOTES, 'UTF-8') ?>">
                            <i class="ph <?= htmlspecialchars((string)($m['status'] === 'applied' ? 'ph-check' : 'ph-clock'), ENT_QUOTES, 'UTF-8') ?> text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars((string)($m['filename'])) ?></p>
                            <p class="text-[10px] text-slate-500 font-semibold">
                                <?php if ($m['status'] === 'applied'): ?>
                                    Applied <?= htmlspecialchars((string)($m['applied_at']), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)($m['execution_time_ms']), ENT_QUOTES, 'UTF-8') ?>ms)
                                <?php else: ?>
                                    Pending — not yet applied
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($migrationStatus['pending'] > 0): ?>
            <form method="POST">
                <?= CsrfToken::field() ?>
                <button type="submit" name="run_migration" value="1" class="w-full bg-brand-900 text-white rounded-xl py-3 font-bold hover:bg-indigo-700 active:scale-95 transition-transform flex items-center justify-center gap-2">
                    <i class="ph ph-play text-lg"></i>
                    Run <?= htmlspecialchars((string)($migrationStatus['pending']), ENT_QUOTES, 'UTF-8') ?> Pending Migration<?= htmlspecialchars((string)($migrationStatus['pending'] > 1 ? 's' : ''), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </form>
        <?php else: ?>
            <div class="text-center py-4">
                <a href="/login" class="inline-flex items-center gap-2 bg-slate-900 text-white px-6 py-3 rounded-xl text-sm font-bold hover:bg-slate-800 transition-colors">
                    <i class="ph ph-sign-in text-lg"></i> Go to Login
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
