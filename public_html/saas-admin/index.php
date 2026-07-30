<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/saas_plans.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
$db = Database::getInstance()->getConnection();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CsrfToken::requireValid();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_property') {
        $pid = (int)($_POST['property_id'] ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        $db->prepare("UPDATE properties SET is_active = ? WHERE id = ?")->execute([$active, $pid]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_plan') {
        $pid   = (int)($_POST['property_id'] ?? 0);
        $plan  = $_POST['plan'] ?? 'starter';
        $until = $_POST['valid_until'] ?? date('Y-m-d', strtotime('+1 year'));
        $db->prepare("UPDATE properties SET plan = ?, valid_until = ?, subscription_status = 'active' WHERE id = ?")
           ->execute([$plan, $until, $pid]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'create_property') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $plan  = $_POST['plan'] ?? 'starter';
        if (!$name) { echo json_encode(['success' => false, 'message' => 'Name required']); exit; }
        $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($name)));
        $code = substr($code, 0, 10) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $stmt = $db->prepare("INSERT INTO properties (property_code, name, email, phone, plan, subscription_status, valid_until, is_active)
            VALUES (?, ?, ?, ?, ?, 'trialing', DATE_ADD(NOW(), INTERVAL 14 DAY), 1)");
        $stmt->execute([$code, $name, $email, $phone, $plan]);
        $newId = (int)$db->lastInsertId();
        echo json_encode(['success' => true, 'property_id' => $newId]);
        exit;
    }

    if ($action === 'extend_trial') {
        $pid  = (int)($_POST['property_id'] ?? 0);
        $days = max(1, min(365, (int)($_POST['days'] ?? 14)));
        $db->prepare("UPDATE properties SET valid_until = DATE_ADD(IFNULL(valid_until, NOW()), INTERVAL ? DAY), subscription_status='trialing' WHERE id=?")
           ->execute([$days, $pid]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save_notes') {
        $pid   = (int)($_POST['property_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $db->prepare("UPDATE properties SET notes = ? WHERE id = ?")->execute([$notes, $pid]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// Load data
$properties = $db->query("
    SELECT p.*, s.status as actual_sub_status, s.gateway as sub_gateway
    FROM properties p 
    LEFT JOIN saas_subscriptions s ON p.id = s.property_id 
    ORDER BY p.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
$plans = SaaSPlans::get($db);

$stats = [
    'total'    => count($properties),
    'active'   => count(array_filter($properties, fn($p) => $p['is_active'] == 1)),
    'trialing' => count(array_filter($properties, fn($p) => ($p['actual_sub_status'] ?? $p['subscription_status'] ?? '') === 'trialing')),
    'suspended'=> count(array_filter($properties, fn($p) => $p['is_active'] == 0)),
];

$totalStaff = (int)$db->query("SELECT COUNT(*) FROM staff_users WHERE access_level != 'superadmin'")->fetchColumn();
$totalBookings = (int)$db->query("SELECT COUNT(*) FROM bookings WHERE booking_status IN ('booked','checked_in')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MicroPMS — Control Plane</title>
    <?= CsrfToken::meta() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #0f172a; --surface: #1e293b; --surface2: #334155;
            --indigo: #6366f1; --indigo-light: #818cf8;
            --emerald: #10b981; --rose: #f43f5e; --amber: #f59e0b;
            --text: #f1f5f9; --muted: #94a3b8; --border: #334155;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
        header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 2rem;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .logo { display: flex; align-items: center; gap: 0.75rem; }
        .logo-icon { font-size: 1.5rem; }
        .logo-text { font-size: 1rem; font-weight: 800; }
        .logo-badge {
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .header-right { display: flex; align-items: center; gap: 1rem; }
        .user-chip {
            display: flex; align-items: center; gap: 0.5rem;
            background: var(--surface2);
            border-radius: 9999px;
            padding: 0.375rem 0.875rem;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        .logout-btn {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--muted);
            padding: 0.375rem 0.875rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .logout-btn:hover { border-color: var(--rose); color: var(--rose); }
        main { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .page-title { font-size: 1.5rem; font-weight: 800; margin-bottom: 0.25rem; }
        .page-subtitle { color: var(--muted); font-size: 0.875rem; margin-bottom: 2rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 1.25rem;
        }
        .stat-value { font-size: 2rem; font-weight: 800; }
        .stat-label { font-size: 0.75rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.25rem; }
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.625rem;
            font-size: 0.8125rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary { background: var(--indigo); color: white; }
        .btn-primary:hover { background: var(--indigo-light); }
        .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
        .btn-ghost { background: transparent; border: 1px solid var(--border); color: var(--muted); }
        .btn-ghost:hover { border-color: var(--indigo); color: var(--indigo-light); }
        .btn-danger { background: rgba(244,63,94,0.15); color: var(--rose); border: 1px solid rgba(244,63,94,0.3); }
        .btn-danger:hover { background: rgba(244,63,94,0.25); }
        .btn-success { background: rgba(16,185,129,0.15); color: var(--emerald); border: 1px solid rgba(16,185,129,0.3); }
        .btn-success:hover { background: rgba(16,185,129,0.25); }
        table { width: 100%; border-collapse: collapse; }
        .table-wrap {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            overflow: hidden;
        }
        th {
            text-align: left;
            padding: 0.875rem 1.25rem;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1px solid var(--border);
            background: rgba(255,255,255,0.02);
        }
        td { padding: 1rem 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        .badge {
            display: inline-flex; align-items: center; gap: 0.25rem;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge-active  { background: rgba(16,185,129,0.15); color: #34d399; }
        .badge-trial   { background: rgba(245,158,11,0.15); color: #fbbf24; }
        .badge-expired { background: rgba(244,63,94,0.15);  color: #fb7185; }
        .badge-suspended { background: rgba(148,163,184,0.15); color: var(--muted); }
        .badge-plan { background: rgba(99,102,241,0.15); color: var(--indigo-light); }
        .prop-name { font-weight: 700; font-size: 0.875rem; }
        .prop-code { font-size: 0.7rem; color: var(--muted); font-family: monospace; margin-top: 0.125rem; }
        .actions-col { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        /* Modal */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px);
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-overlay.show { display: flex; }
        .modal {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1.25rem;
            padding: 2rem;
            width: 100%;
            max-width: 520px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal h3 { font-size: 1rem; font-weight: 800; margin-bottom: 1.25rem; }
        label { display: block; font-size: 0.7rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.375rem; }
        input, select, textarea {
            width: 100%;
            padding: 0.6875rem 0.875rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 0.625rem;
            color: var(--text);
            font-family: inherit;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
            margin-bottom: 1rem;
        }
        input:focus, select:focus, textarea:focus { border-color: var(--indigo); }
        textarea { resize: vertical; min-height: 80px; }
        .modal-footer { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 0.5rem; }
        .toast {
            position: fixed; bottom: 1.5rem; right: 1.5rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 0.875rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 600;
            box-shadow: 0 8px 30px rgba(0,0,0,0.4);
            z-index: 999;
            display: none;
        }
        .toast.show { display: block; animation: slideUp 0.3s ease; }
        .toast.success { border-color: var(--emerald); color: #34d399; }
        .toast.error   { border-color: var(--rose);    color: #fb7185; }
        @keyframes slideUp { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>
<header>
    <div class="logo">
        <span class="logo-icon">🛡️</span>
        <span class="logo-text">MicroPMS</span>
        <span class="logo-badge">Control Plane</span>
    </div>
    <div class="header-right">
        <div class="user-chip">
            <i class="ph ph-user-circle-gear"></i>
            <?= htmlspecialchars($_SESSION['saas_admin_username'] ?? 'Superadmin') ?>
        </div>
        <a href="logout.php" class="logout-btn"><i class="ph ph-sign-out"></i> Logout</a>
    </div>
</header>

<main>
    <p class="page-title">Properties</p>
    <p class="page-subtitle">Manage all hotels and properties on this platform</p>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-label">Total Properties</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:var(--emerald)"><?= $stats['active'] ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:var(--amber)"><?= $stats['trialing'] ?></div>
            <div class="stat-label">On Trial</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color:var(--rose)"><?= $stats['suspended'] ?></div>
            <div class="stat-label">Suspended</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $totalStaff ?></div>
            <div class="stat-label">Total Staff</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $totalBookings ?></div>
            <div class="stat-label">Active Bookings</div>
        </div>
    </div>

    <!-- Properties Table -->
    <div class="actions-bar">
        <h2 style="font-size:0.9rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;">
            All Properties
        </h2>
        <button class="btn btn-primary" onclick="openCreateModal()">
            <i class="ph ph-plus"></i> Add Property
        </button>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Property</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Valid Until</th>
                    <th>Contact</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($properties as $prop): ?>
                <?php
                    $isActive = $prop['is_active'] == 1;
                    $subStatus = $prop['actual_sub_status'] ?? $prop['subscription_status'] ?? 'trialing';
                    $validUntil = $prop['valid_until'] ? new DateTime($prop['valid_until']) : null;
                    $isExpired = $validUntil && $validUntil < new DateTime();

                    if (!$isActive) $statusBadge = '<span class="badge badge-suspended">Suspended</span>';
                    elseif ($subStatus === 'trialing') $statusBadge = '<span class="badge badge-trial">Trial</span>';
                    elseif ($subStatus === 'active') $statusBadge = '<span class="badge badge-active">Active</span>';
                    elseif ($isExpired) $statusBadge = '<span class="badge badge-expired">Expired</span>';
                    else $statusBadge = '<span class="badge badge-suspended">' . htmlspecialchars($subStatus) . '</span>';
                ?>
                <tr>
                    <td>
                        <div class="prop-name"><?= htmlspecialchars($prop['name']) ?></div>
                        <div class="prop-code"><?= htmlspecialchars($prop['property_code']) ?> · ID: <?= $prop['id'] ?></div>
                    </td>
                    <td>
                        <span class="badge badge-plan"><?= ucfirst(htmlspecialchars($prop['plan'])) ?></span>
                    </td>
                    <td><?= $statusBadge ?></td>
                    <td style="font-size:0.8125rem;color:var(--muted);">
                        <?= $validUntil ? $validUntil->format('d M Y') : '—' ?>
                    </td>
                    <td style="font-size:0.8125rem;">
                        <div><?= htmlspecialchars($prop['email'] ?? '—') ?></div>
                        <div style="color:var(--muted)"><?= htmlspecialchars($prop['phone'] ?? '') ?></div>
                    </td>
                    <td>
                        <div class="actions-col">
                            <button class="btn btn-sm btn-ghost" onclick="openPlanModal(<?= htmlspecialchars(json_encode($prop)) ?>)">
                                <i class="ph ph-pencil"></i> Plan
                            </button>
                            <button class="btn btn-sm btn-ghost" onclick="extendTrial(<?= $prop['id'] ?>)">
                                <i class="ph ph-clock"></i> Extend
                            </button>
                            <?php if ($isActive): ?>
                                <button class="btn btn-sm btn-danger" onclick="toggleProperty(<?= $prop['id'] ?>, 0, '<?= htmlspecialchars($prop['name'], ENT_QUOTES) ?>')">
                                    <i class="ph ph-prohibit"></i> Suspend
                                </button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-success" onclick="toggleProperty(<?= $prop['id'] ?>, 1, '<?= htmlspecialchars($prop['name'], ENT_QUOTES) ?>')">
                                    <i class="ph ph-check-circle"></i> Activate
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($properties)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:2rem;">No properties yet. Add one to get started.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- Create Property Modal -->
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <h3><i class="ph ph-buildings"></i> Add New Property</h3>
        <label>Property Name *</label>
        <input type="text" id="new-name" placeholder="e.g. The Grand Hotel">
        <label>Email</label>
        <input type="email" id="new-email" placeholder="hotel@example.com">
        <label>Phone</label>
        <input type="tel" id="new-phone" placeholder="+91 98765 43210">
        <label>Plan</label>
        <select id="new-plan">
            <?php foreach ($plans as $key => $plan): ?>
                <option value="<?= $key ?>"><?= htmlspecialchars($plan['name']) ?> — ₹<?= number_format($plan['price']) ?>/mo</option>
            <?php endforeach; ?>
        </select>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('createModal')">Cancel</button>
            <button class="btn btn-primary" onclick="createProperty()"><i class="ph ph-plus"></i> Create</button>
        </div>
    </div>
</div>

<!-- Edit Plan Modal -->
<div class="modal-overlay" id="planModal">
    <div class="modal">
        <h3 id="plan-modal-title"><i class="ph ph-pencil"></i> Edit Plan</h3>
        <input type="hidden" id="edit-prop-id">
        <label>Plan</label>
        <select id="edit-plan">
            <?php foreach ($plans as $key => $plan): ?>
                <option value="<?= $key ?>"><?= htmlspecialchars($plan['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Valid Until</label>
        <input type="date" id="edit-valid-until">
        <label>Internal Notes</label>
        <textarea id="edit-notes" placeholder="Notes visible only to superadmin..."></textarea>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
            <button class="btn btn-primary" onclick="savePlan()"><i class="ph ph-floppy-disk"></i> Save</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = (type === 'success' ? '✅ ' : '❌ ') + msg;
    t.className = `toast show ${type}`;
    setTimeout(() => t.className = 'toast', 3000);
}

function openCreateModal() { document.getElementById('createModal').classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }

function openPlanModal(prop) {
    document.getElementById('edit-prop-id').value = prop.id;
    document.getElementById('edit-plan').value = prop.plan;
    document.getElementById('edit-valid-until').value = (prop.valid_until || '').substring(0, 10);
    document.getElementById('edit-notes').value = prop.notes || '';
    document.getElementById('plan-modal-title').innerHTML = `<i class="ph ph-pencil"></i> Edit: ${prop.name}`;
    document.getElementById('planModal').classList.add('show');
}

async function post(data) {
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const body = new URLSearchParams(data);
        const res = await fetch(window.location.href, { 
            method: 'POST', 
            headers: { 'X-CSRF-Token': token },
            body 
        });
        return await res.json();
    } catch (e) { return { success: false, message: 'Network error' }; }
}

async function toggleProperty(pid, active, name) {
    const action = active === 0 ? 'suspend' : 'activate';
    if (!confirm(`${action.charAt(0).toUpperCase() + action.slice(1)} property "${name}"?`)) return;
    const data = await post({ action: 'toggle_property', property_id: pid, is_active: active });
    if (data.success) { showToast(`Property ${action}d`); setTimeout(() => location.reload(), 800); }
    else showToast('Error: ' + data.message, 'error');
}

async function createProperty() {
    const data = await post({
        action: 'create_property',
        name:  document.getElementById('new-name').value,
        email: document.getElementById('new-email').value,
        phone: document.getElementById('new-phone').value,
        plan:  document.getElementById('new-plan').value
    });
    if (data.success) { showToast('Property created!'); setTimeout(() => location.reload(), 800); }
    else showToast('Error: ' + data.message, 'error');
}

async function savePlan() {
    const pid = document.getElementById('edit-prop-id').value;
    await post({ action: 'save_notes', property_id: pid, notes: document.getElementById('edit-notes').value });
    const data = await post({
        action: 'update_plan',
        property_id: pid,
        plan: document.getElementById('edit-plan').value,
        valid_until: document.getElementById('edit-valid-until').value
    });
    if (data.success) { showToast('Plan updated!'); setTimeout(() => location.reload(), 800); }
    else showToast('Error: ' + data.message, 'error');
}

async function extendTrial(pid) {
    const days = prompt('Extend trial by how many days?', '14');
    if (!days || isNaN(parseInt(days))) return;
    const data = await post({ action: 'extend_trial', property_id: pid, days: parseInt(days) });
    if (data.success) { showToast('Trial extended!'); setTimeout(() => location.reload(), 800); }
    else showToast('Error: ' + data.message, 'error');
}

// Close modal on backdrop click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('show'); });
});
</script>
</body>
</html>
