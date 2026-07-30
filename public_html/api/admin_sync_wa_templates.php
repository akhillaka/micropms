<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
if (php_sapi_name() !== 'cli') {
    AuthHelper::requireLogin();
}
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
load_db_settings(Database::getInstance()->getConnection());
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

header('Content-Type: application/json');

if (empty(WHATSAPP_TOKEN) || WHATSAPP_TOKEN === 'your_whatsapp_token_here') {
    echo json_encode(['success' => false, 'error' => 'WhatsApp Token is not configured.']);
    exit;
}
$baseUrl = defined('WHATSAPP_WABA_ID') && str_starts_with(WHATSAPP_WABA_ID, 'http') 
           ? rtrim(WHATSAPP_WABA_ID, '/')
           : 'https://one.xpressbot.org/api/workspace/v1';

$channelId = defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '';
$url = $baseUrl . '/whatsapp/templates/list?channelId=' . urlencode($channelId);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-Key: ' . WHATSAPP_TOKEN,
    'Authorization: Bearer ' . WHATSAPP_TOKEN
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Debug logging (development only)
if (getenv('APP_ENV') === 'development' || getenv('APP_ENV') === 'local') {
    file_put_contents(__DIR__ . '/wa_sync_debug.log', "HTTP CODE: $httpCode\nRESPONSE: $response\n");
}

if ($httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($response, true);
    
    $db = Database::getInstance()->getConnection();
    
    $successCount = 0;
    $templates = isset($data['data']) ? $data['data'] : (is_array($data) ? $data : []);
    
    $incomingNames = [];
    
    foreach ($templates as $template) {
        $name = $template['name'] ?? '';
        $language = is_array($template['language']) ? ($template['language']['code'] ?? '') : ($template['language'] ?? 'en');
        $status = $template['status'] ?? 'APPROVED';
        if (isset($template['components'])) {
            $components = json_encode($template['components']);
        } else {
            $compArray = [];
            if (!empty($template['header'])) {
                $compArray[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $template['header']];
            }
            if (!empty($template['body'])) {
                $compArray[] = ['type' => 'BODY', 'text' => $template['body']];
            }
            if (!empty($template['footer'])) {
                $compArray[] = ['type' => 'FOOTER', 'text' => $template['footer']];
            }
            $components = json_encode($compArray);
        }
        
        if (empty($name)) continue;

        $incomingNames[] = $name;

        $stmt = $db->prepare("
            INSERT INTO wa_templates (name, language, components_json, status) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE components_json = VALUES(components_json), status = VALUES(status), updated_at = NOW()
        ");
        if ($stmt->execute([$name, $language, $components, $status])) {
            $successCount++;
        }
    }
    
    // Clean up deleted templates
    if (!empty($incomingNames)) {
        $placeholders = implode(',', array_fill(0, count($incomingNames), '?'));
        $delStmt = $db->prepare("DELETE FROM wa_templates WHERE name NOT IN ($placeholders)");
        $delStmt->execute($incomingNames);
    } else if (isset($data['success']) && $data['success'] === true) {
        // If API succeeded but returned 0 templates, wipe the local table
        $db->query("DELETE FROM wa_templates");
    }
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'SYNC_WA_TEMPLATES', 'SYSTEM', null, ['templates_synced' => $successCount]);
    
    echo json_encode(['success' => true, 'count' => $successCount]);
} else {
    echo json_encode(['success' => false, 'error' => 'XpressBot API returned error: ' . $response]);
}
