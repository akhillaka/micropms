<?php
require_once __DIR__ . '/../AuthHelper.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
AuthHelper::requireLogin();

$propertyId = AuthHelper::getPropertyId();

try {
    $db = Database::getInstance()->getConnection();
    
    // Fetch all events
    $eventsStmt = $db->query("SELECT * FROM wa_automation_events ORDER BY id ASC");
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch configured rules
    $rulesStmt = $db->prepare("SELECT * FROM wa_automations WHERE property_id = ?");
    $rulesStmt->execute([$propertyId]);
    $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Map rules by event_key
    $rulesMap = [];
    foreach ($rules as $r) {
        $rulesMap[$r['event_key']] = $r;
    }
    
    $result = [];
    foreach ($events as $ev) {
        $rule = $rulesMap[$ev['event_key']] ?? null;
        $result[] = [
            'event_name' => $ev['event_name'],
            'event_key' => $ev['event_key'],
            'is_wa_active' => ($rule && $rule['status'] === 'active') ? 1 : 0,
            'wa_template_id' => $rule ? $rule['template_id'] : '',
            'wa_mapping_json' => $rule ? $rule['variable_mapping_json'] : '[]',
            'is_email_active' => 0,
            'email_subject' => '',
            'email_body_html' => '',
            'is_telegram_active' => 0,
            'telegram_body_text' => '',
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    error_log("Failed to fetch automations: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
