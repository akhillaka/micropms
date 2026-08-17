<?php
declare(strict_types=1);

require_once __DIR__ . '/../ApiException.php';
require_once __DIR__ . '/../TenantScope.php';
require_once __DIR__ . '/BookingService.php';

/**
 * 15-minute soft lock on rooms between availability check and booking confirm.
 */
class RoomHoldService {
    public const TTL_SECONDS = 900;

    public static function ensureTable(\PDO $db): void {
        static $ready = false;
        if ($ready) {
            return;
        }
        try {
            $db->query("SELECT 1 FROM room_holds LIMIT 1");
            $ready = true;
        } catch (\PDOException $e) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `room_holds` (
                  `id` INT NOT NULL AUTO_INCREMENT,
                  `property_id` INT NOT NULL,
                  `room_id` INT NOT NULL,
                  `token` CHAR(64) NOT NULL,
                  `staff_id` INT DEFAULT NULL,
                  `check_in` DATETIME NOT NULL,
                  `check_out` DATETIME NOT NULL,
                  `expires_at` DATETIME NOT NULL,
                  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_room_holds_token` (`token`),
                  KEY `idx_room_holds_overlap` (`property_id`, `room_id`, `check_in`, `check_out`, `expires_at`),
                  KEY `idx_room_holds_expires` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $ready = true;
        }
    }

    public static function normalizeDate(string $value): string {
        $value = str_replace('T', ' ', trim($value));
        if (strlen($value) === 10) {
            $value .= ' 00:00:00';
        } elseif (strlen($value) === 16) {
            $value .= ':00';
        }
        return $value;
    }

    /**
     * @param list<int> $roomIds
     * @return array{token: string, expires_at: string, expires_in: int}
     */
    public static function place(
        \PDO $db,
        int $propertyId,
        array $roomIds,
        string $checkIn,
        string $checkOut,
        int $staffId,
        ?string $existingToken = null
    ): array {
        self::ensureTable($db);
        self::purgeExpired($db);

        $checkIn = self::normalizeDate($checkIn);
        $checkOut = self::normalizeDate($checkOut);
        $roomIds = array_values(array_unique(array_map('intval', $roomIds)));
        $roomIds = array_values(array_filter($roomIds, static fn($id) => $id > 0));
        sort($roomIds);

        $token = $existingToken && preg_match('/^[a-f0-9]{64}$/', $existingToken) ? $existingToken : bin2hex(random_bytes(32));

        if (empty($roomIds)) {
            self::release($db, $token, $propertyId);
            return ['token' => $token, 'expires_at' => date('Y-m-d H:i:s'), 'expires_in' => 0];
        }

        $expiresAt = date('Y-m-d H:i:s', time() + self::TTL_SECONDS);

        foreach ($roomIds as $roomId) {
            TenantScope::room($db, $roomId, $propertyId);
            if (!BookingService::isRoomAvailable($db, $roomId, $checkIn, $checkOut, null, $propertyId, $token)) {
                throw new ApiException('Room is no longer available for this timeframe', 409, ['code' => 'ROOM_UNAVAILABLE', 'retryable' => false]);
            }
        }

        $db->prepare("DELETE FROM room_holds WHERE token = ? AND property_id = ?")->execute([$token, $propertyId]);

        $insert = $db->prepare("
            INSERT INTO room_holds (property_id, room_id, token, staff_id, check_in, check_out, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($roomIds as $roomId) {
            $insert->execute([$propertyId, $roomId, $token, $staffId > 0 ? $staffId : null, $checkIn, $checkOut, $expiresAt]);
        }

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    public static function release(\PDO $db, string $token, int $propertyId): void {
        if ($token === '') {
            return;
        }
        self::ensureTable($db);
        $db->prepare("DELETE FROM room_holds WHERE token = ? AND property_id = ?")->execute([$token, $propertyId]);
    }

    /**
     * Drop the hold after a successful booking. Invalid/expired tokens are ignored
     * because BookingService re-checks availability under a row lock.
     *
     * @param list<int> $roomIds
     */
    public static function consume(\PDO $db, ?string $token, int $propertyId, array $roomIds = []): void {
        if ($token === null || $token === '') {
            return;
        }
        self::ensureTable($db);
        self::release($db, $token, $propertyId);
    }

    public static function purgeExpired(\PDO $db): void {
        try {
            $db->exec("DELETE FROM room_holds WHERE expires_at < NOW()");
        } catch (\PDOException $e) {
        }
    }
}
