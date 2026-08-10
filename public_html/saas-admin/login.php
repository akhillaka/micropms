<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/ErrorTracker.php';

$db = Database::getInstance()->getConnection();

if (isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas-admin/index');
    exit;
}

$ipAddress = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (str_contains($ipAddress, ',')) $ipAddress = trim(explode(',', $ipAddress)[0]);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Brute force check
    $failedAttempts = 0;
    try {
        $lockStmt = $db->prepare("
            SELECT COUNT(*) FROM login_attempts 
            WHERE username = :u AND ip_address = :ip AND success = 0 
              AND attempted_at > NOW() - INTERVAL 15 MINUTE
        ");
        $lockStmt->execute(['u' => $username, 'ip' => $ipAddress]);
        $failedAttempts = (int)$lockStmt->fetchColumn();
    } catch (\PDOException $e) {}

    if ($failedAttempts >= 5) {
        $error = 'Too many failed attempts. Locked for 15 minutes.';
    } else {
        // Log attempt
        try {
            $db->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 0)")
               ->execute([$username, $ipAddress]);
            $attemptId = (int)$db->lastInsertId();
        } catch (\PDOException $e) { $attemptId = 0; }

        $stmt = $db->prepare("SELECT * FROM staff_users WHERE username = ? AND access_level = 'superadmin' AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Mark success
            if (!empty($attemptId)) {
                try { $db->prepare("UPDATE login_attempts SET success=1 WHERE id=?")->execute([$attemptId]); } catch (\PDOException $e) {}
            }
            // Update login stats
            try {
                $db->prepare("UPDATE staff_users SET last_login_at=NOW(), last_login_ip=?, login_count=login_count+1 WHERE id=?")
                   ->execute([$ipAddress, $user['id']]);
            } catch (\PDOException $e) {}

            session_regenerate_id(true);
            $_SESSION['saas_admin_id']       = $user['id'];
            $_SESSION['saas_admin_username']  = $user['username'];
            $_SESSION['saas_admin_role']      = 'superadmin';

            header('Location: /saas-admin/index');
            exit;
        } else {
            $error = 'Invalid superadmin credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Control Panel Login | MicroPMS</title>
    <meta name="description" content="MicroPMS SaaS Super-Admin Control Panel — Authorized Access Only">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #F0F9FF 0%, #F8FAFC 45%, #E0F2FE 100%);
            min-height: 100vh;
        }
        .login-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(226,232,240,0.9);
            box-shadow: 0 24px 48px -8px rgba(30,58,138,0.16), 0 8px 20px -4px rgba(15,23,42,0.07);
        }
        .secure-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: linear-gradient(135deg, #FEF3C7, #FDE68A);
            border: 1px solid #FCD34D;
            color: #78350F;
            font-size: 0.65rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.08em;
            padding: 4px 10px; border-radius: 999px;
        }
        .brand-icon {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #1E3A8A, #1D4ED8);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 10px 24px -4px rgba(30,58,138,0.40);
        }
        .input-icon-wrap { position: relative; }
        .input-icon-wrap input { padding-left: 2.75rem !important; border-radius: 0.75rem; border: 1px solid #E2E8F0 !important; background: #fff; font-weight: 500; color: #0F172A; font-family: inherit; padding-top: 0.75rem !important; padding-bottom: 0.75rem !important; width: 100%; transition: all 0.2s; box-shadow: 0 1px 3px rgba(15,23,42,0.04) !important; }
        .input-icon-wrap input:focus { border-color: #1E3A8A !important; outline: none; box-shadow: 0 0 0 3px rgba(30,58,138,0.12), 0 1px 3px rgba(15,23,42,0.04) !important; }
        .input-icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: #94A3B8; font-size: 1.1rem; pointer-events: none; transition: color 0.15s;
        }
        .login-btn {
            background: linear-gradient(135deg, #1E3A8A, #1D4ED8);
            color: #fff; font-weight: 800; font-size: 0.9rem;
            padding: 0.9rem; border-radius: 0.875rem; border: none;
            width: 100%; cursor: pointer;
            transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
            box-shadow: 0 8px 24px -4px rgba(30,58,138,0.38);
            display: flex; align-items: center; justify-content: center; gap: 8px;
            letter-spacing: -0.01em;
        }
        .login-btn:hover {
            background: linear-gradient(135deg, #162F73, #1E3A8A);
            transform: translateY(-2px);
            box-shadow: 0 14px 32px -6px rgba(30,58,138,0.45);
        }
        .login-btn:active { transform: translateY(1px); }
        .hidden { display: none !important; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-up { animation: fadeUp 0.4s cubic-bezier(0.16,1,0.3,1) forwards; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4 py-8">
    <!-- Background decorations -->
    <div class="fixed top-16 left-12 w-80 h-80 bg-blue-200/25 rounded-full blur-3xl pointer-events-none"></div>
    <div class="fixed bottom-16 right-12 w-64 h-64 bg-indigo-200/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="fixed top-1/3 right-1/3 w-48 h-48 bg-sky-200/20 rounded-full blur-2xl pointer-events-none"></div>

    <div class="login-card p-8 rounded-3xl w-full max-w-[420px] z-10 animate-up relative overflow-hidden">
        <!-- Subtle inner glow -->
        <div class="absolute top-0 right-0 w-40 h-40 bg-blue-50 rounded-full -translate-y-1/2 translate-x-1/2 blur-2xl opacity-60 pointer-events-none"></div>

        <!-- Header -->
        <div class="flex flex-col items-center mb-8 relative">
            <div class="brand-icon mb-4">
                <i class="ph ph-shield-check text-3xl text-white"></i>
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Control Plane</h1>
            <p class="text-slate-500 text-sm mt-1.5 font-medium">MicroPMS SaaS Administration</p>
            <div class="mt-3">
                <span class="secure-badge">
                    <i class="ph ph-lock-simple-open"></i>
                    Superadmin Access Only
                </span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="flex items-center gap-2.5 bg-red-50 border border-red-200 text-red-700 text-sm font-semibold rounded-xl p-3.5 mb-5">
                <i class="ph ph-warning-circle text-lg flex-shrink-0"></i>
                <span><?= htmlspecialchars((string)($error)) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5 relative">
            <div>
                <label for="saas-username" class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-2">Superadmin Username</label>
                <div class="input-icon-wrap">
                    <input type="text" id="saas-username" name="username"
                        placeholder="Enter superadmin username"
                        required autocomplete="username">
                    <i class="ph ph-user input-icon"></i>
                </div>
            </div>

            <div>
                <label for="saas-password" class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-2">Password</label>
                <div class="input-icon-wrap">
                    <input type="password" id="saas-password" name="password"
                        placeholder="••••••••"
                        required autocomplete="current-password">
                    <i class="ph ph-lock input-icon"></i>
                </div>
            </div>

            <button type="submit" class="login-btn">
                <i class="ph ph-shield-check text-lg"></i>
                Authenticate &amp; Enter
            </button>
        </form>

        <!-- Divider -->
        <div class="mt-7 pt-5 border-t border-slate-100 text-center relative">
            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider mb-3">Restricted Area — Authorized Personnel Only</p>
            <a href="/admin/login" class="text-xs text-slate-400 hover:text-blue-700 transition-colors font-semibold flex items-center justify-center gap-1.5 group">
                <i class="ph ph-arrow-left text-xs"></i>
                Property Staff Login
            </a>
        </div>
    </div>
</body>
</html>
