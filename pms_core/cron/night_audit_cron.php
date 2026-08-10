<?php
declare(strict_types=1);

// This script should be run via server CRON (e.g., every hour, or every 15 mins)
// It will queue the Night Audit job for any property where the current time is past their configured audit time
// and the audit has not yet run for the current business date.

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../services/NightAudit.php';

try {
    $db = Database::getInstance()->getConnection();

    // 1. Get properties that have night audit enabled
    $stmt = $db->query("
        SELECT s1.property_id, 
               s1.key_value as is_enabled, 
               s2.key_value as audit_time
        FROM system_settings s1
        LEFT JOIN system_settings s2 ON s1.property_id = s2.property_id AND s2.key_name = 'night_audit_time'
        WHERE s1.key_name = 'night_audit_enabled' AND s1.key_value = 'true'
    ");

    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $currentDate = date('Y-m-d');
    $currentTime = date('H:i');

    $queuedCount = 0;

    foreach ($properties as $prop) {
        $propertyId = (int)$prop['property_id'];
        $auditTime = $prop['audit_time'] ?: '02:00'; // default 2 AM

        // If the current time is past the scheduled audit time
        if ($currentTime >= $auditTime) {
            
            // Check if it already ran today
            $lastAudit = NightAudit::getLastAudit($db, $propertyId);
            $lastRunDate = null;
            
            if ($lastAudit && isset($lastAudit['audit_date'])) {
                // Determine if the last audit was for today's business date
                $lastRunDate = date('Y-m-d', strtotime($lastAudit['audit_date']));
            }

            if ($lastRunDate !== $currentDate) {
                // Queue the night audit job
                $payload = json_encode([
                    'job_type' => 'night_audit',
                    'run_by' => 'system_cron',
                    'property_id' => $propertyId
                ]);
                
                $queueStmt = $db->prepare("INSERT INTO jobs_queue (queue_name, property_id, payload_json) VALUES ('night_audit', ?, ?)");
                $queueStmt->execute([$propertyId, $payload]);
                
                echo "Queued Night Audit for Property ID: {$propertyId}\n";
                $queuedCount++;
            }
        }
    }

    echo "Night audit cron completed. Jobs queued: {$queuedCount}\n";

} catch (\Exception $e) {
    echo "CRON ERROR: " . $e->getMessage() . "\n";
}
