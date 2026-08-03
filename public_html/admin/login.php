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
            background: radial-gradient(circle at 10% 20%, rgb(4, 8, 24) 0%, rgb(15, 23, 42) 90%);
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

    <div class="login-glass p-8 rounded-3xl w-full max-w-[420px] z-10 animate-fade-up relative">
        <!-- Logo Icon Header -->
        <div class="flex flex-col items-center mb-8">
            <div class="w-14 h-14 bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 rounded-2xl flex items-center justify-center mb-3 shadow-inner">
                <i class="ph ph-buildings text-3xl"></i>
            </div>
            <h2 class="text-2xl font-extrabold text-white tracking-tight">Welcome Back</h2>
            <p class="text-slate-400 text-xs mt-1.5 font-medium">Log in to manage your property operations</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs font-bold rounded-xl p-3 text-center mb-5 animate-pulse-glow">
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
                    <input type="text" name="username" class="w-full bg-slate-950/50 border border-slate-800 rounded-xl pl-11 pr-4 py-3 text-white text-sm focus:border-indigo-500 focus:outline-none transition-all placeholder-slate-600 shadow-inner" placeholder="Enter username" required>
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
