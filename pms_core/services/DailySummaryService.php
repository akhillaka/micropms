<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../NotificationRelay.php';

class DailySummaryService
{
    /**
     * @return array{ok: bool, telegram: bool, pdf: bool, occupancy_pct: int, message: string}
     */
    public static function send(\PDO $db, int $propertyId, ?string $day = null, bool $force = false): array
    {
        $day = $day ?: date('Y-m-d');
        $data = self::build($db, $propertyId, $day);
        $text = self::telegramText($data);

        $allow = $force || NotificationRelay::isEnabled('daily_summary');
        $telegramOk = false;
        $pdfOk = false;
        $pdfPath = '';
        if ($allow) {
            $telegramOk = NotificationRelay::sendTelegram($text, null, [], $propertyId);
            try {
                $pdfPath = self::writePdf($data);
                $caption = 'Daily report · ' . $data['date_label'] . ' · Occ ' . $data['occupancy_pct'] . '% · Collected Rs ' . $data['today_total_plain'];
                $pdfOk = NotificationRelay::sendTelegramDocument($pdfPath, $caption, $propertyId);
            } catch (\Throwable $e) {
                error_log('Daily summary PDF failed: ' . $e->getMessage());
            }
        }
        if ($pdfPath !== '' && is_file($pdfPath)) {
            @unlink($pdfPath);
        }

        return [
            'ok' => $telegramOk || $pdfOk,
            'telegram' => $telegramOk,
            'pdf' => $pdfOk,
            'occupancy_pct' => $data['occupancy_pct'],
            'message' => !$allow
                ? 'Daily summary is turned off in notification events'
                : ($telegramOk && $pdfOk
                    ? 'Daily summary and PDF sent to Telegram'
                    : ($telegramOk ? 'Telegram text sent (PDF failed)' : ($pdfOk ? 'PDF sent (text failed)' : 'Could not send daily summary'))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(\PDO $db, int $propertyId, string $day): array
    {
        $monthStart = date('Y-m-01', strtotime($day) ?: time());
        $hotel = 'Hotel';
        try {
            $n = $db->prepare('SELECT name FROM properties WHERE id = ?');
            $n->execute([$propertyId]);
            $hotel = (string)($n->fetchColumn() ?: (defined('PROPERTY_NAME') ? PROPERTY_NAME : 'Hotel'));
        } catch (\Throwable) {
        }

        $bookingsCreated = (int)self::scalar($db,
            "SELECT COUNT(*) FROM bookings WHERE DATE(created_at) = :d AND payment_status != 'cancelled' AND property_id = :pid",
            ['d' => $day, 'pid' => $propertyId]
        );
        $inHouse = (int)self::scalar($db,
            "SELECT COUNT(*) FROM bookings WHERE booking_status = 'checked_in' AND payment_status != 'cancelled' AND property_id = :pid",
            ['pid' => $propertyId]
        );
        $checkouts = (int)self::scalar($db,
            "SELECT COUNT(*) FROM bookings WHERE booking_status = 'checked_out' AND DATE(check_out) = :d AND payment_status != 'cancelled' AND property_id = :pid",
            ['d' => $day, 'pid' => $propertyId]
        );
        $totalRooms = (int)self::scalar($db, 'SELECT COUNT(*) FROM rooms WHERE property_id = ?', [$propertyId]);
        $dirtyRooms = (int)self::scalar($db, "SELECT COUNT(*) FROM rooms WHERE property_id = ? AND state = 'dirty'", [$propertyId]);
        $occupancyPct = $totalRooms > 0 ? (int)round(($inHouse / $totalRooms) * 100) : 0;

        $payFilter = self::paymentWhereSql();
        $todayTotal = self::moneySum($db, "SELECT COALESCE(SUM(ABS(amount)), 0) FROM folio_ledger WHERE {$payFilter} AND DATE(recorded_at) = :d AND property_id = :pid", ['d' => $day, 'pid' => $propertyId]);
        $mtdTotal = self::moneySum($db, "SELECT COALESCE(SUM(ABS(amount)), 0) FROM folio_ledger WHERE {$payFilter} AND DATE(recorded_at) BETWEEN :fromd AND :tod AND property_id = :pid", ['fromd' => $monthStart, 'tod' => $day, 'pid' => $propertyId]);

        $byMethod = self::rows($db,
            "SELECT COALESCE(NULLIF(TRIM(payment_method), ''), 'Other') AS label, COALESCE(SUM(ABS(amount)), 0) AS total
             FROM folio_ledger
             WHERE {$payFilter} AND DATE(recorded_at) = :d AND property_id = :pid
             GROUP BY 1
             ORDER BY total DESC",
            ['d' => $day, 'pid' => $propertyId]
        );
        $byCategory = self::rows($db,
            "SELECT COALESCE(NULLIF(TRIM(payment_category), ''), 'Uncategorized') AS label, COALESCE(SUM(ABS(amount)), 0) AS total
             FROM folio_ledger
             WHERE {$payFilter} AND DATE(recorded_at) = :d AND property_id = :pid
             GROUP BY 1
             ORDER BY total DESC",
            ['d' => $day, 'pid' => $propertyId]
        );

        $mtdByMethod = self::rows($db,
            "SELECT COALESCE(NULLIF(TRIM(payment_method), ''), 'Other') AS label, COALESCE(SUM(ABS(amount)), 0) AS total
             FROM folio_ledger
             WHERE {$payFilter} AND DATE(recorded_at) BETWEEN :fromd AND :tod AND property_id = :pid
             GROUP BY 1
             ORDER BY total DESC",
            ['fromd' => $monthStart, 'tod' => $day, 'pid' => $propertyId]
        );

        $expenseToday = self::moneySum($db,
            "SELECT COALESCE(SUM(amount), 0) FROM finance_transactions WHERE type = 'expense' AND DATE(recorded_at) = :d AND property_id = :pid",
            ['d' => $day, 'pid' => $propertyId]
        );
        $expenseMtd = self::moneySum($db,
            "SELECT COALESCE(SUM(amount), 0) FROM finance_transactions WHERE type = 'expense' AND DATE(recorded_at) BETWEEN :fromd AND :tod AND property_id = :pid",
            ['fromd' => $monthStart, 'tod' => $day, 'pid' => $propertyId]
        );
        $expenseRows = self::rows($db,
            "SELECT category, amount, description, payment_method, recorded_at
             FROM finance_transactions
             WHERE type = 'expense' AND DATE(recorded_at) = :d AND property_id = :pid
             ORDER BY recorded_at ASC",
            ['d' => $day, 'pid' => $propertyId]
        );
        $expenseByCat = self::rows($db,
            "SELECT COALESCE(NULLIF(TRIM(category), ''), 'Other') AS label, COALESCE(SUM(amount), 0) AS total
             FROM finance_transactions
             WHERE type = 'expense' AND DATE(recorded_at) = :d AND property_id = :pid
             GROUP BY 1
             ORDER BY total DESC",
            ['d' => $day, 'pid' => $propertyId]
        );

        $occupied = self::rows($db,
            "SELECT r.room_number, rc.name AS room_type, COALESCE(g.name, 'Walk-in') AS guest_name,
                    b.check_in, b.check_out,
                    (SELECT COALESCE(SUM(ABS(fl.amount)), 0) FROM folio_ledger fl
                      WHERE fl.booking_id = b.id AND fl.amount < 0 AND IFNULL(fl.is_refund, 0) = 0) AS amount_collected,
                    (SELECT COALESCE(SUM(fl.amount), 0) FROM folio_ledger fl WHERE fl.booking_id = b.id) AS pending_due
             FROM bookings b
             JOIN rooms r ON b.room_id = r.id
             LEFT JOIN room_categories rc ON r.category_id = rc.id
             LEFT JOIN guests g ON b.guest_id = g.id
             WHERE b.booking_status = 'checked_in' AND b.payment_status != 'cancelled' AND b.property_id = :pid
             ORDER BY r.room_number ASC",
            ['pid' => $propertyId]
        );

        return [
            'hotel' => $hotel,
            'day' => $day,
            'month_start' => $monthStart,
            'date_label' => date('d M Y', strtotime($day) ?: time()),
            'month_label' => date('M Y', strtotime($day) ?: time()),
            'bookings_created' => $bookingsCreated,
            'in_house' => $inHouse,
            'checkouts' => $checkouts,
            'total_rooms' => $totalRooms,
            'dirty_rooms' => $dirtyRooms,
            'clean_rooms' => max(0, $totalRooms - $dirtyRooms),
            'occupancy_pct' => $occupancyPct,
            'today_total' => $todayTotal,
            'today_total_plain' => format_inr($todayTotal),
            'mtd_total' => $mtdTotal,
            'by_method' => $byMethod,
            'by_category' => $byCategory,
            'mtd_by_method' => $mtdByMethod,
            'expense_today' => $expenseToday,
            'expense_mtd' => $expenseMtd,
            'expense_rows' => $expenseRows,
            'expense_by_cat' => $expenseByCat,
            'net_today' => $todayTotal - $expenseToday,
            'occupied' => $occupied,
        ];
    }

    private static function paymentWhereSql(): string
    {
        return 'amount < 0 AND IFNULL(is_refund, 0) = 0';
    }

    /** @param array<string, mixed>|list<mixed> $params */
    private static function scalar(\PDO $db, string $sql, array $params): mixed
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $params */
    private static function moneySum(\PDO $db, string $sql, array $params): float
    {
        return money_float(self::scalar($db, $sql, $params));
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private static function rows(\PDO $db, string $sql, array $params): array
    {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string, mixed> $data */
    public static function telegramText(array $data): string
    {
        $lines = [];
        $lines[] = '📊 <b>Daily Summary — ' . htmlspecialchars((string)$data['date_label']) . '</b>';
        $lines[] = htmlspecialchars((string)$data['hotel']);
        $lines[] = '━━━━━━━━━━━━━━━━━━';
        $lines[] = '';
        $lines[] = '🏨 <b>Occupancy ' . (int)$data['occupancy_pct'] . '%</b>';
        $lines[] = 'Occupied: <b>' . (int)$data['in_house'] . '</b> / ' . (int)$data['total_rooms'] . ' rooms';
        $lines[] = 'Created today: ' . (int)$data['bookings_created'] . ' · Check-outs: ' . (int)$data['checkouts'];
        $lines[] = 'HK Dirty ' . (int)$data['dirty_rooms'] . ' · Clean ' . (int)$data['clean_rooms'];
        $lines[] = '';
        $lines[] = '💰 <b>Revenue collected today</b>';
        $lines[] = 'Total (all methods): <b>₹' . htmlspecialchars(format_inr($data['today_total'])) . '</b>';
        foreach ($data['by_method'] as $row) {
            $lines[] = '· ' . htmlspecialchars((string)$row['label']) . ': ₹' . htmlspecialchars(format_inr($row['total']));
        }
        $lines[] = '';
        $lines[] = '🏷️ <b>By payment category</b>';
        if ($data['by_category'] === []) {
            $lines[] = '<i>No category split</i>';
        } else {
            foreach ($data['by_category'] as $row) {
                $lines[] = '· ' . htmlspecialchars((string)$row['label']) . ': ₹' . htmlspecialchars(format_inr($row['total']));
            }
        }
        $lines[] = '';
        $lines[] = '📅 <b>MTD ' . htmlspecialchars((string)$data['month_label']) . '</b>';
        $lines[] = 'Payment revenue: <b>₹' . htmlspecialchars(format_inr($data['mtd_total'])) . '</b>';
        foreach (array_slice($data['mtd_by_method'], 0, 8) as $row) {
            $lines[] = '· ' . htmlspecialchars((string)$row['label']) . ': ₹' . htmlspecialchars(format_inr($row['total']));
        }
        $lines[] = '';
        $lines[] = '💸 <b>Expenses today</b> ₹' . htmlspecialchars(format_inr($data['expense_today']));
        foreach ($data['expense_by_cat'] as $row) {
            $lines[] = '· ' . htmlspecialchars((string)$row['label']) . ': ₹' . htmlspecialchars(format_inr($row['total']));
        }
        $maxExp = 8;
        foreach (array_slice($data['expense_rows'], 0, $maxExp) as $row) {
            $desc = substr((string)($row['description'] ?? ''), 0, 40);
            $lines[] = '  — ' . htmlspecialchars((string)$row['category']) . ' · ' . htmlspecialchars($desc) . ' · ₹' . htmlspecialchars(format_inr($row['amount']));
        }
        if (count($data['expense_rows']) > $maxExp) {
            $lines[] = '  <i>+' . (count($data['expense_rows']) - $maxExp) . ' more in PDF</i>';
        }
        $lines[] = 'MTD expenses: ₹' . htmlspecialchars(format_inr($data['expense_mtd']));
        $lines[] = 'Net today: <b>₹' . htmlspecialchars(format_inr($data['net_today'])) . '</b>';
        $lines[] = '';
        $lines[] = '🚪 <b>In-house rooms</b>';
        if ($data['occupied'] === []) {
            $lines[] = '<i>No occupied rooms.</i>';
        } else {
            $shown = 0;
            foreach ($data['occupied'] as $room) {
                $block = '• <b>Rm ' . htmlspecialchars((string)$room['room_number']) . '</b> ' . htmlspecialchars((string)($room['room_type'] ?? ''))
                    . "\n  " . htmlspecialchars((string)$room['guest_name'])
                    . "\n  " . htmlspecialchars(date('d M', strtotime((string)$room['check_in']) ?: time()))
                    . ' → ' . htmlspecialchars(date('d M', strtotime((string)$room['check_out']) ?: time()));
                $next = implode("\n", $lines) . "\n" . $block;
                if (strlen($next) > 3500) {
                    $lines[] = '<i>Remaining rooms in the PDF.</i>';
                    break;
                }
                $lines[] = $block;
                $shown++;
            }
            if ($shown < count($data['occupied'])) {
                // already noted
            }
        }
        $lines[] = '';
        $lines[] = 'Full tables attached as PDF.';
        $lines[] = 'MicroPMS · ' . date('h:i A');
        $text = implode("\n", $lines);
        if (strlen($text) > 4000) {
            $text = substr($text, 0, 3900) . "\n… see PDF";
        }
        return $text;
    }

    /** @param array<string, mixed> $data */
    public static function writePdf(array $data): string
    {
        require_once __DIR__ . '/../libs/fpdf.php';
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 8, self::latin('Daily Summary Report'), 0, 1);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, self::latin($data['hotel'] . '  ·  ' . $data['date_label']), 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 7, 'Occupancy', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(0, 5, self::latin(
            'Occupied ' . $data['in_house'] . ' of ' . $data['total_rooms'] . ' rooms (' . $data['occupancy_pct'] . '%)'
            . "\nBookings created today: " . $data['bookings_created']
            . '   Check-outs: ' . $data['checkouts']
            . "\nHousekeeping dirty/clean: " . $data['dirty_rooms'] . '/' . $data['clean_rooms']
        ));
        $pdf->Ln(2);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 7, self::latin('Revenue collected today (all methods): Rs ' . format_inr($data['today_total'])), 0, 1);
        self::pdfKeyValues($pdf, $data['by_method'], 'Payment method');
        self::pdfKeyValues($pdf, $data['by_category'], 'Payment category');

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 7, self::latin('MTD ' . $data['month_label'] . ' payment revenue: Rs ' . format_inr($data['mtd_total'])), 0, 1);
        self::pdfKeyValues($pdf, $data['mtd_by_method'], 'MTD by method');

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 7, self::latin('Expenses today: Rs ' . format_inr($data['expense_today']) . '   MTD: Rs ' . format_inr($data['expense_mtd'])), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, self::latin('Net today (collections - expenses): Rs ' . format_inr($data['net_today'])), 0, 1);
        self::pdfKeyValues($pdf, $data['expense_by_cat'], 'Expense category');

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(28, 6, 'Time', 1);
        $pdf->Cell(32, 6, 'Category', 1);
        $pdf->Cell(28, 6, 'Method', 1);
        $pdf->Cell(72, 6, 'Description', 1);
        $pdf->Cell(30, 6, 'Amount', 1, 1);
        $pdf->SetFont('Arial', '', 8);
        if ($data['expense_rows'] === []) {
            $pdf->Cell(190, 6, 'No expenses today', 1, 1);
        }
        foreach ($data['expense_rows'] as $row) {
            $pdf->Cell(28, 6, self::latin(date('H:i', strtotime((string)$row['recorded_at']) ?: time())), 1);
            $pdf->Cell(32, 6, self::latin(substr((string)$row['category'], 0, 16)), 1);
            $pdf->Cell(28, 6, self::latin(substr((string)($row['payment_method'] ?? '-'), 0, 12)), 1);
            $pdf->Cell(72, 6, self::latin(substr((string)$row['description'], 0, 40)), 1);
            $pdf->Cell(30, 6, self::latin(format_inr($row['amount'])), 1, 1, 'R');
        }
        $pdf->Ln(4);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 7, self::latin('Occupied rooms (' . count($data['occupied']) . ')'), 0, 1);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(18, 6, 'Room', 1);
        $pdf->Cell(28, 6, 'Type', 1);
        $pdf->Cell(42, 6, 'Guest', 1);
        $pdf->Cell(28, 6, 'Check-in', 1);
        $pdf->Cell(28, 6, 'Check-out', 1);
        $pdf->Cell(23, 6, 'Collected', 1);
        $pdf->Cell(23, 6, 'Balance', 1, 1);
        $pdf->SetFont('Arial', '', 8);
        if ($data['occupied'] === []) {
            $pdf->Cell(190, 6, 'No occupied rooms', 1, 1);
        }
        foreach ($data['occupied'] as $room) {
            $pdf->Cell(18, 6, self::latin((string)$room['room_number']), 1);
            $pdf->Cell(28, 6, self::latin(substr((string)($room['room_type'] ?? ''), 0, 14)), 1);
            $pdf->Cell(42, 6, self::latin(substr((string)$room['guest_name'], 0, 22)), 1);
            $pdf->Cell(28, 6, self::latin(date('d M Y', strtotime((string)$room['check_in']) ?: time())), 1);
            $pdf->Cell(28, 6, self::latin(date('d M Y', strtotime((string)$room['check_out']) ?: time())), 1);
            $pdf->Cell(23, 6, self::latin(format_inr($room['amount_collected'])), 1, 0, 'R');
            $pdf->Cell(23, 6, self::latin(format_inr($room['pending_due'])), 1, 1, 'R');
        }

        $pdf->Ln(6);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell(0, 5, 'MicroPMS  ·  generated ' . date('d M Y h:i A'), 0, 1);

        $path = sys_get_temp_dir() . '/micropms_daily_' . preg_replace('/\W+/', '_', (string)$data['day']) . '_' . time() . '.pdf';
        $pdf->Output('F', $path);
        return $path;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function pdfKeyValues(\FPDF $pdf, array $rows, string $title): void
    {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(0, 6, self::latin($title), 0, 1);
        $pdf->SetFont('Arial', '', 9);
        if ($rows === []) {
            $pdf->Cell(0, 5, '-', 0, 1);
            $pdf->Ln(1);
            return;
        }
        foreach ($rows as $row) {
            $pdf->Cell(90, 5, self::latin((string)$row['label']), 0);
            $pdf->Cell(40, 5, self::latin('Rs ' . format_inr($row['total'])), 0, 1, 'R');
        }
        $pdf->Ln(2);
    }

    private static function latin(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }
}
