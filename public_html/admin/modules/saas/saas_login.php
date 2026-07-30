<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../pms_core/Database.php';
require_once __DIR__ . '/../../../../pms_core/ErrorTracker.php';
require_once __DIR__ . '/../../../../pms_core/AuthHelper.php';

$db = Database::getInstance()->getConnection();

$ipAddress = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (str_contains($ipAddress, ',')) {
    $ipAddress = trim(explode(',', $ipAddress)[0]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Brute-force Check: Count failed attempts in last 15 mins
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
        $error = "Too many failed login attempts. Locked for 15 minutes.";
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
            if ($user['access_level'] !== 'owner') {
                $error = "Access denied: This portal is reserved for SaaS Super-Administrators.";
            } elseif ((int)($user['is_active'] ?? 1) !== 1) {
                $error = "Your account has been deactivated.";
            } else {
                // Success!
                if ($attemptId > 0) {
                    try {
                        $db->prepare("UPDATE login_attempts SET success = 1 WHERE id = ?")->execute([$attemptId]);
                    } catch (\PDOException $e) {}
                }
                
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['access_level'];
                $_SESSION['access_level'] = $user['access_level'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['property_id'] = $user['property_id'] ?? 1;
                
                header("Location: saas_properties.php");
                exit;
            }
        } else {
            $error = "Invalid credentials";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Console Login | MicroPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-panel {
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="bg-[#050811] text-slate-100 flex items-center justify-center min-h-screen px-4">
    <div class="glass-panel p-8 rounded-2xl w-full max-w-sm border border-slate-800/80 shadow-2xl relative overflow-hidden">
        
        <!-- Background light effect -->
        <div class="absolute -top-24 -left-24 w-48 h-48 bg-sky-500/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-indigo-500/10 rounded-full blur-3xl"></div>

        <div class="text-center mb-6">
            <span class="inline-block p-3 bg-sky-500/10 border border-sky-500/20 text-sky-400 rounded-2xl mb-4">
                <i class="ph ph-shield-keyhole text-3xl"></i>
            </span>
            <h2 class="text-2xl font-black text-white tracking-tight">SaaS Control Plane</h2>
            <p class="text-slate-400 text-xs mt-1">Super-Admin Console Secure Access</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="p-3 bg-rose-500/10 border border-rose-500/30 text-rose-400 rounded-xl text-xs font-bold text-center mb-4">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Username</label>
                <input type="text" name="username" required placeholder="admin" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">Password</label>
                <input type="password" name="password" required placeholder="••••••••" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
            </div>
            <button type="submit" class="w-full py-3 bg-sky-600 hover:bg-sky-500 text-white font-extrabold rounded-xl text-sm transition shadow-lg shadow-sky-500/10 cursor-pointer">
                Unlock Console
            </button>
        </form>
    </div>
</body>
</html>
