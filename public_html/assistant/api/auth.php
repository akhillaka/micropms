<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/CsrfToken.php';

ApiHandler::run(function(\PDO $db) {
    // 1. Self-Healing DB Check (Auto-migration)
    try {
        // Check and add pin_hash column
        $checkCol = $db->query("SHOW COLUMNS FROM staff_users LIKE 'pin_hash'");
        if ($checkCol && $checkCol->rowCount() === 0) {
            $db->exec("ALTER TABLE staff_users ADD COLUMN pin_hash VARCHAR(255) DEFAULT NULL COMMENT 'Hashed 4-digit PIN for quick login'");
        }
        
        // Check and add assistant_access column
        $checkCol2 = $db->query("SHOW COLUMNS FROM staff_users LIKE 'assistant_access'");
        if ($checkCol2 && $checkCol2->rowCount() === 0) {
            $db->exec("ALTER TABLE staff_users ADD COLUMN assistant_access TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Allowed to access Booking Assistant PWA'");
            // Assign default access to owner and admin
            $db->exec("UPDATE staff_users SET assistant_access = 1 WHERE access_level = 'owner' OR username = 'admin'");
        }
        
        // Check and add is_active column
        $checkCol3 = $db->query("SHOW COLUMNS FROM staff_users LIKE 'is_active'");
        if ($checkCol3 && $checkCol3->rowCount() === 0) {
            $db->exec("ALTER TABLE staff_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Active, 0 = Deactivated'");
        }

        // Ensure at least one active user has assistant access to prevent locked out screens
        $count = (int)$db->query("SELECT COUNT(*) FROM staff_users WHERE assistant_access = 1")->fetchColumn();
        if ($count === 0) {
            $db->exec("UPDATE staff_users SET assistant_access = 1 WHERE access_level IN ('admin', 'owner', 'manager', 'receptionist') OR username = 'admin'");
            $countStillZero = (int)$db->query("SELECT COUNT(*) FROM staff_users WHERE assistant_access = 1")->fetchColumn();
            if ($countStillZero === 0) {
                $db->exec("UPDATE staff_users SET assistant_access = 1 WHERE is_active = 1");
            }
        }
    } catch (\PDOException $e) {
        // Silently log or handle database check failure
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    // Action: Get List of Active Staff with Assistant Access
    if ($action === 'list_staff') {
        $stmt = $db->prepare("SELECT id, username, access_level FROM staff_users WHERE is_active = 1 AND assistant_access = 1 ORDER BY username ASC");
        $stmt->execute();
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($staff as &$u) {
            $u['role'] = $u['access_level']; // map access_level to role dynamically
        }
        ApiResponse::success(['staff' => $staff]);
    }

    // Action: Log in via Username & 4-digit PIN
    elseif ($action === 'login') {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $pin = trim($data['pin'] ?? '');

        if (!$userId || strlen($pin) !== 4 || !is_numeric($pin)) {
            ApiResponse::error('Invalid user or 4-digit PIN format');
        }

        // Rate limiting: Check failed attempts in last 15 minutes
        $ipAddress = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateStmt = $db->prepare("
            SELECT COUNT(*) FROM login_attempts 
            WHERE (username = :username OR ip_address = :ip) 
            AND success = 0 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $rateStmt->execute(['username' => (string)$userId, 'ip' => $ipAddress]);
        $failedAttempts = (int)$rateStmt->fetchColumn();

        if ($failedAttempts >= 5) {
            ApiResponse::error('Too many failed attempts. Please wait 15 minutes or contact your manager.');
        }

        $stmt = $db->prepare("SELECT * FROM staff_users WHERE id = :id AND is_active = 1 AND assistant_access = 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            // Log failed attempt
            $db->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 0)")
               ->execute([(string)$userId, $ipAddress]);
            ApiResponse::error('Staff member not found or access denied');
        }

        // Validate PIN
        $isValid = false;
        if (empty($user['pin_hash'])) {
            ApiResponse::error('PIN not set. Please ask your manager to set a PIN for you in Settings > Staff.');
            return;
        } else {
            if (password_verify($pin, $user['pin_hash'])) {
                $isValid = true;
            }
        }

        if ($isValid) {
            // Log successful attempt
            $db->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 1)")
               ->execute([$user['username'], $ipAddress]);

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['access_level'] ?: $user['role'] ?: 'manager';
            $_SESSION['username'] = $user['username'];

            // Generate CSRF token for the session
            $csrfToken = CsrfToken::generate();

            ApiResponse::success([
                'message' => 'Logged in successfully',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $_SESSION['role'],
                    'pin_set' => !empty($user['pin_hash'])
                ],
                'csrf_token' => $csrfToken
            ]);
        } else {
            // Log failed attempt
            $db->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 0)")
               ->execute([$user['username'], $ipAddress]);
            ApiResponse::error('Incorrect PIN');
        }
    }

    // Action: Check Active Session
    elseif ($action === 'status') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['user_id'])) {
            $stmt = $db->prepare("SELECT id, username, access_level, pin_hash FROM staff_users WHERE id = :id AND is_active = 1");
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();

            if ($user) {
                ApiResponse::success([
                    'logged_in' => true,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'role' => $user['access_level'] ?: $user['role'] ?: 'manager',
                        'pin_set' => !empty($user['pin_hash'])
                    ],
                    'csrf_token' => CsrfToken::generate()
                ]);
            }
        }
        ApiResponse::success(['logged_in' => false]);
    }

    // Action: Change/Setup Staff PIN (Admin only or self-update)
    elseif ($action === 'update_pin') {
        // Enforce session login manually
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            ApiResponse::error('Unauthorized');
        }

        $targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : (int)$_SESSION['user_id'];
        $newPin = trim($data['pin'] ?? '');

        if (strlen($newPin) !== 4 || !is_numeric($newPin)) {
            ApiResponse::error('PIN must be exactly 4 digits');
        }

        // Validate access: Either self updating, or admin/owner updating another user
        $isSelf = ($targetUserId === (int)$_SESSION['user_id']);
        $isAdmin = ($_SESSION['role'] === 'owner' || $_SESSION['username'] === 'admin');

        if (!$isSelf && !$isAdmin) {
            ApiResponse::error('Only administrators can change other staff PINs');
        }

        $stmt = $db->prepare("SELECT id FROM staff_users WHERE id = :id");
        $stmt->execute(['id' => $targetUserId]);
        if (!$stmt->fetch()) {
            ApiResponse::error('Target staff user not found');
        }

        $pinHash = password_hash($newPin, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE staff_users SET pin_hash = :pin_hash WHERE id = :id");
        $stmt->execute(['pin_hash' => $pinHash, 'id' => $targetUserId]);

        ApiResponse::success(['message' => 'PIN updated successfully']);
    }

    // Action: Manage Assistant Access (Admin Only)
    elseif ($action === 'update_access') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            ApiResponse::error('Unauthorized');
        }

        $isAdmin = ($_SESSION['role'] === 'owner' || $_SESSION['username'] === 'admin');
        if (!$isAdmin) {
            ApiResponse::error('Only administrators can update module access permissions');
        }

        $targetUserId = (int)($data['user_id'] ?? 0);
        $accessValue = (int)($data['assistant_access'] ?? 0) === 1 ? 1 : 0;

        if ($targetUserId === (int)$_SESSION['user_id']) {
            ApiResponse::error('Cannot modify your own access settings');
        }

        $stmt = $db->prepare("UPDATE staff_users SET assistant_access = :acc WHERE id = :id");
        $stmt->execute(['acc' => $accessValue, 'id' => $targetUserId]);

        ApiResponse::success(['message' => 'Access permissions updated successfully']);
    }

    // Action: Logout
    elseif ($action === 'logout') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_unset();
        session_destroy();
        ApiResponse::success(['message' => 'Logged out successfully']);
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, false, false, false);
