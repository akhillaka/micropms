<?php
require_once __DIR__ . '/../AuthHelper.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
AuthHelper::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$propertyId = AuthHelper::getPropertyId();
$eventKey = $_POST['event_key'] ?? '';

if (empty($eventKey)) {
    echo json_encode(['success' => false, 'error' => 'Event key is required']);
    exit;
}

$isWaActive = isset($_POST['is_wa_active']) && $_POST['is_wa_active'] == '1' ? 1 : 0;
$waTemplateId = !empty($_POST['wa_template_id']) ? (int)$_POST['wa_template_id'] : null;
$waMapping = $_POST['wa_mapping_json'] ?? '[]';

$isEmailActive = isset($_POST['is_email_active']) && $_POST['is_email_active'] == '1' ? 1 : 0;
$emailSubject = $_POST['email_subject'] ?? '';
$emailBody = $_POST['email_body_html'] ?? '';

$isTelegramActive = isset($_POST['is_telegram_active']) && $_POST['is_telegram_active'] == '1' ? 1 : 0;
$telegramBody = $_POST['telegram_body_text'] ?? '';

try {
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("
        INSERT INTO automation_rules 
        (property_id, event_key, is_wa_active, wa_template_id, wa_mapping_json, is_email_active, email_subject, email_body_html, is_telegram_active, telegram_body_text)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        is_wa_active = VALUES(is_wa_active),
        wa_template_id = VALUES(wa_template_id),
        wa_mapping_json = VALUES(wa_mapping_json),
        is_email_active = VALUES(is_email_active),
        email_subject = VALUES(email_subject),
        email_body_html = VALUES(email_body_html),
        is_telegram_active = VALUES(is_telegram_active),
        telegram_body_text = VALUES(telegram_body_text)
    ");
    
    $stmt->execute([
        $propertyId,
        $eventKey,
        $isWaActive,
        $waTemplateId,
        $waMapping,
        $isEmailActive,
        $emailSubject,
        $emailBody,
        $isTelegramActive,
        $telegramBody
    ]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("Failed to save automation: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
