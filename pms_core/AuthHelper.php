<?php
declare(strict_types=1);

/**
 * Auth Helper - standardized authentication checks and RBAC permission enforcement.
 */
class AuthHelper {

    private const PERMISSIONS = [
        // 'owner' and 'admin' share full permissions; 'admin' is kept as a distinct
        // role so it can be differentiated in future fine-grained access control.
        'superadmin' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'cancel_booking', 'check_in_out',
            'view_folio', 'edit_folio', 'record_payment', 'refund_payment', 'generate_payment_link',
            'view_finance', 'manage_finance', 'export_finance', 'view_reports', 'manage_guests',
            'upload_document', 'housekeeping', 'manage_rooms', 'manage_staff', 'manage_settings',
            'view_audit_logs', 'send_whatsapp', 'view_error_logs', 'resolve_error_logs',
            'manage_pos', 'run_night_audit',
            'manage_properties', 'manage_saas', 'manage_billing',
            'override_room_rates', 'apply_discounts', 'void_folio_item', 'waive_cancellation_fee',
            'void_pos_order', 'discount_pos_order', 'manage_inventory', 'view_pos_reports',
            'update_room_status', 'inspect_rooms', 'manage_maintenance',
            'export_reports', 'export_guest_data', 'manage_payment_gateways', 'manage_automations', 'rollback_booking', 'move_room'
        ],
        'owner' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'cancel_booking', 'check_in_out',
            'view_folio', 'edit_folio', 'record_payment', 'refund_payment', 'generate_payment_link',
            'view_finance', 'manage_finance', 'export_finance', 'view_reports', 'manage_guests',
            'upload_document', 'housekeeping', 'manage_rooms', 'manage_staff', 'manage_settings',
            'view_audit_logs', 'send_whatsapp', 'view_error_logs', 'resolve_error_logs',
            'manage_pos', 'run_night_audit',
            'override_room_rates', 'apply_discounts', 'void_folio_item', 'waive_cancellation_fee',
            'void_pos_order', 'discount_pos_order', 'manage_inventory', 'view_pos_reports',
            'update_room_status', 'inspect_rooms', 'manage_maintenance',
            'export_reports', 'export_guest_data', 'manage_payment_gateways', 'manage_automations', 'rollback_booking', 'move_room'
        ],
        'admin' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'cancel_booking', 'check_in_out',
            'view_folio', 'edit_folio', 'record_payment', 'refund_payment', 'generate_payment_link',
            'view_finance', 'manage_finance', 'export_finance', 'view_reports', 'manage_guests',
            'upload_document', 'housekeeping', 'manage_rooms', 'manage_staff', 'manage_settings',
            'view_audit_logs', 'send_whatsapp', 'view_error_logs', 'resolve_error_logs',
            'manage_pos', 'run_night_audit',
            'override_room_rates', 'apply_discounts', 'void_folio_item', 'waive_cancellation_fee',
            'void_pos_order', 'discount_pos_order', 'manage_inventory', 'view_pos_reports',
            'update_room_status', 'inspect_rooms', 'manage_maintenance',
            'export_reports', 'export_guest_data', 'manage_payment_gateways', 'manage_automations', 'rollback_booking', 'move_room'
        ],
        'manager' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'cancel_booking', 'check_in_out',
            'view_folio', 'edit_folio', 'record_payment', 'generate_payment_link',
            'view_finance', 'view_reports', 'manage_guests',
            'upload_document', 'housekeeping', 'send_whatsapp', 'view_audit_logs',
            'manage_pos', 'run_night_audit',
            'override_room_rates', 'apply_discounts', 'void_folio_item', 'waive_cancellation_fee',
            'void_pos_order', 'discount_pos_order', 'manage_inventory', 'view_pos_reports',
            'update_room_status', 'inspect_rooms', 'manage_maintenance',
            'export_reports', 'manage_automations', 'move_room'
        ],
        'receptionist' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'cancel_booking', 'check_in_out',
            'move_room',
            'view_folio', 'record_payment', 'generate_payment_link', 'manage_guests',
            'upload_document', 'housekeeping', 'send_whatsapp',
            'update_room_status', 'view_pos_reports'
        ],
        'housekeeping' => [
            'view_dashboard', 'housekeeping', 'update_room_status', 'inspect_rooms'
        ],
        'maintenance' => [
            'view_dashboard', 'manage_rooms', 'manage_maintenance'
        ],
        'fb_cashier' => [
            'view_dashboard', 'manage_pos', 'view_pos_reports'
        ],
        'night_auditor' => [
            'view_dashboard', 'view_reports', 'view_finance', 'run_night_audit', 'view_pos_reports',
            'view_folio', 'record_payment', 'refund_payment', 'check_in_out', 'generate_payment_link'
        ]
    ];
    
    /**
     * Seeds the default roles for a newly created property.
     */
    public static function seedRolesForProperty(\PDO $db, int $propertyId): void {
        try {
            $col = $db->query("SHOW COLUMNS FROM roles LIKE 'is_system'")->fetch();
            if (!$col) {
                $db->exec("ALTER TABLE roles ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (\PDOException $e) {}

        $check = $db->prepare("SELECT id FROM roles WHERE property_id = ? AND name = ? LIMIT 1");
        $insert = $db->prepare("INSERT INTO roles (property_id, name, permissions, is_system) VALUES (?, ?, ?, 1)");
        $update = $db->prepare("UPDATE roles SET permissions = ?, is_system = 1 WHERE id = ?");
        foreach (self::PERMISSIONS as $roleName => $perms) {
            if ($roleName === 'superadmin') {
                continue;
            }
            $json = json_encode($perms);
            $check->execute([$propertyId, $roleName]);
            $existingId = $check->fetchColumn();
            if ($existingId) {
                $update->execute([$json, $existingId]);
            } else {
                $insert->execute([$propertyId, $roleName, $json]);
            }
        }
    }
    
    /**
     * Ensures the user is logged in. 
     * Returns a 401 JSON response and exits if unauthorized.
     */
    public static function requireLogin(): void {
        self::hydrateSession(false);
        
        if (!isset($_SESSION['user_id'])) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_THROW_ON_ERROR);
            exit;
        }
    }

    /**
     * Ensures the user is logged in AND has one of the specified roles.
     * Returns a 403 JSON response and exits if access level is insufficient.
     */
    public static function requireRole(string ...$roles): void {
        self::requireLogin();
        
        $userRole = self::getRole();
        if (!in_array($userRole, $roles, true)) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: Access denied for role ' . ($userRole ?? 'guest')], JSON_THROW_ON_ERROR);
            exit;
        }
    }

    /**
     * Ensures the user is logged in AND has the 'owner' access level.
     * Kept for backward compatibility.
     */
    public static function requireOwner(): void {
        self::requireRole('owner');
    }

    /**
     * Ensures the user has a specific permission based on their role.
     */
    public static function requirePermission(string $permission): void {
        self::requireLogin();
        
        if (!self::can($permission)) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden: Missing permission ' . $permission], JSON_THROW_ON_ERROR);
            exit;
        }
    }

    /**
     * Release the session lock so concurrent API polls do not block user actions.
     * $_SESSION remains readable in memory; reopen with session_start() to write.
     */
    public static function releaseSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * Non-exiting permission check. Useful for rendering UI elements.
     */
    public static function can(string $permission): bool {
        self::hydrateSession(false);
        
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        $role = self::normalizeRoleName((string)(self::getRole() ?? ''));
        if ($role === '') {
            return false;
        }
        
        // Check if user has a custom role with custom permissions loaded in session
        if (!empty($_SESSION['custom_permissions']) && is_array($_SESSION['custom_permissions'])) {
            return in_array($permission, $_SESSION['custom_permissions'], true);
        }
        
        $allowedPerms = self::PERMISSIONS[$role] ?? [];
        return in_array($permission, $allowedPerms, true);
    }

    /**
     * Read session from memory after session_write_close() without re-locking.
     * Re-open only when the request has no hydrated $_SESSION yet.
     */
    private static function hydrateSession(bool $forWrite = false): void {
        if ($forWrite) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (empty($_SESSION['user_id'])) {
                self::resumeRememberedSession();
            }
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_SESSION['user_id'])) {
                self::resumeRememberedSession();
            }
            return;
        }
        if (!empty($_SESSION['user_id']) || !empty($_SESSION['property_id'])) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['user_id'])) {
            self::resumeRememberedSession();
        }
    }

    public static function normalizeRoleName(string $role): string {
        $role = strtolower(trim($role));
        return match ($role) {
            'staff', 'user', 'front_desk' => 'receptionist',
            'hk' => 'housekeeping',
            default => $role,
        };
    }

    public static function roleCan(string $role, string $permission): bool {
        $role = self::normalizeRoleName($role);
        if ($role === 'superadmin') {
            return true;
        }
        $allowed = self::PERMISSIONS[$role] ?? [];
        return in_array($permission, $allowed, true);
    }

    public static function telegramActionPermission(string $action): string {
        return match ($action) {
            'add_payment' => 'record_payment',
            'check_in', 'quick_checkout' => 'check_in_out',
            'extend_stay' => 'edit_booking',
            'id_proof' => 'upload_document',
            'mark_room_clean' => 'update_room_status',
            'today_revenue' => 'view_finance',
            'arrivals', 'departures', 'room_status' => 'view_dashboard',
            'cancel_booking' => 'cancel_booking',
            default => $action,
        };
    }

    /**
     * Map Settings role picker (built-in or custom_N) to staff_users columns.
     *
     * @return array{access_level: string, role_name: string, role_id: int|null}
     */
    public static function resolveStaffRoleInput(\PDO $db, int $propertyId, string $roleInput): array {
        $validRoles = ['owner', 'admin', 'manager', 'receptionist', 'housekeeping', 'maintenance', 'fb_cashier', 'night_auditor'];
        if (str_starts_with($roleInput, 'custom_')) {
            $roleId = (int)substr($roleInput, 7);
            $stmt = $db->prepare("SELECT id, name FROM roles WHERE id = ? AND property_id = ?");
            $stmt->execute([$roleId, $propertyId]);
            $customRole = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$customRole) {
                throw new \Exception('Invalid custom role selected');
            }
            return [
                'access_level' => 'manager',
                'role_name' => (string)$customRole['name'],
                'role_id' => $roleId,
            ];
        }
        if (!in_array($roleInput, $validRoles, true)) {
            throw new \Exception('Invalid role selection');
        }
        return [
            'access_level' => $roleInput,
            'role_name' => $roleInput,
            'role_id' => null,
        ];
    }

    public static function assistantRoleAlias(string $accessLevel): string {
        $accessLevel = self::normalizeRoleName($accessLevel);
        return match ($accessLevel) {
            'owner', 'admin', 'superadmin' => 'owner',
            'manager' => 'manager',
            'housekeeping' => 'housekeeping',
            default => 'receptionist',
        };
    }

    public static function applyCustomPermissions(\PDO $db, mixed $roleId, ?int $propertyId = null): void {
        unset($_SESSION['custom_permissions']);
        if (empty($roleId)) {
            return;
        }
        try {
            $pid = $propertyId !== null ? (int)$propertyId : (int)($_SESSION['property_id'] ?? 0);
            if ($pid > 0) {
                $roleStmt = $db->prepare("SELECT permissions FROM roles WHERE id = ? AND property_id = ?");
                $roleStmt->execute([(int)$roleId, $pid]);
            } else {
                $roleStmt = $db->prepare("SELECT permissions FROM roles WHERE id = ?");
                $roleStmt->execute([(int)$roleId]);
            }
            $roleData = $roleStmt->fetch(\PDO::FETCH_ASSOC);
            if ($roleData && !empty($roleData['permissions'])) {
                $decoded = json_decode((string)$roleData['permissions'], true);
                if (is_array($decoded)) {
                    $_SESSION['custom_permissions'] = $decoded;
                }
            }
        } catch (\PDOException $e) {
        }
    }

    /**
     * @return array<string, bool>
     */
    public static function assistantUiPermissions(string $role): array {
        $role = self::normalizeRoleName($role);
        $check = static function (string $permission) use ($role): bool {
            if (!empty($_SESSION['user_id'])) {
                return self::can($permission);
            }
            return self::roleCan($role, $permission);
        };
        return [
            'check_in_out' => $check('check_in_out'),
            'collect_payment' => $check('record_payment'),
            'add_charge' => $check('edit_folio'),
            'edit_charge' => $check('edit_folio'),
            'edit_checkout' => $check('edit_booking'),
            'housekeeping' => $check('housekeeping') || $check('update_room_status'),
            'pos_access' => $check('manage_pos'),
            'view_bill' => $check('view_folio'),
            'view_reports' => $check('view_reports'),
            'create_booking' => $check('create_booking'),
            'cancel_booking' => $check('cancel_booking'),
        ];
    }

    public static function getBuiltInRoles(): array {
        $labels = self::getAllPermissions();
        $roles = [];
        foreach (self::PERMISSIONS as $role => $keys) {
            if ($role === 'superadmin') {
                continue;
            }
            $roles[$role] = [
                'key' => $role,
                'label' => ucwords(str_replace('_', ' ', $role)),
                'permissions' => $keys,
                'permission_labels' => array_map(static fn($k) => $labels[$k] ?? $k, $keys),
            ];
        }
        return $roles;
    }

    /**
     * Returns a map of all available system permissions with human-readable labels.
     */
    public static function getAllPermissions(): array {
        $permissions = [
            'view_dashboard' => 'View Dashboard',
            'create_booking' => 'Create Booking',
            'edit_booking' => 'Edit Booking',
            'move_room' => 'Move Room',
            'cancel_booking' => 'Cancel Booking',
            'check_in_out' => 'Check-in / Check-out',
            'view_folio' => 'View Folio',
            'edit_folio' => 'Edit Folio',
            'record_payment' => 'Record Payment',
            'refund_payment' => 'Refund Payment',
            'generate_payment_link' => 'Generate Payment Link',
            'view_finance' => 'View Finance',
            'manage_finance' => 'Manage Finance',
            'export_finance' => 'Export Finance Data',
            'view_reports' => 'View Reports',
            'manage_guests' => 'Manage Guests',
            'upload_document' => 'Upload Documents',
            'housekeeping' => 'Housekeeping',
            'manage_rooms' => 'Manage Rooms',
            'manage_staff' => 'Manage Staff',
            'manage_settings' => 'Manage Property Settings',
            'view_audit_logs' => 'View Audit Logs',
            'send_whatsapp' => 'Send WhatsApp Messages',
            'view_error_logs' => 'View Error Logs',
            'resolve_error_logs' => 'Resolve Error Logs',
            'manage_pos' => 'Manage Point of Sale',
            'run_night_audit' => 'Run Night Audit',
            'override_room_rates' => 'Override Room Rates',
            'apply_discounts' => 'Apply Discounts',
            'void_folio_item' => 'Void Folio Items',
            'waive_cancellation_fee' => 'Waive Cancellation Fees',
            'void_pos_order' => 'Void POS Orders',
            'discount_pos_order' => 'Discount POS Orders',
            'manage_inventory' => 'Manage Inventory & Stock',
            'view_pos_reports' => 'View POS Reports',
            'update_room_status' => 'Update Housekeeping Status',
            'inspect_rooms' => 'Inspect & Verify Rooms',
            'manage_maintenance' => 'Manage Room Maintenance',
            'export_reports' => 'Export Reports & Data',
            'export_guest_data' => 'Export Guest Database',
            'manage_payment_gateways' => 'Manage Payment Gateways',
            'manage_automations' => 'Manage WhatsApp Automations'
        ];
        
        if (self::isSuperAdmin()) {
            $permissions['manage_properties'] = 'Manage Properties';
            $permissions['manage_saas'] = 'Manage SaaS Platform';
            $permissions['manage_billing'] = 'Manage Billing';
        }
        
        return $permissions;
    }

    /**
     * Returns a categorized map of permissions for UI grouping.
     */
    public static function getGroupedPermissions(): array {
        $groups = [
            'Dashboard' => [
                'view_dashboard' => 'View Dashboard',
            ],
            'Front Desk & Bookings' => [
                'create_booking' => 'Create Booking',
                'edit_booking' => 'Edit Booking',
                'move_room' => 'Move Room',
                'cancel_booking' => 'Cancel Booking',
                'check_in_out' => 'Check-in / Check-out',
                'rollback_booking' => 'Rollback Check-out',
                'override_room_rates' => 'Override Room Rates',
                'apply_discounts' => 'Apply Discounts',
                'waive_cancellation_fee' => 'Waive Cancellation Fees',
            ],
            'Billing & Folio' => [
                'view_folio' => 'View Folio',
                'edit_folio' => 'Edit Folio',
                'void_folio_item' => 'Void Folio Items',
                'record_payment' => 'Record Payment',
                'refund_payment' => 'Refund Payment',
                'generate_payment_link' => 'Generate Payment Link',
            ],
            'Finance & Audit' => [
                'view_finance' => 'View Finance',
                'manage_finance' => 'Manage Finance',
                'export_finance' => 'Export Finance Data',
                'run_night_audit' => 'Run Night Audit',
            ],
            'Guests & Communication' => [
                'manage_guests' => 'Manage Guests',
                'upload_document' => 'Upload Documents',
                'send_whatsapp' => 'Send WhatsApp Messages',
                'export_guest_data' => 'Export Guest Database',
            ],
            'Housekeeping & Maintenance' => [
                'housekeeping' => 'Housekeeping Overview',
                'update_room_status' => 'Update Room Status',
                'inspect_rooms' => 'Inspect & Verify Rooms',
                'manage_maintenance' => 'Manage Room Maintenance',
            ],
            'POS & Inventory' => [
                'manage_pos' => 'Manage Point of Sale',
                'void_pos_order' => 'Void POS Orders',
                'discount_pos_order' => 'Discount POS Orders',
                'view_pos_reports' => 'View POS Reports',
                'manage_inventory' => 'Manage Inventory & Stock',
            ],
            'Reporting' => [
                'view_reports' => 'View Reports',
                'export_reports' => 'Export Reports & Data',
            ],
            'Property Settings' => [
                'manage_rooms' => 'Manage Rooms',
                'manage_staff' => 'Manage Staff & Roles',
                'manage_settings' => 'Manage Property Settings',
                'manage_payment_gateways' => 'Manage Payment Gateways',
                'manage_automations' => 'Manage Automations',
            ],
            'System Logs' => [
                'view_audit_logs' => 'View Audit Logs',
                'view_error_logs' => 'View Error Logs',
                'resolve_error_logs' => 'Resolve Error Logs',
            ]
        ];

        if (self::isSuperAdmin()) {
            $groups['SaaS & Superadmin'] = [
                'manage_properties' => 'Manage Properties',
                'manage_saas' => 'Manage SaaS Platform',
                'manage_billing' => 'Manage Billing',
            ];
        }

        // Add any permissions returned by getAllPermissions that are missing from our static groups above
        // into an "Other" category to ensure complete coverage.
        $allFlat = self::getAllPermissions();
        $covered = [];
        foreach($groups as $k => $g) {
            foreach($g as $pk => $pl) {
                $covered[] = $pk;
            }
        }
        $missing = [];
        foreach($allFlat as $pk => $pl) {
            if(!in_array($pk, $covered)) {
                $missing[$pk] = $pl;
            }
        }
        if(!empty($missing)) {
            $groups['Other'] = $missing;
        }

        return $groups;
    }

    /**
     * Returns the current user's role from the session.
     * Checks both 'role' and 'access_level' for backward compatibility with active sessions.
     */
    public static function getRole(): ?string {
        self::hydrateSession(false);
        $role = $_SESSION['role'] ?? $_SESSION['access_level'] ?? null;
        
        // Normalize 'front_desk' to 'manager' for backward-compatible active sessions
        if ($role === 'front_desk') {
            return 'manager';
        }
        
        return $role;
    }

    /**
     * Returns true if the current user is a superadmin.
     */
    public static function isSuperAdmin(): bool {
        return self::getRole() === 'superadmin';
    }

    /**
     * Returns the currently logged in user info.
     */
    public static function getCurrentUser(): array {
        self::hydrateSession(false);
        return [
            'id'       => $_SESSION['user_id'] ?? null,
            'role'     => self::getRole(),
            'username' => $_SESSION['username'] ?? 'User'
        ];
    }

    /**
     * Returns the current active property ID from the session.
     * Throws an exception if no property context exists, preventing silent cross-tenant leaks.
     */
    public static function getPropertyId(): int {
        self::hydrateSession(false);
        if (!isset($_SESSION['property_id']) || (int)$_SESSION['property_id'] <= 0) {
            // Superadmin with no explicit property selected — use their primary_property_id if set
            if (self::isSuperAdmin()) {
                $primaryId = (int)($_SESSION['primary_property_id'] ?? 0);
                if ($primaryId > 0) {
                    return $primaryId;
                }
                // Last resort: we must NOT default to a potentially random ID like 1.
                // Log and throw an exception to prevent silent cross-tenant leaks.
                error_log("CRITICAL: Superadmin attempted property-scoped action without a valid property_id in session.");
                throw new \Exception("Unauthorized: No active property context found. Superadmin must select a property first.");
            }
            throw new \Exception("Unauthorized: No active property context found. Tenant isolation blocked this request.");
        }
        return (int)$_SESSION['property_id'];
    }

    /**
     * Sets the current active property ID in session (for property switcher).
     */
    public static function setPropertyId(int $propertyId): void {
        self::hydrateSession(true);
        $_SESSION['property_id'] = $propertyId;
    }

    /**
     * Ensures the user is logged in for normal web pages.
     * Redirects to the login page if unauthorized.
     */
    public static function requireLoginOrRedirect(): void {
        self::hydrateSession(false);
        
        if (!isset($_SESSION['user_id'])) {
            require_once __DIR__ . '/ModuleHost.php';
            header('Location: ' . ModuleHost::url('admin', '/login'));
            exit;
        }
    }

    public static function rememberCookieName(): string {
        return 'pms_remember';
    }

    public static function extendSessionCookie(int $lifetimeSeconds): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + $lifetimeSeconds,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)$params['secure'],
            'httponly' => true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    public static function issueRememberToken(\PDO $db, int $staffUserId): void {
        self::clearRememberCookie();
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 2592000);
        try {
            $stmt = $db->prepare("INSERT INTO staff_remember_tokens (staff_user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$staffUserId, $selector, hash('sha256', $validator), $expires]);
        } catch (\PDOException $e) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::rememberCookieName(), $selector . ':' . $validator, [
            'expires' => time() + 2592000,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        self::extendSessionCookie(2592000);
    }

    public static function clearRememberCookie(): void {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::rememberCookieName(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function revokeRememberTokens(\PDO $db, ?int $staffUserId = null): void {
        $raw = (string)($_COOKIE[self::rememberCookieName()] ?? '');
        if (str_contains($raw, ':')) {
            [$selector] = explode(':', $raw, 2);
            try {
                $db->prepare("DELETE FROM staff_remember_tokens WHERE selector = ?")->execute([$selector]);
            } catch (\PDOException $e) {
            }
        }
        if ($staffUserId) {
            try {
                $db->prepare("DELETE FROM staff_remember_tokens WHERE staff_user_id = ?")->execute([$staffUserId]);
            } catch (\PDOException $e) {
            }
        }
        self::clearRememberCookie();
    }

    public static function resumeRememberedSession(): void {
        if (!empty($_SESSION['user_id'])) {
            return;
        }
        $raw = (string)($_COOKIE[self::rememberCookieName()] ?? '');
        if (!str_contains($raw, ':')) {
            return;
        }
        [$selector, $validator] = explode(':', $raw, 2);
        if ($selector === '' || $validator === '') {
            return;
        }
        try {
            require_once __DIR__ . '/Database.php';
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT t.*, u.username, u.access_level, u.property_id, u.role_id, u.is_active
                FROM staff_remember_tokens t
                JOIN staff_users u ON u.id = t.staff_user_id
                WHERE t.selector = ? AND t.expires_at > NOW()
                LIMIT 1");
            $stmt->execute([$selector]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
                self::clearRememberCookie();
                return;
            }
            if (isset($row['is_active']) && (int)$row['is_active'] !== 1) {
                self::revokeRememberTokens($db, (int)$row['staff_user_id']);
                return;
            }
            // Rotate remember token on every successful use
            try {
                $db->prepare('DELETE FROM staff_remember_tokens WHERE selector = ?')->execute([$selector]);
            } catch (\PDOException $e) {
            }
            self::issueRememberToken($db, (int)$row['staff_user_id']);

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['staff_user_id'];
            $_SESSION['role'] = $row['access_level'];
            $_SESSION['access_level'] = $row['access_level'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['staff_user'] = $row['username'];
            $_SESSION['primary_property_id'] = (int)($row['property_id'] ?? 0);
            $_SESSION['property_id'] = (int)($row['property_id'] ?? 0);
            if ($row['access_level'] === 'superadmin') {
                $_SESSION['saas_admin_id'] = (int)$row['staff_user_id'];
                $_SESSION['saas_admin_username'] = $row['username'];
                $_SESSION['saas_admin_role'] = 'superadmin';
            }
            self::applyCustomPermissions($db, $row['role_id'] ?? null, (int)($row['property_id'] ?? 0));
            self::extendSessionCookie(2592000);
        } catch (\Throwable $e) {
        }
    }
}
