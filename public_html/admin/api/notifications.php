<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../pms_core/config.php';
require_once __DIR__ . '/../../../pms_core/Database.php';
require_once __DIR__ . '/../../../pms_core/AuthHelper.php';
AuthHelper::requireLogin();
header('Content-Type: application/json');
$db = Database::getInstance()->getConnection();
$propertyId = AuthHelper::getPropertyId();
AuthHelper::releaseSession();

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    // Fetch unread notifications + latest read notifications (limit 10)
    $stmt = $db->prepare("SELECT * FROM admin_notifications WHERE property_id = ? ORDER BY is_read ASC, created_at DESC LIMIT 10");
    $stmt->execute([$propertyId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $unreadCountStmt = $db->prepare("SELECT COUNT(*) FROM admin_notifications WHERE property_id = ? AND is_read = 0");
    $unreadCountStmt->execute([$propertyId]);
    $unreadCount = $unreadCountStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $notifications
    ]);
    exit;
}

if ($action === 'mark_read') {
    $id = $_POST['id'] ?? null;
    if ($id) {
        $stmt = $db->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ? AND property_id = ?");
        $stmt->execute([$id, $propertyId]);
    } else {
        // Mark all as read
        $stmt = $db->prepare("UPDATE admin_notifications SET is_read = 1 WHERE property_id = ? AND is_read = 0");
        $stmt->execute([$propertyId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_all') {
    $stmt = $db->prepare("DELETE FROM admin_notifications WHERE property_id = ? AND is_read = 1");
    $stmt->execute([$propertyId]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
