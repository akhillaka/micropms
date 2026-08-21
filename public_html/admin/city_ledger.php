<?php
declare(strict_types=1);
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLoginOrRedirect();
if (!AuthHelper::can('view_finance')) {
    header('Location: /admin');
    exit;
}
CsrfToken::checkTimeout();

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/services/CityLedgerService.php';
$db = Database::getInstance()->getConnection();
$propertyId = AuthHelper::getPropertyId();
$canManage = AuthHelper::can('manage_finance');

$companies = CityLedgerService::getCompanies($db, $propertyId);
$totalAr = 0.0;
foreach ($companies as $c) {
    $totalAr += (float)($c['balance'] ?? 0);
}
$selectedId = (int)($_GET['company_id'] ?? 0);
$selected = null;
$ledger = [];
if ($selectedId > 0) {
    $selected = CityLedgerService::getCompany($db, $propertyId, $selectedId);
    if ($selected) {
        $ledger = CityLedgerService::getLedgerLines($db, $propertyId, $selectedId);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= CsrfToken::meta() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>City Ledger | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <?php include __DIR__ . '/components/mobile_nav.php'; ?>
</head>
<body class="flex flex-col min-h-screen bg-slate-50">
<div class="w-full min-h-screen relative flex flex-col max-w-7xl mx-auto">
    <header class="bg-white px-5 py-4 flex items-center justify-between z-10 border-b border-slate-100 sticky top-0 mb-6">
        <div class="flex items-center gap-3">
            <a href="/admin" class="p-2 -ml-2 rounded-full hover:bg-slate-100 transition-colors">
                <i class="ph ph-caret-left text-2xl text-slate-800"></i>
            </a>
            <div>
                <h1 class="text-xl font-extrabold text-slate-900 tracking-tight leading-none">City Ledger</h1>
                <p class="text-xs text-slate-500 font-medium mt-0.5">Corporate accounts &amp; AR</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($canManage): ?>
            <button type="button" onclick="document.getElementById('create-company-modal').classList.remove('hidden')" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">
                New company
            </button>
            <?php endif; ?>
            <?php include __DIR__ . '/components/desktop_nav.php'; ?>
        </div>
    </header>

    <main class="px-5 pb-24 flex-1 space-y-6">
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-white rounded-2xl border border-slate-100 p-4">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Companies</p>
                <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= (int)count($companies) ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-100 p-4">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Outstanding AR</p>
                <p class="text-2xl font-extrabold text-rose-600 mt-1">₹<?= htmlspecialchars(number_format($totalAr, 2)) ?></p>
            </div>
        </div>

        <div class="grid lg:grid-cols-5 gap-6">
            <section class="lg:col-span-2 bg-white rounded-2xl border border-slate-100 overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-50">
                    <h2 class="text-sm font-bold text-slate-800">Companies</h2>
                </div>
                <ul class="divide-y divide-slate-50 max-h-[60vh] overflow-y-auto">
                    <?php if ($companies === []): ?>
                        <li class="px-4 py-8 text-center text-sm text-slate-400">No companies yet.</li>
                    <?php endif; ?>
                    <?php foreach ($companies as $co): ?>
                        <li>
                            <a href="/admin/city_ledger?company_id=<?= (int)$co['id'] ?>"
                               class="block px-4 py-3 hover:bg-slate-50 <?= $selectedId === (int)$co['id'] ? 'bg-indigo-50' : '' ?>">
                                <div class="flex justify-between gap-2">
                                    <span class="text-sm font-bold text-slate-800"><?= htmlspecialchars((string)$co['name']) ?></span>
                                    <span class="text-sm font-bold <?= (float)$co['balance'] > 0 ? 'text-rose-600' : 'text-emerald-600' ?>">
                                        ₹<?= htmlspecialchars(number_format((float)$co['balance'], 2)) ?>
                                    </span>
                                </div>
                                <?php if (!empty($co['contact_details'])): ?>
                                    <p class="text-xs text-slate-500 mt-0.5 truncate"><?= htmlspecialchars((string)$co['contact_details']) ?></p>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="lg:col-span-3 space-y-4">
                <?php if (!$selected): ?>
                    <div class="bg-white rounded-2xl border border-slate-100 p-8 text-center text-sm text-slate-400">
                        Select a company to view ledger lines and settle balances.
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-2xl border border-slate-100 p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-extrabold text-slate-900"><?= htmlspecialchars((string)$selected['name']) ?></h2>
                                <p class="text-xs text-slate-500 mt-1">
                                    Credit limit: ₹<?= htmlspecialchars(number_format((float)$selected['credit_limit'], 2)) ?>
                                    · Balance: <span class="font-bold text-rose-600">₹<?= htmlspecialchars(number_format((float)$selected['balance'], 2)) ?></span>
                                </p>
                            </div>
                            <?php if ($canManage): ?>
                            <div class="flex gap-2">
                            <button type="button" onclick="openEditCompany()" class="text-xs font-bold text-indigo-600 hover:underline">Edit</button>
                            <button type="button" onclick="archiveCompany()" class="text-xs font-bold text-rose-600 hover:underline">Archive</button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($canManage): ?>
                        <div class="mt-4 grid sm:grid-cols-2 gap-3">
                            <form id="settle-form" class="space-y-2 p-3 rounded-xl bg-slate-50 border border-slate-100" onsubmit="return settleCompany(event)">
                                <p class="text-[10px] font-bold text-slate-400 uppercase">Record settlement</p>
                                <input type="number" step="0.01" min="0.01" id="settle-amount" required placeholder="Amount"
                                       class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                <input type="text" id="settle-ref" placeholder="Reference (optional)"
                                       class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                <button type="submit" class="w-full py-2 rounded-lg bg-emerald-600 text-white text-sm font-bold">Settle</button>
                            </form>
                            <form id="transfer-form" class="space-y-2 p-3 rounded-xl bg-slate-50 border border-slate-100" onsubmit="return transferBooking(event)">
                                <p class="text-[10px] font-bold text-slate-400 uppercase">Transfer booking balance</p>
                                <input type="number" min="1" id="transfer-booking-id" required placeholder="Booking ID"
                                       class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                <button type="submit" class="w-full py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold">Transfer to city ledger</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-100 overflow-hidden">
                        <div class="px-4 py-3 border-b border-slate-50">
                            <h3 class="text-sm font-bold text-slate-800">Ledger</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-[10px] uppercase text-slate-400 font-bold">
                                    <tr>
                                        <th class="px-4 py-2">Date</th>
                                        <th class="px-4 py-2">Type</th>
                                        <th class="px-4 py-2">Booking</th>
                                        <th class="px-4 py-2">Status</th>
                                        <th class="px-4 py-2 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                <?php if ($ledger === []): ?>
                                    <tr><td colspan="5" class="px-4 py-6 text-center text-slate-400">No ledger entries.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($ledger as $line): ?>
                                    <tr>
                                        <td class="px-4 py-2 text-xs text-slate-500"><?= htmlspecialchars((string)date('d M Y H:i', strtotime((string)$line['recorded_at']))) ?></td>
                                        <td class="px-4 py-2 font-bold text-slate-700"><?= htmlspecialchars((string)$line['type']) ?></td>
                                        <td class="px-4 py-2 text-xs">
                                            <?php if (!empty($line['booking_id'])): ?>
                                                <a class="text-indigo-600 font-bold" href="/admin/folio?id=<?= (int)$line['booking_id'] ?>">
                                                    <?= htmlspecialchars((string)($line['booking_display_id'] ?: ('#' . $line['booking_id']))) ?>
                                                </a>
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                        <td class="px-4 py-2 text-xs font-bold <?= ($line['status'] ?? '') === 'pending' ? 'text-amber-600' : 'text-emerald-600' ?>">
                                            <?= htmlspecialchars((string)($line['status'] ?? '')) ?>
                                        </td>
                                        <td class="px-4 py-2 text-right font-bold">₹<?= htmlspecialchars(number_format((float)$line['amount'], 2)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<?php if ($canManage): ?>
<div id="create-company-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-5 shadow-xl">
        <h3 class="text-lg font-extrabold text-slate-900 mb-3">New company</h3>
        <form id="create-company-form" class="space-y-3" onsubmit="return createCompany(event)">
            <input name="name" required placeholder="Company name" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
            <input name="contact_details" placeholder="Contact details" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
            <input name="credit_limit" type="number" step="0.01" min="0" value="0" placeholder="Credit limit" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
            <div class="flex gap-2 justify-end pt-2">
                <button type="button" onclick="document.getElementById('create-company-modal').classList.add('hidden')" class="px-4 py-2 text-sm font-bold text-slate-600">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold">Save</button>
            </div>
        </form>
    </div>
</div>
<div id="edit-company-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-5 shadow-xl">
        <h3 class="text-lg font-extrabold text-slate-900 mb-3">Edit company</h3>
        <form id="edit-company-form" class="space-y-3" onsubmit="return updateCompany(event)">
            <input type="hidden" name="company_id" value="<?= (int)($selected['id'] ?? 0) ?>">
            <input name="name" required value="<?= htmlspecialchars((string)($selected['name'] ?? '')) ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
            <input name="contact_details" value="<?= htmlspecialchars((string)($selected['contact_details'] ?? '')) ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
            <input name="credit_limit" type="number" step="0.01" min="0" value="<?= htmlspecialchars((string)($selected['credit_limit'] ?? '0')) ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
            <div class="flex gap-2 justify-end pt-2">
                <button type="button" onclick="document.getElementById('edit-company-modal').classList.add('hidden')" class="px-4 py-2 text-sm font-bold text-slate-600">Cancel</button>
                <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold">Update</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const COMPANY_ID = <?= (int)$selectedId ?>;
async function cityApi(payload) {
    const res = await fetch('/api/admin/city_ledger', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Request failed');
    return json;
}
function openEditCompany() {
    document.getElementById('edit-company-modal')?.classList.remove('hidden');
}
async function createCompany(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        const data = await cityApi({
            action: 'create_company',
            name: fd.get('name'),
            contact_details: fd.get('contact_details'),
            credit_limit: fd.get('credit_limit')
        });
        location.href = '/admin/city_ledger?company_id=' + (data.data?.company_id || data.company_id);
    } catch (err) { alert(err.message); }
    return false;
}
async function updateCompany(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
        await cityApi({
            action: 'update_company',
            company_id: Number(fd.get('company_id')),
            name: fd.get('name'),
            contact_details: fd.get('contact_details'),
            credit_limit: fd.get('credit_limit')
        });
        location.reload();
    } catch (err) { alert(err.message); }
    return false;
}
async function archiveCompany() {
    if (!COMPANY_ID || !confirm('Archive this company? Outstanding balance must be zero.')) return;
    try {
        await cityApi({ action: 'delete_company', company_id: COMPANY_ID });
        location.href = '/admin/city_ledger';
    } catch (err) { alert(err.message); }
}
async function settleCompany(e) {
    e.preventDefault();
    try {
        await cityApi({
            action: 'record_payment',
            company_id: COMPANY_ID,
            amount: Number(document.getElementById('settle-amount').value),
            reference: document.getElementById('settle-ref').value || 'MANUAL'
        });
        location.reload();
    } catch (err) { alert(err.message); }
    return false;
}
async function transferBooking(e) {
    e.preventDefault();
    try {
        await cityApi({
            action: 'transfer_booking',
            company_id: COMPANY_ID,
            booking_id: Number(document.getElementById('transfer-booking-id').value)
        });
        location.reload();
    } catch (err) { alert(err.message); }
    return false;
}
</script>
</body>
</html>
