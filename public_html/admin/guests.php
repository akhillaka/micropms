<?php
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../pms_core/Database.php';
$db = Database::getInstance()->getConnection();

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'last_visit_desc';

require_once __DIR__ . '/../../pms_core/PhoneHelper.php';

$propertyId = AuthHelper::getPropertyId();

// Base WHERE
$whereClause = "g.property_id = :property_id2";
$params = [];
if (!empty($search)) {
    $cleanPhone = PhoneHelper::clean($search);
    $phoneSearch = !empty($cleanPhone) ? "%$cleanPhone%" : "%$search%";
    $whereClause .= " AND (LOWER(g.name) LIKE LOWER(:search1) OR g.phone LIKE :search2 OR LOWER(g.email) LIKE LOWER(:search3))";
    $params['search1'] = "%$search%";
    $params['search2'] = $phoneSearch;
    $params['search3'] = "%$search%";
}

// Sorting logic
$orderSql = "last_visit DESC";
switch ($sort) {
    case 'spent_desc': $orderSql = "total_spent DESC"; break;
    case 'stays_desc': $orderSql = "total_bookings DESC"; break;
    case 'name_asc': $orderSql = "g.name ASC"; break;
    case 'last_visit_desc': $orderSql = "last_visit DESC"; break;
}

$params['property_id'] = $propertyId;
$params['property_id2'] = $propertyId;

// Count total matching
$countQuery = "
    SELECT COUNT(*) FROM (
        SELECT g.id 
        FROM guests g 
        LEFT JOIN bookings b ON g.id = b.guest_id AND b.property_id = :property_id
        WHERE $whereClause 
        GROUP BY g.id
    ) AS cq
";
$stmtCount = $db->prepare($countQuery);
foreach ($params as $k => $v) $stmtCount->bindValue(":$k", $v);
$stmtCount->execute();
$total = $stmtCount->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Fetch guests
$guestsQuery = "
    SELECT g.*, 
           COUNT(b.id) as total_bookings,
           SUM(b.total_amount) as total_spent,
           MAX(b.check_in) as last_visit
    FROM guests g
    LEFT JOIN bookings b ON g.id = b.guest_id AND b.property_id = :property_id
    WHERE $whereClause
    GROUP BY g.id
    ORDER BY $orderSql
    LIMIT :limit OFFSET :offset
";
$stmtGuests = $db->prepare($guestsQuery);
foreach ($params as $k => $v) $stmtGuests->bindValue(":$k", $v);
$stmtGuests->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmtGuests->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtGuests->execute();
$guests = $stmtGuests->fetchAll(PDO::FETCH_ASSOC);

// Top Metrics
$stmtTg = $db->prepare("SELECT COUNT(DISTINCT guest_id) FROM bookings WHERE property_id = ?");
$stmtTg->execute([(int)$propertyId]);
$totalGuestsAll = $stmtTg->fetchColumn();

$stmtTs = $db->prepare("SELECT SUM(total_amount) FROM bookings WHERE property_id = ? AND booking_status != 'cancelled'");
$stmtTs->execute([(int)$propertyId]);
$totalSpentAll = $stmtTs->fetchColumn();

$stmtRg = $db->prepare("SELECT COUNT(*) FROM (SELECT guest_id FROM bookings WHERE property_id = ? GROUP BY guest_id HAVING COUNT(id) > 1) as t");
$stmtRg->execute([(int)$propertyId]);
$returningGuests = $stmtRg->fetchColumn();
$returningPct = $totalGuestsAll > 0 ? round(($returningGuests / $totalGuestsAll) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, ">
    <title>Guest Directory | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
</head>
<body class="flex flex-col min-h-screen bg-slate-50">
    <div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
        
        <!-- App Bar -->
        <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0 mb-6">
            <div class="flex items-center gap-3">
                <a href="index.php" class="p-2 -ml-2 rounded-full hover:bg-slate-100 active:bg-slate-200 transition-colors">
                    <i class="ph ph-caret-left text-2xl text-slate-800"></i>
                </a>
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900 tracking-tight leading-none">Guest CRM</h1>
                    <span class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Directory & Insights</span>
                </div>
            </div>
            <?php include __DIR__ . '/components/desktop_nav.php'; ?>
        </header>

        <main class="flex-1 p-4 space-y-6">
            
            <!-- Metrics Row -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
                        <i class="ph-fill ph-users text-2xl"></i>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-brand-500 uppercase tracking-widest mb-1">Total Guests</div>
                        <div class="text-2xl font-black text-brand-900"><?= htmlspecialchars((string)(number_format($totalGuestsAll)), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-green-100 text-green-600 flex items-center justify-center shrink-0">
                        <i class="ph-fill ph-money text-2xl"></i>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-brand-500 uppercase tracking-widest mb-1">Lifetime Value</div>
                        <div class="text-2xl font-black text-brand-900">₹<?= htmlspecialchars((string)(number_format((float)$totalSpentAll, 2)), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <div class="card-minimal p-5 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center shrink-0">
                        <i class="ph-fill ph-arrows-clockwise text-2xl"></i>
                    </div>
                    <div>
                        <div class="text-[10px] font-bold text-brand-500 uppercase tracking-widest mb-1">Returning Rate</div>
                        <div class="text-2xl font-black text-brand-900"><?= htmlspecialchars((string)($returningPct), ENT_QUOTES, 'UTF-8') ?>% <span class="text-sm font-medium text-brand-500">(<?= htmlspecialchars((string)(number_format($returningGuests)), ENT_QUOTES, 'UTF-8') ?>)</span></div>
                    </div>
                </div>
            </div>

            <!-- Filters & Search -->
            <form method="GET" class="card-minimal p-4 flex flex-col md:flex-row gap-4 items-end">
                <div class="flex-1">
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Search Guest</label>
                    <div class="relative">
                        <i class="ph ph-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-brand-400 text-lg"></i>
                        <input type="text" name="search" id="live-search" autocomplete="off" value="<?= htmlspecialchars((string)($search)) ?>" placeholder="Name, phone, or email..." class="w-full bg-brand-50 border border-brand-200 !pl-12 pr-3 py-2.5 rounded-xl text-sm font-medium outline-none focus:bg-white focus:shadow-minimal transition-all">
                        <div id="search-dropdown" class="absolute left-0 right-0 top-full mt-1 bg-white border border-brand-200 rounded-xl shadow-lg z-50 hidden max-h-60 overflow-y-auto divide-y divide-brand-100"></div>
                    </div>
                </div>
                <div class="w-full md:w-48">
                    <label class="block text-[10px] font-bold text-brand-500 uppercase tracking-wider mb-1">Sort By</label>
                    <select name="sort" class="w-full bg-brand-50 border border-brand-200 p-2.5 rounded-xl text-sm font-medium outline-none focus:bg-white focus:shadow-minimal transition-all">
                        <option value="last_visit_desc" <?= htmlspecialchars((string)($sort === 'last_visit_desc' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Recent Visit</option>
                        <option value="spent_desc" <?= htmlspecialchars((string)($sort === 'spent_desc' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Highest Spent</option>
                        <option value="stays_desc" <?= htmlspecialchars((string)($sort === 'stays_desc' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Most Stays</option>
                        <option value="name_asc" <?= htmlspecialchars((string)($sort === 'name_asc' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Name (A-Z)</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-brand-900 text-white font-bold px-5 py-2.5 rounded-xl hover:-translate-y-0.5 hover:shadow-lg transition-all text-sm h-[42px]">
                        Filter
                    </button>
                    <a href="guests.php" class="bg-brand-100 text-brand-900 font-bold px-4 py-2.5 rounded-xl hover:bg-brand-200 transition-all text-sm h-[42px] flex items-center justify-center">
                        Clear
                    </a>
                </div>
            </form>

            <!-- Table -->
            <div class="card-minimal overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table-brutal">
                        <thead>
                            <tr class="bg-brand-50 border-b border-brand-100 text-[10px] text-brand-500 uppercase tracking-widest">
                                <th class="px-6 py-4 font-bold text-left">Guest Profile</th>
                                <th class="px-6 py-4 font-bold text-left">Contact Info</th>
                                <th class="px-6 py-4 font-bold text-center">Total Stays</th>
                                <th class="px-6 py-4 font-bold text-right">Lifetime Spent</th>
                                <th class="px-6 py-4 font-bold text-right">Last Stay</th>
                                <th class="px-6 py-4 font-bold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach($guests as $g): ?>
                            <tr class="hover:bg-brand-50 transition-colors group">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-brand-900 text-white flex items-center justify-center font-bold text-lg shadow-minimal">
                                            <?= htmlspecialchars((string)(strtoupper(substr($g['name'] ?: '?', 0, 1))), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div>
                                            <p class="font-bold text-brand-900 text-sm flex items-center gap-2">
                                                <?= htmlspecialchars((string)($g['name'])) ?>
                                                <?php if(!empty($g['tags'])): ?>
                                                    <?php foreach(explode(',', $g['tags']) as $tag): $t = trim($tag); if(!$t) continue; ?>
                                                        <span class="px-2 py-0.5 text-[9px] font-black uppercase rounded-full tracking-wider <?= htmlspecialchars((string)($t === 'VIP' ? 'bg-amber-100 text-amber-800 border border-amber-300' : ($t === 'Corporate' ? 'bg-blue-100 text-blue-800 border border-blue-300' : 'bg-purple-100 text-purple-800 border border-purple-300')), ENT_QUOTES, 'UTF-8') ?>">
                                                            <?= htmlspecialchars((string)($t)) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </p>
                                            <p class="text-[11px] text-brand-500 font-medium"><?= htmlspecialchars((string)($g['city'] . ($g['state'] ? ', '.$g['state'] : ''))) ?: 'No Location' ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2 text-sm font-medium text-brand-900">
                                        <i class="ph ph-phone text-brand-400"></i> <?= htmlspecialchars((string)($g['phone'] ?: 'N/A')) ?>
                                    </div>
                                    <?php if($g['email']): ?>
                                    <div class="flex items-center gap-2 text-[11px] text-brand-500 font-medium mt-0.5">
                                        <i class="ph ph-envelope text-brand-400"></i> <?= htmlspecialchars((string)($g['email'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-full text-xs font-bold bg-brand-100 text-brand-900">
                                        <?= htmlspecialchars((string)($g['total_bookings']), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="text-sm font-black text-brand-900">₹<?= htmlspecialchars((string)(number_format((float)$g['total_spent'], 2)), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="text-xs font-bold text-brand-900"><?= htmlspecialchars((string)($g['last_visit'] ? date('M j, Y', strtotime($g['last_visit'])) : 'Never'), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="guest_profile.php?id=<?= htmlspecialchars((string)($g['id']), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-1.5 text-brand-900 bg-white border border-brand-200 hover:border-brand-900 font-bold text-xs px-3 py-1.5 rounded-lg transition-all shadow-sm">
                                        <i class="ph ph-user-circle"></i> View Profile
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if(empty($guests)): ?>
                    <div class="p-12 text-center text-brand-400 flex flex-col items-center">
                        <div class="w-16 h-16 bg-brand-50 rounded-full flex items-center justify-center mb-4">
                            <i class="ph ph-users text-3xl text-brand-300"></i>
                        </div>
                        <p class="text-lg font-bold text-brand-900/70">No guests match your criteria.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if($totalPages > 1): ?>
            <div class="flex items-center justify-between mt-6 bg-white p-2 rounded-2xl border border-brand-200">
                <?php 
                $q = $_GET;
                $q['page'] = $page - 1;
                $prevUrl = '?' . http_build_query($q);
                $q['page'] = $page + 1;
                $nextUrl = '?' . http_build_query($q);
                ?>
                <a href="<?= htmlspecialchars((string)($page > 1 ? $prevUrl : '#'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 text-sm font-bold rounded-xl <?= htmlspecialchars((string)($page > 1 ? 'bg-brand-50 text-brand-900 hover:bg-brand-100' : 'text-brand-300 cursor-not-allowed'), ENT_QUOTES, 'UTF-8') ?> transition-colors flex items-center gap-2">
                    <i class="ph ph-caret-left"></i> Previous
                </a>
                <span class="px-4 py-2 text-xs font-bold text-brand-500 uppercase tracking-widest">
                    Page <?= htmlspecialchars((string)($page), ENT_QUOTES, 'UTF-8') ?> of <?= htmlspecialchars((string)($totalPages), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <a href="<?= htmlspecialchars((string)($page < $totalPages ? $nextUrl : '#'), ENT_QUOTES, 'UTF-8') ?>" class="px-4 py-2 text-sm font-bold rounded-xl <?= htmlspecialchars((string)($page < $totalPages ? 'bg-brand-50 text-brand-900 hover:bg-brand-100' : 'text-brand-300 cursor-not-allowed'), ENT_QUOTES, 'UTF-8') ?> transition-colors flex items-center gap-2">
                    Next <i class="ph ph-caret-right"></i>
                </a>
            </div>
            <?php endif; ?>
            
        </main>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('live-search');
            const dropdown = document.getElementById('search-dropdown');
            let timeout = null;

            searchInput.addEventListener('input', function() {
                clearTimeout(timeout);
                const val = this.value.trim();
                
                if (val.length < 2) {
                    dropdown.classList.add('hidden');
                    return;
                }

                timeout = setTimeout(async () => {
                    try {
                        const res = await fetch(`/api/system/search_guests?q=${encodeURIComponent(val)}`);
                        const data = await res.json();
                        
                        if (data.success && data.guests && data.guests.length > 0) {
                            dropdown.innerHTML = data.guests.map(g => `
                                <a href="guest_profile.php?id=${g.id}" class="block px-4 py-3 hover:bg-brand-50 transition-colors text-left w-full group">
                                    <div class="font-bold text-brand-900 text-sm group-hover:text-brand-accent transition-colors">${g.guest_name}</div>
                                    <div class="text-[11px] text-brand-500 font-medium flex items-center gap-1 mt-0.5"><i class="ph-fill ph-phone text-brand-300"></i> ${g.guest_phone}</div>
                                </a>
                            `).join('');
                            dropdown.classList.remove('hidden');
                        } else {
                            dropdown.innerHTML = '<div class="px-4 py-4 text-xs text-brand-500 text-center font-bold uppercase tracking-wider">No matches found</div>';
                            dropdown.classList.remove('hidden');
                        }
                    } catch (e) {
                        console.error('Search error', e);
                    }
                }, 300);
            });

            // Hide dropdown on click outside
            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>
