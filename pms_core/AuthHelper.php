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
            'manage_properties', 'manage_saas', 'manage_billing'
        ],
        'owner' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'cancel_booking', 'check_in_out',
            'view_folio', 'edit_folio', 'record_payment', 'refund_payment', 'generate_payment_link',
            'view_finance', 'manage_finance', 'export_finance', 'view_reports', 'manage_guests',
            'upload_document', 'housekeeping', 'manage_rooms', 'manage_staff', 'manage_settings',
            'view_audit_logs', 'send_whatsapp', 'view_error_logs', 'resolve_error_logs'
        ],
        'admin' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'cancel_booking', 'check_in_out',
            'view_folio', 'edit_folio', 'record_payment', 'refund_payment', 'generate_payment_link',
            'view_finance', 'manage_finance', 'export_finance', 'view_reports', 'manage_guests',
            'upload_document', 'housekeeping', 'manage_rooms', 'manage_staff', 'manage_settings',
            'view_audit_logs', 'send_whatsapp', 'view_error_logs', 'resolve_error_logs'
        ],
        'manager' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'cancel_booking', 'check_in_out',
            'view_folio', 'edit_folio', 'record_payment', 'generate_payment_link',
            'view_finance', 'view_reports', 'manage_guests',
            'upload_document', 'housekeeping', 'send_whatsapp', 'view_audit_logs'
        ],
        'receptionist' => [
            'view_dashboard', 'create_booking', 'edit_booking', 'check_in_out',
            'view_folio', 'record_payment', 'generate_payment_link', 'manage_guests',
            'upload_document', 'housekeeping', 'send_whatsapp'
        ],
        'housekeeping' => [
            'view_dashboard', 'housekeeping'
        ],
        'maintenance' => [
            'view_dashboard', 'manage_rooms' // Can update room states
        ],
        'fb_cashier' => [
            'view_dashboard', 'manage_pos' // Need to add manage_pos to permissions
        ],
        'night_auditor' => [
            'view_dashboard', 'view_reports', 'view_finance', 'run_night_audit' // Need to add run_night_audit
        ]
    ];
    
    /**
     * Ensures the user is logged in. 
     * Returns a 401 JSON response and exits if unauthorized.
     */
    public static function requireLogin(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
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
     * Non-exiting permission check. Useful for rendering UI elements.
     */
    public static function can(string $permission): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        $role = self::getRole();
        if ($role === null) {
            return false;
        }
        
        // Check if user has a custom role with custom permissions loaded in session
        if (isset($_SESSION['custom_permissions']) && is_array($_SESSION['custom_permissions'])) {
            return in_array($permission, $_SESSION['custom_permissions'], true);
        }
        
        $allowedPerms = self::PERMISSIONS[$role] ?? [];
        return in_array($permission, $allowedPerms, true);
    }

    /**
     * Returns a map of all available system permissions with human-readable labels.
     */
    public static function getAllPermissions(): array {
        $permissions = [
            'view_dashboard' => 'View Dashboard',
            'create_booking' => 'Create Booking',
            'edit_booking' => 'Edit Booking',
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
            'run_night_audit' => 'Run Night Audit'
        ];
        
        if (self::isSuperAdmin()) {
            $permissions['manage_properties'] = 'Manage Properties';
            $permissions['manage_saas'] = 'Manage SaaS Platform';
            $permissions['manage_billing'] = 'Manage Billing';
        }
        
        return $permissions;
    }

    /**
     * Returns the current user's role from the session.
     * Checks both 'role' and 'access_level' for backward compatibility with active sessions.
     */
    public static function getRole(): ?string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
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
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return [
            'id'       => $_SESSION['user_id'] ?? null,
            'role'     => self::getRole(),
            'username' => $_SESSION['username'] ?? 'User'
        ];
    }

    /**
     * Returns the current active property ID from the session (default 1).
     */
    public static function getPropertyId(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return (int)($_SESSION['property_id'] ?? 1);
    }

    /**
     * Sets the current active property ID in session (for property switcher).
     */
    public static function setPropertyId(int $propertyId): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['property_id'] = $propertyId;
    }

    /**
     * Ensures the user is logged in for normal web pages.
     * Redirects to the login page if unauthorized.
     */
    public static function requireLoginOrRedirect(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id'])) {
            header('Location: /admin/login.php');
            exit;
        }
    }
}
