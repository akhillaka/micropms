<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_staff');

    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? null;
    $validRoles = ['owner', 'admin', 'manager', 'receptionist', 'housekeeping'];


    if ($action === 'add') {
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $pin = $data['pin'] ?? '';
        $role = $data['role'] ?? 'manager';

        if (empty($username) || empty($password)) {
            throw new Exception("Username and password are required");
        }
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters");
        }
        if (!empty($pin) && !preg_match('/^\d{4}$/', $pin)) {
            throw new Exception("PIN must be exactly 4 digits");
        }
        if (!in_array($role, $validRoles, true)) {
            throw new Exception("Invalid role selection");
        }

        // Enforce SaaS seat limit before adding a new staff member
        $propertyId = AuthHelper::getPropertyId();
        require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';
        SaaSEntitlementsService::checkStaffLimit($db, $propertyId);

        $stmt = $db->prepare("SELECT id FROM staff_users WHERE username = :u");
        $stmt->execute(['u' => $username]);
        if ($stmt->fetch()) {
            throw new Exception("Username already exists");
        }

        $stmt = $db->prepare("INSERT INTO staff_users (property_id, username, password_hash, pin_hash, access_level, role, is_active) VALUES (:prop, :u, :p, :pin, :a, :r, 1)");
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pinHash = !empty($pin) ? password_hash($pin, PASSWORD_DEFAULT) : null;
        $stmt->execute([
            'prop' => $propertyId,
            'u' => $username, 
            'p' => $hash, 
            'pin' => $pinHash,
            'a' => $role, // Sync access_level for safety
            'r' => $role
        ]);
        $newId = $db->lastInsertId();

        AuditLogger::log($_SESSION['user_id'], 'ADD_USER', 'SYSTEM', $newId, [
            'username' => $username,
            'role' => $role
        ]);

        ApiResponse::success(['message' => 'User added successfully']);

    } elseif ($action === 'edit') {
        $userId = $data['user_id'] ?? null;
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $role = $data['role'] ?? 'manager';
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        if (!$userId || empty($username)) {
            throw new Exception("User ID and username are required");
        }
        if (!in_array($role, $validRoles, true)) {
            throw new Exception("Invalid role selection");
        }

        // Get target user details
        $stmt = $db->prepare("SELECT access_level, username FROM staff_users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            throw new Exception("User not found");
        }

        // Prevent modifying own active status or role
        if ((int)$userId === (int)$_SESSION['user_id'] && ($isActive === 0 || $role !== $targetUser['access_level'])) {
            throw new Exception("Cannot deactivate or change the role of your own logged-in account");
        }

        $stmt = $db->prepare("SELECT id FROM staff_users WHERE username = :u AND id != :id");
        $stmt->execute(['u' => $username, 'id' => $userId]);
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
            "is_active = :ia"
        ];
        $params = [
            'u' => $username,
            'a' => $role,
            'r' => $role,
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

        $sql = "UPDATE staff_users SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        AuditLogger::log($_SESSION['user_id'], 'EDIT_USER', 'SYSTEM', $userId, [
            'username' => $username,
            'role' => $role,
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

        $stmt = $db->prepare("SELECT username FROM staff_users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new Exception("User not found");
        }

        // Soft delete: deactivate the user instead of hard delete to preserve historical integrity
        $stmt = $db->prepare("UPDATE staff_users SET is_active = 0 WHERE id = :id");
        $stmt->execute(['id' => $userId]);

        AuditLogger::log($_SESSION['user_id'], 'DELETE_USER', 'SYSTEM', $userId, [
            'username' => $user['username'],
            'status' => 'deactivated_soft_delete'
        ]);

        ApiResponse::success(['message' => 'User deactivated (soft-deleted)']);

    } elseif ($action === 'invite') {
        $email = trim($data['email'] ?? '');
        $role = $data['role'] ?? 'manager';
        
        if (empty($email)) {
            throw new Exception("Email is required for invitation");
        }
        if (!in_array($role, $validRoles, true)) {
            throw new Exception("Invalid role selection");
        }
        
        $propertyId = AuthHelper::getPropertyId();
        
        require_once __DIR__ . '/../../pms_core/services/SaaSEntitlementsService.php';
        SaaSEntitlementsService::checkStaffLimit($db, $propertyId);
        
        $token = bin2hex(random_bytes(16));
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        $stmt = $db->prepare("INSERT INTO team_invitations (property_id, email, role, token, expires_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$propertyId, $email, $role, $token, $expires]);
        
        $proto = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $inviteLink = "{$proto}://{$host}/admin/accept_invite.php?token={$token}";
        
        AuditLogger::log($_SESSION['user_id'], 'INVITE_USER', 'SYSTEM', null, [
            'email' => $email,
            'role' => $role
        ]);
        
        ApiResponse::success([
            'message' => 'Invitation link generated successfully',
            'invite_link' => $inviteLink
        ]);
    } else {
        throw new Exception("Invalid action");
    }

}, false, true, false);


