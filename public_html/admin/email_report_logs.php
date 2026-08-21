<?php
declare(strict_types=1);
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
if (!AuthHelper::can('manage_settings')) {
    header('Location: /admin');
    exit;
}
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../pms_core/Database.php';
$db = Database::getInstance()->getConnection();
$propertyId = AuthHelper::getPropertyId();

$typeFilter = (string)($_GET['type'] ?? '');
$statusFilter = (string)($_GET['status'] ?? '');
$where = ['property_id = ?'];
$params = [$propertyId];
if (in_array($typeFilter, ['daily_audit', 'weekly_revenue'], true)) {
    $where[] = 'report_type = ?';
    $params[] = $typeFilter;
}
if (in_array($statusFilter, ['sent', 'failed'], true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
$sql = 'SELECT * FROM email_report_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY sent_at DESC, id DESC LIMIT 200';
$logs = [];
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $logs = [];
    $loadError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Email Report Logs | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
</head>
<body class="flex flex-col min-h-screen bg-slate-50">
<div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
    <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0 mb-6">
        <div class="flex items-center gap-3">
            <a href="/admin/settings" class="p-2 -ml-2 rounded-full hover:bg-slate-100 transition-colors">
                <i class="ph ph-caret-left text-2xl text-slate-800"></i>
            </a>
            <div>
                <h1 class="text-xl font-extrabold text-slate-900 tracking-tight leading-none">Email Report Logs</h1>
                <p class="text-xs text-slate-500 font-medium mt-0.5">Daily audit &amp; weekly revenue send history</p>
            </div>
        </div>
        <?php include __DIR__ . '/components/desktop_nav.php'; ?>
    </header>

    <main class="px-5 pb-24 flex-1 space-y-4">
        <?php if (!empty($loadError)): ?>
            <div class="rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm p-3">
                Could not load logs (run migration 042 if the table is missing): <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <form method="get" class="flex flex-wrap gap-2 items-end bg-white border border-slate-100 rounded-2xl p-4">
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Type</label>
                <select name="type" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="daily_audit" <?= $typeFilter === 'daily_audit' ? 'selected' : '' ?>>Daily audit</option>
                    <option value="weekly_revenue" <?= $typeFilter === 'weekly_revenue' ? 'selected' : '' ?>>Weekly revenue</option>
                </select>
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Status</label>
                <select name="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold">Filter</button>
            <a href="/admin/settings" class="px-4 py-2 text-sm font-bold text-indigo-600">Report settings</a>
        </form>

        <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-[10px] uppercase text-slate-400 font-bold">
                        <tr>
                            <th class="px-4 py-2">Sent at</th>
                            <th class="px-4 py-2">Type</th>
                            <th class="px-4 py-2">Recipient</th>
                            <th class="px-4 py-2">Subject</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                    <?php if ($logs === []): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">No email report sends logged yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $row): ?>
                        <tr>
                            <td class="px-4 py-2 text-xs text-slate-500 whitespace-nowrap"><?= htmlspecialchars((string)date('d M Y H:i', strtotime((string)$row['sent_at']))) ?></td>
                            <td class="px-4 py-2 text-xs font-bold text-slate-700"><?= htmlspecialchars((string)$row['report_type']) ?></td>
                            <td class="px-4 py-2 text-xs"><?= htmlspecialchars((string)$row['recipient']) ?></td>
                            <td class="px-4 py-2 text-xs text-slate-600"><?= htmlspecialchars((string)$row['subject']) ?></td>
                            <td class="px-4 py-2 text-xs font-bold <?= ($row['status'] ?? '') === 'failed' ? 'text-rose-600' : 'text-emerald-600' ?>">
                                <?= htmlspecialchars((string)$row['status']) ?>
                            </td>
                            <td class="px-4 py-2 text-xs text-slate-400"><?= htmlspecialchars((string)($row['error_message'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</body>
</html>
