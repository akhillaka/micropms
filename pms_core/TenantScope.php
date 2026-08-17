<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/AuthHelper.php';

/**
 * Central tenant isolation lookups. Every ID-based read/write should go through here
 * so missing property_id filters cannot leak another hotel's data.
 */
class TenantScope {

    public static function propertyId(): int {
        return AuthHelper::getPropertyId();
    }

    public static function booking(\PDO $db, int $bookingId, ?int $propertyId = null, bool $forUpdate = false): array {
        $pid = $propertyId ?? self::propertyId();
        $sql = "SELECT * FROM bookings WHERE id = ? AND property_id = ? LIMIT 1";
        if ($forUpdate && $db->inTransaction()) {
            $sql .= " FOR UPDATE";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$bookingId, $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('Booking not found', 404);
        }
        return $row;
    }

    public static function guest(\PDO $db, int $guestId, ?int $propertyId = null): array {
        $pid = $propertyId ?? self::propertyId();
        $stmt = $db->prepare("SELECT * FROM guests WHERE id = ? AND property_id = ? LIMIT 1");
        $stmt->execute([$guestId, $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('Guest not found', 404);
        }
        return $row;
    }

    public static function staff(\PDO $db, int $userId, ?int $propertyId = null): array {
        $pid = $propertyId ?? self::propertyId();
        $stmt = $db->prepare("SELECT * FROM staff_users WHERE id = ? AND property_id = ? LIMIT 1");
        $stmt->execute([$userId, $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('User not found', 404);
        }
        return $row;
    }

    public static function conversation(\PDO $db, int $convId, ?int $propertyId = null): array {
        $pid = $propertyId ?? self::propertyId();
        $stmt = $db->prepare("SELECT * FROM wa_conversations WHERE id = ? AND property_id = ? LIMIT 1");
        $stmt->execute([$convId, $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('Conversation not found', 404);
        }
        return $row;
    }

    public static function room(\PDO $db, int $roomId, ?int $propertyId = null, bool $forUpdate = false): array {
        $pid = $propertyId ?? self::propertyId();
        $sql = "SELECT * FROM rooms WHERE id = ? AND property_id = ? LIMIT 1";
        if ($forUpdate && $db->inTransaction()) {
            $sql .= " FOR UPDATE";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute([$roomId, $pid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('Room not found', 404);
        }
        return $row;
    }
}
