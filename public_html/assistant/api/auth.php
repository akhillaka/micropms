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
            $db->exec("UPDATE staff_users SET assistant_access = 1 WHERE access_level = 'superadmin' OR access_level = 'owner' OR username = 'admin'");
        }

        // Check and add is_active column
        $checkCol3 = $db->query("SHOW COLUMNS FROM staff_users LIKE 'is_active'");
        if ($checkCol3 && $checkCol3->rowCount() === 0) {
            $db->exec("ALTER TABLE staff_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Active, 0 = Deactivated'");
        }

        // Check and add assistant_role column (separate from MicroPMS access_level)
        $checkRole = $db->query("SHOW COLUMNS FROM staff_users LIKE 'assistant_role'");
        if ($checkRole && $checkRole->rowCount() === 0) {
            $db->exec("ALTER TABLE staff_users ADD COLUMN assistant_role ENUM('owner','manager','receptionist','housekeeping') NOT NULL DEFAULT 'receptionist' COMMENT 'Role inside Hotel Assistant PWA'");
            // Auto-seed role from existing access_level
            $db->exec("UPDATE staff_users SET assistant_role = 'owner'        WHERE access_level IN ('superadmin','owner')");
            $db->exec("UPDATE staff_users SET assistant_role = 'manager'      WHERE access_level IN ('manager','admin') AND assistant_role = 'receptionist'");
            $db->exec("UPDATE staff_users SET assistant_role = 'housekeeping' WHERE access_level = 'housekeeping'");
        }

        // Ensure at least one active user has assistant access
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

    $propertyId = 0;
    try {
        $propertyId = AuthHelper::getPropertyId();
    } catch (\Throwable $e) {
        $propertyId = 0;
    }

    // Action: Get List of Active Staff with Assistant Access
    if ($action === 'list_staff') {
        if ($propertyId <= 0) {
            ApiResponse::success(['staff' => []]);
        }
        $stmt = $db->prepare("SELECT id, username, access_level FROM staff_users WHERE is_active = 1 AND assistant_access = 1 AND property_id = ? ORDER BY username ASC");
        $stmt->execute([$propertyId]);
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($staff as &$u) {
            $u['role'] = $u['access_level']; // map access_level to role dynamically
        }
        ApiResponse::success(['staff' => $staff]);
    }

    // Action: Log in via property id, username, and 4-digit PIN
    elseif ($action === 'login') {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
        $pin = trim((string)($data['pin'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $loginPropertyId = (int)($data['property_id'] ?? 0);
        if ($loginPropertyId <= 0) {
            $loginPropertyId = $propertyId;
        }

        if (strlen($pin) !== 4 || !ctype_digit($pin)) {
            ApiResponse::error('Enter a 4-digit PIN');
        }
        if ($loginPropertyId <= 0 || ($userId <= 0 && $username === '')) {
            ApiResponse::error('Enter property ID, username, and PIN');
        }

        $propStmt = $db->prepare("SELECT id FROM properties WHERE id = ? LIMIT 1");
        $propStmt->execute([$loginPropertyId]);
        if (!$propStmt->fetchColumn()) {
            ApiResponse::error('Property ID not found');
        }

        $rateKey = $username !== '' ? $username : (string)$userId;
        $ipAddress = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (str_contains((string)$ipAddress, ',')) {
            $ipAddress = trim(explode(',', (string)$ipAddress)[0]);
        }
        $rateStmt = $db->prepare("
            SELECT COUNT(*) FROM login_attempts 
            WHERE (username = :username OR ip_address = :ip) 
            AND success = 0 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $rateStmt->execute(['username' => $rateKey, 'ip' => $ipAddress]);
        $failedAttempts = (int)$rateStmt->fetchColumn();

        if ($failedAttempts >= 5) {
            ApiResponse::error('Too many failed attempts. Please wait 15 minutes or contact your manager.');
        }

        $user = null;
        if ($userId > 0) {
            $stmt = $db->prepare("SELECT * FROM staff_users WHERE id = :id AND is_active = 1 AND assistant_access = 1 AND property_id = :pid");
            $stmt->execute(['id' => $userId, 'pid' => $loginPropertyId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        if (!$user && $username !== '') {
            try {
                $stmt = $db->prepare("
                    SELECT u.* FROM staff_users u
                    WHERE u.is_active = 1 AND u.assistant_access = 1
                      AND LOWER(u.username) = LOWER(:uname)
                      AND (
                        u.property_id = :pid
                        OR EXISTS (SELECT 1 FROM staff_properties sp WHERE sp.staff_id = u.id AND sp.property_id = :pid2)
                      )
                    LIMIT 1
                ");
                $stmt->execute(['uname' => $username, 'pid' => $loginPropertyId, 'pid2' => $loginPropertyId]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (\PDOException $e) {
                $stmt = $db->prepare("SELECT * FROM staff_users WHERE is_active = 1 AND assistant_access = 1 AND LOWER(username) = LOWER(?) AND property_id = ? LIMIT 1");
                $stmt->execute([$username, $loginPropertyId]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
        }

        if (!$user) {
            $db->prepare("INSERT INTO login_attempts (username, ip_address, success) VALUES (?, ?, 0)")
               ->execute([$rateKey, $ipAddress]);
            ApiResponse::error('Staff not found for this property, or assistant access is off');
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
            $_SESSION['user_id']          = $user['id'];
            $_SESSION['role']             = $user['access_level'] ?: 'manager';
            $_SESSION['access_level']     = $user['access_level'] ?: 'manager';
            $_SESSION['username']         = $user['username'];
            $_SESSION['staff_user']       = $user['username'];
            $_SESSION['primary_property_id'] = $loginPropertyId;
            $_SESSION['property_id'] = $loginPropertyId;
            AuthHelper::setPropertyId($loginPropertyId);

            AuthHelper::applyCustomPermissions($db, $user['role_id'] ?? null);
            $aRole = AuthHelper::assistantRoleAlias((string)($user['access_level'] ?? 'receptionist'));
            $_SESSION['assistant_role'] = $aRole;
            $permissions = AuthHelper::assistantUiPermissions($aRole);
            $_SESSION['assistant_permissions'] = $permissions;

            AuthHelper::issueRememberToken($db, (int)$user['id']);
            $csrfToken = CsrfToken::generate();

            ApiResponse::success([
                'message' => 'Logged in successfully',
                'user' => [
                    'id'             => $user['id'],
                    'username'       => $user['username'],
                    'role'           => $_SESSION['role'],
                    'assistant_role' => $aRole,
                    'pin_set'        => !empty($user['pin_hash']),
                    'permissions'    => $permissions,
                    'property_id'    => $loginPropertyId,
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
        $uid = (int)(AuthHelper::getCurrentUser()['id'] ?? 0);
        if ($uid > 0) {
            $stmt = $db->prepare("SELECT id, username, access_level, assistant_role, pin_hash, role_id, assistant_access, property_id FROM staff_users WHERE id = :id AND is_active = 1");
            $stmt->execute(['id' => $uid]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($user && (int)($user['assistant_access'] ?? 0) === 1) {
                $aRole = AuthHelper::assistantRoleAlias((string)($user['access_level'] ?? 'receptionist'));
                AuthHelper::applyCustomPermissions($db, $user['role_id'] ?? ($_SESSION['role_id'] ?? null));
                $permissions = AuthHelper::assistantUiPermissions($aRole);
                $_SESSION['assistant_role'] = $aRole;
                $_SESSION['assistant_permissions'] = $permissions;
                if (empty($_SESSION['property_id']) && (int)($user['property_id'] ?? 0) > 0) {
                    AuthHelper::setPropertyId((int)$user['property_id']);
                }
                AuthHelper::extendSessionCookie(2592000);
                ApiResponse::success([
                    'logged_in' => true,
                    'user' => [
                        'id'             => $user['id'],
                        'username'       => $user['username'],
                        'role'           => $user['access_level'],
                        'assistant_role' => $aRole,
                        'pin_set'        => !empty($user['pin_hash']),
                        'permissions'    => $permissions,
                        'property_id'    => (int)($_SESSION['property_id'] ?? $user['property_id'] ?? 0),
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
        $uid = (int)(AuthHelper::getCurrentUser()['id'] ?? 0);
        AuthHelper::revokeRememberTokens($db, $uid > 0 ? $uid : null);
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
