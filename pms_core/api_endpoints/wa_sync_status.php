<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/config.php';

ApiHandler::run(function (\PDO $db) {
    AuthHelper::requireLogin();
    if (!isset($_GET['log_id'])) {
        ApiResponse::error('Missing log_id');
    }

    $logId = (int)$_GET['log_id'];
    $propertyId = AuthHelper::getPropertyId();
    load_db_settings($db, $propertyId);

    $stmt = $db->prepare("SELECT id, message_id, meta_status FROM wa_delivery_logs WHERE id = ? AND property_id = ? AND message_id IS NOT NULL AND status = 'success'");
    $stmt->execute([$logId, $propertyId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        ApiResponse::error('Log not found or missing message_id', 404);
    }

    $terminalStates = ['read', 'failed'];
    if (in_array($log['meta_status'], $terminalStates, true)) {
        ApiResponse::success(['meta_status' => $log['meta_status'], 'cached' => true]);
    }

    $wamid = $log['message_id'];
    $apiKey = defined('WHATSAPP_TOKEN') ? WHATSAPP_TOKEN : '';
    if ($apiKey === '') {
        ApiResponse::error('API Key missing');
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
                $upd = $db->prepare("UPDATE wa_delivery_logs SET meta_status = ? WHERE id = ? AND property_id = ?");
                $upd->execute([$metaStatus, $logId, $propertyId]);
            }
            ApiResponse::success(['meta_status' => $metaStatus]);
        }
    }

    ApiResponse::error('Failed to fetch from XpressBot');
}, true, false, false);
