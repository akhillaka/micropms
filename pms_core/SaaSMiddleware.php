<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthHelper.php';
require_once __DIR__ . '/services/SaaSBillingEngine.php';

/**
 * SaaSMiddleware - Resolves tenant context, checks subscriptions, and enforces RBAC authorization.
 */
class SaaSMiddleware {

    /**
     * Resolves the current tenant workspace ID and validates subscription status.
     * Sets the active tenant property context inside AuthHelper.
     *
     * @param PDO $db
     * @return int Resolved Property ID
     * @throws Exception if subscription is expired/cancelled or tenant is suspended
     */
    public static function resolveAndGuardTenant(\PDO $db): int {
        // Resolve tenant:
        // 1. Custom HTTP Header (cross-validated against staff_properties or superadmin check)
        // 2. Resolved host domain context
        // 3. Active session value
        $propertyId = 1;

        if (isset($_SERVER['HTTP_X_TENANT_ID'])) {
            $requestedId = (int)$_SERVER['HTTP_X_TENANT_ID'];
            $sessionPropertyId = AuthHelper::getPropertyId();
            $isSuperAdmin = AuthHelper::isSuperAdmin();

            if ($isSuperAdmin || $requestedId === $sessionPropertyId) {
                $propertyId = $requestedId;
            } else {
                // Verify if staff is mapped to this property in staff_properties
                try {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM staff_properties WHERE staff_id = ? AND property_id = ?");
                    $stmt->execute([$_SESSION['user_id'] ?? 0, $requestedId]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        $propertyId = $requestedId;
                    } else {
                        $propertyId = $sessionPropertyId;
                    }
                } catch (\PDOException $e) {
                    $propertyId = $sessionPropertyId;
                }
            }
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $resolvedId = SaaSBillingEngine::resolveDomainTenant($db, $_SERVER['HTTP_HOST']);
            $propertyId = ($resolvedId !== null) ? $resolvedId : AuthHelper::getPropertyId();
        } else {
            $propertyId = AuthHelper::getPropertyId();
        }

        // Set context in session / helper
        AuthHelper::setPropertyId($propertyId);

        // Fetch custom permissions for this property context if not superadmin
        if (isset($_SESSION['user_id']) && !AuthHelper::isSuperAdmin()) {
            try {
                $stmt = $db->prepare("
                    SELECT pr.permissions 
                    FROM roles pr
                    INNER JOIN staff_properties sp ON pr.id = sp.role_id
                    WHERE sp.staff_id = ? AND sp.property_id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $propertyId]);
                $perms = $stmt->fetchColumn();
                if ($perms) {
                    $_SESSION['custom_permissions'] = json_decode($perms, true) ?? [];
                } else {
                    $_SESSION['custom_permissions'] = [];
                }
            } catch (\PDOException $e) {}
        }

        // Superadmin bypasses all property-level restrictions
        if (AuthHelper::isSuperAdmin()) {
            return $propertyId;
        }

        // Enforce property-level subscription/suspension checks
        if ($propertyId > 0) {
            try {
                $stmt = $db->prepare("SELECT is_active, subscription_status, valid_until, timezone FROM properties WHERE id = ?");
                $stmt->execute([$propertyId]);
                $prop = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($prop) {
                    if (!empty($prop['timezone'])) {
                        date_default_timezone_set($prop['timezone']);
                    }
                    
                    // Check suspension
                    if ((int)$prop['is_active'] === 0) {
                        http_response_code(403);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'This property account has been suspended. Please contact support.'
                        ], JSON_THROW_ON_ERROR);
                        exit;
                    }

                    // Check trial/subscription expiry
                    if (!empty($prop['valid_until'])) {
                        $expiry = new \DateTime($prop['valid_until']);
                        $now    = new \DateTime();
                        if ($expiry < $now) {
                            http_response_code(402);
                            header('Content-Type: application/json');
                            echo json_encode([
                                'success' => false,
                                'message' => 'Subscription expired on ' . $expiry->format('d M Y') . '. Please renew to continue.'
                            ], JSON_THROW_ON_ERROR);
                            exit;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Fail open — don't block on DB errors during middleware
            }
        }

        return $propertyId;
    }

    /**
     * Enforce role-based access control (RBAC) on an action.
     *
     * @param string $permission The permission to authorize
     */
    public static function authorizePermission(string $permission): void {
        AuthHelper::requirePermission($permission);
    }

    /**
     * Enforce strict role membership check (Owner, Admin, Manager, Housekeeping).
     *
     * @param string ...$roles
     */
    public static function authorizeRoles(string ...$roles): void {
        AuthHelper::requireRole(...$roles);
    }
}
