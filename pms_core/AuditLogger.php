<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class AuditLogger {
    /**
     * Human-readable action descriptions for common actions.
     */
    private const ACTION_LABELS = [
        'CREATE_BOOKING' => 'Created booking',
        'EDIT_BOOKING_DATES' => 'Modified booking dates/room',
        'EXTEND_STAY' => 'Extended guest stay',
        'RECORD_PAYMENT' => 'Recorded payment',
        'DELETE_LEDGER_ENTRY' => 'Deleted ledger entry',
        'ADD_FOLIO_PAYMENT' => 'Added folio entry',
        'POST_CHARGE' => 'Posted charge to folio',
        'CHECK_IN' => 'Guest checked in',
        'CHECK_OUT' => 'Guest checked out',
        'CANCEL_BOOKING' => 'Cancelled booking',
        'MARKED_ROOM_CLEAN' => 'Marked room as clean',
        'MARKED_ROOM_OOO' => 'Marked room out of order',
        'ADD_USER' => 'Added staff user',
        'EDIT_USER' => 'Edited staff user',
        'DELETE_USER' => 'Deactivated staff user',
        'ADD_CATEGORY' => 'Added room category',
        'EDIT_CATEGORY' => 'Edited room category',
        'DELETE_CATEGORY' => 'Deleted room category',
        'ADD_ROOM' => 'Added room',
        'EDIT_ROOM' => 'Edited room',
        'DELETE_ROOM' => 'Deleted room',
        'ADD_RATE' => 'Added rate plan',
        'SAVE_BULK_RATES' => 'Saved rate configuration',
        'DELETE_RATE' => 'Deleted rate plan',
        'SAVE_SETTINGS' => 'Saved system settings',
        'ADD_AUTOMATION_EVENT' => 'Added automation event',
        'DELETE_AUTOMATION_EVENT' => 'Deleted automation event',
        'SAVE_WA_AUTOMATION' => 'Saved WhatsApp automation',
        'SYNC_WA_TEMPLATES' => 'Synced WhatsApp templates',
        'UPLOAD_DOCUMENT' => 'Uploaded document',
        'NIGHT_AUDIT_AUTO_CHECKOUT' => 'Night audit auto-checkout',
        'WA_MESSAGE_SUCCESS' => 'WhatsApp message sent',
        'WA_MESSAGE_FAILED' => 'WhatsApp message failed',
    ];

    /**
     * Logs an action to the audit_logs table with full context.
     *
     * @param int|string|null $staffId The ID of the staff member performing the action.
     * @param string $action The action code (e.g., 'CREATE_BOOKING').
     * @param string $entityType The type of entity (e.g., 'BOOKING', 'ROOM', 'SYSTEM').
     * @param int|string|null $entityId The ID of the entity.
     * @param array $details Additional context about the action.
     * @return void
     */
    public static function log(int|string|null $staffId, string $action, string $entityType, int|string|null $entityId = null, array $details = [], int|string|null $propertyId = null): void {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Enrich details with request context
            $enrichedDetails = array_merge($details, [
                'action_label' => self::ACTION_LABELS[$action] ?? $action,
                'timestamp' => date('Y-m-d H:i:s'),
                'ip' => self::getClientIp(),
                'source' => $details['source'] ?? 'web',
            ]);

            // Add staff username if available
            if ($staffId !== null && (int)$staffId !== 0) {
                $username = $_SESSION['username'] ?? null;
                if ($username) {
                    $enrichedDetails['staff_name'] = $username;
                }
            }

            // Fallback to session property_id if omitted (crucial for tenant isolation)
            if ($propertyId === null && isset($_SESSION['property_id'])) {
                $propertyId = (int)$_SESSION['property_id'];
            }

            // If propertyId is missing or 1000 (from assistant super admin), infer from entity
            if ((empty($propertyId) || $propertyId === 1000) && !empty($entityId)) {
                if ($entityType === 'BOOKING' || $entityType === 'FOLIO') {
                    $bStmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ?");
                    $bStmt->execute([(int)$entityId]);
                    $infProp = $bStmt->fetchColumn();
                    if ($infProp) $propertyId = (int)$infProp;
                } elseif ($entityType === 'ROOM') {
                    $rStmt = $db->prepare("SELECT property_id FROM rooms WHERE id = ?");
                    $rStmt->execute([(int)$entityId]);
                    $infProp = $rStmt->fetchColumn();
                    if ($infProp) $propertyId = (int)$infProp;
                }
            }

            // Ultimate fallback to primary property if still null
            if (empty($propertyId) && isset($_SESSION['primary_property_id'])) {
                $propertyId = (int)$_SESSION['primary_property_id'];
            }

            $stmt = $db->prepare("INSERT INTO audit_logs (staff_id, property_id, action, entity_type, entity_id, details) VALUES (:staff_id, :property_id, :action, :entity_type, :entity_id, :details)");
            $stmt->execute([
                'staff_id' => ($staffId !== null && (int)$staffId !== 0) ? (int)$staffId : null,
                'property_id' => ($propertyId !== null && (int)$propertyId !== 0) ? (int)$propertyId : null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId !== null ? (int)$entityId : null,
                'details' => json_encode($enrichedDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        } catch (\Exception $e) {
            error_log("AuditLogger Error: " . $e->getMessage());
        }
    }

    /**
     * Get client IP address.
     */
    private static function getClientIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
