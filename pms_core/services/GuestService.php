<?php
declare(strict_types=1);

require_once __DIR__ . '/../PhoneHelper.php';
require_once __DIR__ . '/../SequenceGenerator.php';

/**
 * GuestService - Shared guest logic for both Admin API and Assistant PWA.
 */
class GuestService {

    /**
     * Search guests by phone or name.
     */
    public static function search(\PDO $db, string $query, int $limit = 10): array {
        $phone = PhoneHelper::toLocal($query);
        
        if ($phone !== null) {
            // Search by phone
            $stmt = $db->prepare("
                SELECT g.id, g.name, g.phone, g.email, g.city, g.state, g.photo,
                       g.id_proof_front, g.id_proof_back,
                       COUNT(b.id) as stay_count,
                       MAX(b.check_in) as last_stay
                FROM guests g
                LEFT JOIN bookings b ON g.id = b.guest_id AND b.payment_status != 'cancelled'
                WHERE g.phone = :phone AND g.property_id = :propId
                GROUP BY g.id
                LIMIT :limit
            ");
            $propId = class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1;
            $stmt->execute(['phone' => $phone, 'limit' => $limit, 'propId' => $propId]);
        } else {
            // Search by name
            $stmt = $db->prepare("
                SELECT g.id, g.name, g.phone, g.email, g.city, g.state, g.photo,
                       g.id_proof_front, g.id_proof_back,
                       COUNT(b.id) as stay_count,
                       MAX(b.check_in) as last_stay
                FROM guests g
                LEFT JOIN bookings b ON g.id = b.guest_id AND b.payment_status != 'cancelled'
                WHERE g.name LIKE :name AND g.property_id = :propId
                GROUP BY g.id
                ORDER BY last_stay DESC
                LIMIT :limit
            ");
            $propId = class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1;
            $stmt->execute(['name' => "%$query%", 'limit' => $limit, 'propId' => $propId]);
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a new guest or return existing one by phone.
     * 
     * @return array ['guest_id' => int, 'is_new' => bool]
     */
    public static function findOrCreate(\PDO $db, string $name, string $phoneRaw): array {
        $phone = PhoneHelper::toLocal($phoneRaw);
        if ($phone === null) {
            throw new \Exception('Invalid phone number');
        }

        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            // Check if guest exists
            $propId = class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1;
            $stmt = $db->prepare("SELECT id FROM guests WHERE phone = ? AND property_id = ?");
            $stmt->execute([$phone, $propId]);
            $existing = $stmt->fetch();

            if ($existing) {
                if ($shouldCommit) {
                    $db->commit();
                }
                return ['guest_id' => (int)$existing['id'], 'is_new' => false];
            }

            // Create new guest
            $insertStmt = $db->prepare("INSERT INTO guests (property_id, phone, name) VALUES (:pid, :phone, :name)");
            $insertStmt->execute(['pid' => $propId, 'phone' => $phone, 'name' => trim($name)]);
            $guestId = (int)$db->lastInsertId();

            // Assign display ID
            SequenceGenerator::assignDisplayId($db, 'guests', $guestId, 'SEQ_GUEST_FORMAT');

            if ($shouldCommit) {
                $db->commit();
            }
            return ['guest_id' => $guestId, 'is_new' => true];
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get guest profile with booking stats.
     */
    public static function getProfile(\PDO $db, int $guestId): ?array {
        $stmt = $db->prepare("
            SELECT g.*,
                   COUNT(b.id) as total_bookings,
                   COALESCE(SUM(b.total_amount), 0) as lifetime_spent,
                   MAX(b.check_in) as last_visit
            FROM guests g
            LEFT JOIN bookings b ON g.id = b.guest_id AND b.payment_status != 'cancelled'
            WHERE g.id = ?
            GROUP BY g.id
        ");
        $stmt->execute([$guestId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Update guest profile fields.
     */
    public static function update(\PDO $db, int $guestId, array $fields): bool {
        $allowed = ['name', 'phone', 'email', 'age', 'city', 'state', 'country', 'pincode'];
        $updates = [];
        $params = ['id' => $guestId];

        foreach ($allowed as $field) {
            if (isset($fields[$field])) {
                $updates[] = "`$field` = :$field";
                $params[$field] = $fields[$field];
            }
        }

        if (empty($updates)) return false;

        $sql = "UPDATE guests SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Get returning guest info (for showing badge in assistant).
     */
    public static function getReturningInfo(\PDO $db, int $guestId): ?array {
        $stmt = $db->prepare("
            SELECT COUNT(b.id) as total_stays,
                   MAX(b.check_in) as last_visit,
                   MAX(r.room_number) as last_room
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.guest_id = ? AND b.payment_status != 'cancelled'
        ");
        $stmt->execute([$guestId]);
        $info = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($info && (int)$info['total_stays'] > 0) {
            return $info;
        }
        return null;
    }
}
