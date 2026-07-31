<?php
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';

AuthHelper::requireRole('owner', 'superadmin'); // Only owners/superadmins can manage roles

header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();
$propId = AuthHelper::getPropertyId();
$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'list') {
        $stmt = $db->prepare("SELECT * FROM roles WHERE property_id = ? ORDER BY name ASC");
        $stmt->execute([$propId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'roles' => $roles]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
            
            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Role name is required']);
                exit;
            }

            $stmt = $db->prepare("INSERT INTO roles (property_id, name, permissions) VALUES (?, ?, ?)");
            $stmt->execute([$propId, $name, json_encode($permissions)]);
            
            echo json_encode(['success' => true, 'message' => 'Role created successfully']);
            exit;
        }

        if ($action === 'update') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
            
            if (empty($name) || $roleId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid data']);
                exit;
            }

            // Verify ownership
            $check = $db->prepare("SELECT id FROM roles WHERE id = ? AND property_id = ?");
            $check->execute([$roleId, $propId]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Role not found']);
                exit;
            }

            $stmt = $db->prepare("UPDATE roles SET name = ?, permissions = ? WHERE id = ?");
            $stmt->execute([$name, json_encode($permissions), $roleId]);
            
            echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
            exit;
        }

        if ($action === 'delete') {
            $roleId = (int)($_POST['role_id'] ?? 0);
            
            // Verify ownership
            $check = $db->prepare("SELECT id FROM roles WHERE id = ? AND property_id = ?");
            $check->execute([$roleId, $propId]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Role not found']);
                exit;
            }
            
            // Delete (ON DELETE SET NULL ensures staff_users.role_id is cleared)
            $stmt = $db->prepare("DELETE FROM roles WHERE id = ?");
            $stmt->execute([$roleId]);
            
            echo json_encode(['success' => true, 'message' => 'Role deleted successfully']);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
