<?php
declare(strict_types=1);

/**
 * Single source of “what can change on this stay?” for PMS, Assistant, and Telegram.
 */
class StayPolicy {

    public const CHECK_IN = 'check_in';
    public const CHECK_OUT = 'check_out';
    public const ROOM = 'room';
    public const GUEST = 'guest';
    public const RATE = 'rate';
    public const CANCEL = 'cancel';
    public const CHECK_IN_ACTION = 'check_in_action';
    public const CHECK_OUT_ACTION = 'check_out_action';
    public const PAYMENT = 'payment';
    public const ID_PROOF = 'id_proof';

    /**
     * @param array<string, mixed> $booking
     * @return list<string>
     */
    public static function allowedFields(array $booking): array {
        $status = strtolower(trim((string)($booking['booking_status'] ?? '')));
        $pay = strtolower(trim((string)($booking['payment_status'] ?? '')));
        if ($status === 'cancelled' || $pay === 'cancelled') {
            return [];
        }
        if ($status === 'checked_out') {
            return [];
        }
        if ($status === 'checked_in') {
            return [
                self::CHECK_OUT,
                self::ROOM,
                self::GUEST,
                self::RATE,
                self::CHECK_OUT_ACTION,
                self::PAYMENT,
                self::ID_PROOF,
            ];
        }
        // booked (and any unknown active status treated as reservation)
        return [
            self::CHECK_IN,
            self::CHECK_OUT,
            self::ROOM,
            self::GUEST,
            self::RATE,
            self::CANCEL,
            self::CHECK_IN_ACTION,
            self::PAYMENT,
            self::ID_PROOF,
        ];
    }

    /**
     * @param array<string, mixed> $booking
     */
    public static function can(array $booking, string $field): bool {
        return in_array($field, self::allowedFields($booking), true);
    }

    /**
     * @param array<string, mixed> $booking
     */
    public static function assert(array $booking, string $field): void {
        if (self::can($booking, $field)) {
            return;
        }
        $status = (string)($booking['booking_status'] ?? 'unknown');
        throw new \Exception(match ($field) {
            self::CHECK_IN => 'Check-in date and time cannot be changed after the guest is checked in.',
            self::CANCEL => 'Only a booked reservation can be cancelled. Rollback check-in first if the guest is in-house.',
            self::CHECK_IN_ACTION => 'Can only check in from booked status.',
            self::CHECK_OUT_ACTION => 'Can only check out a guest who is checked in.',
            self::CHECK_OUT => 'Checkout date cannot be changed on a checked-out or cancelled stay.',
            self::ROOM => 'Room cannot be changed on a checked-out or cancelled stay.',
            default => "This stay ({$status}) cannot be updated that way.",
        });
    }

    /**
     * Guests may check in at or after the booked time, never before.
     * Change the stay's check-in time first if they arrived early.
     */
    public static function assertCheckInTime(array $booking, ?int $nowTs = null): void {
        $nowTs = $nowTs ?? time();
        $scheduled = strtotime((string)($booking['check_in'] ?? ''));
        if ($scheduled === false) {
            return;
        }
        if ($nowTs + 60 < $scheduled) {
            $when = date('g:i A', $scheduled);
            $day = date('d M Y', $scheduled);
            throw new \Exception(
                "Check-in is scheduled for {$day} at {$when}. It cannot be performed yet. Change the booking check-in time first if the guest arrived early."
            );
        }
    }

    /**
     * Flags for UI (folio, assistant, telegram menus).
     *
     * @param array<string, mixed> $booking
     * @return array{
     *   check_in: bool,
     *   check_out: bool,
     *   room: bool,
     *   guest: bool,
     *   rate: bool,
     *   cancel: bool,
     *   check_in_action: bool,
     *   check_out_action: bool,
     *   payment: bool,
     *   stay_open: bool
     * }
     */
    public static function ui(array $booking): array {
        return [
            'check_in' => self::can($booking, self::CHECK_IN),
            'check_out' => self::can($booking, self::CHECK_OUT),
            'room' => self::can($booking, self::ROOM),
            'guest' => self::can($booking, self::GUEST),
            'rate' => self::can($booking, self::RATE),
            'cancel' => self::can($booking, self::CANCEL),
            'check_in_action' => self::can($booking, self::CHECK_IN_ACTION),
            'check_out_action' => self::can($booking, self::CHECK_OUT_ACTION),
            'payment' => self::can($booking, self::PAYMENT),
            'stay_open' => self::can($booking, self::CHECK_OUT) || self::can($booking, self::CHECK_IN),
        ];
    }

    /**
     * Pad date-only values. Prefer the stay's existing clock so Telegram date-only
     * edits do not reset 14:00 / 11:00.
     */
    public static function normalizeDateTime(string $value, ?string $existing, string $defaultTime): string {
        $value = trim(str_replace('T', ' ', $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $clock = $defaultTime;
            if ($existing && preg_match('/\d{2}:\d{2}:\d{2}/', $existing, $m)) {
                $clock = $m[0];
            }
            return $value . ' ' . $clock;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \Exception('Invalid date or time.');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    public static function sameInstant(string $a, string $b): bool {
        $ta = strtotime($a);
        $tb = strtotime($b);
        return $ta !== false && $tb !== false && $ta === $tb;
    }
}
