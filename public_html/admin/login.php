<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/ErrorTracker.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';

$db = Database::getInstance()->getConnection();

// Seed default owner if no users exist
$count = $db->query("SELECT COUNT(*) FROM staff_users")->fetchColumn();
if ($count == 0) {
    $defaultPass = bin2hex(random_bytes(8));
    $stmt = $db->prepare("INSERT INTO staff_users (username, password_hash, access_level) VALUES ('admin', :hash, 'owner')");
    $stmt->execute(['hash' => password_hash($defaultPass, PASSWORD_DEFAULT)]);
    error_log("MicroPMS: Default admin user created with password: $defaultPass");
    $error = "System initialized. Please check the server error log for the default admin password, then log in.";
}

$ipAddress = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (str_contains($ipAddress, ',')) {
    $ipAddress = trim(explode(',', $ipAddress)[0]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // 1. Brute-force Check: Count failed attempts in last 15 mins
    $failedAttempts = 0;
    try {
        $lockStmt = $db->prepare("
            SELECT COUNT(*) FROM login_attempts 
            WHERE username = :u AND ip_address = :ip AND success = 0 
              AND attempted_at > NOW() - INTERVAL 15 MINUTE
        ");
        $lockStmt->execute(['u' => $username, 'ip' => $ipAddress]);
        $failedAttempts = (int)$lockStmt->fetchColumn();
    } catch (\PDOException $e) {
        $failedAttempts = 0;
    }
    
    if ($failedAttempts >= 5) {
        $error = "Too many failed login attempts. This account is locked for 15 minutes.";
        try {
            ErrorTracker::log('warning', 'auth', 'Blocked login attempt due to lockout', [
                'username' => $username,
                'ip' => $ipAddress
            ]);
        } catch (\Throwable $th) {}
    } else {
        // Log the start of this login attempt
        $attemptId = 0;
        try {
            $logAttempt = $db->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, 0)");
            $logAttempt->execute(['u' => $username, 'ip' => $ipAddress]);
            $attemptId = (int)$db->lastInsertId();
        } catch (\PDOException $e) {
            $attemptId = 0;
        }
        
        $stmt = $db->prepare("SELECT * FROM staff_users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Superadmin gets normal session variables so they can use group_dashboard
            // We removed the redirect to /saas-admin/login so they can access both seamlessly.

            $isUserActive = 1;
            if (isset($user['is_active'])) {
                $isUserActive = (int)$user['is_active'];
            }
            
            if ($isUserActive !== 1) {
                $error = "Your account has been deactivated. Please contact the owner.";
                try {
                    ErrorTracker::log('warning', 'auth', 'Login attempt on deactivated account', [
                        'username' => $username,
                        'ip' => $ipAddress
                    ]);
                } catch (\Throwable $th) {}
            } else {
                // Success! Mark attempt as successful
                if ($attemptId > 0) {
                    try {
                        $db->prepare("UPDATE login_attempts SET success = 1 WHERE id = ?")->execute([$attemptId]);
                    } catch (\PDOException $e) {}
                }
                
                // Update staff stats
                try {
                    $updateStats = $db->prepare("
                        UPDATE staff_users 
                        SET last_login_at = NOW(), 
                            last_login_ip = :ip, 
                            login_count = login_count + 1 
                        WHERE id = :id
                    ");
                    $updateStats->execute(['ip' => $ipAddress, 'id' => $user['id']]);
                } catch (\PDOException $e) {
                    // Fail gracefully if column doesn't exist yet
                }
                
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['access_level']; // Normalize role
                $_SESSION['access_level'] = $user['access_level']; // Ensure legacy check passes
                $_SESSION['username'] = $user['username'];
                
                // Automatically log them into the SaaS control panel if they are a superadmin
                if ($user['access_level'] === 'superadmin' || $user['role'] === 'superadmin') {
                    $_SESSION['saas_admin_id'] = $user['id'];
                    $_SESSION['saas_admin_username'] = $user['username'];
                    $_SESSION['saas_admin_role'] = 'superadmin';
                }
                
                if (!empty($user['role_id'])) {
                    try {
                        $roleStmt = $db->prepare("SELECT permissions FROM roles WHERE id = ?");
                        $roleStmt->execute([$user['role_id']]);
                        $roleData = $roleStmt->fetch();
                        if ($roleData && !empty($roleData['permissions'])) {
                            $_SESSION['custom_permissions'] = json_decode($roleData['permissions'], true) ?? [];
                        }
                    } catch (\PDOException $e) {
                        // ignore if roles table doesn't exist yet
                    }
                }
                

                header("Location: group_dashboard.php");
                exit;
            }
        } else {
            $error = "Invalid credentials";
            
            // Check if this failure triggers the lockout threshold
            if ($failedAttempts + 1 >= 5) {
                // Send immediate Telegram Alert to Owner
                $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                           . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin/settings.php?tab=staff';
                try {
                    ErrorTracker::critical('auth', "Brute-force lockout triggered", [
                        'username' => $username,
                        '_ip' => $ipAddress,
                        'reset_link' => $resetLink
                    ]);
                } catch (\Throwable $th) {}
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login | MicroPMS</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>
    <style>
        body {
            background: linear-gradient(135deg, #EFF6FF 0%, #F8FAFC 50%, #DBEAFE 100%);
            min-height: 100vh;
        }
        .login-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(226,232,240,0.8);
            box-shadow: 0 24px 48px -8px rgba(30,58,138,0.14), 0 8px 20px -4px rgba(15,23,42,0.06);
        }
        .login-blob-1 {
            position: absolute; top: -80px; right: -80px;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(59,130,246,0.18) 0%, transparent 70%);
            border-radius: 50%; pointer-events: none;
        }
        .login-blob-2 {
            position: absolute; bottom: -60px; left: -60px;
            width: 220px; height: 220px;
            background: radial-gradient(circle, rgba(30,58,138,0.12) 0%, transparent 70%);
            border-radius: 50%; pointer-events: none;
        }
        .input-icon-wrap { position: relative; }
        .input-icon-wrap input { padding-left: 2.75rem !important; }
        .input-icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: #94A3B8; font-size: 1.1rem; pointer-events: none;
            transition: color 0.15s;
        }
        .input-icon-wrap input:focus ~ .input-icon { color: #1E3A8A; }
        .login-btn {
            background: linear-gradient(135deg, #1E3A8A, #2563EB);
            color: #fff; font-weight: 800; font-size: 0.9rem;
            padding: 0.875rem; border-radius: 0.875rem; border: none;
            width: 100%; cursor: pointer;
            transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
            box-shadow: 0 8px 20px -4px rgba(30,58,138,0.35);
            display: flex; align-items: center; justify-content: center; gap: 8px;
            letter-spacing: -0.01em;
        }
        .login-btn:hover {
            background: linear-gradient(135deg, #162F73, #1E3A8A);
            transform: translateY(-2px);
            box-shadow: 0 14px 28px -6px rgba(30,58,138,0.40);
        }
        .login-btn:active { transform: translateY(1px); }
        .brand-icon {
            width: 60px; height: 60px;
            background: linear-gradient(135deg, #EFF6FF, #DBEAFE);
            border: 2px solid #BFDBFE; border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 6px 16px -3px rgba(30,58,138,0.18);
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4 py-8">
    <div class="fixed top-20 left-10 w-72 h-72 bg-blue-200/30 rounded-full blur-3xl pointer-events-none"></div>
    <div class="fixed bottom-10 right-10 w-56 h-56 bg-indigo-300/20 rounded-full blur-3xl pointer-events-none"></div>
    <div class="fixed top-1/2 right-1/4 w-40 h-40 bg-sky-200/25 rounded-full blur-2xl pointer-events-none"></div>

    <div class="login-card p-8 rounded-3xl w-full max-w-[420px] z-10 animate-fade-up relative overflow-hidden">
        <div class="login-blob-1"></div>
        <div class="login-blob-2"></div>

        <div class="flex flex-col items-center mb-8 relative">
            <div class="brand-icon mb-4">
                <i class="ph ph-buildings text-3xl text-blue-700"></i>
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Welcome Back</h1>
            <p class="text-slate-500 text-sm mt-1.5 font-medium">Sign in to manage your property</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="flex items-center gap-2.5 bg-red-50 border border-red-200 text-red-700 text-sm font-semibold rounded-xl p-3.5 mb-5">
                <i class="ph ph-warning-circle text-lg flex-shrink-0"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5 relative">
            <div>
                <label for="login-username" class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-2">Username</label>
                <div class="input-icon-wrap">
                    <input type="text" id="login-username" name="username"
                        class="w-full border border-slate-200 rounded-xl py-3 text-sm focus:border-blue-700 focus:outline-none transition-all"
                        placeholder="Enter your username" required autocomplete="username">
                    <i class="ph ph-user input-icon"></i>
                </div>
            </div>

            <div>
                <label for="login-password" class="block text-xs font-bold text-slate-600 uppercase tracking-widest mb-2">Password</label>
                <div class="input-icon-wrap">
                    <input type="password" id="login-password" name="password"
                        class="w-full border border-slate-200 rounded-xl py-3 text-sm focus:border-blue-700 focus:outline-none transition-all"
                        placeholder="••••••••" required autocomplete="current-password">
                    <i class="ph ph-lock input-icon"></i>
                </div>
            </div>

            <button type="submit" class="login-btn">
                <i class="ph ph-sign-in text-lg"></i>
                Access Dashboard
            </button>
        </form>

        <div class="mt-8 border-t border-slate-800/80 pt-5 text-center">
            <a href="/saas-admin/login" class="text-xs text-slate-400 hover:text-indigo-400 transition-colors font-bold flex items-center justify-center gap-1.5">
                <i class="ph ph-arrow-left"></i> SaaS Control Panel Login
            </a>
        </div>
    </div>
</body>
</html>
