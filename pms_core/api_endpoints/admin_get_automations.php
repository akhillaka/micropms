<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AutomationTemplates.php';

ApiHandler::run(function (\PDO $db) {
    if (!AuthHelper::can('send_whatsapp') && !AuthHelper::can('manage_automations') && !AuthHelper::can('manage_settings')) {
        ApiResponse::error('Forbidden', 403);
    }

    $propertyId = AuthHelper::getPropertyId();
    $eventsStmt = $db->query("SELECT * FROM wa_automation_events ORDER BY id ASC");
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    $rulesMap = [];
    try {
        $rulesStmt = $db->prepare("SELECT * FROM automation_rules WHERE property_id = ? AND deleted_at IS NULL");
        $rulesStmt->execute([$propertyId]);
        foreach ($rulesStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rulesMap[$r['event_key']] = $r;
        }
    } catch (\Throwable $e) {
        $rulesMap = [];
    }

    $result = [];
    foreach ($events as $ev) {
        $key = (string)$ev['event_key'];
        $rule = $rulesMap[$key] ?? [];
        $defaults = AutomationTemplates::forEvent($key);

        $savedEmailSubject = trim((string)($rule['email_subject'] ?? ''));
        $savedEmailBody = trim((string)($rule['email_body_html'] ?? ''));
        $savedTelegram = trim((string)($rule['telegram_body_text'] ?? ''));

        $waFromRules = !empty($rule['is_wa_active']) && !empty($rule['wa_template_id']);

        $result[] = [
            'event_name' => $ev['event_name'],
            'event_key' => $key,
            'is_wa_active' => $waFromRules ? 1 : 0,
            'wa_template_id' => $waFromRules ? $rule['wa_template_id'] : '',
            'wa_mapping_json' => $waFromRules ? ($rule['wa_mapping_json'] ?? '[]') : '[]',
            'is_email_active' => !empty($rule['is_email_active']) ? 1 : 0,
            'email_subject' => $savedEmailSubject !== '' ? $savedEmailSubject : $defaults['email_subject'],
            'email_body_html' => $savedEmailBody !== '' ? $savedEmailBody : $defaults['email_body_html'],
            'is_telegram_active' => !empty($rule['is_telegram_active']) ? 1 : 0,
            'telegram_body_text' => $savedTelegram !== '' ? $savedTelegram : $defaults['telegram_body_text'],
            'using_default_email' => $savedEmailSubject === '' && $savedEmailBody === '',
            'using_default_telegram' => $savedTelegram === '',
            'extra_variables' => AutomationTemplates::extraVariables($key),
            'defaults' => $defaults,
        ];
    }

    ApiResponse::success(['data' => $result]);
}, true, false, false);
