<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
if (($_SESSION['access_level'] ?? '') !== 'owner') {
    header('Location: login.php');
    exit;
}
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../pms_core/Database.php';
$db = Database::getInstance()->getConnection();

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$filterAction = $_GET['action'] ?? '';
$filterEntity = $_GET['entity'] ?? '';
$searchQuery = $_GET['q'] ?? '';

$whereClauses = [];
$params = [];

if (!empty($filterAction)) {
    $whereClauses[] = "a.action = :action";
    $params['action'] = $filterAction;
}
if (!empty($filterEntity)) {
    $whereClauses[] = "a.entity_type = :entity";
    $params['entity'] = $filterEntity;
}
if (!empty($searchQuery)) {
    $whereClauses[] = "(LOWER(a.details) LIKE LOWER(:q) OR LOWER(s.username) LIKE LOWER(:q) OR a.entity_id LIKE :q)";
    $params['q'] = '%' . $searchQuery . '%';
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);
}

// Count query needs JOIN because of search query potentially hitting username
$countSql = "SELECT COUNT(*) FROM audit_logs a LEFT JOIN staff_users s ON a.staff_id = s.id $whereSql";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$logsSql = "
    SELECT a.*, COALESCE(s.username, 'System/Guest') as username 
    FROM audit_logs a 
    LEFT JOIN staff_users s ON a.staff_id = s.id 
    $whereSql
    ORDER BY a.created_at DESC 
    LIMIT $perPage OFFSET $offset
";
$logsStmt = $db->prepare($logsSql);
foreach($params as $k => $v) { $logsStmt->bindValue(":$k", $v); }
$logsStmt->execute();
$logs = $logsStmt->fetchAll();

$uniqueActions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$uniqueEntities = $db->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Audit Logs | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>

</head>
<body class="flex flex-col min-h-screen">
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
        
        <!-- App Bar -->
        <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0 mb-6">
            <div class="flex items-center gap-3">
                <a href="index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                    <i class="ph ph-caret-left text-2xl text-slate-800"></i>
                </a>
                <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">System Logs</h1>
            </div>
            <?php include __DIR__ . '/components/desktop_nav.php'; ?>
        </header>
        
        <main class="flex-1 p-4 pb-24 space-y-4">
            
            <!-- Filters -->
            <form method="GET" class="card-minimal p-4 flex flex-col md:flex-row gap-4 mb-4">
                <div class="flex-1">
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Search Details / Username</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search..." class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-lg text-sm font-medium outline-none focus:bg-white focus:shadow-minimal transition-all">
                </div>
                <div class="w-full md:w-48">
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Action</label>
                    <select name="action" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-lg text-sm font-medium outline-none focus:bg-white focus:shadow-minimal transition-all">
                        <option value="">All Actions</option>
                        <?php foreach($uniqueActions as $ua): ?>
                            <option value="<?= htmlspecialchars($ua) ?>" <?= $filterAction === $ua ? 'selected' : '' ?>><?= htmlspecialchars($ua) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-full md:w-48">
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Entity Type</label>
                    <select name="entity" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-lg text-sm font-medium outline-none focus:bg-white focus:shadow-minimal transition-all">
                        <option value="">All Entities</option>
                        <?php foreach($uniqueEntities as $ue): ?>
                            <option value="<?= htmlspecialchars($ue) ?>" <?= $filterEntity === $ue ? 'selected' : '' ?>><?= htmlspecialchars($ue) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="bg-brand-900 text-white font-bold px-5 py-2.5 rounded-lg hover:-translate-y-0.5 hover:shadow-lg transition-all text-sm h-11">
                        Filter
                    </button>
                    <a href="audit_logs.php" class="bg-brand-100 text-brand-900 font-bold px-4 py-2.5 rounded-lg hover:bg-brand-200 transition-all text-sm h-11 flex items-center justify-center">
                        Clear
                    </a>
                </div>
            </form>

            <div class="card-minimal overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse whitespace-nowrap">
                        <thead>
                            <tr class="bg-brand-50 border-b border-brand-200 text-xs font-bold text-brand-900 uppercase tracking-wider">
                                <th class="p-4">Timestamp</th>
                                <th class="p-4">Staff</th>
                                <th class="p-4">Action</th>
                                <th class="p-4">Entity</th>
                                <th class="p-4 w-full">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-100 text-sm font-medium">
                            <?php if(empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="p-10 text-center text-brand-400 font-medium">
                                    <i class="ph ph-files text-4xl mb-2 block"></i>
                                    No logs found.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach($logs as $log): ?>
                                <tr class="hover:bg-brand-50/50 transition-colors">
                                    <td class="p-4 text-brand-900/70 text-xs">
                                        <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                                    </td>
                                    <td class="p-4 font-bold text-brand-900 text-xs">
                                        <?= htmlspecialchars($log['username']) ?>
                                    </td>
                                    <td class="p-4">
                                        <span class="px-2.5 py-1 bg-indigo-50 text-indigo-700 text-[10px] font-bold rounded-full uppercase tracking-wider">
                                            <?= htmlspecialchars($log['action']) ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-brand-900 font-bold text-xs">
                                        <?= $log['entity_type'] ?> <span class="text-brand-400 font-medium">#<?= $log['entity_id'] ?></span>
                                    </td>
                                    <td class="p-4 text-[11px] text-brand-900/80 font-mono leading-relaxed max-w-md truncate">
                                        <?php 
                                            $details = json_decode($log['details'], true);
                                            if (is_array($details)) {
                                                $formatted = [];
                                                foreach($details as $k => $v) {
                                                    $val = is_array($v) ? json_encode($v) : $v;
                                                    $formatted[] = htmlspecialchars("$k: $val");
                                                }
                                                echo implode(' &bull; ', $formatted);
                                            } else {
                                                echo htmlspecialchars($log['details'] ?: '-');
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php if($totalPages > 1): ?>
            <div class="flex items-center justify-center gap-2 mt-6">
                <?php
                $paginationParams = http_build_query(array_filter([
                    'page' => $page - 1,
                    'action' => $filterAction,
                    'entity' => $filterEntity,
                    'q' => $searchQuery,
                ]));
                $paginationParamsNext = http_build_query(array_filter([
                    'page' => $page + 1,
                    'action' => $filterAction,
                    'entity' => $filterEntity,
                    'q' => $searchQuery,
                ]));
                ?>
                <?php if($page > 1): ?>
                <a href="?<?= $paginationParams ?>" class="px-3 py-1.5 text-sm font-bold rounded-lg bg-white border border-slate-200 text-slate-900 hover:bg-slate-50">Previous</a>
                <?php endif; ?>
                <span class="px-3 py-1.5 text-sm font-bold text-slate-500">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if($page < $totalPages): ?>
                <a href="?<?= $paginationParamsNext ?>" class="px-3 py-1.5 text-sm font-bold rounded-lg bg-white border border-slate-200 text-slate-900 hover:bg-slate-50">Next</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
        </main>
    </div>
</body>
</html>
