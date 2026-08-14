<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

class DeadLetterQueue {
    public static function getFailedJobs(?int $propertyId = null): array {
        $db = Database::getInstance()->getConnection();
        if ($propertyId === null) {
            require_once __DIR__ . '/../AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }
        
        $stmt = $db->prepare("SELECT * FROM jobs_queue WHERE dead_letter = 1 AND property_id = ? ORDER BY created_at DESC");
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    public static function retryJob(int $jobId, ?int $propertyId = null): bool {
        $db = Database::getInstance()->getConnection();
        if ($propertyId === null) {
            require_once __DIR__ . '/../AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }
        
        $stmt = $db->prepare("UPDATE jobs_queue SET status = 'pending', dead_letter = 0, attempts = 0, available_at = NOW() WHERE id = ? AND property_id = ? AND dead_letter = 1");
        return $stmt->execute([$jobId, $propertyId]);
    }
    
    public static function deleteJob(int $jobId, ?int $propertyId = null): bool {
        $db = Database::getInstance()->getConnection();
        if ($propertyId === null) {
            require_once __DIR__ . '/../AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }
        
        $stmt = $db->prepare("DELETE FROM jobs_queue WHERE id = ? AND property_id = ? AND dead_letter = 1");
        return $stmt->execute([$jobId, $propertyId]);
    }
}
