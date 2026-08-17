<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['saas_admin_id'])) {
    header('Location: /saas-admin/login');
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
    
    // Add display_id to pos_orders if missing
    try {
        $check = $db->query("SHOW COLUMNS FROM `pos_orders` LIKE 'display_id'")->fetch();
        if (!$check) {
            $db->exec("ALTER TABLE `pos_orders` ADD COLUMN `display_id` VARCHAR(50) NULL AFTER `id`;");
            $db->exec("CREATE INDEX `idx_pos_orders_display` ON `pos_orders`(`display_id`);");
        }
    } catch (\Exception $e) {}

    // Add saved_reports table if missing
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `saved_reports` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `property_id` INT NOT NULL,
              `name` VARCHAR(100) NOT NULL,
              `dataset` VARCHAR(50) NOT NULL,
              `columns` TEXT NOT NULL,
              `filters` TEXT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $checkFilters = $db->query("SHOW COLUMNS FROM `saved_reports` LIKE 'filters'")->fetch();
        if (!$checkFilters) {
            $db->exec("ALTER TABLE `saved_reports` ADD COLUMN `filters` TEXT NULL AFTER `columns`;");
        }
    } catch (\Exception $e) {}

        // Note: Legacy initializeSaaSDB removed seed property 1 and seed admin user
        // Properties will only be created manually via the SaaS admin UI.
    } catch (\Exception $ex) {
        // Suppress schema guard errors
    }

    $createdCredentials = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $jsonActions = ['get_staff', 'save_staff', 'get_audit_logs'];
        if (!CsrfToken::validate()) {
            if (in_array($action, $jsonActions, true)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh the page and try again.']);
                exit;
            }
            $error = 'Invalid security token. Refresh the page and try again.';
            $action = '';
        }
        
        if ($action === 'switch_context') {
            $pId = (int)($_POST['property_id'] ?? 0);
            $propStmt = $db->prepare("SELECT id, name, is_active FROM properties WHERE id = ? LIMIT 1");
            $propStmt->execute([$pId]);
            $property = $propStmt->fetch(PDO::FETCH_ASSOC);
            if (!$property) {
                $error = 'Property not found.';
            } else {
                $saasId = (int)($_SESSION['saas_admin_id'] ?? 0);
                $_SESSION['user_id'] = $saasId > 0 ? $saasId : (int)($_SESSION['user_id'] ?? 0);
                $_SESSION['role'] = 'superadmin';
                $_SESSION['access_level'] = 'superadmin';
                $_SESSION['property_id'] = $pId;
                $_SESSION['saas_impersonating'] = true;
                $_SESSION['saas_view_property_id'] = $pId;
                try {
                    AuditLogger::log($saasId, 'SAAS_IMPERSONATE', 'PROPERTY', $pId, [
                        'property_name' => $property['name'],
                        'source' => 'saas-admin',
                    ], $pId);
                } catch (\Throwable $e) {
                }
                header('Location: /admin');
                exit;
            }
        }

        if ($action === 'update_saas_security') {
            $newPass = trim($_POST['saas_new_password'] ?? '');
            $newPin = trim($_POST['saas_new_pin'] ?? '');
            $suId = (int)($_SESSION['saas_admin_id'] ?? 0);

            if ($suId > 0 && (!empty($newPass) || !empty($newPin))) {
                try {
                    $sql = "UPDATE staff_users SET ";
                    $params = [];
                    $updates = [];
                    if (!empty($newPass)) {
                        $updates[] = "password_hash = ?";
                        $params[] = password_hash($newPass, PASSWORD_BCRYPT);
                    }
                    if (!empty($newPin) && preg_match('/^\d{4}$/', $newPin)) {
                        $updates[] = "pin_hash = ?";
                        $params[] = password_hash($newPin, PASSWORD_BCRYPT);
                    }
                    
                    if (!empty($updates)) {
                        $sql .= implode(', ', $updates) . " WHERE id = ?";
                        $params[] = $suId;
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                        $successMessage = 'SaaS Admin credentials updated successfully.';
                    } else {
                        $error = 'Invalid PIN format (must be 4 digits).';
                    }
                } catch (\Exception $e) {
                    $error = 'Failed to update credentials: ' . $e->getMessage();
                }
            } else {
                $error = 'No new credentials provided.';
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
                    if (strlen($password) < 8) {
                        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
                        exit;
                    }
                    if (!preg_match('/^\d{4}$/', $pin)) {
                        echo json_encode(['success' => false, 'message' => 'PIN must be exactly 4 digits.']);
                        exit;
                    }
                    $passHash = password_hash($password, PASSWORD_BCRYPT);
                    $pinHash = password_hash($pin, PASSWORD_BCRYPT);
                    
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
            try {
                require_once __DIR__ . '/../../pms_core/services/PropertyOnboardService.php';
                $created = PropertyOnboardService::create($db, [
                    'name' => $_POST['name'] ?? '',
                    'city' => $_POST['city'] ?? '',
                    'state' => $_POST['state'] ?? '',
                    'phone' => $_POST['phone'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'gstin' => $_POST['gstin'] ?? '',
                    'plan' => $_POST['plan'] ?? 'starter',
                    'max_rooms' => $_POST['max_rooms'] ?? null,
                    'admin_username' => $_POST['admin_username'] ?? '',
                    'admin_password' => $_POST['admin_password'] ?? '',
                    'admin_pin' => $_POST['admin_pin'] ?? '',
                ]);
                $message = "Property '{$created['property_name']}' registered successfully under '{$created['plan']}' plan!";
                $createdCredentials = [
                    'property_name' => $created['property_name'],
                    'id' => $created['property_id'],
                    'username' => $created['username'],
                    'password' => $created['password'],
                    'pin' => $created['pin'],
                ];
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (\Exception $e) {
                $error = 'Failed to create property: ' . $e->getMessage();
            }
        } elseif ($action === 'update_lead_status') {
            require_once __DIR__ . '/../../pms_core/services/LeadService.php';
            try {
                LeadService::setStatus($db, (int)($_POST['lead_id'] ?? 0), trim((string)($_POST['status'] ?? '')));
                $message = 'Lead status updated.';
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
            $openSaasTab = 'leads';
        } elseif ($action === 'convert_lead') {
            require_once __DIR__ . '/../../pms_core/services/LeadService.php';
            require_once __DIR__ . '/../../pms_core/services/PropertyOnboardService.php';
            $leadId = (int)($_POST['lead_id'] ?? 0);
            $openSaasTab = 'leads';
            try {
                $lead = LeadService::find($db, $leadId);
                if ($lead === null) {
                    throw new \InvalidArgumentException('Lead not found.');
                }
                if (($lead['status'] ?? '') === 'converted') {
                    throw new \InvalidArgumentException('This lead already has an account.');
                }
                $created = PropertyOnboardService::create($db, [
                    'name' => $lead['hotel_name'] ?? '',
                    'city' => $lead['city'] ?? '',
                    'phone' => $lead['phone'] ?? '',
                    'email' => $lead['email'] ?? '',
                    'plan' => $lead['plan'] ?? 'starter',
                    'max_rooms' => $lead['rooms_estimate'] ?? null,
                    'admin_username' => $_POST['admin_username'] ?? '',
                    'admin_password' => $_POST['admin_password'] ?? '',
                    'admin_pin' => $_POST['admin_pin'] ?? '',
                ]);
                LeadService::markConverted($db, $leadId, $created['property_id']);
                $message = "Account created for '{$created['property_name']}'. Share these credentials with the hotel.";
                $createdCredentials = [
                    'property_name' => $created['property_name'],
                    'id' => $created['property_id'],
                    'username' => $created['username'],
                    'password' => $created['password'],
                    'pin' => $created['pin'],
                ];
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (\Exception $e) {
                $error = 'Failed to create account from lead: ' . $e->getMessage();
            }
        } elseif ($action === 'edit_property') {
            $pId = (int)($_POST['property_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $state = trim($_POST['state'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $gstin = trim($_POST['gstin'] ?? '');
            $plan = trim($_POST['plan'] ?? 'starter');
            $maxRooms = (int)($_POST['max_rooms'] ?? 25);
            $customDomain = strtolower(trim($_POST['custom_domain'] ?? ''));
            $customDomain = $customDomain === '' ? null : $customDomain;

            if ($pId > 0 && !empty($name)) {
                try {
                    $prevStmt = $db->prepare("SELECT custom_domain, dns_txt_token FROM properties WHERE id = ?");
                    $prevStmt->execute([$pId]);
                    $prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $domainChanged = strtolower(trim((string)($prev['custom_domain'] ?? ''))) !== (string)($customDomain ?? '');

                    $features = [
                        'ocr_google_vision' => isset($_POST['feat_ocr_google_vision']) && $_POST['feat_ocr_google_vision'] === '1',
                        'whatsapp_automations' => isset($_POST['feat_whatsapp_automations']) && $_POST['feat_whatsapp_automations'] === '1'
                    ];
                    $featuresJson = json_encode($features);

                    $stmt = $db->prepare("UPDATE properties SET name = ?, address = ?, city = ?, state = ?, phone = ?, email = ?, gstin = ?, plan = ?, max_rooms = ?, features_json = ?, custom_domain = ? WHERE id = ?");
                    $stmt->execute([$name, $address, $city, $state, $phone, $email, $gstin, $plan, $maxRooms, $featuresJson, $customDomain, $pId]);

                    if ($customDomain !== null) {
                        $needToken = $domainChanged || empty($prev['dns_txt_token']);
                        if ($needToken) {
                            $db->prepare("UPDATE properties SET dns_txt_token = ?, dns_status = 'unverified', dns_verified_at = NULL WHERE id = ?")
                                ->execute([bin2hex(random_bytes(16)), $pId]);
                        }
                    } else {
                        $db->prepare("UPDATE properties SET dns_status = 'unverified', dns_verified_at = NULL, dns_txt_token = NULL WHERE id = ?")->execute([$pId]);
                    }

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
            $pId = (int)($_POST['property_id'] ?? 0);
            require_once __DIR__ . '/../../pms_core/services/DNSValidator.php';

            $saasTarget = $db->query("SELECT key_value FROM system_settings WHERE key_name = 'SAAS_PORTAL_SUBDOMAIN'")->fetchColumn() ?: ($_SERVER['HTTP_HOST'] ?? 'saas.micropms.com');

            if ($pId <= 0) {
                $error = 'Property is required to verify DNS.';
            } else {
                try {
                    $rowStmt = $db->prepare("SELECT custom_domain, dns_txt_token FROM properties WHERE id = ?");
                    $rowStmt->execute([$pId]);
                    $propRow = $rowStmt->fetch(PDO::FETCH_ASSOC);
                    $customDomain = strtolower(trim((string)($propRow['custom_domain'] ?? $_POST['custom_domain'] ?? '')));
                    $txtToken = (string)($propRow['dns_txt_token'] ?? '');
                    if ($customDomain === '') {
                        $error = 'No custom domain is set for this property.';
                    } else {
                        if ($txtToken === '') {
                            $txtToken = bin2hex(random_bytes(16));
                            $db->prepare("UPDATE properties SET dns_txt_token = ? WHERE id = ?")->execute([$txtToken, $pId]);
                        }
                        $res = DNSValidator::verifyForProperty($customDomain, (string)$saasTarget, $txtToken);
                        if ($res['ok']) {
                            $db->prepare("UPDATE properties SET dns_status = 'verified', dns_verified_at = NOW() WHERE id = ?")->execute([$pId]);
                            $message = $res['message'];
                        } else {
                            $db->prepare("UPDATE properties SET dns_status = 'failed', dns_verified_at = NULL WHERE id = ?")->execute([$pId]);
                            $error = $res['message'];
                        }
                    }
                } catch (\Exception $e) {
                    $error = 'DNS verification failed: ' . $e->getMessage();
                }
            }
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

        if ($action === 'download_deploy_zip') {
            $script = realpath(__DIR__ . '/../../scripts/build_deployment_zip.sh');
            $zipPath = realpath(__DIR__ . '/../../') . '/deployment.zip';
            if ($script === false || !is_file($script)) {
                $error = 'Zip script was not found. Run bash scripts/build_deployment_zip.sh on your computer.';
                $openSaasTab = 'deploy';
            } else {
                $cmd = 'bash ' . escapeshellarg($script) . ' 2>&1';
                exec($cmd, $outLines, $code);
                if ($code !== 0 || !is_file($zipPath)) {
                    $error = 'Could not build deployment.zip: ' . implode(' ', $outLines);
                    $openSaasTab = 'deploy';
                } else {
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="micropms-hostinger.zip"');
                    header('Content-Length: ' . (string)filesize($zipPath));
                    header('Cache-Control: no-store');
                    readfile($zipPath);
                    exit;
                }
            }
        }

        if ($action === 'deploy_hostinger') {
            require_once __DIR__ . '/../../pms_core/services/GithubDeployService.php';
            $deployResult = GithubDeployService::triggerDeploy();
            if (!empty($deployResult['ok'])) {
                $message = $deployResult['message'];
                try {
                    AuditLogger::log((int)($_SESSION['saas_admin_id'] ?? 0), 'DEPLOY_HOSTINGER', 'SYSTEM', null, [
                        'repo' => GithubDeployService::repo(),
                    ]);
                } catch (\Throwable $e) {
                }
            } else {
                $error = $deployResult['message'] ?? 'Deploy failed';
            }
            $openSaasTab = 'deploy';
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

require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/services/GithubDeployService.php';
require_once __DIR__ . '/../../pms_core/services/LeadService.php';
$deployConfigured = GithubDeployService::isConfigured();
$deployLatestRun = $deployConfigured ? GithubDeployService::latestRun() : null;
$openSaasTab = $openSaasTab ?? '';
$leads = [];
$newLeadCount = 0;
try {
    $leads = LeadService::listAll($db);
    $newLeadCount = LeadService::countNew($db);
} catch (\Exception $e) {
    $leads = [];
}

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
    <title>SaaS Control Panel | MicroPMS</title>
    <?= CsrfToken::meta() ?>
    <meta name="description" content="MicroPMS SaaS Super-Admin — Tenant Management & Platform Controls">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    colors: {
                        navy: { 50:'#EFF6FF', 100:'#DBEAFE', 600:'#1E3A8A', 700:'#1D4ED8' }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #F8FAFC; color: #0F172A; }
        * { border-color: #E2E8F0; }
        .hidden { display: none !important; }

        /* ── Light Panel (replaces dark glass-panel) ── */
        .light-panel {
            background: #ffffff;
            border: 1px solid #E2E8F0;
            border-radius: 1.25rem;
            box-shadow: 0 2px 8px -1px rgba(15,23,42,0.06), 0 1px 3px rgba(15,23,42,0.04);
        }
        /* Keep old class name mapped to light ─── */
        .glass-panel {
            background: #ffffff;
            border: 1px solid #E2E8F0;
            border-radius: 1.25rem;
            box-shadow: 0 2px 8px -1px rgba(15,23,42,0.06), 0 1px 3px rgba(15,23,42,0.04);
        }

        /* ── KPI Cards ── */
        .saas-kpi {
            background: #ffffff;
            border: 1px solid #E2E8F0;
            border-radius: 1.25rem;
            padding: 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 2px 8px -1px rgba(15,23,42,0.06);
            transition: all 0.2s cubic-bezier(0.16,1,0.3,1);
            position: relative; overflow: hidden;
        }
        .saas-kpi::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            opacity: 0; transition: opacity 0.2s;
        }
        .saas-kpi:hover { transform: translateY(-2px); box-shadow: 0 10px 28px -4px rgba(15,23,42,0.10); border-color: #BFDBFE; }
        .saas-kpi:hover::before { opacity: 1; }
        .saas-kpi.kpi-blue::before { background: linear-gradient(90deg, #1E3A8A, #3B82F6); }
        .saas-kpi.kpi-green::before { background: linear-gradient(90deg, #059669, #10B981); }
        .saas-kpi.kpi-amber::before { background: linear-gradient(90deg, #B45309, #F59E0B); }
        .saas-kpi.kpi-indigo::before { background: linear-gradient(90deg, #4338CA, #6366F1); }

        /* ── Buttons ── */
        .btn-primary-saas {
            background: linear-gradient(135deg, #1E3A8A, #2563EB);
            color: #fff; font-weight: 700; border-radius: 0.75rem; border: none;
            padding: 0.6rem 1.25rem;
            box-shadow: 0 6px 16px -3px rgba(30,58,138,0.28);
            transition: all 0.2s cubic-bezier(0.16,1,0.3,1); cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; font-size: 0.8125rem;
        }
        .btn-primary-saas:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -4px rgba(30,58,138,0.35); }
        .btn-danger-saas {
            background: linear-gradient(135deg, #DC2626, #EF4444);
            color: #fff; font-weight: 700; border-radius: 0.75rem; border: none;
            padding: 0.5rem 0.875rem;
            box-shadow: 0 4px 12px -2px rgba(239,68,68,0.25);
            transition: all 0.2s; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem;
        }
        .btn-danger-saas:hover { transform: translateY(-1px); }
        .btn-success-saas {
            background: linear-gradient(135deg, #059669, #10B981);
            color: #fff; font-weight: 700; border-radius: 0.75rem; border: none;
            padding: 0.5rem 0.875rem;
            box-shadow: 0 4px 12px -2px rgba(16,185,129,0.25);
            transition: all 0.2s; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem;
        }
        .btn-ghost-saas {
            background: #F8FAFC; color: #475569; font-weight: 600;
            border: 1px solid #E2E8F0; border-radius: 0.75rem;
            padding: 0.5rem 0.875rem; cursor: pointer;
            transition: all 0.2s; font-size: 0.75rem;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .btn-ghost-saas:hover { background: #EFF6FF; border-color: #BFDBFE; color: #1E3A8A; }

        /* ── Inputs ── */
        input, select, textarea {
            border: 1px solid #E2E8F0 !important; border-radius: 0.75rem;
            background: #fff; font-family: inherit; color: #0F172A; font-weight: 500;
            padding: 0.625rem 0.875rem !important;
            box-shadow: 0 1px 3px rgba(15,23,42,0.04) !important;
            transition: all 0.2s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none; border-color: #1E3A8A !important;
            box-shadow: 0 0 0 3px rgba(30,58,138,0.12) !important;
        }

        /* ── Tabs ── */
        .tab-btn {
            white-space: nowrap; font-size: 0.875rem; font-weight: 600;
            padding: 0.75rem 1.25rem; border-bottom: 2px solid transparent;
            color: #64748B; transition: all 0.2s; cursor: pointer; background: transparent; border-top: none; border-left: none; border-right: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .tab-btn:hover { color: #334155; }
        .tab-btn.active { color: #1E3A8A; border-bottom-color: #1E3A8A; font-weight: 700; }

        /* ── Table ── */
        .saas-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.875rem; }
        .saas-table th { background: #F8FAFC; color: #475569; font-weight: 700; text-transform: uppercase; font-size: 0.68rem; letter-spacing: 0.07em; padding: 14px 20px; border-bottom: 1px solid #E2E8F0; text-align: left; }
        .saas-table td { padding: 14px 20px; border-bottom: 1px solid #F1F5F9; color: #0F172A; vertical-align: middle; }
        .saas-table tr:last-child td { border-bottom: none; }
        .saas-table tbody tr:hover { background: #EFF6FF; }

        /* ── Scrollbar ── */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }

        /* ── Animations ── */
        @keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        .animate-up { animation: fadeUp 0.35s cubic-bezier(0.16,1,0.3,1) forwards; }
        .tab-content { animation: fadeUp 0.25s ease forwards; }

        /* ── Loading/Toast stubs ── */
        #global-toast-container { position:fixed; bottom:24px; right:24px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none; }
        @keyframes pms-skeleton { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
        .skeleton {
            display: block;
            background: linear-gradient(90deg, #e2e8f0 0%, #f8fafc 45%, #e2e8f0 90%);
            background-size: 200% 100%;
            animation: pms-skeleton 1.15s ease infinite;
            border-radius: 6px;
            min-height: 0.75rem;
        }
        .skeleton.h-4 { height: 1rem; }
        .skeleton.w-full { width: 100%; }
        .pms-empty-state { display:flex; flex-direction:column; align-items:center; gap:8px; padding:28px 16px; color:#64748B; text-align:center; }
        .pms-empty-state i { font-size:1.75rem; opacity:0.45; }
        .pms-empty-retry { margin-top:6px; background:#fff; border:1px solid #E2E8F0; border-radius:0.75rem; padding:0.5rem 1rem; font-size:0.8125rem; font-weight:700; color:#1E3A8A; cursor:pointer; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen pb-16">
<div id="global-toast-container"></div>

    <div class="max-w-7xl mx-auto px-4 py-8">

        <!-- Top Header Bar -->
        <header class="bg-white border border-slate-200 rounded-2xl px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8 shadow-sm">
            <div class="flex items-center gap-4">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-700 to-blue-900 flex items-center justify-center shadow-md flex-shrink-0">
                    <i class="ph ph-shield-check text-xl text-white"></i>
                </div>
                <div>
                    <h1 class="text-lg font-extrabold text-slate-900 tracking-tight leading-tight">SaaS Control Panel</h1>
                    <p class="text-xs text-slate-500 font-medium">Manage tenants, billing, settings &amp; security</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden sm:flex items-center gap-2 px-3 py-1.5 bg-slate-100 rounded-xl text-xs font-semibold text-slate-600">
                    <i class="ph ph-user-circle text-sm text-blue-700"></i>
                    <?= htmlspecialchars((string)($_SESSION['saas_admin_username'] ?? 'superadmin')) ?>
                    <span class="text-[9px] uppercase tracking-wider font-bold bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded-md">Super</span>
                </span>
                <a href="/saas-admin/logout" class="flex items-center gap-2 px-4 py-2 bg-red-50 hover:bg-red-100 text-red-600 font-bold rounded-xl text-sm transition border border-red-200 cursor-pointer">
                    <i class="ph ph-sign-out text-base"></i> Logout
                </a>
            </div>
        </header>

        <?php if (!empty($message)): ?>
            <div class="flex items-center gap-3 mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl font-semibold text-sm">
                <i class="ph ph-check-circle text-xl flex-shrink-0 text-emerald-600"></i>
                <?= htmlspecialchars((string)($message)) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="flex items-center gap-3 mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl font-semibold text-sm">
                <i class="ph ph-warning-circle text-xl flex-shrink-0 text-red-600"></i>
                <?= htmlspecialchars((string)($error)) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($createdCredentials)): ?>
            <div class="mb-8 p-6 bg-blue-50 border-2 border-blue-200 rounded-2xl shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-extrabold text-blue-900 flex items-center gap-2">
                        <i class="ph ph-key text-xl text-blue-700"></i> Hotel Admin Credentials Created
                    </h3>
                    <span class="text-xs bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded-full border border-blue-200">Copy &amp; Share Securely</span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div class="bg-white p-4 rounded-xl border border-blue-200 shadow-sm">
                        <div class="text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Property</div>
                        <div class="font-extrabold text-slate-900"><?= htmlspecialchars((string)($createdCredentials['property_name'])) ?></div>
                        <div class="text-xs font-mono text-blue-600 mt-0.5">ID: <?= htmlspecialchars((string)($createdCredentials['id'])) ?></div>
                    </div>
                    <div class="bg-white p-4 rounded-xl border border-blue-200 shadow-sm">
                        <div class="text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Username</div>
                        <div class="font-bold text-emerald-700 font-mono text-base"><?= htmlspecialchars((string)($createdCredentials['username'])) ?></div>
                    </div>
                    <div class="bg-white p-4 rounded-xl border border-blue-200 shadow-sm">
                        <div class="text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Password</div>
                        <div class="font-bold text-amber-700 font-mono text-base"><?= htmlspecialchars((string)($createdCredentials['password'])) ?></div>
                    </div>
                    <div class="bg-white p-4 rounded-xl border border-blue-200 shadow-sm">
                        <div class="text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">PWA PIN</div>
                        <div class="font-bold text-purple-700 font-mono text-base"><?= htmlspecialchars((string)($createdCredentials['pin'])) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="saas-kpi kpi-blue">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Active Tenants</p>
                    <p class="text-3xl font-extrabold text-slate-900 mt-1"><?= htmlspecialchars((string)($metrics['active_tenants']), ENT_QUOTES, 'UTF-8') ?> <span class="text-sm font-semibold text-slate-400">/ <?= htmlspecialchars((string)(count($properties)), ENT_QUOTES, 'UTF-8') ?></span></p>
                    <p class="text-xs text-slate-400 mt-1 font-medium">properties running</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center flex-shrink-0">
                    <i class="ph ph-buildings text-2xl text-blue-700"></i>
                </div>
            </div>
            <div class="saas-kpi kpi-green">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Total Rooms</p>
                    <p class="text-3xl font-extrabold text-emerald-700 mt-1"><?= htmlspecialchars((string)($metrics['total_rooms']), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-xs text-slate-400 mt-1 font-medium">across all properties</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-50 border border-emerald-100 flex items-center justify-center flex-shrink-0">
                    <i class="ph ph-bed text-2xl text-emerald-700"></i>
                </div>
            </div>
            <div class="saas-kpi kpi-amber">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Staff Seats</p>
                    <p class="text-3xl font-extrabold text-amber-700 mt-1"><?= htmlspecialchars((string)($metrics['active_staff']), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-xs text-slate-400 mt-1 font-medium">active staff users</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-50 border border-amber-100 flex items-center justify-center flex-shrink-0">
                    <i class="ph ph-users text-2xl text-amber-700"></i>
                </div>
            </div>
            <div class="saas-kpi kpi-indigo">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Projected MRR</p>
                    <p class="text-2xl font-extrabold text-indigo-700 mt-1">₹<?= htmlspecialchars((string)(number_format($metrics['estimated_mrr'], 0)), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-xs text-slate-400 mt-1 font-medium">monthly recurring</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center flex-shrink-0">
                    <i class="ph ph-currency-inr text-2xl text-indigo-700"></i>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="bg-white border border-slate-200 rounded-2xl p-1.5 flex items-center gap-1 mb-6 shadow-sm overflow-x-auto no-scrollbar">
            <button onclick="switchTab('properties')" id="tab-properties" class="tab-btn active">
                <i class="ph ph-buildings"></i> Properties
            </button>
            <button onclick="switchTab('leads')" id="tab-leads" class="tab-btn">
                <i class="ph ph-tray"></i> Leads
                <?php if ($newLeadCount > 0): ?>
                <span class="ml-1 inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-blue-600 text-white text-[10px] font-extrabold"><?= (int)$newLeadCount ?></span>
                <?php endif; ?>
            </button>
            <button onclick="switchTab('onboard')" id="tab-onboard" class="tab-btn">
                <i class="ph ph-user-plus"></i> Onboarding
            </button>
            <button onclick="switchTab('deploy')" id="tab-deploy" class="tab-btn">
                <i class="ph ph-rocket-launch"></i> Deploy
            </button>
            <button onclick="switchTab('settings')" id="tab-settings" class="tab-btn">
                <i class="ph ph-gear"></i> Settings
            </button>
            <button onclick="switchTab('security')" id="tab-security" class="tab-btn">
                <i class="ph ph-shield-check"></i> Security
            </button>
            <button onclick="switchTab('logs'); loadSystemLogs();" id="tab-logs" class="tab-btn">
                <i class="ph ph-scroll"></i> System Logs
            </button>
        </div>

        <!-- TAB CONTENT: Properties List -->
        <div id="content-properties" class="tab-content">
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                        <i class="ph ph-buildings text-blue-700"></i> Registered Properties
                    </h2>
                    <span class="text-xs font-semibold text-slate-500 bg-slate-100 px-2.5 py-1 rounded-full"><?= htmlspecialchars((string)(count($properties)), ENT_QUOTES, 'UTF-8') ?> total</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="saas-table">
                        <thead>
                            <tr>
                                <th>Property Details</th>
                                <th>Domain Mapping</th>
                                <th>SaaS Plan</th>
                                <th>Rooms Usage</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="saas-properties-body">
                            <?php if (empty($properties)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-slate-500">
                                        <div class="pms-empty-state">
                                            <i class="ph ph-buildings"></i>
                                            <p>No properties registered yet.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                            <?php foreach ($properties as $p): ?>
                                <tr>
                                    <td>
                                        <div class="font-bold text-slate-900"><?= htmlspecialchars((string)($p['name'])) ?></div>
                                        <div class="text-xs text-blue-600 font-mono mt-0.5">ID: <?= htmlspecialchars((string)($p['id'])) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['custom_domain'])): ?>
                                            <?php
                                                $dnsVerified = (($p['dns_status'] ?? '') === 'verified');
                                                $txtToken = (string)($p['dns_txt_token'] ?? '');
                                            ?>
                                            <div class="space-y-1">
                                                <div class="text-xs font-mono <?= $dnsVerified ? 'text-emerald-700' : 'text-amber-700' ?> flex items-center gap-1.5">
                                                    <span class="w-1.5 h-1.5 rounded-full <?= $dnsVerified ? 'bg-emerald-500' : 'bg-amber-500' ?> flex-shrink-0"></span>
                                                    <?= htmlspecialchars((string)($p['custom_domain'])) ?>
                                                    <span class="text-[9px] uppercase tracking-wider font-bold <?= $dnsVerified ? 'text-emerald-600' : 'text-amber-600' ?>">
                                                        <?= $dnsVerified ? 'Verified' : 'Unverified' ?>
                                                    </span>
                                                </div>
                                                <?php if ($txtToken !== ''): ?>
                                                    <div class="text-[10px] text-slate-500 font-mono break-all">TXT: micropms-verify=<?= htmlspecialchars($txtToken) ?></div>
                                                <?php endif; ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="verify_dns">
                                                    <input type="hidden" name="property_id" value="<?= (int)$p['id'] ?>">
                                                    <input type="hidden" name="custom_domain" value="<?= htmlspecialchars((string)($p['custom_domain'])) ?>">
                                                    <button type="submit" class="text-[10px] text-slate-400 hover:text-blue-700 flex items-center gap-1 font-semibold transition">
                                                        <i class="ph ph-magnifying-glass"></i> Check DNS
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 font-semibold">No Custom Domain</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $plan = $p['plan'] ?? 'starter';
                                            $planClass = match($plan) {
                                                'enterprise' => 'bg-purple-50 text-purple-700 border border-purple-200',
                                                'pro'        => 'bg-blue-50 text-blue-700 border border-blue-200',
                                                default      => 'bg-slate-100 text-slate-600 border border-slate-200',
                                            };
                                        ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase <?= htmlspecialchars((string)($planClass), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)(ucfirst($plan))) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $maxVal = (int)($p['max_rooms'] ?? 25);
                                            $pct = $maxVal > 0 ? min(100, round(($p['room_count'] / $maxVal) * 100)) : 0;
                                            $barColor = $pct >= 90 ? '#EF4444' : ($pct >= 70 ? '#F59E0B' : '#1E3A8A');
                                        ?>
                                        <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars((string)($p['room_count']), ENT_QUOTES, 'UTF-8') ?> <span class="font-medium text-slate-400">/ <?= htmlspecialchars((string)(($p['max_rooms'] ?? 25) >= 999 ? '∞' : ($p['max_rooms'] ?? 25)), ENT_QUOTES, 'UTF-8') ?></span></div>
                                        <div class="w-24 bg-slate-200 h-1.5 rounded-full mt-1.5 overflow-hidden">
                                            <div class="h-full rounded-full" style="width:<?= htmlspecialchars((string)($pct), ENT_QUOTES, 'UTF-8') ?>%; background:<?= htmlspecialchars((string)($barColor), ENT_QUOTES, 'UTF-8') ?>;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="property_id" value="<?= htmlspecialchars((string)($p['id']), ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="cursor-pointer px-3 py-1 rounded-full text-xs font-bold border transition <?= htmlspecialchars((string)((int)$p['is_active'] === 1 ? 'bg-emerald-50 border-emerald-200 text-emerald-700 hover:bg-emerald-100' : 'bg-red-50 border-red-200 text-red-700 hover:bg-red-100'), ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string)((int)$p['is_active'] === 1 ? 'Active' : 'Disabled'), ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="switch_context">
                                                <input type="hidden" name="property_id" value="<?= htmlspecialchars((string)($p['id']), ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" title="Switch context" class="cursor-pointer btn-success-saas">
                                                    <i class="ph ph-arrows-left-right"></i> Switch
                                                </button>
                                            </form>
                                            <button onclick="openEditModal(<?= htmlspecialchars((string)(json_encode($p))) ?>)" class="btn-ghost-saas">
                                                <i class="ph ph-pencil-simple"></i> Edit
                                            </button>
                                            <button onclick="openStaffModal(<?= htmlspecialchars((string)($p['id']), ENT_QUOTES, 'UTF-8') ?>, '<?= htmlspecialchars((string)(addslashes($p['name']))) ?>')" class="btn-ghost-saas">
                                                <i class="ph ph-users"></i> Staff
                                            </button>
                                            <?php if ((int)$p['id'] !== 1): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="delete_property">
                                                <input type="hidden" name="property_id" value="<?= htmlspecialchars((string)($p['id']), ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="cursor-pointer btn-danger-saas px-2.5" title="Delete Property"
                                                    onclick="return confirm('PERMANENT DELETE: All bookings, rooms, and staff will be lost. Continue?')">
                                                    <i class="ph ph-trash"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: Landing leads -->
        <div id="content-leads" class="tab-content hidden">
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <div>
                        <h2 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                            <i class="ph ph-tray text-blue-700"></i> Landing leads
                        </h2>
                        <p class="text-xs text-slate-500 mt-1">Hotels that requested access. Create an account when you are ready to grant login.</p>
                    </div>
                    <span class="text-xs font-semibold text-slate-500 bg-slate-100 px-2.5 py-1 rounded-full"><?= (int)count($leads) ?> total</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="saas-table">
                        <thead>
                            <tr>
                                <th>Hotel</th>
                                <th>Contact</th>
                                <th>Plan</th>
                                <th>Status</th>
                                <th class="text-right">Grant access</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($leads === []): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-slate-500">
                                    <p>No leads yet. Public requests from <code class="font-mono">/register</code> appear here.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($leads as $lead):
                                $st = (string)($lead['status'] ?? 'new');
                                $stClass = match ($st) {
                                    'converted' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                    'contacted' => 'bg-amber-50 text-amber-800 border-amber-200',
                                    'dismissed' => 'bg-slate-100 text-slate-500 border-slate-200',
                                    default => 'bg-blue-50 text-blue-700 border-blue-200',
                                };
                            ?>
                            <tr>
                                <td>
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars((string)$lead['hotel_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars((string)($lead['city'] ?: '—'), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($lead['rooms_estimate'])): ?> · <?= (int)$lead['rooms_estimate'] ?> rooms<?php endif; ?></div>
                                    <?php if (!empty($lead['message'])): ?>
                                    <div class="text-xs text-slate-400 mt-1 max-w-xs"><?= htmlspecialchars((string)$lead['message'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <div class="text-[10px] text-slate-400 mt-1"><?= htmlspecialchars((string)($lead['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string)($lead['contact_name'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-blue-700"><?= htmlspecialchars((string)$lead['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($lead['phone'] ?: ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase bg-slate-100 text-slate-600 border border-slate-200"><?= htmlspecialchars((string)($lead['plan'] ?? 'starter'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($lead['property_id'])): ?>
                                    <div class="text-[10px] font-mono text-slate-400 mt-1">Property #<?= (int)$lead['property_id'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold uppercase border <?= htmlspecialchars($stClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="text-right">
                                    <?php if ($st !== 'converted' && $st !== 'dismissed'): ?>
                                    <form method="POST" class="flex flex-col items-end gap-2">
                                        <input type="hidden" name="action" value="convert_lead">
                                        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                                        <?= CsrfToken::field() ?>
                                        <input type="text" name="admin_username" placeholder="Username (optional)" class="w-44 text-xs">
                                        <div class="flex gap-1">
                                            <input type="text" name="admin_password" placeholder="Password" class="w-28 text-xs">
                                            <input type="text" name="admin_pin" maxlength="4" placeholder="PIN" class="w-16 text-xs">
                                        </div>
                                        <button type="submit" class="btn-primary-saas text-xs py-1.5 px-3" onclick="return confirm('Create a property and owner login for this hotel?')">Create account</button>
                                    </form>
                                    <div class="flex justify-end gap-2 mt-2">
                                        <?php if ($st === 'new'): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="update_lead_status">
                                            <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                                            <input type="hidden" name="status" value="contacted">
                                            <?= CsrfToken::field() ?>
                                            <button type="submit" class="text-[10px] font-bold text-amber-700 hover:underline">Mark contacted</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="update_lead_status">
                                            <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">
                                            <input type="hidden" name="status" value="dismissed">
                                            <?= CsrfToken::field() ?>
                                            <button type="submit" class="text-[10px] font-bold text-slate-400 hover:text-red-600">Dismiss</button>
                                        </form>
                                    </div>
                                    <?php elseif ($st === 'converted'): ?>
                                    <span class="text-xs font-semibold text-emerald-700">Access granted</span>
                                    <?php else: ?>
                                    <span class="text-xs text-slate-400">Dismissed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: Onboard Property -->
        <div id="content-onboard" class="tab-content hidden">
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm max-w-2xl mx-auto overflow-hidden">
                <div class="flex items-center gap-3 px-6 py-4 bg-slate-50 border-b border-slate-200">
                    <div class="w-9 h-9 rounded-xl bg-blue-50 border border-blue-200 flex items-center justify-center flex-shrink-0">
                        <i class="ph ph-plus-circle text-lg text-blue-700"></i>
                    </div>
                    <div>
                        <h2 class="font-extrabold text-slate-900 text-base">Register New Hotel Property</h2>
                        <p class="text-xs text-slate-500">Onboard a new tenant onto the platform</p>
                    </div>
                </div>
                <form method="POST" class="p-6 space-y-5">
                    <input type="hidden" name="action" value="create_property">

                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Hotel / Property Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" id="onboard-property-name" placeholder="Grand Transit Hotel" required class="w-full text-sm">
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-100">
                        <div class="flex items-center gap-2 text-xs font-extrabold text-blue-700 uppercase tracking-wider mb-3">
                            <i class="ph ph-user-circle"></i> Initial Owner Admin Account
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Admin Username</label>
                                <input type="text" name="admin_username" id="onboard-admin-username" placeholder="Auto-generated if left empty" class="w-full text-sm">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Password</label>
                                    <input type="text" name="admin_password" id="onboard-admin-password" placeholder="Auto-generated if empty" class="w-full text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">PWA PIN (4 digits)</label>
                                    <input type="text" name="admin_pin" id="onboard-admin-pin" maxlength="4" placeholder="1234" class="w-full text-sm">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">City</label>
                            <input type="text" name="city" placeholder="New Delhi" class="w-full text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">State</label>
                            <input type="text" name="state" placeholder="Delhi" class="w-full text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Subscription Plan</label>
                            <select name="plan" class="w-full text-sm">
                                <?php foreach ($saasPlans as $planId => $planData): ?>
                                    <option value="<?= htmlspecialchars((string)($planId)) ?>"><?= htmlspecialchars((string)($planData['name'])) ?> (Max <?= htmlspecialchars((string)($planData['max_rooms'] >= 999 ? '∞' : $planData['max_rooms']), ENT_QUOTES, 'UTF-8') ?> Rooms)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Max Room Override</label>
                            <input type="number" name="max_rooms" placeholder="Plan default applies if empty" class="w-full text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Phone</label>
                            <input type="text" name="phone" placeholder="9876543210" class="w-full text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">GSTIN</label>
                            <input type="text" name="gstin" placeholder="07AAAAA0000A1Z5" class="w-full text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Email</label>
                        <input type="email" name="email" placeholder="owner@hotel.com" class="w-full text-sm">
                    </div>

                    <button type="submit" class="btn-primary-saas w-full py-3.5 text-sm justify-center">
                        <i class="ph ph-plus-circle text-lg"></i> Register Tenant Property
                    </button>
                </form>
            </div>
        </div>
            
        <!-- TAB CONTENT: Deploy to Hostinger -->
        <div id="content-deploy" class="tab-content hidden">
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm max-w-2xl mx-auto overflow-hidden">
                <div class="px-6 py-4 bg-slate-50 border-b border-slate-200">
                    <h2 class="font-extrabold text-slate-900 flex items-center gap-2">
                        <i class="ph ph-rocket-launch text-blue-700"></i> Push update to Hostinger
                    </h2>
                    <p class="text-xs text-slate-500 mt-1">No GitHub token is required. Download a zip and extract it in Hostinger File Manager so <code class="font-mono">pms_core</code> sits next to <code class="font-mono">public_html</code>. Do not replace <code class="font-mono">.env</code> or uploads.</p>
                </div>
                <div class="p-6 space-y-4">
                    <ol class="text-sm text-slate-700 space-y-2 list-decimal pl-5">
                        <li>Download the zip below.</li>
                        <li>In hPanel → File Manager, open the domain folder (parent of <code class="font-mono">public_html</code>).</li>
                        <li>Upload and extract so <code class="font-mono">public_html</code>, <code class="font-mono">pms_core</code>, and <code class="font-mono">db_migrations</code> are siblings.</li>
                        <li>Keep the existing server <code class="font-mono">.env</code>.</li>
                        <li>Open <a href="/admin/run_migration" class="font-bold text-blue-700 hover:underline">/admin/run_migration</a> as owner and click Run.</li>
                    </ol>
                    <form method="POST">
                        <input type="hidden" name="action" value="download_deploy_zip">
                        <?= CsrfToken::field() ?>
                        <button type="submit" class="btn-primary-saas w-full py-3.5 text-sm justify-center">
                            <i class="ph ph-download-simple text-base"></i> Download Hostinger zip
                        </button>
                    </form>
                    <?php
                    $runStatus = $deployLatestRun['status'] ?? '';
                    $runConclusion = $deployLatestRun['conclusion'] ?? '';
                    $runLabel = 'No runs yet';
                    if ($runStatus === 'in_progress' || $runStatus === 'queued') {
                        $runLabel = 'In progress';
                    } elseif ($runConclusion === 'success') {
                        $runLabel = 'Last run succeeded';
                    } elseif ($runConclusion === 'failure' || $runConclusion === 'cancelled') {
                        $runLabel = 'Last run: ' . $runConclusion;
                    } elseif ($runStatus !== '') {
                        $runLabel = $runStatus;
                    }
                    ?>
                    <?php if ($deployConfigured): ?>
                    <div class="p-4 rounded-xl border border-slate-200 bg-slate-50 text-sm">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Optional GitHub Action</p>
                        <p class="font-bold text-slate-800"><?= htmlspecialchars($runLabel) ?></p>
                    </div>
                    <form method="POST" onsubmit="return confirm('Push the current GitHub main branch to Hostinger now?');">
                        <input type="hidden" name="action" value="deploy_hostinger">
                        <?= CsrfToken::field() ?>
                        <button type="submit" class="w-full py-3 text-sm border border-slate-200 rounded-xl font-bold text-slate-700 hover:bg-slate-50">
                            Deploy via GitHub Action
                        </button>
                    </form>
                    <?php else: ?>
                    <p class="text-[11px] text-slate-500">GitHub Action is optional and hidden until you add tokens. Zip upload works without them.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: Platform Settings -->
        <div id="content-settings" class="tab-content hidden">
            <form method="POST" class="space-y-6 max-w-4xl mx-auto">
                <input type="hidden" name="action" value="save_saas_settings">

                <!-- General Settings -->
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-200">
                        <h2 class="font-extrabold text-slate-900 flex items-center gap-2"><i class="ph ph-gear text-blue-700"></i> General Configuration</h2>
                    </div>
                    <div class="p-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Platform Name</label>
                            <input type="text" name="SAAS_PLATFORM_NAME" value="<?= htmlspecialchars((string)($settings['SAAS_PLATFORM_NAME'] ?? 'MicroPMS')) ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Support Email</label>
                            <input type="email" name="SAAS_SUPPORT_EMAIL" value="<?= htmlspecialchars((string)($settings['SAAS_SUPPORT_EMAIL'] ?? '')) ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Designated SaaS Domain</label>
                            <input type="text" name="SAAS_PORTAL_SUBDOMAIN" value="<?= htmlspecialchars((string)($settings['SAAS_PORTAL_SUBDOMAIN'] ?? '')) ?>" placeholder="saas.yourdomain.com" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-indigo-500 focus:outline-none font-mono">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Trial Days</label>
                                <input type="number" name="SAAS_TRIAL_DAYS" value="<?= htmlspecialchars((string)($settings['SAAS_TRIAL_DAYS'] ?? '30')) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-indigo-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Currency</label>
                                <input type="text" name="SAAS_DEFAULT_CURRENCY" value="<?= htmlspecialchars((string)($settings['SAAS_DEFAULT_CURRENCY'] ?? 'INR')) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-indigo-500 focus:outline-none">
                            </div>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Branding & Theming -->
                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-6 py-4 bg-slate-50 border-b border-slate-200">
                        <h2 class="font-extrabold text-slate-900 flex items-center gap-2"><i class="ph ph-paint-brush text-purple-600"></i> Branding &amp; Theming</h2>
                    </div>
                    <div class="p-6 grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Logo URL</label>
                            <input type="url" name="SAAS_LOGO_URL" value="<?= htmlspecialchars((string)($settings['SAAS_LOGO_URL'] ?? '')) ?>" placeholder="https://..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-indigo-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Primary Color (Hex)</label>
                            <div class="flex items-center gap-2">
                                <input type="color" name="SAAS_PRIMARY_COLOR" value="<?= htmlspecialchars((string)($settings['SAAS_PRIMARY_COLOR'] ?? '#0ea5e9')) ?>" class="h-10 w-12 rounded bg-transparent border-0 cursor-pointer">
                                <input type="text" name="SAAS_PRIMARY_COLOR" value="<?= htmlspecialchars((string)($settings['SAAS_PRIMARY_COLOR'] ?? '#0ea5e9')) ?>" placeholder="#0ea5e9" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none font-mono">
                            </div>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Custom CSS</label>
                            <textarea name="SAAS_CUSTOM_CSS" rows="3" placeholder="/* Inject CSS into tenant pages */" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:border-indigo-500 focus:outline-none font-mono"><?= htmlspecialchars((string)($settings['SAAS_CUSTOM_CSS'] ?? '')) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <!-- SMTP Settings -->
                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-5 py-3.5 bg-slate-50 border-b border-slate-200">
                            <h2 class="font-extrabold text-slate-900 flex items-center gap-2"><i class="ph ph-envelope-simple text-amber-600"></i> SMTP Settings</h2>
                        </div>
                        <div class="p-5">
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">SMTP Host</label>
                                <input type="text" name="SAAS_SMTP_HOST" value="<?= htmlspecialchars((string)($settings['SAAS_SMTP_HOST'] ?? '')) ?>" placeholder="smtp.mailgun.org" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Port</label>
                                    <input type="text" name="SAAS_SMTP_PORT" value="<?= htmlspecialchars((string)($settings['SAAS_SMTP_PORT'] ?? '')) ?>" placeholder="587" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Security</label>
                                    <select name="SAAS_SMTP_SECURE" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                                        <option value="tls" <?= htmlspecialchars((string)(($settings['SAAS_SMTP_SECURE'] ?? '') === 'tls' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>TLS</option>
                                        <option value="ssl" <?= htmlspecialchars((string)(($settings['SAAS_SMTP_SECURE'] ?? '') === 'ssl' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>SSL</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">SMTP User</label>
                                <input type="text" name="SAAS_SMTP_USER" value="<?= htmlspecialchars((string)($settings['SAAS_SMTP_USER'] ?? '')) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">SMTP Password</label>
                                <input type="password" name="SAAS_SMTP_PASS" value="<?= htmlspecialchars((string)($settings['SAAS_SMTP_PASS'] ?? '')) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                            </div>
                        </div>
                        </div>
                    </div>

                    <!-- Security & Billing -->
                    <div class="space-y-6">
                        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                            <div class="px-5 py-3.5 bg-slate-50 border-b border-slate-200">
                                <h2 class="font-extrabold text-slate-900 flex items-center gap-2"><i class="ph ph-credit-card text-emerald-600"></i> Central Billing</h2>
                            </div>
                            <div class="p-5 space-y-3">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Gateway</label>
                                    <select name="SAAS_PAYMENT_GATEWAY" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                                        <option value="razorpay" <?= htmlspecialchars((string)(($settings['SAAS_PAYMENT_GATEWAY'] ?? '') === 'razorpay' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Razorpay</option>
                                        <option value="phonepe" <?= htmlspecialchars((string)(($settings['SAAS_PAYMENT_GATEWAY'] ?? '') === 'phonepe' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>PhonePe</option>
                                        <option value="stripe" <?= htmlspecialchars((string)(($settings['SAAS_PAYMENT_GATEWAY'] ?? '') === 'stripe' ? 'selected' : ''), ENT_QUOTES, 'UTF-8') ?>>Stripe</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Key ID</label>
                                    <input type="text" name="SAAS_PG_KEY_ID" value="<?= htmlspecialchars((string)($settings['SAAS_PG_KEY_ID'] ?? '')) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Key Secret</label>
                                    <input type="password" name="SAAS_PG_SECRET" value="<?= htmlspecialchars((string)($settings['SAAS_PG_SECRET'] ?? '')) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                                </div>
                            </div>
                            </div>
                        </div>

                        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                            <div class="px-5 py-3.5 bg-slate-50 border-b border-slate-200">
                                <h2 class="font-extrabold text-slate-900 flex items-center gap-2"><i class="ph ph-shield-check text-red-600"></i> Compliance &amp; Security</h2>
                            </div>
                            <div class="p-5 space-y-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">TOS URL</label>
                                    <input type="url" name="SAAS_TOS_URL" value="<?= htmlspecialchars((string)($settings['SAAS_TOS_URL'] ?? '')) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-400 uppercase mb-1">Privacy Policy URL</label>
                                    <input type="url" name="SAAS_PRIVACY_URL" value="<?= htmlspecialchars((string)($settings['SAAS_PRIVACY_URL'] ?? '')) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-white text-sm focus:border-indigo-500 focus:outline-none">
                                </div>
                                <div class="flex items-center justify-between pt-2">
                                    <span class="text-sm text-slate-700 font-semibold">Enable Public Registration</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="hidden" name="SAAS_PUBLIC_REGISTRATION" value="0">
                                        <input type="checkbox" name="SAAS_PUBLIC_REGISTRATION" value="1" <?= htmlspecialchars((string)(($settings['SAAS_PUBLIC_REGISTRATION'] ?? '0') === '1' ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                        <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                                    </label>
                                </div>
                                <div class="flex items-center justify-between pt-1">
                                    <span class="text-sm text-slate-700 font-semibold">Maintenance Mode</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="hidden" name="SAAS_MAINTENANCE_MODE" value="0">
                                        <input type="checkbox" name="SAAS_MAINTENANCE_MODE" value="1" <?= htmlspecialchars((string)(($settings['SAAS_MAINTENANCE_MODE'] ?? '0') === '1' ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="sr-only peer">
                                        <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-red-500"></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="btn-primary-saas w-full py-3.5 text-sm justify-center">
                        <i class="ph ph-floppy-disk text-base"></i> Save All Global Settings
                    </button>
                </div>
            </form>

            <!-- SaaS Plans Configuration Panel -->
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm max-w-2xl mx-auto mt-6 overflow-hidden">
                <div class="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                    <h2 class="font-extrabold text-slate-900 flex items-center gap-2"><i class="ph ph-sliders text-blue-700"></i> SaaS Plans Configuration</h2>
                    <button onclick="document.getElementById('newPlanModal').classList.remove('hidden')" class="btn-success-saas text-xs">
                        <i class="ph ph-plus"></i> Create Plan
                    </button>
                </div>
                <div class="p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="save_plans_config">

                    <?php foreach ($saasPlans as $key => $plan): ?>
                        <div class="border-b border-slate-100 pb-5 mb-5 last:border-0 last:pb-0 last:mb-0 space-y-4">
                            <div class="flex justify-between items-center">
                                <h3 class="text-sm font-extrabold text-blue-700 uppercase tracking-wider"><?= htmlspecialchars((string)($plan['name'])) ?> Tier</h3>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Pricing (Monthly)</label>
                                    <input type="number" name="plan_<?= htmlspecialchars((string)($key), ENT_QUOTES, 'UTF-8') ?>_price" value="<?= htmlspecialchars((string)($plan['price']), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:border-indigo-500 focus:outline-none font-bold">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Max Rooms Limit</label>
                                    <input type="number" name="plan_<?= htmlspecialchars((string)($key), ENT_QUOTES, 'UTF-8') ?>_max_rooms" value="<?= htmlspecialchars((string)($plan['max_rooms']), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:border-indigo-500 focus:outline-none font-bold">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-400 uppercase mb-1">Max Staff Seats</label>
                                    <input type="number" name="plan_<?= htmlspecialchars((string)($key), ENT_QUOTES, 'UTF-8') ?>_max_staff" value="<?= htmlspecialchars((string)($plan['max_staff']), ENT_QUOTES, 'UTF-8') ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:border-indigo-500 focus:outline-none font-bold">
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider">Feature Entitlements</label>
                                <div class="flex flex-wrap gap-4 text-xs">
                                    <?php foreach ($plan['features'] as $fKey => $fVal): ?>
                                        <label class="flex items-center gap-2 cursor-pointer text-slate-600 hover:text-slate-900 font-medium">
                                            <input type="hidden" name="plan_<?= htmlspecialchars((string)($key), ENT_QUOTES, 'UTF-8') ?>_feat_<?= htmlspecialchars((string)($fKey), ENT_QUOTES, 'UTF-8') ?>" value="0">
                                            <input type="checkbox" name="plan_<?= htmlspecialchars((string)($key), ENT_QUOTES, 'UTF-8') ?>_feat_<?= htmlspecialchars((string)($fKey), ENT_QUOTES, 'UTF-8') ?>" value="1" <?= htmlspecialchars((string)($fVal ? 'checked' : ''), ENT_QUOTES, 'UTF-8') ?> class="accent-blue-700 rounded">
                                            <?= htmlspecialchars((string)(str_replace('_', ' ', $fKey))) ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <button type="submit" class="btn-primary-saas w-full py-3 justify-center">
                        <i class="ph ph-floppy-disk"></i> Save SaaS Plan Settings
                    </button>
                </form>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: System Logs -->
        <div id="content-logs" class="tab-content hidden">
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 bg-slate-50 border-b border-slate-200">
                    <h2 class="font-extrabold text-slate-900 flex items-center gap-2">
                        <i class="ph ph-scroll text-blue-700"></i> System Audit Logs
                    </h2>
                    <div class="flex items-center gap-2">
                        <select id="logFilterProperty" onchange="loadSystemLogs()" class="text-xs w-48">
                            <option value="">All Properties (Global)</option>
                            <?php foreach ($properties as $p): ?>
                                <option value="<?= htmlspecialchars((string)($p['id']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($p['name'])) ?> (ID: <?= htmlspecialchars((string)($p['id'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button onclick="loadSystemLogs()" class="btn-ghost-saas">
                            <i class="ph ph-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="saas-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Property</th>
                                <th>Actor</th>
                                <th>Action</th>
                                <th>Type</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody id="systemLogsBody">
                            <tr><td colspan="6" class="text-center text-slate-400 py-8 text-sm"><i class="ph ph-spinner"></i> Loading logs...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB CONTENT: Security -->
        <div id="content-security" class="tab-content hidden">
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm max-w-lg mx-auto overflow-hidden">
                <div class="px-6 py-4 bg-slate-50 border-b border-slate-200">
                    <h2 class="font-extrabold text-slate-900 flex items-center gap-2">
                        <i class="ph ph-shield-check text-blue-700"></i> Superadmin Credentials
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Update your SaaS superadmin password and 4-digit PIN.</p>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="update_saas_security">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">New Password <span class="text-slate-400 normal-case font-medium">(optional)</span></label>
                        <input type="password" name="saas_new_password" placeholder="Leave blank to keep current" class="w-full text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">New 4-Digit PIN <span class="text-slate-400 normal-case font-medium">(optional)</span></label>
                        <input type="text" name="saas_new_pin" pattern="\d{4}" maxlength="4" placeholder="e.g. 1234" class="w-full text-sm">
                    </div>
                    <button type="submit" class="btn-primary-saas w-full py-3 justify-center">
                        <i class="ph ph-lock-key text-base"></i> Update Credentials
                    </button>
                </form>
            </div>
        </div>

    </div>

    <!-- New Plan Modal -->
    <div id="newPlanModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-white border border-slate-200 rounded-2xl w-full max-w-sm p-6 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-base font-extrabold text-slate-900 flex items-center gap-2"><i class="ph ph-plus-circle text-blue-700"></i> Create Custom Plan</h3>
                <button onclick="document.getElementById('newPlanModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 w-7 h-7 rounded-lg hover:bg-slate-100 flex items-center justify-center transition">
                    <i class="ph ph-x"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="create_new_plan">
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Plan Identifier (No spaces)</label>
                    <input type="text" name="new_plan_id" placeholder="e.g. lifetime_free" required class="w-full text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Display Name</label>
                    <input type="text" name="new_plan_name" placeholder="e.g. Lifetime Free Tier" required class="w-full text-sm">
                </div>
                <p class="text-[10px] text-slate-400 italic">New plan defaults: all modules enabled, unlimited resources. Adjust limits after creation.</p>
                <button type="submit" class="btn-success-saas w-full py-2.5 justify-center"><i class="ph ph-plus"></i> Create Plan</button>
            </form>
        </div>
    </div>

    <!-- Edit Property Modal -->
    <div id="editModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-white border border-slate-200 rounded-2xl w-full max-w-lg shadow-xl">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
                <h3 class="font-extrabold text-slate-900 flex items-center gap-2">
                    <i class="ph ph-pencil-simple text-blue-700"></i> Edit Property Details
                </h3>
                <button onclick="closeEditModal()" class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center transition">
                    <i class="ph ph-x"></i>
                </button>
            </div>

            <form method="POST" class="p-6 space-y-3">
                <input type="hidden" name="action" value="edit_property">
                <input type="hidden" name="property_id" id="edit_property_id">

                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Hotel / Property Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="edit_name" required class="w-full text-sm">
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">SaaS Plan</label>
                        <select name="plan" id="edit_plan" class="w-full text-sm">
                            <?php foreach ($saasPlans as $planId => $planData): ?>
                                <option value="<?= htmlspecialchars((string)($planId)) ?>"><?= htmlspecialchars((string)($planData['name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Max Rooms</label>
                        <input type="number" name="max_rooms" id="edit_max_rooms" class="w-full text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Custom Domain</label>
                        <input type="text" name="custom_domain" id="edit_custom_domain" placeholder="hotel.com" class="w-full text-sm font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">City</label>
                        <input type="text" name="city" id="edit_city" class="w-full text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">State</label>
                        <input type="text" name="state" id="edit_state" class="w-full text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Address</label>
                    <textarea name="address" id="edit_address" rows="2" class="w-full text-sm"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Phone</label>
                        <input type="text" name="phone" id="edit_phone" class="w-full text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">GSTIN</label>
                        <input type="text" name="gstin" id="edit_gstin" class="w-full text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-600 uppercase tracking-wider mb-1.5">Email</label>
                    <input type="email" name="email" id="edit_email" class="w-full text-sm">
                </div>

                <div class="space-y-2 py-3 border-t border-slate-100">
                    <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider">Feature Flag Overrides (Add-ons)</label>
                    <div class="flex flex-wrap gap-4 text-xs text-slate-700">
                        <label class="flex items-center gap-2 cursor-pointer font-medium">
                            <input type="hidden" name="feat_ocr_google_vision" value="0">
                            <input type="checkbox" name="feat_ocr_google_vision" id="edit_feat_ocr_google_vision" value="1" class="accent-blue-700 rounded">
                            Google Vision OCR Scanner
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer font-medium">
                            <input type="hidden" name="feat_whatsapp_automations" value="0">
                            <input type="checkbox" name="feat_whatsapp_automations" id="edit_feat_whatsapp_automations" value="1" class="accent-blue-700 rounded">
                            WhatsApp Automations
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                    <button type="button" onclick="closeEditModal()" class="btn-ghost-saas">Cancel</button>
                    <button type="submit" class="btn-primary-saas"><i class="ph ph-floppy-disk"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/js/api-client.js"></script>
    <script>
        const csrfToken = <?= json_encode(CsrfToken::generate()) ?>;
        document.querySelectorAll('form').forEach((form) => {
            const method = (form.getAttribute('method') || 'get').toLowerCase();
            if (method === 'get') return;
            if (form.querySelector('input[name="_csrf_token"]')) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_csrf_token';
            input.value = csrfToken;
            form.appendChild(input);
        });
        function withCsrf(formData) {
            formData.append('_csrf_token', csrfToken);
            return formData;
        }

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.getElementById('content-' + tabId).classList.remove('hidden');
            document.getElementById('tab-' + tabId).classList.add('active');
        }
        <?php if (in_array(($openSaasTab ?? ''), ['settings', 'deploy', 'leads'], true)): ?>
        switchTab(<?= json_encode($openSaasTab) ?>);
        <?php endif; ?>

        function openEditModal(p) {
            document.getElementById('edit_property_id').value = p.id || '';
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
            const tbody = document.getElementById('staffListBody');
            if (window.ApiClient) ApiClient.showSkeleton(tbody, { rows: 4, type: 'table' });
            const formData = new FormData();
            formData.append('action', 'get_staff');
            formData.append('property_id', activePropertyId);
            withCsrf(formData);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    tbody.innerHTML = '';
                    if (data.staff.length === 0) {
                        if (window.ApiClient) {
                            ApiClient.showEmptyState(tbody, { message: 'No staff users registered.', icon: 'ph-users' });
                        } else {
                            tbody.innerHTML = `<tr><td colspan="4" class="px-4 py-3 text-center text-slate-500">No staff users registered.</td></tr>`;
                        }
                        return;
                    }
                    data.staff.forEach(su => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td class="font-bold text-slate-900 font-mono">${escapeHtml(su.username)}</td>
                            <td><span class="px-2 py-0.5 rounded-full bg-blue-50 text-[10px] uppercase font-bold text-blue-700 border border-blue-200">${escapeHtml(su.role)}</span></td>
                            <td>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${su.is_active == 1 ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200'}">
                                    ${su.is_active == 1 ? 'Active' : 'Suspended'}
                                </span>
                            </td>
                            <td class="text-right">
                                <button onclick='editStaffUser(${JSON.stringify(su)})' class="btn-ghost-saas"><i class="ph ph-pencil-simple"></i> Edit</button>
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
            document.getElementById('staff_form_username').readOnly = true;
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
            withCsrf(formData);

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
            const tbody = document.getElementById('systemLogsBody');
            if (window.ApiClient) ApiClient.showSkeleton(tbody, { rows: 6, type: 'table' });
            const propId = document.getElementById('logFilterProperty').value;
            const formData = new FormData();
            formData.append('action', 'get_audit_logs');
            if (propId !== '') {
                formData.append('property_id', propId);
            }
            withCsrf(formData);

            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    tbody.innerHTML = '';
                    if (data.logs.length === 0) {
                        if (window.ApiClient) {
                            ApiClient.showEmptyState(tbody, { message: 'No logs found.', icon: 'ph-scroll' });
                        } else {
                            tbody.innerHTML = `<tr><td colspan="6" class="px-4 py-4 text-center text-slate-500">No logs found.</td></tr>`;
                        }
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
                            <td class="px-4 py-3 font-mono text-indigo-400">${escapeHtml(log.username || 'System')}</td>
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
    <div id="staffModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center hidden p-4">
        <div class="bg-white border border-slate-200 rounded-2xl w-full max-w-2xl shadow-xl flex flex-col max-h-[90vh]">
            <!-- Modal Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 shrink-0">
                <h3 class="font-extrabold text-slate-900 flex items-center gap-2" id="staff_property_title">
                    <i class="ph ph-users-three text-blue-700"></i> Manage Staff
                </h3>
                <button onclick="closeStaffModal()" class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center transition">
                    <i class="ph ph-x"></i>
                </button>
            </div>

            <!-- Scrollable Body -->
            <div class="overflow-y-auto grow space-y-4 p-6">
                <div class="flex justify-between items-center">
                    <p class="text-xs text-slate-500">Registered staff members for this tenant workspace.</p>
                    <button onclick="showAddStaffForm()" class="btn-primary-saas">
                        <i class="ph ph-plus"></i> Add Staff Account
                    </button>
                </div>

                <!-- Form Panel (hidden by default) -->
                <div id="staffUserFormPanel" class="bg-blue-50 border border-blue-200 rounded-xl p-4 hidden space-y-3">
                    <div class="text-xs font-bold text-blue-800 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="ph ph-user-gear text-sm"></i> Account Configuration
                    </div>
                    <form id="staffUserForm" onsubmit="saveStaffUser(event)" class="space-y-3">
                        <input type="hidden" name="staff_id" id="staff_form_id">

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Username *</label>
                                <input type="text" name="username" id="staff_form_username" required class="w-full text-xs">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Status</label>
                                <select name="is_active" id="staff_form_is_active" class="w-full text-xs">
                                    <option value="1">Active</option>
                                    <option value="0">Suspended / Disabled</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Access Level</label>
                                <select name="access_level" id="staff_form_access_level" class="w-full text-xs">
                                    <option value="owner">Owner / Property Admin</option>
                                    <option value="manager">Manager / Front Desk</option>
                                    <option value="housekeeping">Housekeeping Staff</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5">Display Role (Label)</label>
                                <input type="text" name="role" id="staff_form_role" required class="w-full text-xs">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5" id="staff_form_password_hint">Password</label>
                                <input type="password" name="password" id="staff_form_password" class="w-full text-xs">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-600 uppercase tracking-wider mb-1.5" id="staff_form_pin_hint">PIN (4 digits)</label>
                                <input type="password" name="pin" id="staff_form_pin" maxlength="4" class="w-full text-xs">
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 pt-2 border-t border-blue-200">
                            <button type="button" onclick="document.getElementById('staffUserFormPanel').classList.add('hidden')" class="btn-ghost-saas">Cancel</button>
                            <button type="submit" class="btn-success-saas"><i class="ph ph-check"></i> Apply Changes</button>
                        </div>
                    </form>
                </div>

                <!-- Staff Table -->
                <div class="border border-slate-200 rounded-xl overflow-hidden">
                    <table class="saas-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="staffListBody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-slate-200 shrink-0">
                <button type="button" onclick="closeStaffModal()" class="btn-ghost-saas"><i class="ph ph-x-circle"></i> Close Window</button>
            </div>
        </div>
    </div>
</body>
</html>

