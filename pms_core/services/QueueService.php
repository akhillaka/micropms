<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

class QueueService {

    /**
     * Push a new job onto the queue.
     */
    public static function push(string $queueName, array $payload, int $delaySeconds = 0, ?int $propertyId = null): int {
        $db = Database::getInstance()->getConnection();
        
        $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payloadJson === false) {
            throw new \RuntimeException('Could not encode job payload as JSON');
        }
        
        $stmt = $db->prepare("
            INSERT INTO jobs_queue (queue_name, property_id, payload_json, status, available_at)
            VALUES (?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([$queueName, $propertyId, $payloadJson, $availableAt]);
        
        return (int)$db->lastInsertId();
    }

    /**
     * Pop a job from the queue safely, avoiding race conditions.
     */
    public static function pop(string $queueName = 'default'): ?array {
        $db = Database::getInstance()->getConnection();
        
        $db->beginTransaction();
        
        try {
            // Attempt to use MySQL 8.0 SKIP LOCKED for high concurrency
            $stmt = $db->prepare("
                SELECT id, payload_json, attempts, property_id 
                FROM jobs_queue 
                WHERE status = 'pending' 
                  AND queue_name = ? 
                  AND available_at <= NOW() 
                ORDER BY id ASC 
                LIMIT 1 
                FOR UPDATE SKIP LOCKED
            ");
            $stmt->execute([$queueName]);
            $job = $stmt->fetch();
            
            if (!$job) {
                $db->commit();
                return null;
            }
            
            // Mark as processing
            $updateStmt = $db->prepare("UPDATE jobs_queue SET status = 'processing' WHERE id = ?");
            $updateStmt->execute([$job['id']]);
            
            $db->commit();
            
            return [
                'id' => (int)$job['id'],
                'payload' => json_decode($job['payload_json'], true) ?? [],
                'attempts' => (int)$job['attempts'],
                'property_id' => isset($job['property_id']) ? (int)$job['property_id'] : null
            ];
            
        } catch (\PDOException $e) {
            $db->rollBack();
            
            // Fallback for MySQL 5.7 where SKIP LOCKED is not supported
            if (strpos($e->getMessage(), 'SKIP LOCKED') !== false || strpos($e->getMessage(), 'syntax error') !== false) {
                return self::popFallback($queueName);
            }
            throw $e;
        }
    }
    
    /**
     * Fallback popping mechanism for MySQL 5.7
     */
    private static function popFallback(string $queueName): ?array {
        $db = Database::getInstance()->getConnection();
        
        // This relies on UPDATE ... LIMIT 1 which is atomic
        $db->query("SET @update_id := 0");
        $stmt = $db->prepare("
            UPDATE jobs_queue 
            SET status = 'processing', id = (SELECT @update_id := id)
            WHERE status = 'pending' 
              AND queue_name = ? 
              AND available_at <= NOW()
            ORDER BY id ASC 
            LIMIT 1
        ");
        $stmt->execute([$queueName]);
        
        $idStmt = $db->query("SELECT @update_id");
        $jobId = (int)$idStmt->fetchColumn();
        
        if ($jobId <= 0) {
            return null;
        }
        
        $selStmt = $db->prepare("SELECT id, payload_json, attempts, property_id FROM jobs_queue WHERE id = ?");
        $selStmt->execute([$jobId]);
        $job = $selStmt->fetch();
        
        return [
            'id' => (int)$job['id'],
            'payload' => json_decode($job['payload_json'], true) ?? [],
            'attempts' => (int)$job['attempts'],
            'property_id' => isset($job['property_id']) ? (int)$job['property_id'] : null
        ];
    }

    /**
     * Mark a job as completed.
     */
    public static function complete(int $jobId): void {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE jobs_queue SET status = 'completed' WHERE id = ?");
        $stmt->execute([$jobId]);
    }

    /**
     * Mark a job as failed, with automatic retries.
     */
    public static function fail(int $jobId, ?\Throwable $exception = null): void {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("SELECT attempts, max_attempts FROM jobs_queue WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        
        if (!$job) return;
        
        $attempts = (int)$job['attempts'] + 1;
        $maxAttempts = (int)$job['max_attempts'];
        $errorMessage = $exception ? substr($exception->getMessage(), 0, 500) : 'Unknown error';
        
        if ($attempts >= $maxAttempts) {
            $update = $db->prepare("UPDATE jobs_queue SET status = 'failed', attempts = ?, dead_letter = 1, error_log = ? WHERE id = ?");
            $update->execute([$attempts, $errorMessage, $jobId]);
            
            if ($exception) {
                error_log("Job #$jobId failed permanently. Error: " . $exception->getMessage());
            }
        } else {
            // Exponential backoff delay (attempts^2 * 10 seconds)
            $delay = pow($attempts, 2) * 10;
            $availableAt = date('Y-m-d H:i:s', time() + $delay);
            
            $update = $db->prepare("UPDATE jobs_queue SET status = 'pending', attempts = ?, available_at = ?, error_log = ? WHERE id = ?");
            $update->execute([$attempts, $availableAt, $errorMessage, $jobId]);
        }
    }

    /**
     * Recover stuck jobs that have been processing for more than 15 minutes.
     */
    public static function recoverStuckJobs(): void {
        $db = Database::getInstance()->getConnection();
        // Reset jobs stuck in processing for > 15 minutes back to pending
        $db->exec("UPDATE jobs_queue SET status = 'pending', available_at = NOW() WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    }

    /**
     * Process a few telegram jobs quickly after a request commits.
     * Web Push stays on cron_worker — multi-device fan-out must not block HTTP.
     */
    public static function drainNotifyQueues(int $maxJobs = 4, int $budgetMs = 800): int {
        $start = (int)(microtime(true) * 1000);
        $done = 0;

        require_once __DIR__ . '/../NotificationRelay.php';

        while ($done < $maxJobs) {
            if (((int)(microtime(true) * 1000) - $start) >= $budgetMs) {
                break;
            }
            $job = self::pop('telegram');
            if (!$job) {
                break;
            }

            try {
                $message = (string)($job['payload']['message'] ?? '');
                if ($message === '') {
                    throw new \RuntimeException('Empty telegram payload');
                }
                $ok = NotificationRelay::sendTelegramSync($message, null, [], $job['property_id'] ?? null);
                if (!$ok) {
                    throw new \RuntimeException('Telegram sync send failed');
                }
                self::complete($job['id']);
                $done++;
            } catch (\Throwable $e) {
                self::fail($job['id'], $e);
                $done++;
            }
        }

        return $done;
    }
}
