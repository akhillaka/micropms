<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/config.php';
$db = Database::getInstance()->getConnection();
require_once __DIR__ . '/../../pms_core/saas_plans.php';

// Enforce SaaS Subdomain Restriction if configured
try {
    $saasSubdomain = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'SAAS_PORTAL_SUBDOMAIN'")->fetchColumn();
    if (!empty($saasSubdomain)) {
        $currentHost = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
        $targetHost = strtolower(trim($saasSubdomain));
        if ($currentHost !== $targetHost) {
            http_response_code(404);
            echo "<h1>404 Not Found</h1><p>The requested URL was not found on this server.</p>";
            exit;
        }
    }
} catch (\Exception $ex) {
    // Graceful fallback if schema is not loaded yet
}

$message = '';
$error = '';

// Auto-Schema & Default Property Initializer Guard
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `properties` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `property_code` VARCHAR(50) NOT NULL UNIQUE,
          `name` VARCHAR(150) NOT NULL,
          `address` TEXT DEFAULT NULL,
          `city` VARCHAR(100) DEFAULT NULL,
          `state` VARCHAR(100) DEFAULT NULL,
          `country` VARCHAR(100) DEFAULT 'India',
          `pincode` VARCHAR(10) DEFAULT NULL,
          `phone` VARCHAR(20) DEFAULT NULL,
          `email` VARCHAR(255) DEFAULT NULL,
          `gstin` VARCHAR(20) DEFAULT NULL,
          `is_active` TINYINT(1) DEFAULT 1,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Add property_id column to core tables if missing
    $db->exec("ALTER TABLE `rooms` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;");
    $db->exec("ALTER TABLE `bookings` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;");
    $db->exec("ALTER TABLE `staff_users` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;");
    $db->exec("ALTER TABLE `room_categories` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;");
    $db->exec("ALTER TABLE `sliding_rates` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;");
    $db->exec("ALTER TABLE `finance_transactions` ADD COLUMN IF NOT EXISTS `property_id` INT DEFAULT 1;");

        // Seed default property from system settings if empty
        $pCount = (int)$db->query("SELECT COUNT(*) FROM properties")->fetchColumn();
        if ($pCount === 0) {
            $pName = defined('PROPERTY_NAME') && !empty(PROPERTY_NAME) ? PROPERTY_NAME : 'Primary Hotel Property';
            $pAddr = defined('PROPERTY_ADDRESS') ? PROPERTY_ADDRESS : '';
            $pPhone = defined('PROPERTY_PHONE') ? PROPERTY_PHONE : '';
            $pEmail = defined('PROPERTY_EMAIL') ? PROPERTY_EMAIL : '';
            
            $stmtSeed = $db->prepare("INSERT INTO properties (id, property_code, name, address, phone, email, is_active) VALUES (1, 'PROP-DEFAULT', ?, ?, ?, ?, 1)");
            $stmtSeed->execute([$pName, $pAddr, $pPhone, $pEmail]);
            
            // Link any unassigned rooms & bookings to default property 1
            $db->exec("UPDATE rooms SET property_id = 1 WHERE property_id IS NULL OR property_id = 0;");
            $db->exec("UPDATE bookings SET property_id = 1 WHERE property_id IS NULL OR property_id = 0;");
        }

        // Seed default owner staff user if missing
        $userCount = (int)$db->query("SELECT COUNT(*) FROM staff_users WHERE access_level = 'owner'")->fetchColumn();
        if ($userCount === 0) {
            $rawPass = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'), 0, 12);
            $rawPin = (string)rand(1000, 9999);
            $defPassHash = password_hash($rawPass, PASSWORD_BCRYPT);
            $defPinHash = password_hash($rawPin, PASSWORD_BCRYPT);
            $db->exec("INSERT INTO staff_users (username, password_hash, pin_hash, access_level, role, property_id, is_active) VALUES ('admin', '{$defPassHash}', '{$defPinHash}', 'owner', 'Owner', 1, 1)");
            error_log("CRITICAL: Initial admin user created. Username: admin, Password: {$rawPass}, PIN: {$rawPin}");
        }
    } catch (\Exception $ex) {
        // Suppress schema guard errors
    }

    $createdCredentials = null;

    // Handle Property Creation / Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'switch_context') {
            $pId = (int)($_POST['property_id'] ?? 0);
            if ($pId > 0) {
                // To switch context, we could mock the staff session, but the Superadmin is a separate session.
                // For now, we set a global override if the system supports it, or redirect.
                // Since superadmin lacks a staff_users ID, we simulate it by setting a special session flag:
                $_SESSION['user_id'] = 0; 
                $_SESSION['role'] = 'owner';
                $_SESSION['property_id'] = $pId;
                header('Location: ../admin/index.php');
                exit;
            }
        }

        if ($action === 'get_staff') {
            header('Content-Type: application/json');
            $pId = (int)($_POST['property_id'] ?? 0);
            try {
                $stmt = $db->prepare("SELECT id, username, access_level, role, is_active, created_at FROM staff_users WHERE property_id = ? ORDER BY id ASC");
                $stmt->execute([$pId]);
                $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'staff' => $staff]);
            } catch (\Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        if ($action === 'get_audit_logs') {
            header('Content-Type: application/json');
            $pId = isset($_POST['property_id']) && $_POST['property_id'] !== '' ? (int)$_POST['property_id'] : null;
            try {
                $sql = "SELECT al.*, p.name as property_name, su.username 
                        FROM audit_logs al 
                        LEFT JOIN properties p ON al.property_id = p.id 
                        LEFT JOIN staff_users su ON al.staff_id = su.id ";
                $params = [];
                if ($pId !== null) {
                    $sql .= "WHERE al.property_id = ? ";
                    $params[] = $pId;
                }
                $sql .= "ORDER BY al.id DESC LIMIT 100";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'logs' => $logs]);
            } catch (\Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        if ($action === 'save_staff') {
            header('Content-Type: application/json');
            $pId = (int)($_POST['property_id'] ?? 0);
            $suId = (int)($_POST['staff_id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $accessLevel = trim($_POST['access_level'] ?? 'manager');
            $role = trim($_POST['role'] ?? 'Manager');
            $isActive = (int)($_POST['is_active'] ?? 1);
            $password = trim($_POST['password'] ?? '');
            $pin = trim($_POST['pin'] ?? '');

            if (empty($username) || $pId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Username and property ID required.']);
                exit;
            }

            try {
                if ($suId > 0) {
                    $sql = "UPDATE staff_users SET username = ?, access_level = ?, role = ?, is_active = ? WHERE id = ? AND property_id = ?";
                    $params = [$username, $accessLevel, $role, $isActive, $suId, $pId];
                    
                    if (!empty($password)) {
                        $sql = "UPDATE staff_users SET username = ?, access_level = ?, role = ?, is_active = ?, password_hash = ? WHERE id = ? AND property_id = ?";
                        $params = [$username, $accessLevel, $role, $isActive, password_hash($password, PASSWORD_BCRYPT), $suId, $pId];
                    }
                    if (!empty($pin) && preg_match('/^\d{4}$/', $pin)) {
                        $sql = str_replace("WHERE", ", pin_hash = ? WHERE", $sql);
                        $pinHash = password_hash($pin, PASSWORD_BCRYPT);
                        array_splice($params, count($params) - 2, 0, [$pinHash]);
                    }
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                } else {
                    $passHash = password_hash(!empty($password) ? $password : 'pass123', PASSWORD_BCRYPT);
                    $pinHash = password_hash(!empty($pin) && preg_match('/^\d{4}$/', $pin) ? $pin : '1234', PASSWORD_BCRYPT);
                    
                    $stmt = $db->prepare("INSERT INTO staff_users (username, password_hash, pin_hash, access_level, role, property_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $passHash, $pinHash, $accessLevel, $role, $pId, $isActive]);
                }
                echo json_encode(['success' => true]);
            } catch (\Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        if ($action === 'create_property') {
            $pCode = strtoupper(trim($_POST['property_code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $gstin = trim($_POST['gstin'] ?? '');
            $plan = trim($_POST['plan'] ?? 'starter');
            
            $plansConfig = SaaSPlans::get($db);
            $planDefaults = $plansConfig[$plan] ?? $plansConfig['starter'];
            $maxRooms = (int)($_POST['max_rooms'] ?? $planDefaults['max_rooms']);
            $maxStaff = (int)($planDefaults['max_staff'] ?? 5);
            
            // Admin user inputs or auto-generations
            $adminUser = trim($_POST['admin_username'] ?? '');
            if (empty($adminUser)) {
                $adminUser = 'admin_' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $pCode));
            }
            
            $rawPass = trim($_POST['admin_password'] ?? '');
            if (empty($rawPass)) {
                $rawPass = 'Pass' . rand(1000, 9999);
            }
            
            $rawPin = trim($_POST['admin_pin'] ?? '');
            if (empty($rawPin) || !preg_match('/^\d{4}$/', $rawPin)) {
                $rawPin = (string)rand(1000, 9999);
            }

            if (empty($pCode) || empty($name)) {
                $error = 'Property code and name are required.';
            } else {
                try {
                    $db->beginTransaction();

                    $stmt = $db->prepare("INSERT INTO properties (property_code, name, city, state, phone, email, gstin, plan, max_rooms, max_staff, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$pCode, $name, $city, $state, $phone, $email, $gstin, $plan, $maxRooms, $maxStaff]);
                    $propId = (int)$db->lastInsertId();

                    // Create Admin Staff User for the new property
                    $passHash = password_hash($rawPass, PASSWORD_BCRYPT);
                    $pinHash = password_hash($rawPin, PASSWORD_BCRYPT);

                    $userStmt = $db->prepare("INSERT INTO staff_users (username, password_hash, pin_hash, access_level, role, property_id, is_active) VALUES (?, ?, ?, 'owner', 'Property Admin', ?, 1)");
                    $userStmt->execute([$adminUser, $passHash, $pinHash, $propId]);

                    $db->commit();

                    $message = "Property '{$name}' registered successfully under '{$plan}' plan!";
                    $createdCredentials = [
                        'property_name' => $name,
                        'property_code' => $pCode,
                        'username' => $adminUser,
                        'password' => $rawPass,
                        'pin' => $rawPin
                    ];
                } catch (\Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Failed to create property: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'edit_property') {
            $pId = (int)($_POST['property_id'] ?? 0);
            $pCode = strtoupper(trim($_POST['property_code'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $gstin = trim($_POST['gstin'] ?? '');
            $plan = trim($_POST['plan'] ?? 'starter');
            $maxRooms = (int)($_POST['max_rooms'] ?? 25);

            if ($pId > 0 && !empty($pCode) && !empty($name)) {
                try {
                    $features = [
                        'ocr_google_vision' => isset($_POST['feat_ocr_google_vision']) && $_POST['feat_ocr_google_vision'] === '1',
                        'whatsapp_automations' => isset($_POST['feat_whatsapp_automations']) && $_POST['feat_whatsapp_automations'] === '1'
                    ];
                    $featuresJson = json_encode($features);

                    $stmt = $db->prepare("UPDATE properties SET property_code = ?, name = ?, address = ?, city = ?, state = ?, phone = ?, email = ?, gstin = ?, plan = ?, max_rooms = ?, features_json = ? WHERE id = ?");
                    $stmt->execute([$pCode, $name, $address, $city, $state, $phone, $email, $gstin, $plan, $maxRooms, $featuresJson, $pId]);
                    $message = "Property '{$name}' updated successfully!";
                } catch (\Exception $e) {
                    $error = 'Failed to update property: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'toggle_status') {
            $pId = (int)($_POST['property_id'] ?? 0);
            if ($pId > 0 && $pId !== 1) { // Prevent toggling default property
                $db->prepare("UPDATE properties SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?")->execute([$pId]);
                $message = "Property status updated.";
            }
        } elseif ($action === 'delete_property') {
            $pId = (int)($_POST['property_id'] ?? 0);
            if ($pId === 1) {
                $error = "Cannot delete the primary default property (ID 1).";
            } elseif ($pId > 0) {
                try {
                    $db->beginTransaction();
                    
                    // Clean up associated data
                    $db->prepare("DELETE FROM audit_logs WHERE property_id = ?")->execute([$pId]);
                    $db->prepare("DELETE FROM housekeeping_logs WHERE property_id = ?")->execute([$pId]);
                    $db->prepare("DELETE FROM finance_transactions WHERE property_id = ?")->execute([$pId]);
                    $db->prepare("DELETE FROM payment_gateway_configs WHERE property_id = ?")->execute([$pId]);
                    $db->prepare("DELETE FROM saas_subscriptions WHERE property_id = ?")->execute([$pId]);
                    $db->prepare("DELETE FROM staff_users WHERE property_id = ? AND access_level != 'superadmin'")->execute([$pId]);
                    
                    // Delete bookings before rooms
                    $db->prepare("DELETE FROM bookings WHERE property_id = ?")->execute([$pId]);
                    $db->prepare("DELETE FROM rooms WHERE property_id = ?")->execute([$pId]);
                    $db->prepare("DELETE FROM room_categories WHERE property_id = ?")->execute([$pId]);
                    $db->prepare("DELETE FROM sliding_rates WHERE property_id = ?")->execute([$pId]);
                    
                    // Finally delete the property
                    $db->prepare("DELETE FROM properties WHERE id = ?")->execute([$pId]);
                    
                    $db->commit();
                    $message = "Property and all its associated data deleted successfully.";
                } catch (\Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = "Failed to delete property: " . $e->getMessage();
                }
            }
        } elseif ($action === 'save_saas_settings') {
            $updates = [];
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'SAAS_') === 0) {
                    $updates[$key] = trim($value);
                }
            }
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("INSERT INTO system_settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
                foreach ($updates as $k => $v) {
                    $stmt->execute([$k, $v]);
                }
                $db->commit();
                $message = "SaaS Settings updated successfully.";
            } catch (\Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Failed to update SaaS Settings: " . $e->getMessage();
            }
        } elseif ($action === 'verify_dns') {
            $customDomain = trim($_POST['custom_domain'] ?? '');
            
            require_once __DIR__ . '/../../pms_core/services/DNSValidator.php';
            
            // Get target SaaS subdomain settings
            $saasTarget = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'SAAS_PORTAL_SUBDOMAIN'")->fetchColumn() ?: ($_SERVER['HTTP_HOST'] ?? 'saas.micropms.com');
            
            $res = DNSValidator::verifyDomain($customDomain, $saasTarget);
        } elseif ($action === 'save_plans_config') {
            $plans = SaaSPlans::get($db);
            foreach ($plans as $key => &$planData) {
                if (isset($_POST["plan_{$key}_price"])) {
                    $planData['price'] = (float)$_POST["plan_{$key}_price"];
                }
                if (isset($_POST["plan_{$key}_max_rooms"])) {
                    $planData['max_rooms'] = (int)$_POST["plan_{$key}_max_rooms"];
                }
                if (isset($_POST["plan_{$key}_max_staff"])) {
                    $planData['max_staff'] = (int)$_POST["plan_{$key}_max_staff"];
                }
                if (isset($planData['features'])) {
                    foreach ($planData['features'] as $fKey => &$fVal) {
                        $fVal = isset($_POST["plan_{$key}_feat_{$fKey}"]) && $_POST["plan_{$key}_feat_{$fKey}"] === '1';
                    }
                }
            }
            if (SaaSPlans::save($db, $plans)) {
                $message = "SaaS Plans Configuration saved successfully!";
            } else {
                $error = "Failed to save plans configuration.";
            }
        } elseif ($action === 'create_new_plan') {
            $newPlanId = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['new_plan_id'] ?? ''));
            $newPlanName = trim($_POST['new_plan_name'] ?? '');
            if (!empty($newPlanId) && !empty($newPlanName)) {
                $plans = SaaSPlans::get($db);
                if (!isset($plans[$newPlanId])) {
                    $plans[$newPlanId] = [
                        'name' => $newPlanName,
                        'price' => 0,
                        'max_rooms' => 999,
                        'max_staff' => 50,
                        'features' => [
                            'ocr_google_vision' => true,
                            'whatsapp_automations' => true,
                            'custom_domain_mapping' => true,
                            'pos_module' => true,
                            'whatsapp_module' => true,
                            'housekeeping_module' => true
                        ]
                    ];
                    if (SaaSPlans::save($db, $plans)) {
                        $message = "New custom plan '{$newPlanName}' created successfully!";
                    } else {
                        $error = "Failed to create plan.";
                    }
                } else {
                    $error = "A plan with this ID already exists.";
                }
            } else {
                $error = "Plan ID and Name are required.";
            }
        }
    }

// Fetch all properties
$properties = [];
try {
    $stmt = $db->query("
        SELECT p.*, 
               (SELECT COUNT(*) FROM rooms r WHERE r.property_id = p.id OR (p.id = 1 AND (r.property_id IS NULL OR r.property_id = 0))) as room_count,
               (SELECT COUNT(*) FROM bookings b WHERE b.property_id = p.id OR (p.id = 1 AND (b.property_id IS NULL OR b.property_id = 0))) as booking_count,
               (SELECT COALESCE(SUM(total_amount), 0) FROM bookings b WHERE (b.property_id = p.id OR (p.id = 1 AND (b.property_id IS NULL OR b.property_id = 0))) AND payment_status = 'completed_paid') as total_revenue
        FROM properties p
        ORDER BY p.id ASC
    ");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    $properties = [];
}

// Fetch SaaS Global Configurations
$settings = [];
try {
    $stmt = $db->query("SELECT key_name, key_value FROM system_settings WHERE key_name LIKE 'SAAS_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (\Exception $e) {}

// For backwards compatibility in this file
$saasPlatformName = $settings['SAAS_PLATFORM_NAME'] ?? 'MicroPMS SaaS Platform';
$saasSupportEmail = $settings['SAAS_SUPPORT_EMAIL'] ?? 'support@micropms.com';
$saasTrialDays = (int)($settings['SAAS_TRIAL_DAYS'] ?? 30);
$saasDefaultCurrency = $settings['SAAS_DEFAULT_CURRENCY'] ?? 'INR';
$saasPortalSubdomain = $settings['SAAS_PORTAL_SUBDOMAIN'] ?? '';
$saasPlans = SaaSPlans::get($db);

$metrics = [
    'active_tenants' => 0,
    'total_rooms' => 0,
    'active_staff' => 0,
    'estimated_mrr' => 0.0
];
foreach ($properties as $p) {
    if ((int)$p['is_active'] === 1) {
        $metrics['active_tenants']++;
        $metrics['total_rooms'] += (int)$p['room_count'];
        $pPlan = $p['plan'] ?? 'starter';
        if (isset($saasPlans[$pPlan]['price'])) {
            $metrics['estimated_mrr'] += $saasPlans[$pPlan]['price'];
        }
    }
}
try {
    $metrics['active_staff'] = (int)$db->query("SELECT COUNT(*) FROM staff_users WHERE is_active = 1")->fetchColumn();
} catch (\Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Super-Admin Portal | MicroPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-panel {
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="bg-[#050811] text-slate-100 min-h-screen pb-16">

    <div class="max-w-7xl mx-auto px-4 py-8">
        
        <!-- Header -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8 pb-6 border-b border-slate-800">
            <div>
                <h1 class="text-3xl font-extrabold text-white flex items-center gap-3 tracking-tight">
                    <span class="p-2.5 bg-sky-500/10 border border-sky-500/20 text-sky-400 rounded-2xl">
                        <i class="ph ph-buildings text-2xl"></i>
                    </span>
                    SaaS Super-Admin Console
                </h1>
                <p class="text-slate-400 text-sm mt-2">Manage tenant credentials, system settings, subscription billing, and security diagnostics</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="logout.php" class="px-4 py-2.5 bg-rose-900/40 hover:bg-rose-900/60 text-rose-400 font-bold rounded-xl text-sm transition flex items-center gap-2 border border-rose-500/20">
                    <i class="ph ph-sign-out text-base"></i> Logout
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-6 p-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 rounded-xl font-bold text-sm">
                ✅ <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="mb-6 p-4 bg-rose-500/10 border border-rose-500/30 text-rose-400 rounded-xl font-bold text-sm">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($createdCredentials)): ?>
            <div class="mb-8 p-6 bg-sky-950/40 border border-sky-500/30 rounded-2xl shadow-xl space-y-4">
                <div class="flex items-center justify-between border-b border-sky-500/20 pb-3">
                    <h3 class="text-lg font-extrabold text-sky-400 flex items-center gap-2">
                        <i class="ph ph-key"></i> Created Hotel Admin Credentials
                    </h3>
                    <span class="text-xs bg-sky-500/20 text-sky-300 font-bold px-3 py-1 rounded-full">Ready to copy</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                    <div class="bg-slate-950/60 p-4 rounded-xl border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Property Name</div>
                        <div class="font-extrabold text-white mt-1"><?= htmlspecialchars($createdCredentials['property_name']) ?></div>
                        <div class="text-xs font-mono text-sky-400 mt-0.5"><?= htmlspecialchars($createdCredentials['property_code']) ?></div>
                    </div>
                    <div class="bg-slate-950/60 p-4 rounded-xl border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Admin Username</div>
                        <div class="font-bold text-emerald-400 font-mono text-base mt-1"><?= htmlspecialchars($createdCredentials['username']) ?></div>
                    </div>
                    <div class="bg-slate-950/60 p-4 rounded-xl border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Password</div>
                        <div class="font-bold text-amber-400 font-mono text-base mt-1"><?= htmlspecialchars($createdCredentials['password']) ?></div>
                    </div>
                    <div class="bg-slate-950/60 p-4 rounded-xl border border-slate-800">
                        <div class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">PWA PIN</div>
                        <div class="font-bold text-purple-400 font-mono text-base mt-1"><?= htmlspecialchars($createdCredentials['pin']) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Quick Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="glass-panel p-6 rounded-2xl flex items-center justify-between">
                <div>
                    <div class="text-slate-400 text-xs font-bold uppercase tracking-wider">Active Tenants</div>
                    <div class="text-3xl font-extrabold text-white mt-2"><?= $metrics['active_tenants'] ?> <span class="text-xs font-normal text-slate-500">/ <?= count($properties) ?></span></div>
                </div>
                <div class="p-3 bg-sky-500/10 text-sky-400 border border-sky-500/20 rounded-xl">
                    <i class="ph ph-house-line text-2xl"></i>
                </div>
            </div>
            <div class="glass-panel p-6 rounded-2xl flex items-center justify-between">
                <div>
                    <div class="text-slate-400 text-xs font-bold uppercase tracking-wider">Active Rooms</div>
                    <div class="text-3xl font-extrabold text-emerald-400 mt-2">
                        <?= $metrics['total_rooms'] ?>
                    </div>
                </div>
                <div class="p-3 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-xl">
                    <i class="ph ph-bed text-2xl"></i>
                </div>
            </div>
            <div class="glass-panel p-6 rounded-2xl flex items-center justify-between">
                <div>
                    <div class="text-slate-400 text-xs font-bold uppercase tracking-wider">Total Staff Seats</div>
                    <div class="text-3xl font-extrabold text-amber-400 mt-2">
                        <?= $metrics['active_staff'] ?>
                    </div>
                </div>
                <div class="p-3 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded-xl">
                    <i class="ph ph-users text-2xl"></i>
                </div>
            </div>
            <div class="glass-panel p-6 rounded-2xl flex items-center justify-between">
                <div>
                    <div class="text-slate-400 text-xs font-bold uppercase tracking-wider">Projected MRR</div>
                    <div class="text-3xl font-extrabold text-sky-400 mt-2">
                        ₹<?= number_format($metrics['estimated_mrr'], 2) ?>
                    </div>
                </div>
                <div class="p-3 bg-sky-500/10 text-sky-400 border border-sky-500/20 rounded-xl">
                    <i class="ph ph-currency-inr text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Dynamic Dashboard Navigation Tabs -->
        <div class="flex items-center gap-2 border-b border-slate-800 mb-8 overflow-x-auto pb-px">
            <button onclick="switchTab('properties')" id="tab-properties" class="tab-btn px-5 py-3 border-b-2 font-bold text-sm transition-all flex items-center gap-2 border-sky-500 text-sky-400">
                <i class="ph ph-list-bullets text-base"></i> Registered Hotels
            </button>
            <button onclick="switchTab('onboard')" id="tab-onboard" class="tab-btn px-5 py-3 border-b-2 font-bold text-sm transition-all flex items-center gap-2 border-transparent text-slate-400 hover:text-white">
                <i class="ph ph-plus-circle text-base"></i> Onboard New Tenant
            </button>
            <button onclick="switchTab('settings')" id="tab-settings" class="tab-btn px-5 py-3 border-b-2 font-bold text-sm transition-all flex items-center gap-2 border-transparent text-slate-400 hover:text-white">
                <i class="ph ph-gear text-base"></i> Platform Settings
            </button>
            <button onclick="switchTab('logs'); loadSystemLogs();" id="tab-logs" class="tab-btn px-5 py-3 border-b-2 font-bold text-sm transition-all flex items-center gap-2 border-transparent text-slate-400 hover:text-white">
                <i class="ph ph-scroll text-base"></i> System Logs
            </button>
        </div>

        <!-- TAB CONTENT: Properties List -->
        <div id="content-properties" class="tab-content space-y-6">
            <div class="glass-panel rounded-2xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-900/60 text-slate-400 uppercase text-xs border-b border-slate-800">
                            <tr>
                                <th class="px-6 py-4 font-bold">Property Details</th>
                                <th class="px-6 py-4 font-bold">Domain Mapping</th>
                                <th class="px-6 py-4 font-bold">SaaS Plan</th>
                                <th class="px-6 py-4 font-bold">Rooms Usage</th>
                                <th class="px-6 py-4 font-bold">System Status</th>
                                <th class="px-6 py-4 text-right font-bold">Management Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-slate-300">
                            <?php foreach ($properties as $p): ?>
                                <tr class="hover:bg-slate-900/20 transition-all">
                                    <td class="px-6 py-4">
                                        <div class="font-extrabold text-white text-base"><?= htmlspecialchars($p['name']) ?></div>
                                        <div class="text-xs text-sky-400 font-mono mt-0.5"><?= htmlspecialchars($p['property_code']) ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if (!empty($p['custom_domain'])): ?>
                                            <div class="space-y-1">
                                                <div class="text-xs font-mono text-emerald-400 flex items-center gap-1.5">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                    <?= htmlspecialchars($p['custom_domain']) ?>
                                                </div>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="verify_dns">
                                                    <input type="hidden" name="custom_domain" value="<?= htmlspecialchars($p['custom_domain']) ?>">
                                                    <button type="submit" class="text-[10px] text-slate-400 hover:text-sky-400 underline transition-all font-semibold">🔍 Check DNS</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500 font-bold">No Custom Domain</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 rounded-md text-[10px] font-extrabold uppercase border <?= ($p['plan'] ?? 'starter') === 'enterprise' ? 'bg-purple-500/10 text-purple-400 border-purple-500/20' : (($p['plan'] ?? 'starter') === 'pro' ? 'bg-sky-500/10 text-sky-400 border-sky-500/20' : 'bg-slate-800/80 text-slate-400 border-slate-700/50') ?>">
                                            <?= htmlspecialchars(ucfirst($p['plan'] ?? 'starter')) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-white text-sm"><?= $p['room_count'] ?> / <?= ($p['max_rooms'] ?? 25) >= 999 ? '∞' : ($p['max_rooms'] ?? 25) ?></div>
                                        <div class="w-24 bg-slate-800 h-1.5 rounded-full mt-1.5 overflow-hidden">
                                            <?php 
                                                $maxVal = (int)($p['max_rooms'] ?? 25);
                                                $pct = $maxVal > 0 ? min(100, round(($p['room_count'] / $maxVal) * 100)) : 0;
                                            ?>
                                            <div class="bg-sky-500 h-full rounded-full" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="property_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="px-3 py-1 rounded-full text-xs font-bold border transition <?= (int)$p['is_active'] === 1 ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20' : 'bg-rose-500/10 border-rose-500/20 text-rose-400 hover:bg-rose-500/20' ?>">
                                                <?= (int)$p['is_active'] === 1 ? 'Active' : 'Disabled' ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-6 py-4 text-right space-x-2">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="switch_context">
                                            <input type="hidden" name="property_id" value="<?= $p['id'] ?>">
                                            <button type="submit" title="Switch view to this hotel's dashboard" class="px-3 py-2 bg-emerald-950/60 hover:bg-emerald-900/80 text-emerald-400 border border-emerald-500/20 rounded-xl text-xs font-bold transition">
                                                👁️ Switch Context
                                            </button>
                                        </form>
                                        <button onclick="openEditModal(<?= htmlspecialchars(json_encode($p)) ?>)" class="px-3 py-2 bg-slate-900 hover:bg-slate-800 text-sky-400 border border-slate-800 rounded-xl text-xs font-bold transition">
                                            ✏️ Edit Details
                                        </button>
                                        <button onclick="openStaffModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')" class="px-3 py-2 bg-slate-900 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl text-xs font-bold transition">
                                             👤 Staff Users
                                        </button>
                                        <?php if ((int)$p['id'] !== 1): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('WARNING: Are you sure you want to PERMANENTLY DELETE this property and all its associated bookings, rooms, and staff? This cannot be undone!');">
                                            <input type="hidden" name="action" value="delete_property">
                                            <input type="hidden" name="property_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="px-3 py-2 bg-rose-950/60 hover:bg-rose-900/80 text-rose-400 border border-rose-500/20 rounded-xl text-xs font-bold transition" title="Delete Property">
                                                <i class="ph ph-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: Onboard Property -->
        <div id="content-onboard" class="tab-content hidden">
            <div class="glass-panel p-6 rounded-2xl max-w-2xl mx-auto">
                <h2 class="text-xl font-bold text-white mb-6 flex items-center gap-2">
                    <i class="ph ph-plus-circle text-sky-400"></i>
                    Register New Hotel Property
                </h2>
                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="create_property">
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Property Code *</label>
                            <input type="text" name="property_code" placeholder="PROP-DELHI-01" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Hotel / Property Name *</label>
                            <input type="text" name="name" placeholder="Grand Transit Hotel" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-800">
                        <div class="text-xs font-bold text-sky-400 uppercase tracking-wider mb-3">👤 Initial Owner Admin Account</div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Admin Username</label>
                                <input type="text" name="admin_username" placeholder="Auto-generated if left empty" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Password</label>
                                    <input type="text" name="admin_password" placeholder="Auto-generated if empty" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">PWA Login PIN (4 digits)</label>
                                    <input type="text" name="admin_pin" maxlength="4" placeholder="1234" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-800">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">City</label>
                            <input type="text" name="city" placeholder="New Delhi" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">State</label>
                            <input type="text" name="state" placeholder="Delhi" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Subscription Plan</label>
                            <select name="plan" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                                <?php foreach ($saasPlans as $planId => $planData): ?>
                                    <option value="<?= htmlspecialchars($planId) ?>"><?= htmlspecialchars($planData['name']) ?> (Max <?= $planData['max_rooms'] >= 999 ? '∞' : $planData['max_rooms'] ?> Rooms)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Max Room Override</label>
                            <input type="number" name="max_rooms" placeholder="Plan defaults applied if empty" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Phone</label>
                            <input type="text" name="phone" placeholder="9876543210" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">GSTIN</label>
                            <input type="text" name="gstin" placeholder="07AAAAA0000A1Z5" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase mb-2">Email</label>
                        <input type="email" name="email" placeholder="owner@hotel.com" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                    </div>

                    <button type="submit" class="w-full py-3.5 bg-sky-600 hover:bg-sky-500 text-white font-extrabold rounded-xl text-sm transition shadow-lg shadow-sky-500/10">
                        ➕ Register Tenant Property
                    </button>
                </form>
            </div>
        </div>
            
           <!-- TAB CONTENT: Platform Settings -->
        <div id="content-settings" class="tab-content hidden">
            <form method="POST" class="space-y-6 max-w-4xl mx-auto">
                <input type="hidden" name="action" value="save_saas_settings">
                
                <!-- General Settings -->
                <div class="glass-panel p-6 rounded-2xl">
                    <h2 class="text-xl font-bold text-white mb-4 flex items-center gap-2"><i class="ph ph-gear text-sky-400"></i> General Configuration</h2>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Platform Name</label>
                            <input type="text" name="SAAS_PLATFORM_NAME" value="<?= htmlspecialchars($settings['SAAS_PLATFORM_NAME'] ?? 'MicroPMS') ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Support Email</label>
                            <input type="email" name="SAAS_SUPPORT_EMAIL" value="<?= htmlspecialchars($settings['SAAS_SUPPORT_EMAIL'] ?? '') ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Designated SaaS Domain</label>
                            <input type="text" name="SAAS_PORTAL_SUBDOMAIN" value="<?= htmlspecialchars($settings['SAAS_PORTAL_SUBDOMAIN'] ?? '') ?>" placeholder="saas.yourdomain.com" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none font-mono">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Trial Days</label>
                                <input type="number" name="SAAS_TRIAL_DAYS" value="<?= htmlspecialchars($settings['SAAS_TRIAL_DAYS'] ?? '30') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Currency</label>
                                <input type="text" name="SAAS_DEFAULT_CURRENCY" value="<?= htmlspecialchars($settings['SAAS_DEFAULT_CURRENCY'] ?? 'INR') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Branding & Theming -->
                <div class="glass-panel p-6 rounded-2xl">
                    <h2 class="text-xl font-bold text-white mb-4 flex items-center gap-2"><i class="ph ph-paint-brush text-purple-400"></i> Branding & Theming</h2>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Logo URL</label>
                            <input type="url" name="SAAS_LOGO_URL" value="<?= htmlspecialchars($settings['SAAS_LOGO_URL'] ?? '') ?>" placeholder="https://..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Primary Color (Hex)</label>
                            <div class="flex items-center gap-2">
                                <input type="color" name="SAAS_PRIMARY_COLOR" value="<?= htmlspecialchars($settings['SAAS_PRIMARY_COLOR'] ?? '#0ea5e9') ?>" class="h-10 w-12 rounded bg-transparent border-0 cursor-pointer">
                                <input type="text" name="SAAS_PRIMARY_COLOR" value="<?= htmlspecialchars($settings['SAAS_PRIMARY_COLOR'] ?? '#0ea5e9') ?>" placeholder="#0ea5e9" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none font-mono">
                            </div>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Custom CSS</label>
                            <textarea name="SAAS_CUSTOM_CSS" rows="3" placeholder="/* Inject CSS into tenant pages */" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-sky-500 focus:outline-none font-mono"><?= htmlspecialchars($settings['SAAS_CUSTOM_CSS'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <!-- SMTP Settings -->
                    <div class="glass-panel p-6 rounded-2xl">
                        <h2 class="text-xl font-bold text-white mb-4 flex items-center gap-2"><i class="ph ph-envelope-simple text-amber-400"></i> SMTP Settings</h2>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">SMTP Host</label>
                                <input type="text" name="SAAS_SMTP_HOST" value="<?= htmlspecialchars($settings['SAAS_SMTP_HOST'] ?? '') ?>" placeholder="smtp.mailgun.org" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Port</label>
                                    <input type="text" name="SAAS_SMTP_PORT" value="<?= htmlspecialchars($settings['SAAS_SMTP_PORT'] ?? '') ?>" placeholder="587" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Security</label>
                                    <select name="SAAS_SMTP_SECURE" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                                        <option value="tls" <?= ($settings['SAAS_SMTP_SECURE'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                        <option value="ssl" <?= ($settings['SAAS_SMTP_SECURE'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">SMTP User</label>
                                <input type="text" name="SAAS_SMTP_USER" value="<?= htmlspecialchars($settings['SAAS_SMTP_USER'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">SMTP Password</label>
                                <input type="password" name="SAAS_SMTP_PASS" value="<?= htmlspecialchars($settings['SAAS_SMTP_PASS'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                            </div>
                        </div>
                    </div>

                    <!-- Security & Billing -->
                    <div class="space-y-6">
                        <div class="glass-panel p-6 rounded-2xl">
                            <h2 class="text-xl font-bold text-white mb-4 flex items-center gap-2"><i class="ph ph-credit-card text-emerald-400"></i> Central Billing</h2>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Gateway</label>
                                    <select name="SAAS_PAYMENT_GATEWAY" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                                        <option value="razorpay" <?= ($settings['SAAS_PAYMENT_GATEWAY'] ?? '') === 'razorpay' ? 'selected' : '' ?>>Razorpay</option>
                                        <option value="stripe" <?= ($settings['SAAS_PAYMENT_GATEWAY'] ?? '') === 'stripe' ? 'selected' : '' ?>>Stripe</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Key ID</label>
                                    <input type="text" name="SAAS_PG_KEY_ID" value="<?= htmlspecialchars($settings['SAAS_PG_KEY_ID'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Key Secret</label>
                                    <input type="password" name="SAAS_PG_SECRET" value="<?= htmlspecialchars($settings['SAAS_PG_SECRET'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                                </div>
                            </div>
                        </div>

                        <div class="glass-panel p-6 rounded-2xl">
                            <h2 class="text-xl font-bold text-white mb-4 flex items-center gap-2"><i class="ph ph-shield-check text-rose-400"></i> Compliance & Security</h2>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">TOS URL</label>
                                    <input type="url" name="SAAS_TOS_URL" value="<?= htmlspecialchars($settings['SAAS_TOS_URL'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Privacy Policy URL</label>
                                    <input type="url" name="SAAS_PRIVACY_URL" value="<?= htmlspecialchars($settings['SAAS_PRIVACY_URL'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                                </div>
                                <div class="flex items-center justify-between pt-2">
                                    <span class="text-sm text-slate-300 font-semibold">Enable Public Registration</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="hidden" name="SAAS_PUBLIC_REGISTRATION" value="0">
                                        <input type="checkbox" name="SAAS_PUBLIC_REGISTRATION" value="1" <?= ($settings['SAAS_PUBLIC_REGISTRATION'] ?? '0') === '1' ? 'checked' : '' ?> class="sr-only peer">
                                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between pt-1">
                                    <span class="text-sm text-slate-300 font-semibold">Maintenance Mode</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="hidden" name="SAAS_MAINTENANCE_MODE" value="0">
                                        <input type="checkbox" name="SAAS_MAINTENANCE_MODE" value="1" <?= ($settings['SAAS_MAINTENANCE_MODE'] ?? '0') === '1' ? 'checked' : '' ?> class="sr-only peer">
                                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-rose-500"></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-4">
                    <button type="submit" class="w-full py-4 bg-sky-600 hover:bg-sky-500 text-white font-extrabold rounded-2xl text-base transition shadow-lg shadow-sky-500/20">
                        💾 Save All Global Settings
                    </button>
                </div>
            </form>

            <!-- SaaS Plans Configuration Panel -->
            <div class="glass-panel p-6 rounded-2xl max-w-2xl mx-auto mt-6">
                <h2 class="text-xl font-bold text-white mb-6 flex items-center justify-between">
                    <span class="flex items-center gap-2"><i class="ph ph-sliders text-emerald-400"></i> SaaS Plans Configuration</span>
                    <button onclick="document.getElementById('newPlanModal').classList.remove('hidden')" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg text-xs font-bold transition flex items-center gap-1"><i class="ph ph-plus"></i> Create Plan</button>
                </h2>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="save_plans_config">

                    <?php foreach ($saasPlans as $key => $plan): ?>
                        <div class="border-b border-slate-800/80 pb-5 mb-5 last:border-0 last:pb-0 last:mb-0 space-y-4">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-extrabold text-sky-400 uppercase tracking-wider"><?= htmlspecialchars($plan['name']) ?> Tier</h3>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Pricing (Monthly)</label>
                                    <input type="number" name="plan_<?= $key ?>_price" value="<?= $plan['price'] ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:border-sky-500 focus:outline-none font-bold">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Max Rooms Limit</label>
                                    <input type="number" name="plan_<?= $key ?>_max_rooms" value="<?= $plan['max_rooms'] ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:border-sky-500 focus:outline-none font-bold">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Max Staff Seats</label>
                                    <input type="number" name="plan_<?= $key ?>_max_staff" value="<?= $plan['max_staff'] ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:border-sky-500 focus:outline-none font-bold">
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-[10px] font-semibold text-slate-400 uppercase">Enable Feature Entitlements</label>
                                <div class="flex flex-wrap gap-4 text-xs">
                                    <?php foreach ($plan['features'] as $fKey => $fVal): ?>
                                        <label class="flex items-center gap-2 cursor-pointer text-slate-300 hover:text-white">
                                            <input type="hidden" name="plan_<?= $key ?>_feat_<?= $fKey ?>" value="0">
                                            <input type="checkbox" name="plan_<?= $key ?>_feat_<?= $fKey ?>" value="1" <?= $fVal ? 'checked' : '' ?> class="accent-sky-500 rounded bg-slate-950 border-slate-800">
                                            <?= htmlspecialchars(str_replace('_', ' ', $fKey)) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="w-full py-3 bg-slate-900 hover:bg-slate-800 text-emerald-400 font-bold rounded-xl text-sm border border-slate-800 transition">
                        💾 Save SaaS Plan Settings
                    </button>
                </form>
            </div>
        </div>

        <!-- TAB CONTENT: System Logs -->
        <div id="content-logs" class="tab-content hidden space-y-6">
            <div class="glass-panel p-6 rounded-2xl">
                <div class="flex items-center justify-between border-b border-slate-800 pb-4 mb-6">
                    <h2 class="text-xl font-bold text-white flex items-center gap-2">
                        <i class="ph ph-scroll text-sky-400"></i>
                        System Audit Logs
                    </h2>
                    <div class="flex items-center gap-3">
                        <select id="logFilterProperty" onchange="loadSystemLogs()" class="bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-xs focus:border-sky-500 focus:outline-none">
                            <option value="">All Properties (Global)</option>
                            <?php foreach ($properties as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['property_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button onclick="loadSystemLogs()" class="px-3 py-2 bg-slate-900 hover:bg-slate-800 text-sky-400 border border-slate-800 rounded-xl text-xs font-bold transition flex items-center gap-1">
                            <i class="ph ph-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>

                <div class="border border-slate-800 rounded-xl overflow-hidden bg-slate-950/20">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900/60 text-slate-400 uppercase text-[10px] font-bold border-b border-slate-800">
                            <tr>
                                <th class="px-4 py-3">Timestamp</th>
                                <th class="px-4 py-3">Property</th>
                                <th class="px-4 py-3">Actor / User</th>
                                <th class="px-4 py-3">Action Description</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">IP Address</th>
                            </tr>
                        </thead>
                        <tbody id="systemLogsBody">
                            <!-- Populated dynamically via AJAX -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- New Plan Modal -->
    <div id="newPlanModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-sm p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-lg font-bold text-white">Create Custom Plan</h3>
                <button onclick="document.getElementById('newPlanModal').classList.add('hidden')" class="text-slate-400 hover:text-white font-bold text-lg">✕</button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="create_new_plan">
                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Plan Identifier (No spaces)</label>
                    <input type="text" name="new_plan_id" placeholder="e.g. lifetime_free" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Display Name</label>
                    <input type="text" name="new_plan_name" placeholder="e.g. Lifetime Free Tier" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                </div>
                <div class="text-[10px] text-slate-400 italic">The new plan will be created with all modules enabled and unlimited resources by default. You can adjust limits and pricing in the Plans Configuration panel after creating it.</div>
                <button type="submit" class="w-full py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-sm transition">Create Plan</button>
            </form>
        </div>
    </div>

    <!-- Edit Property Modal -->
    <div id="editModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="ph ph-pencil-simple text-sky-400"></i>
                    Edit Property Details
                </h3>
                <button onclick="closeEditModal()" class="text-slate-400 hover:text-white font-bold text-lg">✕</button>
            </div>

            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="edit_property">
                <input type="hidden" name="property_id" id="edit_property_id">

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Property Code *</label>
                    <input type="text" name="property_code" id="edit_property_code" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Hotel / Property Name *</label>
                    <input type="text" name="name" id="edit_name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">SaaS Plan</label>
                        <select name="plan" id="edit_plan" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                            <?php foreach ($saasPlans as $planId => $planData): ?>
                                <option value="<?= htmlspecialchars($planId) ?>"><?= htmlspecialchars($planData['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Max Rooms</label>
                        <input type="number" name="max_rooms" id="edit_max_rooms" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Custom Domain</label>
                        <input type="text" name="custom_domain" id="edit_custom_domain" placeholder="hotel.com" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">City</label>
                        <input type="text" name="city" id="edit_city" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">State</label>
                        <input type="text" name="state" id="edit_state" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Address</label>
                    <textarea name="address" id="edit_address" rows="2" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Phone</label>
                        <input type="text" name="phone" id="edit_phone" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">GSTIN</label>
                        <input type="text" name="gstin" id="edit_gstin" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Email</label>
                    <input type="email" name="email" id="edit_email" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-sky-500 focus:outline-none">
                </div>

                <div class="space-y-2 py-2 border-t border-slate-800">
                    <label class="block text-[10px] font-bold text-slate-400 uppercase">Tenant Feature Flag Overrides (Add-ons)</label>
                    <div class="flex flex-wrap gap-4 text-xs text-slate-300">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="hidden" name="feat_ocr_google_vision" value="0">
                            <input type="checkbox" name="feat_ocr_google_vision" id="edit_feat_ocr_google_vision" value="1" class="accent-sky-500 rounded bg-slate-950 border-slate-800">
                            Google Vision OCR Scanner
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="hidden" name="feat_whatsapp_automations" value="0">
                            <input type="checkbox" name="feat_whatsapp_automations" id="edit_feat_whatsapp_automations" value="1" class="accent-sky-500 rounded bg-slate-950 border-slate-800">
                            WhatsApp Automations
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-xl text-sm transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-sky-600 hover:bg-sky-500 text-white font-bold rounded-xl text-sm transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tabId) {
            // Hide all contents
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            // Remove active classes from all buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('border-sky-500', 'text-sky-400');
                btn.classList.add('border-transparent', 'text-slate-400');
            });

            // Show active content
            document.getElementById('content-' + tabId).classList.remove('hidden');
            // Add active class to clicked button
            const activeBtn = document.getElementById('tab-' + tabId);
            activeBtn.classList.remove('border-transparent', 'text-slate-400');
            activeBtn.classList.add('border-sky-500', 'text-sky-400');
        }

        function openEditModal(p) {
            document.getElementById('edit_property_id').value = p.id || '';
            document.getElementById('edit_property_code').value = p.property_code || '';
            document.getElementById('edit_name').value = p.name || '';
            document.getElementById('edit_address').value = p.address || '';
            document.getElementById('edit_city').value = p.city || '';
            document.getElementById('edit_state').value = p.state || '';
            document.getElementById('edit_phone').value = p.phone || '';
            document.getElementById('edit_email').value = p.email || '';
            document.getElementById('edit_gstin').value = p.gstin || '';
            document.getElementById('edit_plan').value = p.plan || 'starter';
            document.getElementById('edit_max_rooms').value = p.max_rooms || '';
            document.getElementById('edit_custom_domain').value = p.custom_domain || '';

            let features = {};
            try {
                if (p.features_json) {
                    features = JSON.parse(p.features_json);
                }
            } catch (e) {}
            
            document.getElementById('edit_feat_ocr_google_vision').checked = !!features.ocr_google_vision;
            document.getElementById('edit_feat_whatsapp_automations').checked = !!features.whatsapp_automations;

            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        let activePropertyId = 0;

        async function openStaffModal(propertyId, propertyName) {
            activePropertyId = propertyId;
            document.getElementById('staff_property_title').innerText = "Manage Staff: " + propertyName;
            document.getElementById('staffModal').classList.remove('hidden');
            await loadStaffList();
        }

        function closeStaffModal() {
            document.getElementById('staffModal').classList.add('hidden');
            document.getElementById('staffUserFormPanel').classList.add('hidden');
        }

        async function loadStaffList() {
            const formData = new FormData();
            formData.append('action', 'get_staff');
            formData.append('property_id', activePropertyId);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    const tbody = document.getElementById('staffListBody');
                    tbody.innerHTML = '';
                    if (data.staff.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="4" class="px-4 py-3 text-center text-slate-500">No staff users registered.</td></tr>`;
                        return;
                    }
                    data.staff.forEach(su => {
                        const tr = document.createElement('tr');
                        tr.className = "border-b border-slate-800/50 text-xs text-slate-300 hover:bg-slate-800/10";
                        tr.innerHTML = `
                            <td class="px-4 py-3 font-bold text-white font-mono">${escapeHtml(su.username)}</td>
                            <td class="px-4 py-3"><span class="px-2 py-0.5 rounded bg-slate-800 text-[10px] uppercase font-bold text-slate-400">${escapeHtml(su.role)}</span></td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold ${su.is_active == 1 ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400'}">
                                    ${su.is_active == 1 ? 'Active' : 'Suspended'}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right space-x-2">
                                <button onclick='editStaffUser(${JSON.stringify(su)})' class="px-2 py-1 bg-slate-800 hover:bg-slate-700 text-sky-400 rounded transition font-bold">Edit</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    alert('Error loading staff: ' + data.message);
                }
            } catch (e) {
                alert('Connection error loading staff list.');
            }
        }

        function showAddStaffForm() {
            document.getElementById('staff_form_id').value = '';
            document.getElementById('staff_form_username').value = '';
            document.getElementById('staff_form_username').disabled = false;
            document.getElementById('staff_form_access_level').value = 'manager';
            document.getElementById('staff_form_role').value = 'Manager';
            document.getElementById('staff_form_is_active').value = '1';
            document.getElementById('staff_form_password').value = '';
            document.getElementById('staff_form_pin').value = '';
            document.getElementById('staff_form_password_hint').innerText = 'Password *';
            document.getElementById('staff_form_pin_hint').innerText = 'PIN * (4 digits)';
            document.getElementById('staffUserFormPanel').classList.remove('hidden');
        }

        function editStaffUser(su) {
            document.getElementById('staff_form_id').value = su.id;
            document.getElementById('staff_form_username').value = su.username;
            document.getElementById('staff_form_username').disabled = true;
            document.getElementById('staff_form_access_level').value = su.access_level;
            document.getElementById('staff_form_role').value = su.role;
            document.getElementById('staff_form_is_active').value = su.is_active;
            document.getElementById('staff_form_password').value = '';
            document.getElementById('staff_form_pin').value = '';
            document.getElementById('staff_form_password_hint').innerText = 'New Password (leave empty to keep current)';
            document.getElementById('staff_form_pin_hint').innerText = 'New PIN (leave empty to keep current)';
            document.getElementById('staffUserFormPanel').classList.remove('hidden');
        }

        async function saveStaffUser(event) {
            event.preventDefault();
            const formData = new FormData(document.getElementById('staffUserForm'));
            formData.append('action', 'save_staff');
            formData.append('property_id', activePropertyId);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('staffUserFormPanel').classList.add('hidden');
                    await loadStaffList();
                } else {
                    alert('Error saving staff user: ' + data.message);
                }
            } catch (e) {
                alert('Connection error saving staff user.');
            }
        }

        async function loadSystemLogs() {
            const propId = document.getElementById('logFilterProperty').value;
            const formData = new FormData();
            formData.append('action', 'get_audit_logs');
            if (propId !== '') {
                formData.append('property_id', propId);
            }

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    const tbody = document.getElementById('systemLogsBody');
                    tbody.innerHTML = '';
                    if (data.logs.length === 0) {
                        tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-4 text-center text-slate-500">No logs found.</td></tr>`;
                        return;
                    }
                    data.logs.forEach(log => {
                        let ipAddr = log.ip_address || '127.0.0.1';
                        let detailsStr = '';
                        try {
                            const details = JSON.parse(log.details);
                            if (details.ip) ipAddr = details.ip;
                            detailsStr = Object.entries(details)
                                .filter(([k]) => k !== 'logged_at' && k !== 'ip')
                                .map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`)
                                .join(', ');
                        } catch (e) {
                            detailsStr = log.details;
                        }
                        
                        const tr = document.createElement('tr');
                        tr.className = "border-b border-slate-800/60 text-xs text-slate-300 hover:bg-slate-800/10";
                        tr.innerHTML = `
                            <td class="px-4 py-3 text-slate-400 font-mono">${escapeHtml(log.created_at || '')}</td>
                            <td class="px-4 py-3 font-bold text-white">${escapeHtml(log.property_name || 'Global System')}</td>
                            <td class="px-4 py-3 font-mono text-sky-400">${escapeHtml(log.username || 'System')}</td>
                            <td class="px-4 py-3"><div class="font-semibold text-slate-200">${escapeHtml(log.action)}</div><div class="text-[10px] text-slate-500">${escapeHtml(detailsStr)}</div></td>
                            <td class="px-4 py-3"><span class="px-1.5 py-0.5 rounded bg-slate-800 text-[9px] uppercase font-bold text-slate-400">${escapeHtml(log.entity_type)}</span></td>
                            <td class="px-4 py-3 text-slate-500 font-mono">${escapeHtml(ipAddr)}</td>
                        `;
                        tbody.appendChild(tr);
                    });
                } else {
                    alert('Error loading logs: ' + data.message);
                }
            } catch (e) {
                alert('Connection error loading system audit logs.');
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
    </script>

    <!-- Staff Users Modal -->
    <div id="staffModal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl p-6 shadow-2xl space-y-4 flex flex-col max-h-[90vh]">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3 shrink-0">
                <h3 class="text-lg font-bold text-white flex items-center gap-2" id="staff_property_title">
                    Manage Staff
                </h3>
                <button onclick="closeStaffModal()" class="text-slate-400 hover:text-white font-bold text-lg">✕</button>
            </div>

            <!-- List Panel -->
            <div class="overflow-y-auto grow space-y-4">
                <div class="flex justify-between items-center">
                    <div class="text-xs text-slate-400">List of registered staff members for this tenant workspace.</div>
                    <button onclick="showAddStaffForm()" class="px-3 py-1.5 bg-sky-600 hover:bg-sky-500 text-white rounded-lg text-xs font-bold transition flex items-center gap-1">
                        <i class="ph ph-plus"></i> Add Staff Account
                    </button>
                </div>

                <!-- Form Panel (Hidden by default, shown on Add/Edit click) -->
                <div id="staffUserFormPanel" class="bg-slate-950/60 p-4 rounded-xl border border-slate-800 hidden space-y-3">
                    <div class="text-xs font-bold text-sky-400 uppercase">Account Config Form</div>
                    <form id="staffUserForm" onsubmit="saveStaffUser(event)" class="space-y-3">
                        <input type="hidden" name="staff_id" id="staff_form_id">
                        
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Username *</label>
                                <input type="text" name="username" id="staff_form_username" required class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-1.5 text-white text-xs focus:border-sky-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Status</label>
                                <select name="is_active" id="staff_form_is_active" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-1.5 text-white text-xs focus:border-sky-500 focus:outline-none">
                                    <option value="1">Active</option>
                                    <option value="0">Suspended / Disabled</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Access Level</label>
                                <select name="access_level" id="staff_form_access_level" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-1.5 text-white text-xs focus:border-sky-500 focus:outline-none">
                                    <option value="owner">Owner / Property Admin</option>
                                    <option value="manager">Manager / Front Desk</option>
                                    <option value="housekeeping">Housekeeping Staff</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Display Role (Label)</label>
                                <input type="text" name="role" id="staff_form_role" required class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-1.5 text-white text-xs focus:border-sky-500 focus:outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1" id="staff_form_password_hint">Password</label>
                                <input type="password" name="password" id="staff_form_password" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-1.5 text-white text-xs focus:border-sky-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1" id="staff_form_pin_hint">PIN (4 digits)</label>
                                <input type="password" name="pin" id="staff_form_pin" maxlength="4" class="w-full bg-slate-900 border border-slate-800 rounded-lg px-3 py-1.5 text-white text-xs focus:border-sky-500 focus:outline-none">
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 pt-2 border-t border-slate-800">
                            <button type="button" onclick="document.getElementById('staffUserFormPanel').classList.add('hidden')" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded text-xs font-semibold">Cancel</button>
                            <button type="submit" class="px-4 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded text-xs font-bold">Apply Changes</button>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="border border-slate-800 rounded-xl overflow-hidden bg-slate-950/20">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-400 uppercase text-[10px] font-bold border-b border-slate-800">
                            <tr>
                                <th class="px-4 py-2.5">Username</th>
                                <th class="px-4 py-2.5">Role</th>
                                <th class="px-4 py-2.5">Status</th>
                                <th class="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="staffListBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800 shrink-0">
                <button type="button" onclick="closeStaffModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-xl text-sm transition">Close Window</button>
            </div>
        </div>
    </div>
</body>
</html>

