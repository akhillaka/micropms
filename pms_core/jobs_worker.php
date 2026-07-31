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
            SELECT id, type, payload, attempts, max_attempts
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

        echo "Processing job {$job['id']} of type {$job['type']}...\n";

        // Process the job
        $payload = json_decode($job['payload'], true);
        $success = false;
        $errorMsg = null;

        try {
            switch ($job['type']) {
                case 'whatsapp':
                    $phone = $payload['phone'] ?? '';
                    $message = $payload['message'] ?? '';
                    $isHsm = $payload['is_hsm'] ?? false;
                    $res = NotificationRelay::sendWhatsAppSync($phone, $message, $isHsm);
                    $success = isset($res['success']) && $res['success'] === true;
                    if (!$success) {
                        $errorMsg = json_encode($res);
                    }
                    break;

                case 'telegram':
                    $message = $payload['message'] ?? '';
                    $res = NotificationRelay::sendTelegramSync($message);
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

        // Finalize job
        $db->beginTransaction();
        if ($success) {
            $stmt = $db->prepare("UPDATE jobs_queue SET status = 'completed', error_log = NULL WHERE id = ?");
            $stmt->execute([$job['id']]);
            echo "Job {$job['id']} completed successfully.\n";
        } else {
            if ($job['attempts'] >= $job['max_attempts']) {
                $stmt = $db->prepare("UPDATE jobs_queue SET status = 'failed', error_log = ? WHERE id = ?");
                $stmt->execute([$errorMsg, $job['id']]);
                echo "Job {$job['id']} failed permanently.\n";
            } else {
                // Retry in 1 minute
                $stmt = $db->prepare("UPDATE jobs_queue SET status = 'pending', available_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE), error_log = ? WHERE id = ?");
                $stmt->execute([$errorMsg, $job['id']]);
                echo "Job {$job['id']} failed, will retry.\n";
            }
        }
        $db->commit();

    } catch (\Throwable $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        echo "Worker Error: " . $e->getMessage() . "\n";
        sleep(5);
    }
}
