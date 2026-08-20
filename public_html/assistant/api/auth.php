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

        if ($propertyId <= 0) {
            ApiResponse::error('No property context. Reload the assistant from your hotel link.');
        }

        $stmt = $db->prepare("SELECT * FROM staff_users WHERE id = :id AND is_active = 1 AND assistant_access = 1 AND property_id = :pid");
        $stmt->execute(['id' => $userId, 'pid' => $propertyId]);
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
            $_SESSION['user_id']          = $user['id'];
            $_SESSION['role']             = $user['access_level'] ?: 'manager';
            $_SESSION['access_level']     = $user['access_level'] ?: 'manager';
            $_SESSION['username']         = $user['username'];
            $_SESSION['staff_user']       = $user['username'];
            $_SESSION['primary_property_id'] = (int)($user['property_id'] ?? 0);
            $_SESSION['property_id'] = (int)($user['property_id'] ?? 0);
            if ($_SESSION['property_id'] <= 0 && $propertyId > 0) {
                $_SESSION['property_id'] = $propertyId;
            }
            if ($_SESSION['property_id'] > 0) {
                AuthHelper::setPropertyId((int)$_SESSION['property_id']);
            }

            AuthHelper::applyCustomPermissions($db, $user['role_id'] ?? null);
            $aRole = AuthHelper::assistantRoleAlias((string)($user['access_level'] ?? 'receptionist'));
            $_SESSION['assistant_role'] = $aRole;
            $permissions = AuthHelper::assistantUiPermissions($aRole);
            $_SESSION['assistant_permissions'] = $permissions;

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
            $stmt = $db->prepare("SELECT id, username, access_level, assistant_role, pin_hash, role_id FROM staff_users WHERE id = :id AND is_active = 1");
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();

            if ($user) {
                $aRole = AuthHelper::assistantRoleAlias((string)($user['access_level'] ?? 'receptionist'));
                AuthHelper::applyCustomPermissions($db, $user['role_id'] ?? ($_SESSION['role_id'] ?? null));
                $permissions = AuthHelper::assistantUiPermissions($aRole);
                $_SESSION['assistant_role'] = $aRole;
                $_SESSION['assistant_permissions'] = $permissions;
                ApiResponse::success([
                    'logged_in' => true,
                    'user' => [
                        'id'             => $user['id'],
                        'username'       => $user['username'],
                        'role'           => $user['access_level'],
                        'assistant_role' => $aRole,
                        'pin_set'        => !empty($user['pin_hash']),
                        'permissions'    => $permissions,
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
