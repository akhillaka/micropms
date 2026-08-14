<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/NotificationRelay.php';

echo "Worker started. Waiting for jobs...\n";

while (true) {
    try {
        $db = Database::getInstance();
        
        $db->beginTransaction();

        // Fetch a pending job using FOR UPDATE SKIP LOCKED
        $stmt = $db->query("
            SELECT id, queue_name AS type, property_id, payload_json AS payload, attempts, max_attempts
            FROM jobs_queue
            WHERE status = 'pending' AND available_at <= NOW()
            ORDER BY created_at ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        ");
        
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$job) {
            $db->rollBack();
            sleep(2); // Wait 2 seconds if no jobs
            continue;
        }

        // Mark as processing
        $updateStmt = $db->prepare("UPDATE jobs_queue SET status = 'processing', attempts = attempts + 1 WHERE id = ?");
        $updateStmt->execute([$job['id']]);
        $db->commit();

        $msg = "[Worker] Processing job {$job['id']} of type {$job['type']}...";
        echo $msg . "\n";
        error_log($msg);

        // Process the job
        $payload = json_decode($job['payload'], true);
        $success = false;
        $errorMsg = null;
        $propertyId = (int)$job['property_id'];

        try {
            switch ($job['type']) {
                case 'whatsapp':
                    // Use processWhatsAppJob which reads the new phoneNumber/payload format
                    // and handles delivery logging + audit trail internally.
                    $res = NotificationRelay::processWhatsAppJob($payload);
                    $success = true; // processWhatsAppJob throws on failure
                    break;

                case 'email':
                    require_once __DIR__ . '/helpers/EmailHelper.php';
                    $to = $payload['to'] ?? '';
                    $subject = $payload['subject'] ?? '';
                    $body = $payload['body'] ?? '';
                    $res = EmailHelper::send($to, $subject, $body, true);
                    $success = $res;
                    if (!$success) {
                        $errorMsg = "Email dispatch failed.";
                    }
                    break;

                case 'telegram':
                    $message = $payload['message'] ?? '';
                    $res = NotificationRelay::sendTelegramSync($message, null, [], $propertyId);
                    $success = $res;
                    if (!$success) {
                        $errorMsg = "Telegram API failed.";
                    }
                    break;

                default:
                    throw new \Exception("Unknown job type: {$job['type']}");
            }
        } catch (\Throwable $e) {
            $success = false;
            $errorMsg = $e->getMessage();
        }

        $db->beginTransaction();
        if ($success) {
            $stmt = $db->prepare("UPDATE jobs_queue SET status = 'completed', error_log = NULL WHERE id = ?");
            $stmt->execute([$job['id']]);
            $msg = "[Worker] Job {$job['id']} completed successfully.";
            echo $msg . "\n";
            error_log($msg);
        } else {
            if (($job['attempts'] + 1) >= $job['max_attempts']) {
                $stmt = $db->prepare("UPDATE jobs_queue SET status = 'failed', dead_letter = 1, error_log = ? WHERE id = ?");
                $stmt->execute([$errorMsg, $job['id']]);
                $msg = "[Worker] Job {$job['id']} failed permanently and moved to DLQ. Error: {$errorMsg}";
                echo $msg . "\n";
                error_log($msg);
            } else {
                // Exponential backoff: attempts^2 * 10 seconds
                $delay = (int)pow($job['attempts'] + 1, 2) * 10;
                $stmt = $db->prepare("UPDATE jobs_queue SET status = 'pending', available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), error_log = ? WHERE id = ?");
                $stmt->execute([$delay, $errorMsg, $job['id']]);
                $msg = "[Worker] Job {$job['id']} failed, will retry in {$delay}s. Error: {$errorMsg}";
                echo $msg . "\n";
                error_log($msg);
            }
        }
        $db->commit();

    } catch (\Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $msg = "[Worker] Error: " . $e->getMessage();
        echo $msg . "\n";
        error_log($msg);
        sleep(5);
    }
}
