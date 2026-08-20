<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/TenantScope.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_staff');

    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? null;
    $propertyId = AuthHelper::getPropertyId();

    $resolveRole = function(string $roleInput) use ($db, $propertyId) {
        return AuthHelper::resolveStaffRoleInput($db, $propertyId, $roleInput);
    };

    $syncStaffAssignment = function(int $staffId, int $pid, ?int $roleId) use ($db) {
        try {
            $stmt = $db->prepare("INSERT INTO staff_properties (staff_id, property_id, role_id) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)");
            $stmt->execute([$staffId, $pid, $roleId]);
        } catch (\PDOException $e) {
        }
    };

    if ($action === 'add') {
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $pin = $data['pin'] ?? '';
        $roleInput = $data['role'] ?? 'manager';
        
        $resolvedRole = $resolveRole($roleInput);


        if (empty($username) || empty($password)) {
            throw new Exception("Username and password are required");
        }
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters");
        }
        if (!empty($pin) && !preg_match('/^\d{4}$/', $pin)) {
            throw new Exception("PIN must be exactly 4 digits");
        }
        // Enforce SaaS seat limit before adding a new staff member
        $propertyId = AuthHelper::getPropertyId();
        require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';
        SaaSEntitlementsService::checkStaffLimit($db, $propertyId);

        // Username must be unique within this property
        $stmt = $db->prepare("SELECT id FROM staff_users WHERE username = :u AND property_id = :pid");
        $stmt->execute(['u' => $username, 'pid' => $propertyId]);
        if ($stmt->fetch()) {
            throw new Exception("Username already exists");
        }

        $stmt = $db->prepare("INSERT INTO staff_users (property_id, username, password_hash, pin_hash, access_level, role, role_id, assistant_role, is_active) VALUES (:prop, :u, :p, :pin, :a, :r, :rid, :ar, 1)");
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pinHash = !empty($pin) ? password_hash($pin, PASSWORD_DEFAULT) : null;
        $stmt->execute([
            'prop' => $propertyId,
            'u' => $username, 
            'p' => $hash, 
            'pin' => $pinHash,
            'a' => $resolvedRole['access_level'],
            'r' => $resolvedRole['role_name'],
            'rid' => $resolvedRole['role_id'],
            'ar' => AuthHelper::assistantRoleAlias($resolvedRole['access_level']),
        ]);
        $newId = (int)$db->lastInsertId();
        $syncStaffAssignment($newId, $propertyId, $resolvedRole['role_id']);

        AuditLogger::log($_SESSION['user_id'] ?? null, 'ADD_USER', 'SYSTEM', (int)$newId, [
            'username' => $username,
            'role' => $roleInput
        ]);

        ApiResponse::success(['message' => 'User added successfully']);

    } elseif ($action === 'edit') {
        $userId = $data['user_id'] ?? null;
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $roleInput = $data['role'] ?? 'manager';
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        
        $resolvedRole = $resolveRole($roleInput);

        if (!$userId || empty($username)) {
            throw new Exception("User ID and username are required");
        }

        $targetUser = TenantScope::staff($db, (int)$userId, $propertyId);

        // Prevent modifying own active status or role
        if ((int)$userId === (int)$_SESSION['user_id'] && ($isActive === 0 || $resolvedRole['access_level'] !== $targetUser['access_level'])) {
            throw new Exception("Cannot deactivate or change the role of your own logged-in account");
        }

        // Prevent non-superadmins from modifying a superadmin account
        if ($targetUser['access_level'] === 'superadmin' && !AuthHelper::isSuperAdmin()) {
            throw new Exception("Access denied: You cannot modify a superadmin account");
        }

        // Username must be unique within this property (excluding self)
        $stmt = $db->prepare("SELECT id FROM staff_users WHERE username = :u AND id != :id AND property_id = :pid");
        $stmt->execute(['u' => $username, 'id' => $userId, 'pid' => $propertyId]);
        if ($stmt->fetch()) {
            throw new Exception("Username already taken by another user");
        }

        if (!empty($password)) {
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters");
            }
        }
        
        $pin = $data['pin'] ?? '';
        if (!empty($pin) && !preg_match('/^\d{4}$/', $pin)) {
            throw new Exception("PIN must be exactly 4 digits");
        }

        // Build dynamic query
        $updateFields = [
            "username = :u",
            "access_level = :a",
            "role = :r",
            "role_id = :rid",
            "assistant_role = :ar",
            "is_active = :ia"
        ];
        $params = [
            'u' => $username,
            'a' => $resolvedRole['access_level'],
            'r' => $resolvedRole['role_name'],
            'rid' => $resolvedRole['role_id'],
            'ar' => AuthHelper::assistantRoleAlias($resolvedRole['access_level']),
            'ia' => $isActive,
            'id' => $userId
        ];

        if (!empty($password)) {
            $updateFields[] = "password_hash = :p";
            $params['p'] = password_hash($password, PASSWORD_DEFAULT);
        }

        if (!empty($pin)) {
            $updateFields[] = "pin_hash = :pin";
            $params['pin'] = password_hash($pin, PASSWORD_DEFAULT);
        }

        $params['pid'] = $propertyId;
        $sql = "UPDATE staff_users SET " . implode(", ", $updateFields) . " WHERE id = :id AND property_id = :pid";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $syncStaffAssignment((int)$userId, $propertyId, $resolvedRole['role_id']);

        AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_USER', 'SYSTEM', (int)$userId, [
            'username' => $username,
            'role' => $roleInput,
            'is_active' => $isActive,
            'password_changed' => !empty($password),
            'pin_changed' => !empty($pin)
        ]);

        ApiResponse::success(['message' => 'User updated successfully']);

    } elseif ($action === 'delete') {
        $userId = $data['user_id'] ?? null;
        if (!$userId) {
            throw new Exception("User ID is required");
        }
        if ($userId == $_SESSION['user_id']) {
            throw new Exception("Cannot delete your own account");
        }

        $user = TenantScope::staff($db, (int)$userId, $propertyId);

        // Prevent non-superadmins from deleting a superadmin account
        if ($user['access_level'] === 'superadmin' && !AuthHelper::isSuperAdmin()) {
            throw new Exception("Access denied: You cannot delete a superadmin account");
        }

        // Soft delete: deactivate the user instead of hard delete to preserve historical integrity
        $stmt = $db->prepare("UPDATE staff_users SET is_active = 0 WHERE id = :id AND property_id = :pid");
        $stmt->execute(['id' => $userId, 'pid' => $propertyId]);

        AuditLogger::log($_SESSION['user_id'] ?? null, 'DELETE_USER', 'SYSTEM', $userId, [
            'username' => $user['username'],
            'status' => 'deactivated_soft_delete'
        ]);

        ApiResponse::success(['message' => 'User deactivated (soft-deleted)']);

    } elseif ($action === 'invite') {
        $email = trim($data['email'] ?? '');
        $roleInput = $data['role'] ?? 'manager';
        
        if (empty($email)) {
            throw new Exception("Email is required for invitation");
        }
        
        $resolvedRole = $resolveRole($roleInput);
        
        SaaSEntitlementsService::checkStaffLimit($db, $propertyId);
        
        $token = bin2hex(random_bytes(16));
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        $stmt = $db->prepare("INSERT INTO team_invitations (property_id, email, role, token, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$propertyId, $email, $roleInput, $token, $expires]);
        
        $proto = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $inviteLink = "{$proto}://{$host}/admin/accept_invite?token={$token}";
        
        AuditLogger::log($_SESSION['user_id'] ?? null, 'INVITE_USER', 'SYSTEM', null, [
            'email' => $email,
            'role' => $roleInput
        ]);
        
        ApiResponse::success([
            'message' => 'Invitation link generated successfully',
            'invite_link' => $inviteLink
        ]);
    } else {
        throw new Exception("Invalid action");
    }

}, true, true, false);


