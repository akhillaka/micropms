<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/ErrorTracker.php';

$db = Database::getInstance()->getConnection();

// Block non-superadmin
if (isset($_SESSION['saas_admin_id'])) {
    header('Location: index.php');
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

            header('Location: index.php');
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
    <title>SaaS Superadmin Login | MicroPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body {
            background: radial-gradient(circle at 10% 20%, rgb(4, 8, 24) 0%, rgb(15, 23, 42) 90%);
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .login-glass {
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .login-glass input {
            padding-left: 2.75rem !important;
            background-color: rgba(9, 15, 29, 0.8) !important;
            color: #ffffff !important;
            border-color: rgba(51, 65, 85, 0.5) !important;
        }
        .login-glass input:focus {
            border-color: #4f46e5 !important;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.25) !important;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4 overflow-hidden relative">
    <!-- Gradient glow animations in background -->
    <div class="absolute top-1/4 left-1/4 w-[400px] h-[400px] bg-indigo-500/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute bottom-1/4 right-1/4 w-[350px] h-[350px] bg-violet-600/10 rounded-full blur-[100px] pointer-events-none"></div>

    <div class="login-glass p-8 rounded-3xl w-full max-w-[420px] z-10 relative">
        <!-- Logo Icon Header -->
        <div class="flex flex-col items-center mb-8">
            <div class="w-14 h-14 bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 rounded-2xl flex items-center justify-center mb-3 shadow-inner">
                <i class="ph ph-shield-check text-3xl"></i>
            </div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight">Control Plane</h2>
            <div class="mt-2">
                <span class="text-[9px] bg-indigo-500/20 text-indigo-300 font-black px-2.5 py-1 rounded-full uppercase tracking-wider">⚠️ Superadmin Access</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs font-bold rounded-xl p-3 text-center mb-5">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Username</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-500">
                        <i class="ph ph-user text-lg"></i>
                    </span>
                    <input type="text" name="username" class="w-full bg-slate-950/50 border border-slate-800 rounded-xl pl-11 pr-4 py-3 text-white text-sm focus:border-indigo-500 focus:outline-none transition-all placeholder-slate-600 shadow-inner" placeholder="superadmin" required>
                </div>
            </div>

            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-500">
                        <i class="ph ph-lock text-lg"></i>
                    </span>
                    <input type="password" name="password" class="w-full bg-slate-950/50 border border-slate-800 rounded-xl pl-11 pr-4 py-3 text-white text-sm focus:border-indigo-500 focus:outline-none transition-all placeholder-slate-600 shadow-inner" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="w-full py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white font-extrabold rounded-xl text-sm transition shadow-lg shadow-indigo-500/20 hover:-translate-y-0.5 active:translate-y-0 active:scale-[0.98]">
                Authenticate
            </button>
        </form>

        <div class="mt-8 border-t border-slate-800/80 pt-5 text-center">
            <a href="/login" class="text-xs text-slate-400 hover:text-indigo-400 transition-colors font-bold flex items-center justify-center gap-1.5">
                <i class="ph ph-arrow-left"></i> Property Staff Login
            </a>
        </div>
    </div>
</body>
</html>
