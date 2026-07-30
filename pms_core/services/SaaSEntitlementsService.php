<?php
declare(strict_types=1);

require_once __DIR__ . '/../saas_plans.php';

/**
 * SaaSEntitlementsService - Enforces plan-based entitlement checks, seat limits, and feature toggles.
 */
class SaaSEntitlementsService {

    /**
     * Checks if the tenant is allowed to add more staff users (seat limit check).
     *
     * @param PDO $db
     * @param int $propertyId
     * @throws Exception if staff count exceeds plan entitlement limits
     */
    public static function checkStaffLimit(\PDO $db, int $propertyId): void {
        if ($propertyId === 1) {
            return; // Primary property is bypass-exempted
        }

        $stmt = $db->prepare("SELECT plan, max_staff FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        $prop = $stmt->fetch();

        if (!$prop) {
            throw new \Exception("Property tenant account not found.");
        }

        // Load limits dynamically from plans config if not customized
        $plansConfig = SaaSPlans::get($db);
        $planKey = $prop['plan'] ?? 'starter';
        $maxStaff = isset($plansConfig[$planKey]['max_staff']) ? (int)$plansConfig[$planKey]['max_staff'] : (int)$prop['max_staff'];

        // Count current staff active on the tenant workspace
        $countStmt = $db->prepare("SELECT COUNT(*) FROM staff_users WHERE property_id = ? AND is_active = 1");
        $countStmt->execute([$propertyId]);
        $currentStaff = (int)$countStmt->fetchColumn();

        if ($currentStaff >= $maxStaff) {
            throw new \Exception("SaaS Seat Limit Reached: Your current '" . ucfirst($prop['plan']) . "' plan permits up to {$maxStaff} active team seats. Upgrade to register more staff users.");
        }
    }

    /**
     * Verifies if a specific feature flag is active for the given tenant workspace.
     * Checks localized tenant overrides, then falls back to global feature flags.
     *
     * @param PDO $db
     * @param int $propertyId
     * @param string $flagKey
     * @return bool True if active
     */
    public static function isFeatureEnabled(\PDO $db, int $propertyId, string $flagKey): bool {
        // 1. Check tenant specific DB feature flags overrides
        $stmt = $db->prepare("SELECT flag_value FROM saas_feature_flags WHERE property_id = ? AND flag_key = ? LIMIT 1");
        $stmt->execute([$propertyId, $flagKey]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            return $val === 'true' || $val === '1';
        }

        // 2. Check tenant plan settings features JSON override
        $planStmt = $db->prepare("SELECT plan, features_json FROM properties WHERE id = ? LIMIT 1");
        $planStmt->execute([$propertyId]);
        $prop = $planStmt->fetch();
        if ($prop && !empty($prop['features_json'])) {
            $features = json_decode($prop['features_json'], true) ?: [];
            if (isset($features[$flagKey])) {
                return (bool)$features[$flagKey];
            }
        }

        // 3. Fall back to global platform default flag
        $globalStmt = $db->prepare("SELECT flag_value FROM saas_feature_flags WHERE property_id IS NULL AND flag_key = ? LIMIT 1");
        $globalStmt->execute([$flagKey]);
        $globalVal = $globalStmt->fetchColumn();
        if ($globalVal !== false) {
            return $globalVal === 'true' || $globalVal === '1';
        }

        // 4. Defaults plan-based configuration resolution
        $plansConfig = SaaSPlans::get($db);
        $plan = $prop['plan'] ?? 'starter';
        if (isset($plansConfig[$plan]['features'][$flagKey])) {
            return (bool)$plansConfig[$plan]['features'][$flagKey];
        }

        return false;
    }
}
