<?php
declare(strict_types=1);

require_once __DIR__ . '/GuestService.php';
require_once __DIR__ . '/BookingService.php';
require_once __DIR__ . '/FolioService.php';
require_once __DIR__ . '/../PhoneHelper.php';
require_once __DIR__ . '/../AuditLogger.php';
require_once __DIR__ . '/../config.php';

class BookingImportService {
    public const MAX_STAYS = 500;
    public const COLUMNS = [
        'row_type', 'import_ref', 'guest_name', 'guest_phone', 'guest_email', 'room_number',
        'check_in', 'check_out', 'status', 'source', 'rate_plan_name', 'total_amount', 'folio_no',
        'adults', 'children', 'folio_type', 'description', 'amount', 'category', 'payment_method',
    ];

    public static function templateCsv(): string {
        $header = implode(',', self::COLUMNS);
        $rows = [
            'booking,IMP-001,Asha Kumar,9876543210,asha@example.com,101,2026-08-01 14:00,2026-08-03 11:00,checked_out,OTA,,4000.00,FLO-IMP-1,2,0,,,,,',
            'folio,IMP-001,,,,,,,,,,,,,,,charge,Breakfast buffet,350.00,F&B,',
            'folio,IMP-001,,,,,,,,,,,,,,,payment,Advance collected,2000.00,,Cash',
            'booking,IMP-002,Rahul Singh,9123456789,,102,2026-08-10 14:00,2026-08-11 11:00,booked,Walk-in,,2500.00,,2,0,,,,,',
        ];
        return $header . "\n" . implode("\n", $rows) . "\n";
    }

    public static function parseFile(string $path): array {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \Exception('Could not read CSV file.');
        }
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            throw new \Exception('CSV is empty.');
        }
        $header = array_map(static function ($h) {
            return strtolower(trim((string)$h));
        }, $header);

        $stays = [];
        $order = [];
        $lastRef = null;
        $line = 1;
        while (($cols = fgetcsv($fh)) !== false) {
            $line++;
            if (count($cols) === 1 && trim((string)$cols[0]) === '') {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim((string)($cols[$i] ?? ''));
            }
            $type = strtolower($row['row_type'] ?? 'booking');
            if ($type === '') {
                $type = 'booking';
            }
            if ($type === 'booking') {
                $ref = $row['import_ref'] !== '' ? $row['import_ref'] : ('ROW-' . $line);
                if (isset($stays[$ref])) {
                    $stays[$ref]['error'] = 'Duplicate import_ref in file.';
                } else {
                    $order[] = $ref;
                    $stays[$ref] = [
                        'import_ref' => $ref,
                        'guest_name' => $row['guest_name'] ?? '',
                        'guest_phone' => $row['guest_phone'] ?? '',
                        'guest_email' => $row['guest_email'] ?? '',
                        'room_number' => $row['room_number'] ?? '',
                        'check_in' => $row['check_in'] ?? '',
                        'check_out' => $row['check_out'] ?? '',
                        'status' => strtolower($row['status'] ?? 'booked') ?: 'booked',
                        'source' => ($row['source'] ?? '') !== '' ? $row['source'] : 'Import',
                        'rate_plan_name' => $row['rate_plan_name'] ?? '',
                        'total_amount' => $row['total_amount'] ?? '',
                        'folio_no' => $row['folio_no'] ?? '',
                        'adults' => $row['adults'] ?? '2',
                        'children' => $row['children'] ?? '0',
                        'folio' => [],
                        'error' => null,
                        'line' => $line,
                    ];
                }
                $lastRef = $ref;
            } elseif ($type === 'folio') {
                $ref = $row['import_ref'] !== '' ? $row['import_ref'] : $lastRef;
                if ($ref === null || !isset($stays[$ref])) {
                    $orphan = 'FOLIO-LINE-' . $line;
                    $order[] = $orphan;
                    $stays[$orphan] = [
                        'import_ref' => $orphan,
                        'error' => 'Folio row has no matching booking import_ref.',
                        'folio' => [],
                        'guest_name' => '',
                        'guest_phone' => '',
                        'room_number' => '',
                        'check_in' => '',
                        'check_out' => '',
                        'folio_count' => 0,
                    ];
                    continue;
                }
                $stays[$ref]['folio'][] = [
                    'folio_type' => strtolower($row['folio_type'] ?? ''),
                    'description' => $row['description'] ?? '',
                    'amount' => $row['amount'] ?? '',
                    'category' => $row['category'] ?? '',
                    'payment_method' => $row['payment_method'] ?? '',
                    'line' => $line,
                ];
            }
        }
        fclose($fh);

        $out = [];
        foreach ($order as $ref) {
            if (isset($stays[$ref])) {
                $stays[$ref]['folio_count'] = count($stays[$ref]['folio'] ?? []);
                $out[] = $stays[$ref];
            }
        }
        return $out;
    }

    public static function validateStays(\PDO $db, int $propertyId, array $stays): array {
        self::ensureImportRefColumn($db);
        $methods = get_payment_methods($db, $propertyId);
        $categories = get_payment_categories($db, $propertyId);
        $methodLc = array_map('strtolower', $methods);
        $catLc = array_map('strtolower', $categories);

        $rooms = [];
        $rStmt = $db->prepare("SELECT id, room_number FROM rooms WHERE property_id = ?");
        $rStmt->execute([$propertyId]);
        foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rooms[strtolower((string)$r['room_number'])] = (int)$r['id'];
        }

        $seenRefs = [];
        foreach ($stays as &$stay) {
            if (!empty($stay['error'])) {
                continue;
            }
            if (count($seenRefs) >= self::MAX_STAYS) {
                $stay['error'] = 'File exceeds ' . self::MAX_STAYS . ' stays.';
                continue;
            }
            $stay['check_in'] = self::normalizeDateTime($stay['check_in'] ?? '', '14:00:00');
            $stay['check_out'] = self::normalizeDateTime($stay['check_out'] ?? '', '11:00:00');
            if ($stay['check_in'] === null || $stay['check_out'] === null) {
                $stay['error'] = 'Invalid check-in or check-out date.';
                continue;
            }
            if (strtotime($stay['check_out']) <= strtotime($stay['check_in'])) {
                $stay['error'] = 'Check-out must be after check-in.';
                continue;
            }
            $phone = PhoneHelper::toLocal((string)$stay['guest_phone']);
            if ($phone === null) {
                $stay['error'] = 'Invalid guest phone.';
                continue;
            }
            $stay['guest_phone'] = $phone;
            if (trim((string)$stay['guest_name']) === '') {
                $stay['error'] = 'Guest name is required.';
                continue;
            }
            $roomKey = strtolower((string)$stay['room_number']);
            if (!isset($rooms[$roomKey])) {
                $stay['error'] = 'Room number not found.';
                continue;
            }
            $stay['room_id'] = $rooms[$roomKey];
            $status = $stay['status'] ?? 'booked';
            if (!in_array($status, ['booked', 'checked_in', 'checked_out'], true)) {
                $stay['error'] = 'Status must be booked, checked_in, or checked_out.';
                continue;
            }
            $ref = (string)$stay['import_ref'];
            if ($ref !== '' && self::importRefExists($db, $propertyId, $ref)) {
                $stay['error'] = 'import_ref already imported.';
                continue;
            }
            if (!BookingService::isRoomAvailable($db, (int)$stay['room_id'], $stay['check_in'], $stay['check_out'], null, $propertyId)) {
                $stay['error'] = 'Room is not available for these dates.';
                continue;
            }
            foreach ($stay['folio'] as $fi => $line) {
                $ftype = $line['folio_type'];
                $amt = (float)$line['amount'];
                if (!in_array($ftype, ['charge', 'payment'], true) || $amt <= 0) {
                    $stay['error'] = 'Folio row ' . ($line['line'] ?? '') . ' needs folio_type charge/payment and amount.';
                    break;
                }
                if ($ftype === 'payment') {
                    $pm = $line['payment_method'];
                    if ($pm === '' || !in_array(strtolower($pm), $methodLc, true)) {
                        $stay['error'] = 'Folio payment method is not in Settings payment methods.';
                        break;
                    }
                }
                if ($ftype === 'charge' && $line['category'] !== '' && !in_array(strtolower($line['category']), $catLc, true)) {
                    $stay['error'] = 'Folio category is not in Settings payment categories.';
                    break;
                }
                $stay['folio'][$fi]['amount'] = $amt;
            }
            $seenRefs[] = $ref;
        }
        unset($stay);
        return $stays;
    }

    public static function commit(\PDO $db, int $propertyId, array $stays): array {
        $created = 0;
        $skipped = 0;
        $errors = [];
        $categories = get_payment_categories($db, $propertyId);
        $defaultCat = $categories[0] ?? 'Other';

        foreach ($stays as $stay) {
            if (!empty($stay['error'])) {
                $skipped++;
                $errors[] = ($stay['import_ref'] ?? '') . ': ' . $stay['error'];
                continue;
            }
            try {
                $guest = GuestService::findOrCreate($db, (string)$stay['guest_name'], (string)$stay['guest_phone']);
                $guestId = (int)$guest['guest_id'];
                $email = trim((string)($stay['guest_email'] ?? ''));
                if ($email !== '') {
                    GuestService::update($db, $guestId, ['email' => $email]);
                }

                $hasFolio = !empty($stay['folio']);
                $total = $stay['total_amount'] !== '' ? (float)$stay['total_amount'] : null;
                $createdStay = BookingService::createBooking($db, [
                    'room_id' => (int)$stay['room_id'],
                    'guest_id' => $guestId,
                    'check_in' => $stay['check_in'],
                    'check_out' => $stay['check_out'],
                    'booking_status' => $stay['status'],
                    'booking_source' => $stay['source'],
                    'rate_plan_name' => $stay['rate_plan_name'] !== '' ? $stay['rate_plan_name'] : null,
                    'price_override' => $total,
                    'adults' => max(1, (int)$stay['adults']),
                    'children' => max(0, (int)$stay['children']),
                    'offline_folio_id' => $stay['folio_no'] !== '' ? $stay['folio_no'] : null,
                    'skip_room_charges' => $hasFolio,
                    'skip_google_sheets' => true,
                ]);
                $bookingId = (int)$createdStay['booking_id'];
                self::storeImportRef($db, $bookingId, $propertyId, (string)$stay['import_ref']);

                $i = 0;
                foreach ($stay['folio'] as $line) {
                    $i++;
                    $ref = 'IMP-' . $bookingId . '-' . $i;
                    if ($line['folio_type'] === 'charge') {
                        $cat = $line['category'] !== '' ? $line['category'] : $defaultCat;
                        FolioService::postCharge($db, $bookingId, (float)$line['amount'], (string)$line['description'], $cat);
                    } else {
                        FolioService::recordPayment(
                            $db,
                            $bookingId,
                            (float)$line['amount'],
                            (string)$line['payment_method'],
                            $ref,
                            'import',
                            $line['category'] !== '' ? $line['category'] : 'booking'
                        );
                    }
                }
                $created++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = ($stay['import_ref'] ?? '') . ': ' . $e->getMessage();
            }
        }

        AuditLogger::log($_SESSION['user_id'] ?? null, 'BOOKING_IMPORT', 'BOOKING', null, [
            'created' => $created,
            'skipped' => $skipped,
        ]);

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    private static function normalizeDateTime(string $raw, string $defaultTime): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $raw .= ' ' . $defaultTime;
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    public static function ensureImportRefColumn(\PDO $db): void {
        try {
            $col = $db->query("SHOW COLUMNS FROM bookings LIKE 'import_ref'")->fetch();
            if (!$col) {
                $db->exec("ALTER TABLE bookings ADD COLUMN import_ref VARCHAR(80) NULL DEFAULT NULL");
            }
        } catch (\Throwable $e) {
            // Column may already exist or lack ALTER privilege.
        }
    }

    private static function importRefExists(\PDO $db, int $propertyId, string $ref): bool {
        try {
            $stmt = $db->prepare("SELECT id FROM bookings WHERE property_id = ? AND import_ref = ? LIMIT 1");
            $stmt->execute([$propertyId, $ref]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function storeImportRef(\PDO $db, int $bookingId, int $propertyId, string $ref): void {
        if ($ref === '') {
            return;
        }
        try {
            $stmt = $db->prepare("UPDATE bookings SET import_ref = ? WHERE id = ? AND property_id = ?");
            $stmt->execute([$ref, $bookingId, $propertyId]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
