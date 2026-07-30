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
            if ($user['access_level'] === 'superadmin' || $user['role'] === 'superadmin') {
                header("Location: /saas-admin/login");
                exit;
            }

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
    <title>Staff Login</title>
    <?php include __DIR__ . '/components/ui_head.php'; ?>

</head>
<body class="bg-brand-100 flex items-center justify-center h-screen px-4">
    <div class="bg-white p-8 rounded-2xl  w-full max-w-sm border border-brand-100">
        <h2 class="text-2xl font-bold mb-6 text-center text-brand-800">Staff Login</h2>
        <?php if (isset($error)): ?>
            <p class="text-error-500 text-sm mb-4 text-center"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-brand-900">Username</label>
                <input type="text" name="username" class="mt-1 block w-full rounded-md border-brand-300  focus:border-blue-500 focus:ring-blue-500 border p-2 outline-none" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-brand-900">Password</label>
                <input type="password" name="password" class="mt-1 block w-full rounded-md border-brand-300  focus:border-blue-500 focus:ring-blue-500 border p-2 outline-none" required>
            </div>
            <button type="submit" class="w-full bg-brand-accent text-white rounded-lg py-2.5 font-semibold hover:bg-brand-accentHover active:scale-95 transition-transform ">Login</button>
        </form>
    </div>
</body>
</html>
