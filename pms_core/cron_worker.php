<?php
/**
 * Asynchronous Background Worker
 * Run this via OS Cron every minute:
 * * * * * * php /path/to/pms_core/cron_worker.php
 */
declare(strict_types=1);

// Limit execution time to avoid overlapping runs from consuming too much memory
// A single invocation runs for up to 55 seconds, processing jobs in a loop.
$endTime = time() + 55;

require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/NotificationRelay.php';
require_once __DIR__ . '/GoogleSheetService.php';

echo "Worker started at " . date('Y-m-d H:i:s') . "\n";

while (time() < $endTime) {
    $jobProcessed = false;
    
    // Process WhatsApp Queue
    $waJob = QueueService::pop('whatsapp');
    if ($waJob) {
        $jobProcessed = true;
        echo "Processing WhatsApp Job #{$waJob['id']}...\n";
        try {
            NotificationRelay::processWhatsAppJob($waJob['payload']);
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
            $success = GoogleSheetService::sendWebhook($gsJob['payload']);
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
    
    // Process Default Queue
    $defaultJob = QueueService::pop('default');
    if ($defaultJob) {
        $jobProcessed = true;
        echo "Processing Default Job #{$defaultJob['id']}...\n";
        try {
            // Assume payload contains 'class', 'method', 'args' for generic jobs
            $class = $defaultJob['payload']['class'] ?? null;
            $method = $defaultJob['payload']['method'] ?? null;
            $args = $defaultJob['payload']['args'] ?? [];
            
            if ($class && $method && is_callable([$class, $method])) {
                call_user_func_array([$class, $method], $args);
                QueueService::complete($defaultJob['id']);
                echo "Completed Default Job #{$defaultJob['id']}\n";
            } else {
                throw new \Exception("Invalid generic job payload or not callable");
            }
        } catch (\Throwable $e) {
            QueueService::fail($defaultJob['id'], $e);
            echo "Failed Default Job #{$defaultJob['id']}: " . $e->getMessage() . "\n";
        }
    }
    
    // If no jobs were processed in this loop, sleep for a bit to prevent CPU spinning
    if (!$jobProcessed) {
        usleep(500000); // 500ms
    }
}

echo "Worker shutting down at " . date('Y-m-d H:i:s') . "\n";
