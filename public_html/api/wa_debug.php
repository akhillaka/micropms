<?php
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireLogin();
if (!AuthHelper::isSuperAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$log = [
    'time'    => date('Y-m-d H:i:s'),
    'method'  => $_SERVER['REQUEST_METHOD'],
    'get'     => $_GET,
    'headers' => getallheaders(),
    'body'    => file_get_contents('php://input'),
];
file_put_contents('/tmp/wa_webhook_debug.log', json_encode($log, JSON_PRETTY_PRINT) . "\n\n---\n\n", FILE_APPEND);
http_response_code(200);
echo json_encode(['status' => 'logged']);
