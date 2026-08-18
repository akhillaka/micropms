<?php
declare(strict_types=1);

/**
 * SaaSBillingEngine - Enforces SaaS plans, room limits, and subscription status validations.
 */
require_once __DIR__ . '/../saas_plans.php';

class SaaSBillingEngine {

    public static function resolvePlanCap(\PDO $db, int $propertyId, string $capKey, int $fallback): int {
        $stmt = $db->prepare("SELECT plan, max_rooms, max_staff FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        $prop = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$prop) {
            return $fallback;
        }
        $plans = SaaSPlans::get($db);
        $planKey = $prop['plan'] ?? 'starter';
        $planCap = (int)($plans[$planKey][$capKey] ?? $fallback);
        $propertyCap = (int)($prop[$capKey] ?? $fallback);
        $cap = $propertyCap > 0 ? $propertyCap : $planCap;
        if ($planCap > 0) {
            $cap = min($cap, $planCap);
        }
        return max(0, $cap);
    }

    public static function exportRowLimit(\PDO $db, int $propertyId): int {
        $stmt = $db->prepare("SELECT plan FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        $planKey = (string)($stmt->fetchColumn() ?: 'starter');
        $plans = SaaSPlans::get($db);
        return (int)($plans[$planKey]['max_export_rows'] ?? 500);
    }

    /** @param array<int, array> $rows */
    public static function applyExportLimit(array $rows, int $limit): array {
        if ($limit <= 0 || count($rows) <= $limit) {
            return $rows;
        }
        return array_slice($rows, 0, $limit);
    }

    /**
     * Verifies if a property is allowed to add more rooms based on their active SaaS plan.
     */
    public static function checkRoomLimit(\PDO $db, int $propertyId): void {
        $stmt = $db->prepare("SELECT plan FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        $prop = $stmt->fetch();

        if (!$prop) {
            throw new \Exception("Property tenant account not found.");
        }

        $maxRooms = self::resolvePlanCap($db, $propertyId, 'max_rooms', 15);
        
        // Count active rooms for this property
        $roomStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ? AND deleted_at IS NULL");
        $roomStmt->execute([$propertyId]);
        $currentRooms = (int)$roomStmt->fetchColumn();

        if ($currentRooms >= $maxRooms) {
            throw new \Exception("SaaS Plan Limit Exceeded: Your active '" . ucfirst($prop['plan']) . "' plan allows a maximum of {$maxRooms} rooms. Please upgrade your subscription plan to add more rooms.");
        }
    }

    /**
     * Checks if a property has a valid, active subscription.
     * Throws an exception or returns false if subscription is expired or cancelled.
     */
    public static function checkSubscription(\PDO $db, int $propertyId): bool {
        $stmt = $db->prepare("SELECT is_active, subscription_status, valid_until, is_exempt_from_billing FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        $prop = $stmt->fetch();

        if (!$prop) {
            return false;
        }

        // Exempt properties bypass billing completely (unlimited free access)
        if ((int)$prop['is_exempt_from_billing'] === 1) {
            return true;
        }

        // Check active toggles
        if ((int)$prop['is_active'] !== 1 || $prop['subscription_status'] === 'cancelled') {
            return false;
        }

        // Check expiration date
        if (!empty($prop['valid_until'])) {
            $expiryTs = strtotime($prop['valid_until']);
            if ($expiryTs < time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolves property tenant context based on white-label custom domain or Host Header.
     */
    public static function resolveDomainTenant(\PDO $db, string $httpHost): ?int {
        $cleanHost = strtolower(trim($httpHost));
        $cleanHost = preg_replace('/:\d+$/', '', $cleanHost) ?? $cleanHost;

        $stmt = $db->prepare("SELECT id FROM properties WHERE custom_domain = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$cleanHost]);
        $id = $stmt->fetchColumn();

        return $id ? (int)$id : null;
    }
}
