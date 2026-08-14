<?php
declare(strict_types=1);

// Run via server CRON (e.g. every 15 minutes).
// Executes night audit in-process — jobs_queue is not processed for this job type.

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../services/NightAudit.php';

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->query("
        SELECT s1.property_id,
               s2.key_value as audit_time
        FROM system_settings s1
        LEFT JOIN system_settings s2 ON s1.property_id = s2.property_id AND s2.key_name = 'night_audit_time'
        WHERE s1.key_name = 'night_audit_enabled' AND s1.key_value = 'true'
    ");

    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hour = (int)date('G');
    $currentDate = ($hour < 6) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
    $currentTime = date('H:i');

    $ranCount = 0;

    foreach ($properties as $prop) {
        $propertyId = (int)$prop['property_id'];
        $auditTime = $prop['audit_time'] ?: '02:00';

        if ($currentTime < $auditTime) {
            continue;
        }

        $lastAudit = NightAudit::getLastAudit($db, $propertyId);
        $lastRunDate = ($lastAudit && ($lastAudit['status'] ?? '') === 'success' && isset($lastAudit['audit_date']))
            ? date('Y-m-d', strtotime($lastAudit['audit_date']))
            : null;

        if ($lastRunDate === $currentDate) {
            continue;
        }

        $audit = new NightAudit($db, $propertyId);
        $result = $audit->run('system_cron');
        echo "Property {$propertyId}: " . ($result['status'] ?? 'unknown') . " — " . ($result['message'] ?? $result['error_message'] ?? 'done') . "\n";
        if (($result['status'] ?? '') === 'success') {
            $ranCount++;
        }
    }

    echo "Night audit cron completed. Successful runs: {$ranCount}\n";

} catch (\Exception $e) {
    echo "CRON ERROR: " . $e->getMessage() . "\n";
}
