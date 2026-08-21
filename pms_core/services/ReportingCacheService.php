<?php
declare(strict_types=1);

/**
 * Nightly occupancy / ADR / RevPAR snapshots used by reports.
 */
class ReportingCacheService {

    public static function refreshDay(\PDO $db, int $propertyId, string $date): array {
        self::ensureTable($db);
        $date = substr($date, 0, 10);
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        $roomsStmt = $db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = ? AND deleted_at IS NULL");
        $roomsStmt->execute([$propertyId]);
        $totalRooms = (int)$roomsStmt->fetchColumn();

        $occStmt = $db->prepare("
            SELECT COUNT(DISTINCT room_id)
            FROM bookings
            WHERE property_id = ?
              AND deleted_at IS NULL
              AND booking_status IN ('booked', 'checked_in', 'checked_out')
              AND payment_status != 'cancelled'
              AND check_in < ?
              AND check_out > ?
        ");
        $occStmt->execute([$propertyId, $end, $start]);
        $occupied = (int)$occStmt->fetchColumn();

        $revStmt = $db->prepare("
            SELECT COALESCE(SUM(fl.amount), 0)
            FROM folio_ledger fl
            JOIN bookings b ON b.id = fl.booking_id
            WHERE b.property_id = ?
              AND fl.entry_kind = 'ROOM_CHARGE'
              AND DATE(fl.recorded_at) = ?
        ");
        $revStmt->execute([$propertyId, $date]);
        $revenue = (float)$revStmt->fetchColumn();

        $occupancy = $totalRooms > 0 ? round(($occupied / $totalRooms) * 100, 2) : 0.0;
        $adr = $occupied > 0 ? round($revenue / $occupied, 2) : 0.0;
        $revpar = $totalRooms > 0 ? round($revenue / $totalRooms, 2) : 0.0;

        $row = [
            'property_id' => $propertyId,
            'stat_date' => $date,
            'total_rooms' => $totalRooms,
            'occupied_rooms' => $occupied,
            'occupancy_percent' => $occupancy,
            'room_revenue' => round($revenue, 2),
            'adr' => $adr,
            'revpar' => $revpar,
        ];

        $sql = "
            INSERT INTO report_daily_stats
                (property_id, stat_date, total_rooms, occupied_rooms, occupancy_percent, room_revenue, adr, revpar)
            VALUES
                (:property_id, :stat_date, :total_rooms, :occupied_rooms, :occupancy_percent, :room_revenue, :adr, :revpar)
            ON DUPLICATE KEY UPDATE
                total_rooms = VALUES(total_rooms),
                occupied_rooms = VALUES(occupied_rooms),
                occupancy_percent = VALUES(occupancy_percent),
                room_revenue = VALUES(room_revenue),
                adr = VALUES(adr),
                revpar = VALUES(revpar)
        ";
        $db->prepare($sql)->execute($row);
        return $row;
    }

    public static function refreshProperty(\PDO $db, int $propertyId, int $daysBack = 2): int {
        $count = 0;
        for ($i = 1; $i <= $daysBack; $i++) {
            self::refreshDay($db, $propertyId, date('Y-m-d', strtotime('-' . $i . ' days')));
            $count++;
        }
        return $count;
    }

    /** @return array<string, array>|null */
    public static function getRange(\PDO $db, int $propertyId, string $start, string $end): ?array {
        self::ensureTable($db);
        $start = substr($start, 0, 10);
        $end = substr($end, 0, 10);
        $stmt = $db->prepare("
            SELECT stat_date as date, total_rooms, occupied_rooms, occupancy_percent, room_revenue, adr, revpar
            FROM report_daily_stats
            WHERE property_id = ? AND stat_date >= ? AND stat_date <= ?
            ORDER BY stat_date ASC
        ");
        $stmt->execute([$propertyId, $start, $end]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            return null;
        }
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['date']] = $row;
        }
        $expected = 0;
        $cursor = strtotime($start);
        $last = strtotime($end);
        while ($cursor !== false && $last !== false && $cursor <= $last) {
            $expected++;
            $cursor = strtotime('+1 day', $cursor);
        }
        if (count($byDate) < $expected) {
            return null;
        }
        return array_values($byDate);
    }

    private static function ensureTable(\PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `report_daily_stats` (
              `property_id` INT(11) NOT NULL,
              `stat_date` DATE NOT NULL,
              `total_rooms` INT(11) NOT NULL DEFAULT 0,
              `occupied_rooms` INT(11) NOT NULL DEFAULT 0,
              `occupancy_percent` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
              `room_revenue` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `adr` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `revpar` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`property_id`, `stat_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
