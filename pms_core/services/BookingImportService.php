<?php
declare(strict_types=1);

require_once __DIR__ . '/GuestService.php';
require_once __DIR__ . '/BookingService.php';
require_once __DIR__ . '/FolioService.php';
require_once __DIR__ . '/../PhoneHelper.php';
require_once __DIR__ . '/../AuditLogger.php';
require_once __DIR__ . '/../SequenceGenerator.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../GoogleSheetService.php';

class BookingImportService {
    public const MAX_STAYS = 500;
    public const MAX_PAYMENTS = 2000;
    public const MAX_EXPENSES = 500;
    public const COLUMNS = [
        'row_type', 'import_ref', 'guest_name', 'guest_phone', 'guest_email', 'room_number',
        'check_in', 'check_out', 'status', 'source', 'rate_plan_name', 'total_amount', 'folio_no',
        'adults', 'children', 'folio_type', 'description', 'amount', 'category', 'payment_method',
    ];

    /** Same headers the live Google Sheet tabs use (including Check-In TIme). */
    public static function sheetHeaders(string $type): array {
        $catalog = GoogleSheetService::fieldCatalog();
        return $catalog[$type] ?? [];
    }

    public static function templateCsv(string $sheet = 'booking'): string {
        if ($sheet === 'payment') {
            return self::csvFromRows(self::sheetHeaders('payment'), [
                ['', 'IMP-001', 'FLO-IMP-1', '101', 'Deluxe', 'Asha Kumar', '2000.00', 'Cash', 'Aug-2026', '2026-08-01 14:10:00', 'Advance collected', 'Import'],
            ]);
        }
        if ($sheet === 'expense') {
            return self::csvFromRows(self::sheetHeaders('expense'), [
                ['', 'Housekeeping', '850.00', 'Laundry supplies', 'Cash', 'Aug-2026', '2026-08-02 10:00:00', 'Import'],
            ]);
        }
        return self::csvFromRows(self::sheetHeaders('booking'), [
            ['IMP-001', 'FLO-IMP-1', '101', 'Deluxe', 'Asha Kumar', '9876543210', '2000.00', 'Aug-2026', '2026-08-01', '14:00:00', '2026-08-03', '11:00:00', '2', '45', '2000.00', 'Checked out', 'Import'],
            ['IMP-002', '', '102', 'Standard', 'Rahul Singh', '9123456789', '2500.00', 'Aug-2026', '2026-08-10', '14:00:00', '2026-08-11', '11:00:00', '1', '21', '', 'Booked', 'Walk-in'],
        ]);
    }

    public static function templateZip(): string {
        if (!class_exists('ZipArchive')) {
            return self::templateCsv('booking');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'gsimp');
        if ($tmp === false) {
            return self::templateCsv('booking');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return self::templateCsv('booking');
        }
        $zip->addFromString('Bookings.csv', self::templateCsv('booking'));
        $zip->addFromString('Payments.csv', self::templateCsv('payment'));
        $zip->addFromString('Expenses.csv', self::templateCsv('expense'));
        $zip->close();
        $bin = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    public static function parseFile(string $path): array {
        $parsed = self::parseUpload($path, $path);
        return $parsed['stays'];
    }

    /**
     * @return array{stays: list<array>, payments: list<array>, expenses: list<array>, format: string}
     */
    public static function parseUpload(string $path, string $originalName = ''): array {
        $name = strtolower($originalName);
        if (str_ends_with($name, '.zip') || self::isZipFile($path)) {
            return self::parseZip($path);
        }
        $kind = self::detectCsvKind($path);
        if ($kind === 'legacy') {
            return [
                'stays' => self::parseLegacyFile($path),
                'payments' => [],
                'expenses' => [],
                'format' => 'legacy',
            ];
        }
        if ($kind === 'booking') {
            return ['stays' => self::parseSheetBookings($path), 'payments' => [], 'expenses' => [], 'format' => 'google_sheet'];
        }
        if ($kind === 'payment') {
            return ['stays' => [], 'payments' => self::parseSheetPayments($path), 'expenses' => [], 'format' => 'google_sheet'];
        }
        if ($kind === 'expense') {
            return ['stays' => [], 'payments' => [], 'expenses' => self::parseSheetExpenses($path), 'format' => 'google_sheet'];
        }
        throw new \Exception('CSV headers must match the Google Sheet Bookings, Payments, or Expenses tab.');
    }

    public static function validateBundle(\PDO $db, int $propertyId, array $bundle, bool $canManageFinance = true): array {
        $stays = self::validateStays($db, $propertyId, $bundle['stays'] ?? []);
        $payments = self::validatePayments($db, $propertyId, $bundle['payments'] ?? [], $stays);
        $expenses = self::validateExpenses($db, $propertyId, $bundle['expenses'] ?? [], $canManageFinance);
        return [
            'stays' => $stays,
            'payments' => $payments,
            'expenses' => $expenses,
            'format' => $bundle['format'] ?? 'google_sheet',
        ];
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
            if (!in_array($status, ['booked', 'checked_in', 'checked_out', 'cancelled'], true)) {
                $stay['error'] = 'Status must match Check-in/Check-Out (Booked, Checked in, Checked out).';
                continue;
            }
            $ref = (string)$stay['import_ref'];
            $existingId = self::findBookingId($db, $propertyId, $ref);
            if ($existingId !== null) {
                $stay['existing_booking_id'] = $existingId;
                $stay['action'] = 'exists';
                $seenRefs[] = $ref;
                continue;
            }
            $mustCheckAvail = in_array($status, ['booked', 'checked_in'], true);
            if ($mustCheckAvail && !BookingService::isRoomAvailable($db, (int)$stay['room_id'], $stay['check_in'], $stay['check_out'], null, $propertyId)) {
                $stay['error'] = 'Room is not available for these dates.';
                continue;
            }
            foreach ($stay['folio'] ?? [] as $fi => $line) {
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
            $stay['action'] = 'create';
            $seenRefs[] = $ref;
        }
        unset($stay);
        return $stays;
    }

    public static function commit(\PDO $db, int $propertyId, array $stays): array {
        $bundle = self::commitBundle($db, $propertyId, [
            'stays' => $stays,
            'payments' => [],
            'expenses' => [],
        ]);
        return [
            'created' => $bundle['created_stays'],
            'skipped' => $bundle['skipped_stays'],
            'errors' => $bundle['errors'],
        ];
    }

    /**
     * @return array{created_stays:int,skipped_stays:int,created_payments:int,skipped_payments:int,created_expenses:int,skipped_expenses:int,errors:list<string>}
     */
    public static function commitBundle(\PDO $db, int $propertyId, array $bundle): array {
        $createdStays = 0;
        $skippedStays = 0;
        $createdPayments = 0;
        $skippedPayments = 0;
        $createdExpenses = 0;
        $skippedExpenses = 0;
        $errors = [];
        $categories = get_payment_categories($db, $propertyId);
        $defaultCat = $categories[0] ?? 'Other';
        $idMap = [];

        foreach ($bundle['stays'] ?? [] as $stay) {
            if (!empty($stay['error'])) {
                $skippedStays++;
                $errors[] = ($stay['import_ref'] ?? '') . ': ' . $stay['error'];
                continue;
            }
            $ref = (string)($stay['import_ref'] ?? '');
            if (!empty($stay['existing_booking_id'])) {
                $skippedStays++;
                if ($ref !== '') {
                    $idMap[self::normId($ref)] = (int)$stay['existing_booking_id'];
                }
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
                $hasSheetPayments = !empty($stay['sheet_payments']);
                $rate = isset($stay['rate_per_night']) && $stay['rate_per_night'] !== '' ? (float)$stay['rate_per_night'] : 0.0;
                $days = BookingService::calculateDays((string)$stay['check_in'], (string)$stay['check_out']);
                $stayTotal = $stay['total_amount'] !== '' && $stay['total_amount'] !== null
                    ? (float)$stay['total_amount']
                    : ($rate > 0 ? round($rate * max(1, $days), 2) : null);
                if ($rate > 0 && ($stay['total_amount'] === '' || $stay['total_amount'] === null)) {
                    $stayTotal = round($rate * max(1, $days), 2);
                }

                $createdStay = BookingService::createBooking($db, [
                    'room_id' => (int)$stay['room_id'],
                    'guest_id' => $guestId,
                    'guest_name' => $stay['guest_name'],
                    'check_in' => $stay['check_in'],
                    'check_out' => $stay['check_out'],
                    'booking_status' => $stay['status'] === 'cancelled' ? 'cancelled' : $stay['status'],
                    'booking_source' => ($stay['source'] ?? '') !== '' ? $stay['source'] : 'Import',
                    'rate_plan_name' => ($stay['rate_plan_name'] ?? '') !== '' ? $stay['rate_plan_name'] : null,
                    'price_override' => $stayTotal,
                    'adults' => max(1, (int)($stay['adults'] ?? 2)),
                    'children' => max(0, (int)($stay['children'] ?? 0)),
                    'offline_folio_id' => ($stay['folio_no'] ?? '') !== '' ? $stay['folio_no'] : null,
                    'skip_room_charges' => $hasFolio,
                    'skip_google_sheets' => true,
                    'skip_notifications' => true,
                    'skip_night_audit' => true,
                ]);
                $bookingId = (int)$createdStay['booking_id'];
                if ($ref !== '' && $ref !== ('ROW-' . ($stay['line'] ?? ''))) {
                    self::storeImportRef($db, $bookingId, $propertyId, $ref);
                    if (!preg_match('/^ROW-\d+$/', $ref)) {
                        self::setDisplayId($db, 'bookings', $bookingId, $propertyId, $ref);
                    }
                } else {
                    self::storeImportRef($db, $bookingId, $propertyId, $ref);
                }
                if ($ref !== '') {
                    $idMap[self::normId($ref)] = $bookingId;
                }
                $disp = $createdStay['display_id'] ?? '';
                if ($disp !== '') {
                    $idMap[self::normId((string)$disp)] = $bookingId;
                }

                $i = 0;
                foreach ($stay['folio'] ?? [] as $line) {
                    $i++;
                    $payRef = 'IMP-' . $bookingId . '-' . $i;
                    if ($line['folio_type'] === 'charge') {
                        $cat = $line['category'] !== '' ? $line['category'] : $defaultCat;
                        FolioService::postCharge($db, $bookingId, (float)$line['amount'], (string)$line['description'], $cat);
                    } else {
                        FolioService::recordPayment(
                            $db,
                            $bookingId,
                            (float)$line['amount'],
                            (string)$line['payment_method'],
                            $payRef,
                            'import',
                            $line['category'] !== '' ? $line['category'] : 'booking',
                            null,
                            false,
                            true,
                            true
                        );
                    }
                }

                if (!$hasFolio && !$hasSheetPayments) {
                    $collected = (float)($stay['total_collected'] ?? 0);
                    if ($collected > 0) {
                        FolioService::recordPayment(
                            $db,
                            $bookingId,
                            $collected,
                            'Cash',
                            'IMP-COLLECTED-' . $bookingId,
                            'import',
                            'booking',
                            null,
                            false,
                            true,
                            true
                        );
                    }
                }
                $createdStays++;
            } catch (\Throwable $e) {
                $skippedStays++;
                $errors[] = ($stay['import_ref'] ?? '') . ': ' . $e->getMessage();
            }
        }

        foreach ($bundle['payments'] ?? [] as $pay) {
            if (!empty($pay['error'])) {
                $skippedPayments++;
                $errors[] = ($pay['payment_id'] ?: $pay['booking_id'] ?? '') . ': ' . $pay['error'];
                continue;
            }
            try {
                $bookingId = $pay['resolved_booking_id'] ?? null;
                if ($bookingId === null) {
                    $bookingId = $idMap[self::normId((string)$pay['booking_id'])] ?? self::findBookingId($db, $propertyId, (string)$pay['booking_id']);
                }
                if ($bookingId === null) {
                    throw new \Exception('Booking ID not found.');
                }
                $entryId = FolioService::recordPayment(
                    $db,
                    (int)$bookingId,
                    (float)$pay['amount'],
                    (string)$pay['payment_method'],
                    $pay['payment_id'] !== '' ? (string)$pay['payment_id'] : ('IMP-PAY-' . $bookingId . '-' . ($pay['line'] ?? '0')),
                    'import',
                    ($pay['category'] ?? '') !== '' ? (string)$pay['category'] : 'booking',
                    $pay['recorded_at'] ?? null,
                    false,
                    true,
                    true
                );
                if (($pay['payment_id'] ?? '') !== '') {
                    self::setDisplayId($db, 'folio_ledger', $entryId, $propertyId, (string)$pay['payment_id']);
                }
                $createdPayments++;
            } catch (\Throwable $e) {
                $skippedPayments++;
                $errors[] = ($pay['payment_id'] ?: $pay['booking_id'] ?? '') . ': ' . $e->getMessage();
            }
        }

        foreach ($bundle['expenses'] ?? [] as $exp) {
            if (!empty($exp['error'])) {
                $skippedExpenses++;
                $errors[] = ($exp['expense_id'] ?? '') . ': ' . $exp['error'];
                continue;
            }
            try {
                $stmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, amount, description, payment_method, staff_id, recorded_at) VALUES (:pid, 'expense', :cat, :amt, :desc, :pm, :staff_id, :rec)");
                $stmt->execute([
                    'pid' => $propertyId,
                    'cat' => $exp['category'],
                    'amt' => $exp['amount'],
                    'desc' => $exp['description'],
                    'pm' => $exp['payment_method'],
                    'staff_id' => $_SESSION['user_id'] ?? null,
                    'rec' => $exp['recorded_at'],
                ]);
                $id = (int)$db->lastInsertId();
                if (($exp['expense_id'] ?? '') !== '') {
                    self::setDisplayId($db, 'finance_transactions', $id, $propertyId, (string)$exp['expense_id']);
                } else {
                    SequenceGenerator::assignDisplayId($db, 'finance_transactions', $id, 'SEQ_TRANSACTION_FORMAT');
                }
                $createdExpenses++;
            } catch (\Throwable $e) {
                $skippedExpenses++;
                $errors[] = ($exp['expense_id'] ?? '') . ': ' . $e->getMessage();
            }
        }

        AuditLogger::log($_SESSION['user_id'] ?? null, 'BOOKING_IMPORT', 'BOOKING', null, [
            'created_stays' => $createdStays,
            'created_payments' => $createdPayments,
            'created_expenses' => $createdExpenses,
        ]);

        return [
            'created_stays' => $createdStays,
            'skipped_stays' => $skippedStays,
            'created_payments' => $createdPayments,
            'skipped_payments' => $skippedPayments,
            'created_expenses' => $createdExpenses,
            'skipped_expenses' => $skippedExpenses,
            'errors' => $errors,
        ];
    }

    private static function validatePayments(\PDO $db, int $propertyId, array $payments, array $stays): array {
        $methods = get_payment_methods($db, $propertyId);
        $methodLc = array_map('strtolower', $methods);
        $stayRefs = [];
        foreach ($stays as $stay) {
            if (empty($stay['error'])) {
                $stayRefs[self::normId((string)$stay['import_ref'])] = true;
                if (!empty($stay['existing_booking_id'])) {
                    $stayRefs['id:' . (int)$stay['existing_booking_id']] = true;
                }
            }
        }

        $out = [];
        foreach ($payments as $i => $pay) {
            if (count($out) >= self::MAX_PAYMENTS) {
                $pay['error'] = 'File exceeds ' . self::MAX_PAYMENTS . ' payments.';
                $out[] = $pay;
                continue;
            }
            if (($pay['payment_id'] ?? '') !== '') {
                $exist = self::findLedgerId($db, $propertyId, (string)$pay['payment_id']);
                if ($exist !== null) {
                    $pay['error'] = 'Payment ID already imported.';
                    $out[] = $pay;
                    continue;
                }
            }
            $amt = (float)($pay['amount'] ?? 0);
            if ($amt <= 0) {
                $pay['error'] = 'Amount Paid must be greater than 0.';
                $out[] = $pay;
                continue;
            }
            $pm = (string)($pay['payment_method'] ?? '');
            if ($pm === '' || !in_array(strtolower($pm), $methodLc, true)) {
                $pay['error'] = 'Payment Type is not in Settings payment methods.';
                $out[] = $pay;
                continue;
            }
            $bid = trim((string)($pay['booking_id'] ?? ''));
            if ($bid === '') {
                $pay['error'] = 'Booking ID is required.';
                $out[] = $pay;
                continue;
            }
            $resolved = self::findBookingId($db, $propertyId, $bid);
            if ($resolved === null && empty($stayRefs[self::normId($bid)])) {
                $pay['error'] = 'Booking ID not found in file or database.';
                $out[] = $pay;
                continue;
            }
            $pay['resolved_booking_id'] = $resolved;
            $pay['amount'] = $amt;
            $recorded = self::normalizeDateTime((string)($pay['payment_date'] ?? ''), date('H:i:s'));
            $pay['recorded_at'] = $recorded;
            $out[] = $pay;
        }
        return $out;
    }

    private static function validateExpenses(\PDO $db, int $propertyId, array $expenses, bool $canManageFinance): array {
        $out = [];
        foreach ($expenses as $exp) {
            if (!$canManageFinance) {
                $exp['error'] = 'Finance permission is required to import expenses.';
                $out[] = $exp;
                continue;
            }
            if (count($out) >= self::MAX_EXPENSES) {
                $exp['error'] = 'File exceeds ' . self::MAX_EXPENSES . ' expenses.';
                $out[] = $exp;
                continue;
            }
            if (($exp['expense_id'] ?? '') !== '') {
                $exist = self::findExpenseId($db, $propertyId, (string)$exp['expense_id']);
                if ($exist !== null) {
                    $exp['error'] = 'Expense ID already imported.';
                    $out[] = $exp;
                    continue;
                }
            }
            $amt = (float)($exp['amount'] ?? 0);
            if ($amt <= 0) {
                $exp['error'] = 'Amount must be greater than 0.';
                $out[] = $exp;
                continue;
            }
            if (trim((string)($exp['category'] ?? '')) === '') {
                $exp['error'] = 'Category is required.';
                $out[] = $exp;
                continue;
            }
            if (trim((string)($exp['description'] ?? '')) === '') {
                $exp['error'] = 'Description is required.';
                $out[] = $exp;
                continue;
            }
            $rec = self::normalizeDateTime((string)($exp['expense_date'] ?? ''), date('H:i:s'));
            if ($rec === null) {
                $rec = date('Y-m-d H:i:s');
            }
            $exp['amount'] = $amt;
            $exp['payment_method'] = ($exp['payment_method'] ?? '') !== '' ? $exp['payment_method'] : 'Cash';
            $exp['recorded_at'] = $rec;
            $out[] = $exp;
        }
        return $out;
    }

    private static function parseZip(string $path): array {
        if (!class_exists('ZipArchive')) {
            throw new \Exception('Zip support is not enabled on this server. Upload each sheet as a CSV.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \Exception('Could not read ZIP file.');
        }
        $stays = [];
        $payments = [];
        $expenses = [];
        $tmpDir = sys_get_temp_dir() . '/gsimp_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700, true);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getName($i);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }
                if (!preg_match('/\.csv$/i', $name)) {
                    continue;
                }
                $base = strtolower(basename($name));
                $dest = $tmpDir . '/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
                $stream = $zip->getFromIndex($i);
                if ($stream === false) {
                    continue;
                }
                file_put_contents($dest, $stream);
                $kind = self::detectCsvKind($dest);
                if ($kind === 'unknown' && str_contains($base, 'booking')) {
                    $kind = 'booking';
                }
                if ($kind === 'unknown' && str_contains($base, 'payment')) {
                    $kind = 'payment';
                }
                if ($kind === 'unknown' && str_contains($base, 'expense')) {
                    $kind = 'expense';
                }
                if ($kind === 'booking' || $kind === 'legacy') {
                    $stays = array_merge($stays, $kind === 'legacy' ? self::parseLegacyFile($dest) : self::parseSheetBookings($dest));
                } elseif ($kind === 'payment') {
                    $payments = array_merge($payments, self::parseSheetPayments($dest));
                } elseif ($kind === 'expense') {
                    $expenses = array_merge($expenses, self::parseSheetExpenses($dest));
                }
            }
        } finally {
            $zip->close();
            self::wipeDir($tmpDir);
        }
        if ($stays === [] && $payments === [] && $expenses === []) {
            throw new \Exception('ZIP must contain Bookings.csv, Payments.csv, and/or Expenses.csv.');
        }
        self::flagStaysWithSheetPayments($stays, $payments);
        return ['stays' => $stays, 'payments' => $payments, 'expenses' => $expenses, 'format' => 'google_sheet'];
    }

    private static function parseSheetBookings(string $path): array {
        $rows = self::readAssocRows($path);
        $stays = [];
        $line = 1;
        foreach ($rows as $row) {
            $line++;
            $bookingId = self::cell($row, ['booking id', 'booking_id']);
            $name = self::blankDash(self::cell($row, ['full name', 'guest_name']));
            $phone = self::blankDash(self::cell($row, ['phone no', 'guest_phone', 'phone']));
            $room = self::blankDash(self::cell($row, ['room no', 'room_number']));
            if ($name === '' && $phone === '' && $room === '' && $bookingId === '') {
                continue;
            }
            $inDate = self::cell($row, ['check-in date', 'check_in_date', 'check in date']);
            $inTime = self::cell($row, ['check-in time', 'check_in_time', 'check in time']);
            $outDate = self::cell($row, ['check-out-date', 'check-out date', 'check_out_date', 'check out date']);
            $outTime = self::cell($row, ['check-out time', 'check_out_time', 'check out time']);
            $ref = $bookingId !== '' ? $bookingId : ('ROW-' . $line);
            $stays[] = [
                'import_ref' => $ref,
                'guest_name' => $name,
                'guest_phone' => $phone,
                'guest_email' => '',
                'room_number' => $room,
                'check_in' => self::joinDateAndTime($inDate, $inTime),
                'check_out' => self::joinDateAndTime($outDate, $outTime),
                'status' => self::mapSheetStatus(self::cell($row, ['check-in/check-out', 'status'])),
                'source' => self::blankDash(self::cell($row, ['user'])) ?: 'Import',
                'rate_plan_name' => '',
                'rate_per_night' => self::cell($row, ['rate per night', 'rate_per_night']),
                'total_amount' => '',
                'total_collected' => self::cell($row, ['total amount collected', 'total_amount_collected']),
                'folio_no' => self::blankDash(self::cell($row, ['folio no', 'folio_no'])),
                'adults' => '2',
                'children' => '0',
                'folio' => [],
                'sheet_payments' => false,
                'error' => null,
                'line' => $line,
                'folio_count' => 0,
            ];
        }
        return $stays;
    }

    private static function parseSheetPayments(string $path): array {
        $rows = self::readAssocRows($path);
        $out = [];
        $line = 1;
        foreach ($rows as $row) {
            $line++;
            $bookingId = self::cell($row, ['booking id', 'booking_id']);
            $amount = self::cell($row, ['amount paid', 'amount']);
            $payId = self::cell($row, ['payment id', 'payment_id']);
            if ($bookingId === '' && $amount === '' && $payId === '') {
                continue;
            }
            $out[] = [
                'payment_id' => $payId,
                'booking_id' => $bookingId,
                'amount' => $amount,
                'payment_method' => self::cell($row, ['payment type', 'payment_type', 'payment method']),
                'category' => self::cell($row, ['category']),
                'payment_date' => self::cell($row, ['payment date', 'payment_date']),
                'error' => null,
                'line' => $line,
            ];
        }
        return $out;
    }

    private static function parseSheetExpenses(string $path): array {
        $rows = self::readAssocRows($path);
        $out = [];
        $line = 1;
        foreach ($rows as $row) {
            $line++;
            $expId = self::cell($row, ['expense id', 'expense_id']);
            $amount = self::cell($row, ['amount']);
            $desc = self::cell($row, ['description']);
            if ($expId === '' && $amount === '' && $desc === '') {
                continue;
            }
            $out[] = [
                'expense_id' => $expId,
                'category' => self::cell($row, ['category']),
                'amount' => $amount,
                'description' => $desc !== '' ? $desc : 'Imported expense',
                'payment_method' => self::cell($row, ['payment method', 'payment_method']) ?: 'Cash',
                'expense_date' => self::cell($row, ['expense date', 'expense_date']),
                'error' => null,
                'line' => $line,
            ];
        }
        return $out;
    }

    private static function parseLegacyFile(string $path): array {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \Exception('Could not read CSV file.');
        }
        $header = self::readCsvLine($fh);
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
        while (($cols = self::readCsvLine($fh)) !== false) {
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

    public static function detectCsvKind(string $path): string {
        $headers = self::readHeaderKeys($path);
        if ($headers === []) {
            return 'unknown';
        }
        if (in_array('row_type', $headers, true) || in_array('import_ref', $headers, true)) {
            return 'legacy';
        }
        if (in_array('payment id', $headers, true) || in_array('amount paid', $headers, true)) {
            return 'payment';
        }
        if (in_array('expense id', $headers, true) || (in_array('expense date', $headers, true) && in_array('amount', $headers, true))) {
            return 'expense';
        }
        if (in_array('check-in date', $headers, true) || in_array('full name', $headers, true) || in_array('booking id', $headers, true)) {
            return 'booking';
        }
        return 'unknown';
    }

    public static function mapSheetStatus(string $raw): string {
        $s = strtolower(trim($raw));
        $s = str_replace(['-', '/', '_'], ' ', $s);
        $s = (string)preg_replace('/\s+/', ' ', $s);
        if ($s === '') {
            return 'booked';
        }
        if (str_contains($s, 'cancel')) {
            return 'cancelled';
        }
        if (str_contains($s, 'checked out') || $s === 'checkout' || $s === 'check out') {
            return 'checked_out';
        }
        if (str_contains($s, 'checked in') || $s === 'checkin' || $s === 'check in') {
            return 'checked_in';
        }
        if ($s === 'booked') {
            return 'booked';
        }
        return $s;
    }

    private static function normalizeDateTime(string $raw, string $defaultTime): ?string {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') {
            return null;
        }
        if (preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}(?:\s+.+)?$/', $raw)) {
            $parts = preg_split('/\s+/', $raw, 2);
            $date = $parts[0];
            $time = $parts[1] ?? $defaultTime;
            if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $date, $m)) {
                $raw = sprintf('%04d-%02d-%02d %s', (int)$m[3], (int)$m[2], (int)$m[1], $time);
            }
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
        return self::findBookingId($db, $propertyId, $ref) !== null;
    }

    private static function findBookingId(\PDO $db, int $propertyId, string $ref): ?int {
        $ref = trim($ref);
        if ($ref === '' || $ref === '-') {
            return null;
        }
        try {
            $sql = "SELECT id FROM bookings WHERE property_id = ? AND (display_id = ? OR import_ref = ?";
            $params = [$propertyId, $ref, $ref];
            if (ctype_digit($ref)) {
                $sql .= " OR id = ?";
                $params[] = (int)$ref;
            }
            $sql .= ") LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        } catch (\Throwable $e) {
            try {
                $stmt = $db->prepare("SELECT id FROM bookings WHERE property_id = ? AND display_id = ? LIMIT 1");
                $stmt->execute([$propertyId, $ref]);
                $id = $stmt->fetchColumn();
                return $id !== false ? (int)$id : null;
            } catch (\Throwable $e2) {
                return null;
            }
        }
    }

    private static function findLedgerId(\PDO $db, int $propertyId, string $ref): ?int {
        $stmt = $db->prepare("SELECT id FROM folio_ledger WHERE property_id = ? AND display_id = ? LIMIT 1");
        $stmt->execute([$propertyId, $ref]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private static function findExpenseId(\PDO $db, int $propertyId, string $ref): ?int {
        $stmt = $db->prepare("SELECT id FROM finance_transactions WHERE property_id = ? AND type = 'expense' AND (display_id = ? OR CONCAT('EXP-', id) = ?) LIMIT 1");
        $stmt->execute([$propertyId, $ref, $ref]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
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

    private static function setDisplayId(\PDO $db, string $table, int $id, int $propertyId, string $displayId): void {
        $allowed = ['bookings' => true, 'folio_ledger' => true, 'finance_transactions' => true];
        if (!isset($allowed[$table]) || $displayId === '') {
            return;
        }
        try {
            $stmt = $db->prepare("UPDATE {$table} SET display_id = ? WHERE id = ? AND property_id = ?");
            $stmt->execute([$displayId, $id, $propertyId]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private static function csvFromRows(array $headers, array $rows): string {
        $fh = fopen('php://temp', 'r+');
        self::writeCsvLine($fh, $headers);
        foreach ($rows as $row) {
            self::writeCsvLine($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv === false ? '' : $csv;
    }

    private static function readHeaderKeys(string $path): array {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }
        $header = self::readCsvLine($fh);
        fclose($fh);
        if (!$header) {
            return [];
        }
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        }
        return array_map([self::class, 'normHeader'], $header);
    }

    private static function readAssocRows(string $path): array {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \Exception('Could not read CSV file.');
        }
        $header = self::readCsvLine($fh);
        if (!$header) {
            fclose($fh);
            throw new \Exception('CSV is empty.');
        }
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        }
        $keys = array_map([self::class, 'normHeader'], $header);
        $rows = [];
        while (($cols = self::readCsvLine($fh)) !== false) {
            if (count($cols) === 1 && trim((string)$cols[0]) === '') {
                continue;
            }
            $row = [];
            foreach ($keys as $i => $key) {
                $row[$key] = trim((string)($cols[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    private static function normHeader(string $h): string {
        $h = strtolower(trim($h));
        $h = (string)preg_replace('/\s+/', ' ', $h);
        return $h;
    }

    private static function cell(array $row, array $keys): string {
        foreach ($keys as $key) {
            $k = self::normHeader($key);
            if (isset($row[$k]) && $row[$k] !== '') {
                return trim((string)$row[$k]);
            }
        }
        return '';
    }

    private static function joinDateAndTime(string $date, string $time): string {
        $date = trim($date);
        $time = trim($time);
        if ($date === '') {
            return $time;
        }
        if ($time === '' || preg_match('/\d{1,2}:\d{2}/', $date)) {
            return $date;
        }
        return $date . ' ' . $time;
    }

    private static function blankDash(string $v): string {
        $v = trim($v);
        return ($v === '-' || strcasecmp($v, 'n/a') === 0) ? '' : $v;
    }

    private static function normId(string $id): string {
        return strtolower(trim($id));
    }

    private static function flagStaysWithSheetPayments(array &$stays, array $payments): void {
        $payRefs = [];
        foreach ($payments as $p) {
            $payRefs[self::normId((string)($p['booking_id'] ?? ''))] = true;
        }
        foreach ($stays as &$stay) {
            $stay['sheet_payments'] = isset($payRefs[self::normId((string)$stay['import_ref'])]);
            $stay['folio_count'] = ($stay['sheet_payments'] ? 1 : 0) + count($stay['folio'] ?? []);
        }
        unset($stay);
    }

    private static function isZipFile(string $path): bool {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $sig = fread($fh, 4);
        fclose($fh);
        return $sig === "PK\x03\x04" || $sig === "PK\x05\x06" || $sig === "PK\x07\x08";
    }

    private static function readCsvLine($fh): array|false {
        return fgetcsv($fh, null, ',', '"', '\\');
    }

    private static function writeCsvLine($fh, array $row): void {
        fputcsv($fh, $row, ',', '"', '\\');
    }

    private static function wipeDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
