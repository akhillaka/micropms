<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';

ApiHandler::run(function (\PDO $db) {
    if (!AuthHelper::can('send_whatsapp') && !AuthHelper::can('manage_automations') && !AuthHelper::can('manage_settings')) {
        ApiResponse::error('Forbidden', 403);
    }

    $propertyId = AuthHelper::getPropertyId();
    $eventsStmt = $db->query("SELECT * FROM wa_automation_events ORDER BY id ASC");
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $rulesStmt = $db->prepare("SELECT * FROM wa_automations WHERE property_id = ?");
    $rulesStmt->execute([$propertyId]);
    $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

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

    ApiResponse::success(['data' => $result]);
}, true, false, false);
