<?php
/**
 * Asynchronous Background Worker
 * Run this via OS Cron every minute:
 * * * * * * php /path/to/pms_core/cron_worker.php
 */
declare(strict_types=1);

// Limit execution time to avoid overlapping runs from consuming too much memory
// A single invocation runs for up to 55 seconds, processing jobs in a loop.
$endTime = time() + 20;

require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/NotificationRelay.php';
require_once __DIR__ . '/GoogleSheetService.php';

echo "Worker started at " . date('Y-m-d H:i:s') . "\n";

// Recover any jobs that crashed mid-processing
try {
    QueueService::recoverStuckJobs();
} catch (\Throwable $t) {
    error_log("Failed to recover stuck jobs: " . $t->getMessage());
}

while (time() < $endTime) {
    $jobProcessed = false;
    
    // Process WhatsApp Queue
    $waJob = QueueService::pop('whatsapp');
    if ($waJob) {
        $jobProcessed = true;
        echo "Processing WhatsApp Job #{$waJob['id']}...\n";
        try {
            NotificationRelay::processWhatsAppJob($waJob['payload'], $waJob['property_id'] ?? null);
            QueueService::complete($waJob['id']);
            echo "Completed WhatsApp Job #{$waJob['id']}\n";
        } catch (\Throwable $e) {
            QueueService::fail($waJob['id'], $e);
            echo "Failed WhatsApp Job #{$waJob['id']}: " . $e->getMessage() . "\n";
        }
    }
    
    // Process Google Sheets Queue
    $gsJob = QueueService::pop('google_sheets');
    if ($gsJob) {
        $jobProcessed = true;
        echo "Processing Google Sheets Job #{$gsJob['id']}...\n";
        try {
            $gsProp = isset($gsJob['property_id']) ? (int)$gsJob['property_id'] : null;
            $success = GoogleSheetService::sendWebhook($gsJob['payload'], $gsProp && $gsProp > 0 ? $gsProp : null);
            if ($success) {
                QueueService::complete($gsJob['id']);
                echo "Completed Google Sheets Job #{$gsJob['id']}\n";
            } else {
                throw new \Exception("Google Sheets Webhook returned failure");
            }
        } catch (\Throwable $e) {
            QueueService::fail($gsJob['id'], $e);
            echo "Failed Google Sheets Job #{$gsJob['id']}: " . $e->getMessage() . "\n";
        }
    }

    // Process Email Queue
    $emailJob = QueueService::pop('email');
    if ($emailJob) {
        $jobProcessed = true;
        echo "Processing Email Job #{$emailJob['id']}...\n";
        error_log("[CronWorker] Processing Email Job #{$emailJob['id']}");
        try {
            require_once __DIR__ . '/helpers/EmailHelper.php';
            $to      = $emailJob['payload']['to'] ?? '';
            $subject = $emailJob['payload']['subject'] ?? '';
            $body    = $emailJob['payload']['body'] ?? '';
            if (empty($to)) throw new \Exception('No recipient address in email job payload');
            $ok = EmailHelper::send($to, $subject, $body, true);
            if (!$ok) throw new \Exception('EmailHelper::send() returned false');
            QueueService::complete($emailJob['id']);
            echo "Completed Email Job #{$emailJob['id']}\n";
            error_log("[CronWorker] Completed Email Job #{$emailJob['id']}");
        } catch (\Throwable $e) {
            QueueService::fail($emailJob['id'], $e);
            echo "Failed Email Job #{$emailJob['id']}: " . $e->getMessage() . "\n";
            error_log("[CronWorker] Failed Email Job #{$emailJob['id']}: " . $e->getMessage());
        }
    }

    // Process Telegram Queue
    $tgJob = QueueService::pop('telegram');
    if ($tgJob) {
        $jobProcessed = true;
        echo "Processing Telegram Job #{$tgJob['id']}...\n";
        error_log("[CronWorker] Processing Telegram Job #{$tgJob['id']}");
        try {
            $message = $tgJob['payload']['message'] ?? '';
            if (empty($message)) throw new \Exception('No message in telegram job payload');
            $ok = NotificationRelay::sendTelegramSync($message, null, [], $tgJob['property_id'] ?? null);
            if (!$ok) throw new \Exception('sendTelegramSync() returned false');
            QueueService::complete($tgJob['id']);
            echo "Completed Telegram Job #{$tgJob['id']}\n";
            error_log("[CronWorker] Completed Telegram Job #{$tgJob['id']}");
            usleep(35000);
        } catch (\Throwable $e) {
            QueueService::fail($tgJob['id'], $e);
            echo "Failed Telegram Job #{$tgJob['id']}: " . $e->getMessage() . "\n";
            error_log("[CronWorker] Failed Telegram Job #{$tgJob['id']}: " . $e->getMessage());
        }
    }

    
    // Process Default Queue (allowlisted handlers only)
    $defaultJob = QueueService::pop('default');
    if ($defaultJob) {
        $jobProcessed = true;
        echo "Processing Default Job #{$defaultJob['id']}...\n";
        try {
            $allowed = [
                'NotificationRelay::processWhatsAppJob' => [NotificationRelay::class, 'processWhatsAppJob'],
                'NotificationRelay::sendTelegramSync' => [NotificationRelay::class, 'sendTelegramSync'],
                'GoogleSheetService::sendWebhook' => [GoogleSheetService::class, 'sendWebhook'],
            ];
            $class = $defaultJob['payload']['class'] ?? null;
            $method = $defaultJob['payload']['method'] ?? null;
            $key = is_string($class) && is_string($method) ? ($class . '::' . $method) : '';
            if ($key === '' || !isset($allowed[$key])) {
                throw new \Exception('Rejected non-allowlisted generic job');
            }
            $args = $defaultJob['payload']['args'] ?? [];
            if (!is_array($args)) {
                $args = [];
            }
            call_user_func_array($allowed[$key], $args);
            QueueService::complete($defaultJob['id']);
            echo "Completed Default Job #{$defaultJob['id']}\n";
        } catch (\Throwable $e) {
            QueueService::fail($defaultJob['id'], $e);
            echo "Failed Default Job #{$defaultJob['id']}: " . $e->getMessage() . "\n";
        }
    }
    
    // If no jobs were processed in this loop, sleep for a bit to prevent CPU spinning
    if (!$jobProcessed) {
        break;
    }
}

echo "Worker shutting down at " . date('Y-m-d H:i:s') . "\n";
