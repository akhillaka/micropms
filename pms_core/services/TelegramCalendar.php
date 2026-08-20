<?php
declare(strict_types=1);

/**
 * Inline month calendar + hour/minute pickers for the Telegram ops bot.
 * Callback data stays well under Telegram's 64-byte limit.
 *
 * Day / month:  c:{flow}:{which}:{YYYYMM|YYYYMMDD}
 * Hour:         t:{flow}:{which}:h{HH}
 * Minute:       t:{flow}:{which}:m{HH}{MM}
 */
class TelegramCalendar {

    public const FLOWS = ['nb', 'eb'];
    public const WHICH = ['in', 'out'];

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function monthKeyboard(string $flow, string $which, string $yearMonth, string $cancelCallback = 'main_menu'): array {
        self::assertFlow($flow, $which);
        $yearMonth = preg_replace('/\D/', '', $yearMonth) ?: date('Ym');
        $yearMonth = substr($yearMonth, 0, 6);
        $year = (int)substr($yearMonth, 0, 4);
        $month = (int)substr($yearMonth, 4, 2);
        if ($year < 2020 || $month < 1 || $month > 12) {
            $year = (int)date('Y');
            $month = (int)date('n');
            $yearMonth = sprintf('%04d%02d', $year, $month);
        }

        $first = mktime(12, 0, 0, $month, 1, $year) ?: time();
        $daysInMonth = (int)date('t', $first);
        $startDow = (int)date('w', $first); // 0 Sun
        $title = date('F Y', $first);

        $prev = date('Ym', strtotime('-1 month', $first) ?: $first);
        $next = date('Ym', strtotime('+1 month', $first) ?: $first);

        $rows = [];
        $rows[] = [
            ['text' => '‹', 'callback_data' => "c:{$flow}:{$which}:{$prev}"],
            ['text' => $title, 'callback_data' => "c:{$flow}:{$which}:{$yearMonth}"],
            ['text' => '›', 'callback_data' => "c:{$flow}:{$which}:{$next}"],
        ];
        $rows[] = array_map(
            static fn(string $d) => ['text' => $d, 'callback_data' => "c:{$flow}:{$which}:{$yearMonth}"],
            ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
        );

        $week = [];
        for ($i = 0; $i < $startDow; $i++) {
            $week[] = ['text' => ' ', 'callback_data' => "c:{$flow}:{$which}:{$yearMonth}"];
        }
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $ymd = sprintf('%04d%02d%02d', $year, $month, $day);
            $week[] = ['text' => (string)$day, 'callback_data' => "c:{$flow}:{$which}:{$ymd}"];
            if (count($week) === 7) {
                $rows[] = $week;
                $week = [];
            }
        }
        if ($week) {
            while (count($week) < 7) {
                $week[] = ['text' => ' ', 'callback_data' => "c:{$flow}:{$which}:{$yearMonth}"];
            }
            $rows[] = $week;
        }
        $rows[] = [['text' => '🔙 Cancel', 'callback_data' => $cancelCallback]];

        self::assertCallbackSizes($rows);
        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function hourKeyboard(string $flow, string $which, string $cancelCallback = 'main_menu'): array {
        self::assertFlow($flow, $which);
        $rows = [];
        $row = [];
        for ($h = 0; $h < 24; $h++) {
            $hh = sprintf('%02d', $h);
            $row[] = ['text' => $hh, 'callback_data' => "t:{$flow}:{$which}:h{$hh}"];
            if (count($row) === 6) {
                $rows[] = $row;
                $row = [];
            }
        }
        $rows[] = [['text' => '🔙 Cancel', 'callback_data' => $cancelCallback]];
        self::assertCallbackSizes($rows);
        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function minuteKeyboard(string $flow, string $which, int $hour, string $cancelCallback = 'main_menu'): array {
        self::assertFlow($flow, $which);
        $hour = max(0, min(23, $hour));
        $hh = sprintf('%02d', $hour);
        $rows = [];
        $row = [];
        for ($m = 0; $m < 60; $m += 5) {
            $mm = sprintf('%02d', $m);
            $row[] = ['text' => "{$hh}:{$mm}", 'callback_data' => "t:{$flow}:{$which}:m{$hh}{$mm}"];
            if (count($row) === 4) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        $rows[] = [['text' => '🔙 Cancel', 'callback_data' => $cancelCallback]];
        self::assertCallbackSizes($rows);
        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array{kind: string, flow: string, which: string, year_month?: string, date?: string, hour?: int, minute?: int}|null
     */
    public static function parse(string $data): ?array {
        if (preg_match('/^c:(nb|eb):(in|out):(\d{6}|\d{8})$/', $data, $m)) {
            $digits = $m[3];
            if (strlen($digits) === 6) {
                return ['kind' => 'month', 'flow' => $m[1], 'which' => $m[2], 'year_month' => $digits];
            }
            return [
                'kind' => 'day',
                'flow' => $m[1],
                'which' => $m[2],
                'date' => substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 2),
                'year_month' => substr($digits, 0, 6),
            ];
        }
        if (preg_match('/^t:(nb|eb):(in|out):h(\d{2})$/', $data, $m)) {
            return ['kind' => 'hour', 'flow' => $m[1], 'which' => $m[2], 'hour' => (int)$m[3]];
        }
        if (preg_match('/^t:(nb|eb):(in|out):m(\d{2})(\d{2})$/', $data, $m)) {
            return ['kind' => 'minute', 'flow' => $m[1], 'which' => $m[2], 'hour' => (int)$m[3], 'minute' => (int)$m[4]];
        }
        return null;
    }

    public static function maxCallbackLength(): int {
        return strlen('t:eb:out:m2355');
    }

    private static function assertFlow(string $flow, string $which): void {
        if (!in_array($flow, self::FLOWS, true) || !in_array($which, self::WHICH, true)) {
            throw new \InvalidArgumentException('Invalid calendar flow');
        }
    }

    /**
     * @param list<list<array{text: string, callback_data: string}>> $rows
     */
    private static function assertCallbackSizes(array $rows): void {
        foreach ($rows as $row) {
            foreach ($row as $btn) {
                if (strlen((string)$btn['callback_data']) > 64) {
                    throw new \RuntimeException('Telegram callback_data exceeds 64 bytes');
                }
            }
        }
    }
}
