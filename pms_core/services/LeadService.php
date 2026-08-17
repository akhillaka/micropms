<?php
declare(strict_types=1);

require_once __DIR__ . '/../saas_plans.php';

/**
 * Public landing interest leads. Accounts are created later from SaaS admin.
 */
class LeadService {
    public const STATUSES = ['new', 'contacted', 'converted', 'dismissed'];

    public static function ensureTable(\PDO $db): void {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `saas_leads` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `hotel_name` VARCHAR(190) NOT NULL,
              `contact_name` VARCHAR(190) DEFAULT NULL,
              `email` VARCHAR(190) NOT NULL,
              `phone` VARCHAR(40) DEFAULT NULL,
              `city` VARCHAR(120) DEFAULT NULL,
              `plan` VARCHAR(40) NOT NULL DEFAULT 'starter',
              `rooms_estimate` INT(11) DEFAULT NULL,
              `message` TEXT DEFAULT NULL,
              `status` VARCHAR(20) NOT NULL DEFAULT 'new',
              `property_id` INT(11) DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_leads_status` (`status`),
              KEY `idx_leads_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id:int}
     */
    public static function capture(\PDO $db, array $input): array {
        self::ensureTable($db);

        $hotel = trim((string)($input['hotel_name'] ?? $input['name'] ?? ''));
        $contact = trim((string)($input['contact_name'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $phone = trim((string)($input['phone'] ?? ''));
        $city = trim((string)($input['city'] ?? ''));
        $plan = trim((string)($input['plan'] ?? 'starter'));
        $message = trim((string)($input['message'] ?? ''));
        $roomsRaw = trim((string)($input['rooms_estimate'] ?? ''));
        $rooms = $roomsRaw === '' ? null : (int)$roomsRaw;
        if ($rooms !== null && $rooms < 1) {
            $rooms = null;
        }

        $plans = SaaSPlans::get($db);
        if (!isset($plans[$plan])) {
            $plan = isset($plans['starter']) ? 'starter' : (string)array_key_first($plans);
        }

        if ($hotel === '') {
            throw new \InvalidArgumentException('Hotel name is required.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid email address.');
        }
        if ($phone === '') {
            throw new \InvalidArgumentException('Phone number is required.');
        }

        $stmt = $db->prepare(
            'INSERT INTO saas_leads (hotel_name, contact_name, email, phone, city, plan, rooms_estimate, message, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'new\')'
        );
        $stmt->execute([
            $hotel,
            $contact !== '' ? $contact : null,
            $email,
            $phone,
            $city !== '' ? $city : null,
            $plan,
            $rooms,
            $message !== '' ? $message : null,
        ]);

        return ['id' => (int)$db->lastInsertId()];
    }

    /** @return list<array<string, mixed>> */
    public static function listAll(\PDO $db): array {
        self::ensureTable($db);
        $stmt = $db->query('SELECT * FROM saas_leads ORDER BY id DESC');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function countNew(\PDO $db): int {
        self::ensureTable($db);
        return (int)$db->query("SELECT COUNT(*) FROM saas_leads WHERE status = 'new'")->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public static function find(\PDO $db, int $id): ?array {
        self::ensureTable($db);
        $stmt = $db->prepare('SELECT * FROM saas_leads WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function setStatus(\PDO $db, int $id, string $status): void {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown lead status.');
        }
        $lead = self::find($db, $id);
        if ($lead === null) {
            throw new \InvalidArgumentException('Lead not found.');
        }
        if (($lead['status'] ?? '') === 'converted' && $status !== 'converted') {
            throw new \InvalidArgumentException('Converted leads cannot change status.');
        }
        $stmt = $db->prepare('UPDATE saas_leads SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }

    public static function markConverted(\PDO $db, int $id, int $propertyId): void {
        $lead = self::find($db, $id);
        if ($lead === null) {
            throw new \InvalidArgumentException('Lead not found.');
        }
        $stmt = $db->prepare("UPDATE saas_leads SET status = 'converted', property_id = ? WHERE id = ?");
        $stmt->execute([$propertyId, $id]);
    }
}
