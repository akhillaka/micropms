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
        $payloadJson = json_encode($payload);
        
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
                SELECT id, payload_json, attempts 
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
                'attempts' => (int)$job['attempts']
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
        
        $selStmt = $db->prepare("SELECT id, payload_json, attempts FROM jobs_queue WHERE id = ?");
        $selStmt->execute([$jobId]);
        $job = $selStmt->fetch();
        
        return [
            'id' => (int)$job['id'],
            'payload' => json_decode($job['payload_json'], true) ?? [],
            'attempts' => (int)$job['attempts']
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
        
        if ($attempts >= $maxAttempts) {
            $update = $db->prepare("UPDATE jobs_queue SET status = 'failed', attempts = ? WHERE id = ?");
            $update->execute([$attempts, $jobId]);
            
            if ($exception) {
                error_log("Job #$jobId failed permanently. Error: " . $exception->getMessage());
            }
        } else {
            // Exponential backoff delay (attempts^2 * 10 seconds)
            $delay = pow($attempts, 2) * 10;
            $availableAt = date('Y-m-d H:i:s', time() + $delay);
            
            $update = $db->prepare("UPDATE jobs_queue SET status = 'pending', attempts = ?, available_at = ? WHERE id = ?");
            $update->execute([$attempts, $availableAt, $jobId]);
        }
    }
}
