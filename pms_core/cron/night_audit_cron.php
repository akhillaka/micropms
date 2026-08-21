<?php
declare(strict_types=1);

// Run via server CRON (e.g. every 15 minutes).
// Executes night audit in-process — jobs_queue is not processed for this job type.

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../services/NightAudit.php';
require_once __DIR__ . '/../ErrorTracker.php';

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->query("
        SELECT s1.property_id,
               s2.key_value AS audit_time,
               COALESCE(NULLIF(TRIM(p.timezone), ''), 'Asia/Kolkata') AS timezone
        FROM system_settings s1
        LEFT JOIN system_settings s2
          ON s1.property_id = s2.property_id AND s2.key_name = 'night_audit_time'
        LEFT JOIN properties p ON p.id = s1.property_id
        WHERE s1.key_name = 'night_audit_enabled' AND s1.key_value = 'true'
    ");

    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ranCount = 0;

    foreach ($properties as $prop) {
        $propertyId = (int)$prop['property_id'];
        $auditTime = $prop['audit_time'] ?: '02:00';
        $tzName = (string)($prop['timezone'] ?? 'Asia/Kolkata');

        try {
            try {
                $tz = new DateTimeZone($tzName);
            } catch (\Throwable $e) {
                $tz = new DateTimeZone('Asia/Kolkata');
            }
            $now = new DateTime('now', $tz);
            $hour = (int)$now->format('G');
            $currentDate = ($hour < 6)
                ? (clone $now)->modify('-1 day')->format('Y-m-d')
                : $now->format('Y-m-d');
            $currentTime = $now->format('H:i');

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
            echo "Property {$propertyId} ({$tzName}): " . ($result['status'] ?? 'unknown') . " — " . ($result['message'] ?? $result['error_message'] ?? 'done') . "\n";
            if (($result['status'] ?? '') === 'success') {
                $ranCount++;
            } elseif (($result['status'] ?? '') !== 'success') {
                ErrorTracker::log(
                    'error',
                    'system',
                    'Night audit failed for property ' . $propertyId . ': ' . (string)($result['message'] ?? $result['error_message'] ?? 'unknown'),
                    ['property_id' => $propertyId, 'result' => $result]
                );
            }
        } catch (\Throwable $e) {
            echo "Property {$propertyId} ERROR: " . $e->getMessage() . "\n";
            try {
                ErrorTracker::log('error', 'system', 'Night audit cron exception: ' . $e->getMessage(), [
                    'property_id' => $propertyId,
                ]);
            } catch (\Throwable $ignore) {
            }
        }
    }

    echo "Night audit cron completed. Successful runs: {$ranCount}\n";

} catch (\Exception $e) {
    echo "CRON ERROR: " . $e->getMessage() . "\n";
}
