<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
AuthHelper::requireOwner();

header('Content-Type: application/json');
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
CsrfToken::requireValid();

require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

$result = NotificationRelay::sendTestTelegram();
echo json_encode($result);
