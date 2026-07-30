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
    <title>MicroPMS — Superadmin Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .card {
            background: rgba(255,255,255,0.97);
            border-radius: 1.5rem;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 400px;
            padding: 2.5rem;
        }
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo-icon {
            width: 60px; height: 60px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 1rem;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.75rem;
        }
        .logo h1 { font-size: 1.25rem; font-weight: 800; color: #0f172a; }
        .logo p  { font-size: 0.75rem; color: #64748b; margin-top: 0.25rem; font-weight: 500; letter-spacing: 0.05em; text-transform: uppercase; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.25rem 0.75rem;
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.375rem;
        }
        input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: inherit;
            color: #0f172a;
            outline: none;
            transition: border-color 0.2s;
            background: #f8fafc;
            margin-bottom: 1rem;
        }
        input:focus { border-color: #4f46e5; background: white; }
        .error-box {
            background: #fff1f2;
            color: #9f1239;
            border: 1px solid #fecdd3;
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            font-size: 0.8125rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .btn {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 700;
            border: none;
            border-radius: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            letter-spacing: 0.02em;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 8px 25px rgba(79,70,229,0.4); }
        .footer-link {
            text-align: center;
            margin-top: 1.25rem;
            font-size: 0.75rem;
            color: #94a3b8;
        }
        .footer-link a { color: #4f46e5; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <div class="logo-icon">🛡️</div>
        <h1>MicroPMS</h1>
        <p>Control Plane</p>
    </div>
    <div style="text-align:center; margin-bottom:1.5rem;">
        <span class="badge">⚠️ Superadmin Access Only</span>
    </div>

    <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div>
            <label>Username</label>
            <input type="text" name="username" required autocomplete="username" placeholder="superadmin">
        </div>
        <div>
            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password" placeholder="••••••••••">
        </div>
        <button type="submit" class="btn">Sign in to Control Plane</button>
    </form>
    <div class="footer-link">
        <a href="/admin/login.php">← Property Staff Login</a>
    </div>
</div>
</body>
</html>
