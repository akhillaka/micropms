<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

/**
 * SaaSAuditLogger - Implements tenant-scoped immutable audit logging system.
 */
class SaaSAuditLogger {

    /**
     * Records an immutable log entry tagged by property_id (tenant) and staff_id (user).
     *
     * @param PDO $db
     * @param int|null $propertyId The tenant ID context
     * @param int|null $staffId The user ID context
     * @param string $action Action description
     * @param string $entityType Entity type (e.g. 'ROOM', 'BOOKING')
     * @param int|null $entityId Entity primary key ID
     * @param array $details Arbitrary metadata details
     */
    public static function log(\PDO $db, ?int $propertyId, ?int $staffId, string $action, string $entityType, ?int $entityId = null, array $details = []): void {
        try {
            $ip = self::getClientIp();
            
            $enriched = array_merge($details, [
                'ip' => $ip,
                'logged_at' => date('Y-m-d H:i:s'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);

            // Default to property ID from session if null
            if ($propertyId === null) {
                if (class_exists('AuthHelper')) {
                    $propertyId = AuthHelper::getPropertyId();
                } else {
                    $propertyId = 1;
                }
            }

            $stmt = $db->prepare("
                INSERT INTO audit_logs (property_id, staff_id, action, entity_type, entity_id, details)
                VALUES (:pid, :staff_id, :action, :entity_type, :entity_id, :details)
            ");
            $stmt->execute([
                'pid' => $propertyId,
                'staff_id' => $staffId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => json_encode($enriched, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            ]);
        } catch (\Exception $e) {
            error_log("SaaSAuditLogger Error: " . $e->getMessage());
        }
    }

    private static function getClientIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '127.0.0.1';
    }
}
