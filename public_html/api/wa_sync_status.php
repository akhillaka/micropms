<?php
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';

AuthHelper::requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['log_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing log_id']);
    exit;
}

$logId = (int)$_GET['log_id'];
$db = Database::getInstance()->getConnection();
load_db_settings($db);

$stmt = $db->prepare("SELECT id, message_id, meta_status FROM wa_delivery_logs WHERE id = ? AND message_id IS NOT NULL AND status = 'success'");
$stmt->execute([$logId]);
$log = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$log) {
    echo json_encode(['success' => false, 'error' => 'Log not found or missing message_id']);
    exit;
}

$terminalStates = ['read', 'failed'];
if (in_array($log['meta_status'], $terminalStates)) {
    echo json_encode(['success' => true, 'meta_status' => $log['meta_status'], 'cached' => true]);
    exit;
}

$wamid = $log['message_id'];
$apiKey = defined('WHATSAPP_TOKEN') ? WHATSAPP_TOKEN : '';

if (empty($apiKey)) {
    echo json_encode(['success' => false, 'error' => 'API Key missing']);
    exit;
}

$url = "https://one.xpressbot.org/api/workspace/v1/whatsapp/message/status?wamid=" . urlencode($wamid);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-API-Key: " . $apiKey,
    "Authorization: Bearer " . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($res) {
    $data = json_decode($res, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($data['data']['status'])) {
        $metaStatus = $data['data']['status'];
        
        if ($metaStatus !== $log['meta_status']) {
            $upd = $db->prepare("UPDATE wa_delivery_logs SET meta_status = ? WHERE id = ?");
            $upd->execute([$metaStatus, $logId]);
        }
        
        echo json_encode(['success' => true, 'meta_status' => $metaStatus]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Failed to fetch from XpressBot']);
