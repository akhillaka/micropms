<?php
declare(strict_types=1);

/**
 * ChannelAdapter - OTA / channel manager contract.
 * iCal is the first adapter; Booking.com/Airbnb APIs can implement the same methods later.
 */
interface ChannelAdapter {
    public function pushAvailability(\PDO $db, int $propertyId): void;
    public function pullBookings(\PDO $db, int $propertyId): array;
}

/**
 * iCal import/export per room. Export is a public .ics URL; import pulls an OTA feed
 * and writes room_maintenance blocks so inventory stays blocked.
 */
class IcalService implements ChannelAdapter {

    public static function ensureFeed(\PDO $db, int $propertyId, int $roomId): array {
        self::ensureTable($db);
        $stmt = $db->prepare("SELECT * FROM room_ical_feeds WHERE room_id = ? AND property_id = ? LIMIT 1");
        $stmt->execute([$roomId, $propertyId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $token = bin2hex(random_bytes(16));
        $ins = $db->prepare("INSERT INTO room_ical_feeds (property_id, room_id, export_token) VALUES (?, ?, ?)");
        $ins->execute([$propertyId, $roomId, $token]);
        $id = (int)$db->lastInsertId();
        $stmt->execute([$roomId, $propertyId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'id' => $id,
            'property_id' => $propertyId,
            'room_id' => $roomId,
            'export_token' => $token,
            'import_url' => null,
        ];
    }

    public static function publicExportUrl(string $token): string {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https' : 'http') . '://' . $host . '/ical/' . $token . '.ics';
    }

    public static function saveImportUrl(\PDO $db, int $propertyId, int $roomId, string $url): array {
        $feed = self::ensureFeed($db, $propertyId, $roomId);
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('Import URL must start with http:// or https://');
        }
        $db->prepare("UPDATE room_ical_feeds SET import_url = ?, last_error = NULL WHERE id = ? AND property_id = ?")
            ->execute([$url !== '' ? $url : null, $feed['id'], $propertyId]);
        return self::ensureFeed($db, $propertyId, $roomId);
    }

    public static function exportCalendar(\PDO $db, string $token): string {
        self::ensureTable($db);
        $stmt = $db->prepare("
            SELECT f.room_id, f.property_id, r.room_number
            FROM room_ical_feeds f
            JOIN rooms r ON r.id = f.room_id
            WHERE f.export_token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $feed = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$feed) {
            throw new \RuntimeException('Calendar not found');
        }

        $roomId = (int)$feed['room_id'];
        $propertyId = (int)$feed['property_id'];
        $roomNumber = (string)$feed['room_number'];

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MicroPMS//Channel iCal//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        $bookings = $db->prepare("
            SELECT id, check_in, check_out, booking_status
            FROM bookings
            WHERE room_id = ? AND property_id = ?
              AND booking_status IN ('booked', 'checked_in')
              AND payment_status != 'cancelled'
        ");
        $bookings->execute([$roomId, $propertyId]);
        foreach ($bookings->fetchAll(\PDO::FETCH_ASSOC) as $b) {
            $lines = array_merge($lines, self::vevent(
                'pms-booking-' . $b['id'] . '@micropms',
                (string)$b['check_in'],
                (string)$b['check_out'],
                'Occupied - Room ' . $roomNumber
            ));
        }

        $maint = $db->prepare("SELECT id, start_date, end_date, reason, external_uid FROM room_maintenance WHERE room_id = ?");
        $maint->execute([$roomId]);
        foreach ($maint->fetchAll(\PDO::FETCH_ASSOC) as $m) {
            $uid = $m['external_uid'] ?: ('pms-maint-' . $m['id'] . '@micropms');
            $lines = array_merge($lines, self::vevent(
                $uid,
                (string)$m['start_date'] . ' 00:00:00',
                (string)$m['end_date'] . ' 00:00:00',
                $m['reason'] ?: ('Blocked - Room ' . $roomNumber)
            ));
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    public function pushAvailability(\PDO $db, int $propertyId): void {
        // iCal is pull-based; OTA partners fetch the export URL.
    }

    public function pullBookings(\PDO $db, int $propertyId): array {
        return self::syncProperty($db, $propertyId);
    }

    public static function syncProperty(\PDO $db, int $propertyId): array {
        self::ensureTable($db);
        $stmt = $db->prepare("SELECT * FROM room_ical_feeds WHERE property_id = ? AND import_url IS NOT NULL AND import_url != ''");
        $stmt->execute([$propertyId]);
        $feeds = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $imported = 0;
        $errors = 0;
        foreach ($feeds as $feed) {
            try {
                $imported += self::importFeed($db, $feed);
                $db->prepare("UPDATE room_ical_feeds SET last_synced_at = NOW(), last_error = NULL WHERE id = ?")->execute([$feed['id']]);
            } catch (\Throwable $e) {
                $errors++;
                $db->prepare("UPDATE room_ical_feeds SET last_error = ? WHERE id = ?")
                    ->execute([substr($e->getMessage(), 0, 250), $feed['id']]);
            }
        }
        return ['feeds' => count($feeds), 'imported' => $imported, 'errors' => $errors];
    }

    public static function importFeed(\PDO $db, array $feed): int {
        $url = (string)($feed['import_url'] ?? '');
        if ($url === '') {
            return 0;
        }
        $ics = self::httpGet($url);
        $events = self::parseEvents($ics);
        $roomId = (int)$feed['room_id'];
        $propertyId = (int)$feed['property_id'];
        $count = 0;

        $upsert = $db->prepare("
            INSERT INTO room_maintenance (room_id, property_id, start_date, end_date, reason, external_uid)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE start_date = VALUES(start_date), end_date = VALUES(end_date), reason = VALUES(reason)
        ");

        foreach ($events as $ev) {
            $uid = substr((string)$ev['uid'], 0, 190);
            if ($uid === '' || str_starts_with($uid, 'pms-')) {
                continue;
            }
            $start = substr($ev['start'], 0, 10);
            $end = substr($ev['end'], 0, 10);
            if ($start === '' || $end === '' || $end < $start) {
                continue;
            }
            $reason = substr('iCal: ' . ($ev['summary'] ?: 'OTA block'), 0, 250);
            $upsert->execute([$roomId, $propertyId, $start, $end, $reason, $uid]);
            $count++;
        }
        return $count;
    }

    private static function vevent(string $uid, string $start, string $end, string $summary): array {
        return [
            'BEGIN:VEVENT',
            'UID:' . self::escapeText($uid),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . self::formatIcalDate($start),
            'DTEND:' . self::formatIcalDate($end),
            'SUMMARY:' . self::escapeText($summary),
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    private static function formatIcalDate(string $dt): string {
        $ts = strtotime($dt) ?: time();
        return gmdate('Ymd\THis\Z', $ts);
    }

    private static function escapeText(string $text): string {
        return str_replace(["\\", ",", ";", "\n"], ["\\\\", "\\,", "\\;", "\\n"], $text);
    }

    private static function parseEvents(string $ics): array {
        $ics = str_replace(["\r\n", "\r"], "\n", $ics);
        $ics = preg_replace("/\n[ \t]/", '', $ics) ?? $ics;
        $events = [];
        $current = null;
        foreach (explode("\n", $ics) as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') {
                $current = ['uid' => '', 'start' => '', 'end' => '', 'summary' => ''];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($current) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (str_starts_with($line, 'UID:')) {
                $current['uid'] = substr($line, 4);
            } elseif (str_starts_with($line, 'SUMMARY:')) {
                $current['summary'] = substr($line, 8);
            } elseif (str_starts_with($line, 'DTSTART')) {
                $current['start'] = self::parseIcalDate($line);
            } elseif (str_starts_with($line, 'DTEND')) {
                $current['end'] = self::parseIcalDate($line);
            }
        }
        return $events;
    }

    private static function parseIcalDate(string $line): string {
        $value = $line;
        if (str_contains($line, ':')) {
            $value = substr($line, strrpos($line, ':') + 1);
        }
        $value = trim($value);
        if (preg_match('/^(\d{8})T(\d{6})/', $value, $m)) {
            return substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2)
                . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2) . ':' . substr($m[2], 4, 2);
        }
        if (preg_match('/^(\d{8})$/', $value, $m)) {
            return substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
        }
        return $value;
    }

    private static function httpGet(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'MicroPMS-iCal/1.0',
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            throw new \RuntimeException($err !== '' ? $err : ('HTTP ' . $code));
        }
        return (string)$body;
    }

    private static function ensureTable(\PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `room_ical_feeds` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `property_id` INT(11) NOT NULL,
              `room_id` INT(11) NOT NULL,
              `export_token` CHAR(32) NOT NULL,
              `import_url` VARCHAR(500) DEFAULT NULL,
              `last_synced_at` DATETIME DEFAULT NULL,
              `last_error` VARCHAR(255) DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_ical_room` (`room_id`),
              UNIQUE KEY `uq_ical_token` (`export_token`),
              KEY `idx_ical_property` (`property_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
