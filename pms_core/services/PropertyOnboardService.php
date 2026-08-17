<?php
declare(strict_types=1);

require_once __DIR__ . '/../saas_plans.php';
require_once __DIR__ . '/../AuthHelper.php';

/**
 * Creates a property + owner staff user. Shared by SaaS onboard and public register.
 */
class PropertyOnboardService {
    /**
     * @param array{
     *   name?:string, city?:string, state?:string, phone?:string, email?:string, gstin?:string,
     *   plan?:string, admin_username?:string, admin_password?:string, admin_pin?:string
     * } $input
     * @return array{property_id:int, user_id:int, username:string, password:string, pin:string, plan:string, property_name:string}
     */
    public static function create(\PDO $db, array $input): array {
        $name = trim((string)($input['name'] ?? ''));
        $city = trim((string)($input['city'] ?? ''));
        $state = trim((string)($input['state'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $gstin = trim((string)($input['gstin'] ?? ''));
        $plan = trim((string)($input['plan'] ?? 'starter'));

        $plansConfig = SaaSPlans::get($db);
        if (!isset($plansConfig[$plan])) {
            $plan = isset($plansConfig['starter']) ? 'starter' : (string)array_key_first($plansConfig);
        }
        $planDefaults = $plansConfig[$plan] ?? ['max_rooms' => 15, 'max_staff' => 5, 'features' => []];
        $maxRooms = (int)($planDefaults['max_rooms'] ?? 15);
        if (isset($input['max_rooms']) && $input['max_rooms'] !== null && $input['max_rooms'] !== '') {
            $postedRooms = (int)$input['max_rooms'];
            if ($postedRooms > 0) {
                $maxRooms = $postedRooms;
            }
        }
        $maxStaff = (int)($planDefaults['max_staff'] ?? 5);
        $featuresJson = json_encode($planDefaults['features'] ?? [], JSON_THROW_ON_ERROR);

        $adminUser = trim((string)($input['admin_username'] ?? ''));
        if ($adminUser === '') {
            $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $name));
            $adminUser = 'admin_' . ($slug !== '' ? $slug : 'hotel');
        }

        $rawPass = (string)($input['admin_password'] ?? '');
        if ($rawPass === '') {
            $rawPass = 'Pass' . random_int(1000, 9999);
        }

        $rawPin = trim((string)($input['admin_pin'] ?? ''));
        if ($rawPin === '' || !preg_match('/^\d{4}$/', $rawPin)) {
            $rawPin = (string)random_int(1000, 9999);
        }

        if ($name === '' || $adminUser === '') {
            throw new \InvalidArgumentException('Property name and admin username are required.');
        }

        $exists = $db->prepare('SELECT id FROM staff_users WHERE username = ? LIMIT 1');
        $exists->execute([$adminUser]);
        if ($exists->fetchColumn()) {
            throw new \InvalidArgumentException('That username is already taken. Choose another.');
        }

        $isFree = ((int)($planDefaults['price'] ?? 0) === 0);
        $status = $isFree ? 'active' : 'trialing';
        $validUntil = $isFree ? null : date('Y-m-d H:i:s', strtotime('+14 days'));

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO properties (name, city, state, phone, email, gstin, plan, max_rooms, max_staff, features_json, subscription_status, valid_until, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $name, $city, $state, $phone, $email, $gstin, $plan,
                $maxRooms, $maxStaff, $featuresJson, $status, $validUntil,
            ]);
            $propId = (int)$db->lastInsertId();

            $userStmt = $db->prepare(
                "INSERT INTO staff_users (username, password_hash, pin_hash, access_level, role, property_id, is_active)
                 VALUES (?, ?, ?, 'owner', 'Property Admin', ?, 1)"
            );
            $userStmt->execute([
                $adminUser,
                password_hash($rawPass, PASSWORD_BCRYPT),
                password_hash($rawPin, PASSWORD_BCRYPT),
                $propId,
            ]);
            $userId = (int)$db->lastInsertId();

            AuthHelper::seedRolesForProperty($db, $propId);

            $roleStmt = $db->prepare("SELECT id FROM roles WHERE property_id = ? AND name = 'owner' LIMIT 1");
            $roleStmt->execute([$propId]);
            $roleId = $roleStmt->fetchColumn();
            if ($roleId) {
                $spStmt = $db->prepare('INSERT INTO staff_properties (staff_id, property_id, role_id) VALUES (?, ?, ?)');
                $spStmt->execute([$userId, $propId, (int)$roleId]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'property_id' => $propId,
            'user_id' => $userId,
            'username' => $adminUser,
            'password' => $rawPass,
            'pin' => $rawPin,
            'plan' => $plan,
            'property_name' => $name,
        ];
    }
}
