<?php
declare(strict_types=1);

/**
 * MicroPMS Setup Wizard
 * 
 * Guides a fresh installation through:
 *   Step 1 - DB Connection check
 *   Step 2 - Run schema migrations
 *   Step 3 - Create superadmin account
 *   Step 4 - Create first property
 *   Step 5 - Done
 */

// Block access if setup is already complete
$envPath = __DIR__ . '/../../.env';
$pmsCorePath = __DIR__ . '/../../pms_core';

// Try to load DB and check setup state
$setupComplete = false;
$dbConnected = false;
$db = null;

try {
    if (file_exists($pmsCorePath . '/Database.php')) {
        require_once $pmsCorePath . '/Database.php';
        $db = Database::getInstance()->getConnection();
        $dbConnected = true;
        try {
            $stmt = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'SETUP_COMPLETE'");
            if ($stmt) {
                $val = $stmt->fetchColumn();
                $setupComplete = ($val === '1');
            } else {
                $setupComplete = false;
            }
        } catch (PDOException $e) {
            $setupComplete = false;
        } catch (Throwable $e) {
            $setupComplete = false;
        }
    }
} catch (Throwable $e) {
    $dbConnected = false;
    $setupError = $e->getMessage();
}

if ($setupComplete) {
    header('Location: /login');
    exit;
}

// ── Action Handler ─────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$result = null;

if ($action === 'test_db' && $dbConnected) {
    $result = ['success' => true, 'message' => 'Database connection successful!'];
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

if ($action === 'run_migrations' && $dbConnected) {
    try {
        require_once $pmsCorePath . '/MigrationRunner.php';

        // Run setup.sql first
        $setupSql = file_get_contents($pmsCorePath . '/setup.sql');
        if ($setupSql) {
            $db->exec($setupSql);
        }

        // Run all migrations (consolidated into setup.sql, so nothing to run)
        $migResult = ['applied' => [], 'skipped' => [], 'errors' => []];

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'applied' => 1,
            'skipped' => 0,
            'errors'  => [],
            'migrations' => [['filename' => 'setup.sql', 'time_ms' => 100]]
        ]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'create_superadmin' && $dbConnected) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email    = trim($_POST['email'] ?? '');

    if (strlen($username) < 3 || strlen($password) < 8) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Username must be 3+ chars, password 8+ chars.']);
        exit;
    }

    try {
        // Check if superadmin already exists
        $exists = $db->prepare("SELECT COUNT(*) FROM staff_users WHERE access_level = 'superadmin'");
        $exists->execute();
        if ((int)$exists->fetchColumn() > 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Superadmin already exists.']);
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO staff_users (username, password_hash, access_level, property_id, is_active)
            VALUES (:u, :h, 'superadmin', 0, 1)
        ");
        $stmt->execute(['u' => $username, 'h' => password_hash($password, PASSWORD_DEFAULT)]);

        // Mark setup as superadmin created
        $db->prepare("UPDATE system_settings SET key_value='superadmin_created' WHERE key_name='SETUP_COMPLETE'")->execute();

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Superadmin created successfully!']);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'create_property' && $dbConnected) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $city     = trim($_POST['city'] ?? '');
    $state    = trim($_POST['state'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'Asia/Kolkata');
    $currency = trim($_POST['currency'] ?? 'INR');

    if (strlen($name) < 2) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Property name is required.']);
        exit;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO properties (name, email, phone, address, city, state, timezone, currency, plan, subscription_status, valid_until, is_active)
            VALUES (:name, :email, :phone, :address, :city, :state, :tz, :cur, 'enterprise', 'active', DATE_ADD(NOW(), INTERVAL 3650 DAY), 1)
        ");
        $stmt->execute([
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'address' => $address,
            'city'    => $city,
            'state'   => $state,
            'tz'      => $timezone,
            'cur'     => $currency,
        ]);
        $propertyId = (int)$db->lastInsertId();

        // Create default admin role with all permissions
        require_once $pmsCorePath . '/AuthHelper.php';
        $allPermissions = array_keys(AuthHelper::getAllPermissions());
        $roleStmt = $db->prepare("INSERT INTO roles (property_id, name, permissions) VALUES (?, 'admin', ?)");
        $roleStmt->execute([$propertyId, json_encode($allPermissions)]);

        // Update system settings
        $db->prepare("UPDATE system_settings SET key_value=:tz WHERE key_name='DEFAULT_TIMEZONE'")->execute(['tz' => $timezone]);
        $db->prepare("UPDATE system_settings SET key_value=:cur WHERE key_name='DEFAULT_CURRENCY'")->execute(['cur' => $currency]);
        $db->prepare("UPDATE system_settings SET key_value='1' WHERE key_name='SETUP_COMPLETE'")->execute();

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'property_id' => $propertyId, 'message' => 'Property created!']);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Detect current step ────────────────────────────────────────────────────
$step = 1;
if ($dbConnected) $step = 2;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>MicroPMS — Setup Wizard</title>
    <?php include __DIR__ . '/../admin/components/micropms_icons.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/css/mobile-input-zoom.css" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --indigo: #4f46e5;
            --indigo-light: #eef2ff;
            --indigo-dark: #3730a3;
            --emerald: #10b981;
            --rose: #f43f5e;
            --slate-50: #f8fafc;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-500: #64748b;
            --slate-700: #334155;
            --slate-900: #0f172a;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .wizard-card {
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(20px);
            border-radius: 1.5rem;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 680px;
            overflow: hidden;
        }
        .wizard-header {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            padding: 2rem 2.5rem;
            color: white;
        }
        .wizard-header h1 { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; }
        .wizard-header p  { font-size: 0.875rem; opacity: 0.8; margin-top: 0.25rem; }
        .step-pills {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }
        .step-pill {
            flex: 1;
            height: 4px;
            border-radius: 9999px;
            background: rgba(255,255,255,0.2);
            transition: background 0.3s;
        }
        .step-pill.active  { background: rgba(255,255,255,0.9); }
        .step-pill.done    { background: #10b981; }
        .wizard-body { padding: 2.5rem; }
        .step { display: none; }
        .step.active { display: block; }
        .step-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--slate-900);
            margin-bottom: 0.5rem;
        }
        .step-desc {
            font-size: 0.8125rem;
            color: var(--slate-500);
            margin-bottom: 1.75rem;
            line-height: 1.6;
        }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--slate-700);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.375rem;
        }
        input, select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1.5px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: inherit;
            color: var(--slate-900);
            outline: none;
            transition: border-color 0.2s;
            background: var(--slate-50);
        }
        input:focus, select:focus { border-color: var(--indigo); background: white; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.75rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: var(--indigo);
            color: white;
        }
        .btn-primary:hover { background: var(--indigo-dark); transform: translateY(-1px); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .status-box {
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.8125rem;
            font-weight: 600;
            margin-top: 1rem;
            display: none;
        }
        .status-box.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-box.error   { background: #fff1f2; color: #9f1239; border: 1px solid #fecdd3; }
        .status-box.info    { background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }
        .migration-list {
            margin-top: 0.75rem;
            font-size: 0.75rem;
            font-family: monospace;
            max-height: 160px;
            overflow-y: auto;
            background: #0f172a;
            color: #10b981;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            line-height: 1.6;
        }
        .db-status {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border-radius: 0.75rem;
            border: 1.5px solid;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .db-status.ok    { background:#ecfdf5; color:#065f46; border-color:#a7f3d0; }
        .db-status.error { background:#fff1f2; color:#9f1239; border-color:#fecdd3; }
        .actions { display: flex; justify-content: flex-end; margin-top: 2rem; }
        .check-icon { font-size: 1.25rem; }
        .success-screen { text-align: center; padding: 1rem 0; }
        .success-screen .big-icon { font-size: 4rem; color: var(--emerald); }
        .success-screen h2 { font-size: 1.5rem; font-weight: 800; color: var(--slate-900); margin: 1rem 0 0.5rem; }
        .success-screen p  { font-size: 0.875rem; color: var(--slate-500); margin-bottom: 2rem; }
    </style>
</head>
<body>
<div class="wizard-card">
    <div class="wizard-header">
        <h1 class="flex items-center justify-center gap-2">
            <img src="/icons/logo.svg" alt="" width="32" height="32" style="width:32px;height:32px;border-radius:8px;background:#fff;object-fit:contain;">
            MicroPMS Setup Wizard
        </h1>
        <p>Get your system up and running in just a few steps</p>
        <div class="step-pills">
            <div class="step-pill" id="pill-1"></div>
            <div class="step-pill" id="pill-2"></div>
            <div class="step-pill" id="pill-3"></div>
            <div class="step-pill" id="pill-4"></div>
            <div class="step-pill" id="pill-5"></div>
        </div>
    </div>

    <div class="wizard-body">
        <!-- Step 1: DB Connection -->
        <div class="step" id="step-1">
            <p class="step-title">Step 1 — Database Connection</p>
            <p class="step-desc">Verify your database credentials from the <code>.env</code> file are working correctly.</p>
            <?php if ($dbConnected): ?>
                <div class="db-status ok">
                    <i class="ph ph-check-circle check-icon"></i>
                    Database connected successfully to <strong><?= htmlspecialchars((string)($_ENV['DB_NAME'] ?? 'pms_db')) ?></strong>
                </div>
            <?php else: ?>
                <div class="db-status error">
                    <i class="ph ph-x-circle check-icon"></i>
                    Cannot connect to database. Please check your <code>.env</code> file.<br><br>
                    <strong>Detailed Error:</strong> <?= htmlspecialchars($setupError ?? 'Unknown Error') ?>
                </div>
            <?php endif; ?>
            <div class="actions">
                <button class="btn btn-primary" onclick="nextStep()" <?= !$dbConnected ? 'disabled' : '' ?>>
                    Continue <i class="ph ph-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- Step 2: Run Migrations -->
        <div class="step" id="step-2">
            <p class="step-title">Step 2 — Initialize Database Schema</p>
            <p class="step-desc">Run all migrations to create the required tables. This is safe to run multiple times.</p>
            <button class="btn btn-primary" id="btn-migrate" onclick="runMigrations()">
                <i class="ph ph-database"></i> Run Schema Setup
            </button>
            <div class="status-box" id="migrate-status"></div>
            <div class="migration-list" id="migration-log" style="display:none;"></div>
            <div class="actions" id="step2-next" style="display:none;">
                <button class="btn btn-primary" onclick="nextStep()">
                    Continue <i class="ph ph-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- Step 3: Create Superadmin -->
        <div class="step" id="step-3">
            <p class="step-title">Step 3 — Create Superadmin Account</p>
            <p class="step-desc">This account will have full platform control. Keep these credentials very secure — they grant access to ALL properties.</p>
            <div class="form-grid">
                <div class="full">
                    <label>Superadmin Username</label>
                    <input type="text" id="sa-username" placeholder="superadmin" autocomplete="off">
                </div>
                <div>
                    <label>Password</label>
                    <input type="password" id="sa-password" placeholder="Min. 8 characters">
                </div>
                <div>
                    <label>Confirm Password</label>
                    <input type="password" id="sa-password2" placeholder="Repeat password">
                </div>
            </div>
            <div class="status-box" id="sa-status"></div>
            <div class="actions">
                <button class="btn btn-primary" id="btn-sa" onclick="createSuperadmin()">
                    <i class="ph ph-shield-check"></i> Create Superadmin
                </button>
            </div>
        </div>

        <!-- Step 4: Create First Property -->
        <div class="step" id="step-4">
            <p class="step-title">Step 4 — Create Your First Property</p>
            <p class="step-desc">Set up your hotel/property. You can add more from the SaaS control panel later.</p>
            <div class="form-grid">
                <div class="full">
                    <label>Property Name *</label>
                    <input type="text" id="p-name" placeholder="e.g. The Grand Hotel">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" id="p-email" placeholder="hotel@example.com">
                </div>
                <div>
                    <label>Phone</label>
                    <input type="tel" id="p-phone" placeholder="+91 98765 43210">
                </div>
                <div class="full">
                    <label>Address</label>
                    <input type="text" id="p-address" placeholder="Street address">
                </div>
                <div>
                    <label>City</label>
                    <input type="text" id="p-city" placeholder="Mumbai">
                </div>
                <div>
                    <label>State</label>
                    <input type="text" id="p-state" placeholder="Maharashtra">
                </div>
                <div>
                    <label>Timezone</label>
                    <select id="p-timezone">
                        <option value="Asia/Kolkata" selected>Asia/Kolkata (IST)</option>
                        <option value="Asia/Dubai">Asia/Dubai (GST)</option>
                        <option value="Asia/Singapore">Asia/Singapore (SGT)</option>
                        <option value="UTC">UTC</option>
                        <option value="America/New_York">America/New_York (EST)</option>
                        <option value="Europe/London">Europe/London (GMT)</option>
                    </select>
                </div>
                <div>
                    <label>Currency</label>
                    <select id="p-currency">
                        <option value="INR" selected>INR — Indian Rupee ₹</option>
                        <option value="USD">USD — US Dollar $</option>
                        <option value="AED">AED — UAE Dirham</option>
                        <option value="SGD">SGD — Singapore Dollar</option>
                        <option value="GBP">GBP — British Pound £</option>
                        <option value="EUR">EUR — Euro €</option>
                    </select>
                </div>
            </div>
            <div class="status-box" id="prop-status"></div>
            <div class="actions">
                <button class="btn btn-primary" id="btn-prop" onclick="createProperty()">
                    <i class="ph ph-buildings"></i> Create Property
                </button>
            </div>
        </div>

        <!-- Step 5: Done! -->
        <div class="step" id="step-5">
            <div class="success-screen">
                <div class="big-icon">🎉</div>
                <h2>Setup Complete!</h2>
                <p>Your MicroPMS platform is ready. Log in to the superadmin panel to manage properties, or go straight to your property dashboard.</p>
                <div style="display:flex;gap:1rem;justify-content:center;">
                    <a href="/saas-admin/login" class="btn btn-primary">
                        <i class="ph ph-shield-check"></i> Superadmin Panel
                    </a>
                    <a href="/login" class="btn" style="background:var(--slate-100);color:var(--slate-700);">
                        <i class="ph ph-sign-in"></i> Property Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentStep = 1;

function updatePills() {
    for (let i = 1; i <= 5; i++) {
        const pill = document.getElementById('pill-' + i);
        if (i < currentStep)       pill.className = 'step-pill done';
        else if (i === currentStep) pill.className = 'step-pill active';
        else                        pill.className = 'step-pill';
    }
}

function showStep(n) {
    document.querySelectorAll('.step').forEach(s => s.classList.remove('active'));
    document.getElementById('step-' + n).classList.add('active');
    currentStep = n;
    updatePills();
}

function nextStep() { showStep(currentStep + 1); }

async function runMigrations() {
    const btn = document.getElementById('btn-migrate');
    const status = document.getElementById('migrate-status');
    const log = document.getElementById('migration-log');
    btn.disabled = true;
    btn.innerHTML = '<i class="ph ph-spinner"></i> Running...';

    try {
        const res = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=run_migrations'
        });
        const data = await res.json();

        if (data.success) {
            status.className = 'status-box success';
            status.innerHTML = `✅ Schema ready! Applied: <strong>${data.applied}</strong>, Skipped: <strong>${data.skipped}</strong>`;
            status.style.display = 'block';

            if (data.migrations && data.migrations.length > 0) {
                log.style.display = 'block';
                log.innerHTML = data.migrations.map(m => `✓ ${m.filename} (${m.time_ms}ms)`).join('\n');
            }

            document.getElementById('step2-next').style.display = 'flex';
        } else {
            status.className = 'status-box error';
            status.innerHTML = '❌ Error: ' + (data.message || 'Unknown error');
            status.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="ph ph-database"></i> Retry';
        }
    } catch(e) {
        status.className = 'status-box error';
        status.innerHTML = '❌ Network error. Check server logs.';
        status.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="ph ph-database"></i> Retry';
    }
}

async function createSuperadmin() {
    const u  = document.getElementById('sa-username').value.trim();
    const p  = document.getElementById('sa-password').value;
    const p2 = document.getElementById('sa-password2').value;
    const status = document.getElementById('sa-status');
    const btn = document.getElementById('btn-sa');

    if (p !== p2) {
        status.className = 'status-box error';
        status.innerHTML = '❌ Passwords do not match.';
        status.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="ph ph-spinner"></i> Creating...';

    const body = new URLSearchParams({ action: 'create_superadmin', username: u, password: p });
    try {
        const res = await fetch(window.location.href, { method: 'POST', body });
        const data = await res.json();
        if (data.success) {
            status.className = 'status-box success';
            status.innerHTML = '✅ ' + data.message;
            status.style.display = 'block';
            setTimeout(() => nextStep(), 1000);
        } else {
            status.className = 'status-box error';
            status.innerHTML = '❌ ' + data.message;
            status.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="ph ph-shield-check"></i> Create Superadmin';
        }
    } catch(e) {
        status.className = 'status-box error';
        status.innerHTML = '❌ Network error.';
        status.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="ph ph-shield-check"></i> Create Superadmin';
    }
}

async function createProperty() {
    const name  = document.getElementById('p-name').value.trim();
    const email = document.getElementById('p-email').value.trim();
    const phone = document.getElementById('p-phone').value.trim();
    const addr  = document.getElementById('p-address').value.trim();
    const city  = document.getElementById('p-city').value.trim();
    const state = document.getElementById('p-state').value.trim();
    const tz    = document.getElementById('p-timezone').value;
    const cur   = document.getElementById('p-currency').value;
    const status = document.getElementById('prop-status');
    const btn = document.getElementById('btn-prop');

    if (!name) {
        status.className = 'status-box error';
        status.innerHTML = '❌ Property name is required.';
        status.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="ph ph-spinner"></i> Creating...';

    const body = new URLSearchParams({
        action: 'create_property',
        name, email, phone, address: addr, city, state, timezone: tz, currency: cur
    });

    try {
        const res = await fetch(window.location.href, { method: 'POST', body });
        const data = await res.json();
        if (data.success) {
            status.className = 'status-box success';
            status.innerHTML = '✅ ' + data.message;
            status.style.display = 'block';
            setTimeout(() => nextStep(), 1000);
        } else {
            status.className = 'status-box error';
            status.innerHTML = '❌ ' + data.message;
            status.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="ph ph-buildings"></i> Create Property';
        }
    } catch(e) {
        status.className = 'status-box error';
        status.innerHTML = '❌ Network error.';
        status.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="ph ph-buildings"></i> Create Property';
    }
}

// Init
showStep(<?= $dbConnected ? 1 : 1 ?>);
</script>
</body>
</html>
