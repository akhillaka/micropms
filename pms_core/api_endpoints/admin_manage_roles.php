<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requireRole('owner', 'superadmin');
    $propId = AuthHelper::getPropertyId();
    $action = $_REQUEST['action'] ?? '';

    if ($action === 'list') {
        $stmt = $db->prepare("SELECT * FROM roles WHERE property_id = ? ORDER BY name ASC");
        $stmt->execute([$propId]);
        ApiResponse::success(['roles' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ApiResponse::error('Invalid action');
    }

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
        if ($name === '') {
            ApiResponse::error('Role name is required');
        }
        $stmt = $db->prepare("INSERT INTO roles (property_id, name, permissions) VALUES (?, ?, ?)");
        $stmt->execute([$propId, $name, json_encode($permissions)]);
        ApiResponse::success(['message' => 'Role created successfully']);
    }

    if ($action === 'update') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
        if ($name === '' || $roleId <= 0) {
            ApiResponse::error('Invalid data');
        }
        $check = $db->prepare("SELECT id FROM roles WHERE id = ? AND property_id = ?");
        $check->execute([$roleId, $propId]);
        if (!$check->fetch()) {
            ApiResponse::error('Role not found', 404);
        }
        $stmt = $db->prepare("UPDATE roles SET name = ?, permissions = ? WHERE id = ? AND property_id = ?");
        $stmt->execute([$name, json_encode($permissions), $roleId, $propId]);
        ApiResponse::success(['message' => 'Role updated successfully']);
    }

    if ($action === 'delete') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $check = $db->prepare("SELECT id FROM roles WHERE id = ? AND property_id = ?");
        $check->execute([$roleId, $propId]);
        if (!$check->fetch()) {
            ApiResponse::error('Role not found', 404);
        }
        $stmt = $db->prepare("DELETE FROM roles WHERE id = ? AND property_id = ?");
        $stmt->execute([$roleId, $propId]);
        ApiResponse::success(['message' => 'Role deleted successfully']);
    }

    ApiResponse::error('Invalid action');
}, true, $_SERVER['REQUEST_METHOD'] !== 'GET', false);
