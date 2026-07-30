<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();

if (AuthHelper::getRole() !== 'owner') {
    header('Location: ../../index.php');
    exit;
}

require_once __DIR__ . '/../../../../pms_core/Database.php';
require_once __DIR__ . '/../../../../pms_core/config.php';
$db = Database::getInstance()->getConnection();

// Handle Clear Logs POST request (SaaS Platform Maintenance option)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_logs') {
    try {
        $db->exec("TRUNCATE TABLE audit_logs");
        $message = "Audit logs cleared successfully.";
    } catch (\Exception $e) {
        $error = "Failed to clear logs: " . $e->getMessage();
    }
}

// Fetch global logs joined with properties & staff
$logs = [];
try {
    $stmt = $db->query("
        SELECT al.*, su.username, p.name as property_name, p.property_code
        FROM audit_logs al
        LEFT JOIN staff_users su ON al.staff_id = su.id
        LEFT JOIN properties p ON su.property_id = p.id
        ORDER BY al.created_at DESC
        LIMIT 150
    ");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    $error = "Failed to load audit logs: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Security Diagnostics & Audit Logs | MicroPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-panel {
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="bg-[#050811] text-slate-100 min-h-screen">

    <div class="max-w-7xl mx-auto px-4 py-8">
        
        <!-- Header -->
        <div class="flex items-center justify-between mb-8 pb-6 border-b border-slate-800">
            <div>
                <h1 class="text-3xl font-extrabold text-white flex items-center gap-3 tracking-tight">
                    <span class="p-2.5 bg-rose-500/10 border border-rose-500/20 text-rose-400 rounded-2xl">
                        <i class="ph ph-shield-check text-2xl"></i>
                    </span>
                    SaaS Security & Diagnostics
                </h1>
                <p class="text-slate-400 text-sm mt-2">Cross-property audit trail tracking, backend logs, and server transactions monitoring</p>
            </div>
            <div class="flex items-center gap-3">
                <form method="POST" onsubmit="return confirm('Are you sure you want to permanently clear all audit logs?');" class="inline">
                    <input type="hidden" name="action" value="clear_logs">
                    <button type="submit" class="px-4 py-2.5 bg-rose-950/40 hover:bg-rose-900/60 text-rose-400 font-bold border border-rose-900/30 rounded-xl text-sm transition">
                        🗑️ Clear Logs
                    </button>
                </form>
                <a href="saas_properties.php" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl text-sm transition flex items-center gap-2">
                    <i class="ph ph-arrow-left text-base"></i> Back to Console
                </a>
            </div>
        </div>

        <?php if (isset($message)): ?>
            <div class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-xl font-bold text-sm">
                ✅ <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="mb-6 p-4 bg-rose-500/10 border border-rose-500/30 text-rose-400 rounded-xl font-bold text-sm">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Audit Log Table Card -->
        <div class="glass-panel rounded-2xl overflow-hidden shadow-2xl">
            <div class="p-6 border-b border-slate-800 flex items-center justify-between">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <i class="ph ph-activity text-sky-400"></i>
                    Global Operational Logs (Last 150 Actions)
                </h2>
                <span class="text-xs bg-slate-800 text-slate-400 font-extrabold px-3 py-1 rounded-full"><?= count($logs) ?> logs found</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs border-b border-slate-800">
                        <tr>
                            <th class="px-6 py-4 font-bold">Timestamp</th>
                            <th class="px-6 py-4 font-bold">Tenant Hotel</th>
                            <th class="px-6 py-4 font-bold">User</th>
                            <th class="px-6 py-4 font-bold">Action Code</th>
                            <th class="px-6 py-4 font-bold">Scope</th>
                            <th class="px-6 py-4 font-bold">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-300">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-slate-500 font-semibold">
                                    No audit logs recorded yet in the database.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr class="hover:bg-slate-900/20 transition-all">
                                    <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-slate-400">
                                        <?= htmlspecialchars($l['created_at']) ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-extrabold text-white text-xs"><?= htmlspecialchars($l['property_name'] ?? 'System/Root') ?></div>
                                        <?php if (!empty($l['property_code'])): ?>
                                            <div class="text-[10px] text-sky-400 font-mono mt-0.5"><?= htmlspecialchars($l['property_code']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="font-bold text-slate-200 text-xs font-mono">
                                            <?= htmlspecialchars($l['username'] ?? 'System-Bot') ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2.5 py-1 rounded-md text-[10px] font-extrabold uppercase bg-slate-800/80 text-slate-300 border border-slate-700/50">
                                            <?= htmlspecialchars($l['action']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-xs font-semibold text-slate-400">
                                        <?= htmlspecialchars($l['target_type'] ?? 'Global') ?>
                                        <?php if (!empty($l['target_id'])): ?>
                                            <span class="text-sky-500 font-mono">#<?= $l['target_id'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-xs font-mono text-slate-400 max-w-xs truncate" title="<?= htmlspecialchars($l['details'] ?? '') ?>">
                                        <?= htmlspecialchars($l['details'] ?? 'N/A') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</body>
</html>
