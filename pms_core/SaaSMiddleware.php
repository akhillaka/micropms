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
        $propertyId = 0;
        $loggedIn = isset($_SESSION['user_id']);

        if (isset($_SERVER['HTTP_X_TENANT_ID'])) {
            $requestedId = (int)$_SERVER['HTTP_X_TENANT_ID'];
            if ($loggedIn) {
                $sessionPropertyId = AuthHelper::getPropertyId();
                $isSuperAdmin = AuthHelper::isSuperAdmin();

                if ($isSuperAdmin || $requestedId === $sessionPropertyId) {
                    $propertyId = $requestedId;
                } else {
                    try {
                        $stmt = $db->prepare("SELECT COUNT(*) FROM staff_properties WHERE staff_id = ? AND property_id = ?");
                        $stmt->execute([$_SESSION['user_id'] ?? 0, $requestedId]);
                        $propertyId = ((int)$stmt->fetchColumn() > 0) ? $requestedId : $sessionPropertyId;
                    } catch (\PDOException $e) {
                        $propertyId = $sessionPropertyId;
                    }
                }
            } else {
                $propertyId = 0;
            }
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $resolvedId = SaaSBillingEngine::resolveDomainTenant($db, $_SERVER['HTTP_HOST']);
            if ($resolvedId !== null) {
                if ($loggedIn) {
                    $sessionPropertyId = AuthHelper::getPropertyId();
                    if (AuthHelper::isSuperAdmin() || $resolvedId === $sessionPropertyId) {
                        $propertyId = $resolvedId;
                    } else {
                        try {
                            $stmt = $db->prepare("SELECT COUNT(*) FROM staff_properties WHERE staff_id = ? AND property_id = ?");
                            $stmt->execute([$_SESSION['user_id'] ?? 0, $resolvedId]);
                            $propertyId = ((int)$stmt->fetchColumn() > 0) ? $resolvedId : $sessionPropertyId;
                        } catch (\PDOException $e) {
                            $propertyId = $sessionPropertyId;
                        }
                    }
                } else {
                    $propertyId = $resolvedId;
                }
            } elseif ($loggedIn) {
                $propertyId = AuthHelper::getPropertyId();
            } else {
                $propertyId = self::resolveLoopbackTenant($db) ?? 0;
            }
        } elseif ($loggedIn) {
            $propertyId = AuthHelper::getPropertyId();
        }

        if ($propertyId > 0) {
            AuthHelper::setPropertyId($propertyId);
        }

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

                    if (!SaaSBillingEngine::checkSubscription($db, $propertyId)) {
                        http_response_code(402);
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false,
                            'message' => 'Subscription is inactive, cancelled, or expired. Please renew to continue.'
                        ], JSON_THROW_ON_ERROR);
                        exit;
                    }
                }
            } catch (\Exception $e) {
                // Fail open — don't block on DB errors during middleware
            }
        }

        return $propertyId;
    }

    /**
     * Local PHP built-in server has no custom_domain. Bind the only active
     * property (or id 1000 when present) so PIN login can list staff.
     */
    private static function resolveLoopbackTenant(\PDO $db): ?int {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return null;
        }
        try {
            $ids = $db->query("SELECT id FROM properties WHERE is_active = 1 ORDER BY id ASC")->fetchAll(\PDO::FETCH_COLUMN);
            $ids = array_map('intval', $ids ?: []);
            if (count($ids) === 1) {
                return $ids[0];
            }
            if (in_array(1000, $ids, true)) {
                return 1000;
            }
            return $ids[0] ?? null;
        } catch (\PDOException $e) {
            return null;
        }
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
