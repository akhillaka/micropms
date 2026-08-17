<?php
declare(strict_types=1);

/**
 * Default guest email + staff Telegram copy for notification automations.
 * Email goes to the guest. Telegram goes to the property operations chat.
 */
class AutomationTemplates {

    /**
     * @return array{email_subject: string, email_body_html: string, telegram_body_text: string}
     */
    public static function forEvent(string $eventKey): array {
        $all = self::all();
        return $all[$eventKey] ?? self::generic();
    }

    /**
     * @return array<string, array{email_subject: string, email_body_html: string, telegram_body_text: string}>
     */
    public static function all(): array {
        $stay = self::stayBlock();
        $folio = self::folioBlock();

        return [
            'booking_confirmed' => [
                'email_subject' => 'Your stay at {hotel_name} is confirmed',
                'email_body_html' => self::email(
                    'Reservation confirmed',
                    'You\'re booked, {first_name}.',
                    'We have your dates. Check-in is straightforward — keep this note handy, or open your folio anytime from the button below.',
                    $stay . $folio,
                    'View folio',
                    '{invoice_link}',
                    'If anything in this reservation needs to change, reply to this email or call the front desk.'
                ),
                'telegram_body_text' => self::tg(
                    'New booking',
                    "{guest_name}\nRoom {room_number} · {room_type}\n\n{check_in_date}\n→ {check_out_date}\n\n₹{total_amount}  ·  paid ₹{paid_amount}"
                ),
            ],
            'guest_check_in' => [
                'email_subject' => 'Welcome to {hotel_name} — you\'re checked in',
                'email_body_html' => self::email(
                    'Checked in',
                    'Welcome in, {first_name}.',
                    'Room {room_number} is ready. Front desk is here if you need extra towels, a late breakfast, or a quieter room. Checkout is {check_out_date}.',
                    $stay,
                    'Open guest folio',
                    '{invoice_link}',
                    'Charges posted during your stay will show on your folio in real time.'
                ),
                'telegram_body_text' => self::tg(
                    'Checked in',
                    "{guest_name}\nRoom {room_number} · {room_type}\nOut {check_out_date}\n\nBalance ₹{balance_amount}"
                ),
            ],
            'guest_check_out' => [
                'email_subject' => 'Thank you for staying at {hotel_name}',
                'email_body_html' => self::email(
                    'Checked out',
                    'Safe travels, {first_name}.',
                    'Your stay is closed and the folio is settled. A copy of the invoice is one tap away if you need it for records or reimbursement.',
                    $folio,
                    'Download invoice',
                    '{invoice_link}',
                    'We hope the room felt like it was yours. If something missed the mark, tell us — we read every note.'
                ),
                'telegram_body_text' => self::tg(
                    'Checked out',
                    "{guest_name}\nRoom {room_number}\nPaid ₹{paid_amount}  ·  balance ₹{balance_amount}"
                ),
            ],
            'booking_cancelled' => [
                'email_subject' => 'Your reservation at {hotel_name} was cancelled',
                'email_body_html' => self::email(
                    'Reservation cancelled',
                    'This booking is no longer held.',
                    'Reservation {booking_id} for {check_in_date} has been cancelled. If this was unexpected, contact us and we will look it up immediately.',
                    $stay,
                    '',
                    '',
                    'If a payment was collected, our team will process any refund according to the rate rules for this stay.'
                ),
                'telegram_body_text' => self::tg(
                    'Cancelled',
                    "{guest_name}\nRoom {room_number}\n{check_in_date} → {check_out_date}\nBooking {booking_id}"
                ),
            ],
            'payment_link' => [
                'email_subject' => '{hotel_name} — ₹{balance_amount} is due on your stay',
                'email_body_html' => self::email(
                    'Payment due',
                    'A balance is waiting, {first_name}.',
                    '₹{balance_amount} is outstanding on booking {booking_id}. Pay securely from this email — the link opens a checkout page, not a chat.',
                    $stay . $folio,
                    'Pay now',
                    '{payment_link}',
                    'Already paid? You can ignore this. The folio updates as soon as the payment clears.'
                ),
                'telegram_body_text' => self::tg(
                    'Payment link sent',
                    "{guest_name}\nRoom {room_number}\nDue ₹{balance_amount}\n{payment_link}"
                ),
            ],
            'guest_review_form' => [
                'email_subject' => 'How was {hotel_name}, {first_name}?',
                'email_body_html' => self::email(
                    'A minute of your time',
                    'Tell us how the stay felt.',
                    'You were with us in room {room_number}. A short note — what worked, what didn\'t — helps the next guest, and us.',
                    self::rows([
                        ['Stay', '{check_in_date} → {check_out_date}'],
                        ['Room', '{room_number} · {room_type}'],
                    ]),
                    'Leave a review',
                    '{review_link}',
                    'Private to the property. We do not publish your email.'
                ),
                'telegram_body_text' => self::tg(
                    'Review requested',
                    "{guest_name}\nRoom {room_number}\n{review_link}"
                ),
            ],
            'guest_invoice' => [
                'email_subject' => 'Invoice for your stay at {hotel_name}',
                'email_body_html' => self::email(
                    'Invoice',
                    'Your folio, in one place.',
                    'Booking {booking_id}. Use this for accounts, GST, or your own records. The link expires after 24 hours — ask the desk if you need a fresh copy.',
                    $stay . $folio,
                    'Open invoice',
                    '{invoice_link}',
                    'Questions on a line item? Reply with the date and we will trace it.'
                ),
                'telegram_body_text' => self::tg(
                    'Invoice sent',
                    "{guest_name}\nRoom {room_number}\n₹{total_amount}  ·  paid ₹{paid_amount}\n{invoice_link}"
                ),
            ],
            'pre_departure' => [
                'email_subject' => 'Checkout at {checkout_time} — {hotel_name}',
                'email_body_html' => self::email(
                    'Leaving soon',
                    'Checkout is at {checkout_time}.',
                    'Room {room_number}. Please settle any open charges at the desk, leave keys where you found them, and tell us if you need a luggage hold or a cab.',
                    self::rows([
                        ['Checkout', '{check_out_date}'],
                        ['Room', '{room_number}'],
                        ['Open balance', '₹{balance_amount}'],
                    ]),
                    'Review folio',
                    '{invoice_link}',
                    'Running late? A quick call to the desk is enough — we would rather know than guess.'
                ),
                'telegram_body_text' => self::tg(
                    'Checkout in ~30 min',
                    "{guest_name}\nRoom {room_number}\nDue {checkout_time}\nBalance ₹{balance_amount}"
                ),
            ],
            'room_marked_dirty' => [
                'email_subject' => 'Housekeeping: room {room_number} needs turning',
                'email_body_html' => self::email(
                    'Housekeeping',
                    'Room {room_number} is dirty.',
                    'Marked after checkout or a status change. Assign it on the board when you can.',
                    self::rows([
                        ['Room', '{room_number}'],
                        ['Category', '{room_type}'],
                    ]),
                    '',
                    '',
                    'This note is for operations. Guests should not normally receive it.'
                ),
                'telegram_body_text' => self::tg(
                    'Room dirty',
                    "Room {room_number}\n{room_type}\nReady for housekeeping"
                ),
            ],
        ];
    }

    /**
     * Extra placeholders beyond the global set, by event.
     *
     * @return list<string>
     */
    public static function extraVariables(string $eventKey): array {
        return match ($eventKey) {
            'payment_link' => ['payment_link', 'amount_due'],
            'pre_departure' => ['checkout_time'],
            'guest_review_form' => ['review_link'],
            'guest_invoice' => ['invoice_link'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function systemEventKeys(): array {
        return array_keys(self::all());
    }

    /**
     * @return array{email_subject: string, email_body_html: string, telegram_body_text: string}
     */
    private static function generic(): array {
        return [
            'email_subject' => '{hotel_name} — an update on your stay',
            'email_body_html' => self::email(
                'Update',
                'A note from {hotel_name}.',
                'Hi {first_name}, this is about booking {booking_id}.',
                self::stayBlock() . self::folioBlock(),
                'View details',
                '{invoice_link}',
                'If you were not expecting this, contact the front desk.'
            ),
            'telegram_body_text' => self::tg(
                'Automation',
                "{guest_name}\nRoom {room_number}\n{check_in_date} → {check_out_date}"
            ),
        ];
    }

    private static function stayBlock(): string {
        return self::rows([
            ['Stay', '{check_in_date} → {check_out_date}'],
            ['Room', '{room_number} · {room_type}'],
            ['Booking', '{booking_id}'],
        ]);
    }

    private static function folioBlock(): string {
        return self::rows([
            ['Total', '₹{total_amount}'],
            ['Paid', '₹{paid_amount}'],
            ['Balance', '₹{balance_amount}'],
        ]);
    }

    /**
     * @param list<array{0: string, 1: string}> $rows
     */
    private static function rows(array $rows): string {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;background:#f7f4ef;border-radius:12px;">';
        $html .= '<tr><td style="padding:4px 22px;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        foreach ($rows as $i => $row) {
            $border = $i === 0 ? 'none' : '1px solid #ece7df';
            $html .= '<tr>'
                . '<td style="padding:14px 0;border-top:' . $border . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#a39e96;width:34%;">' . $row[0] . '</td>'
                . '<td style="padding:14px 0;border-top:' . $border . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;color:#1c1917;text-align:right;">' . $row[1] . '</td>'
                . '</tr>';
        }
        $html .= '</table></td></tr></table>';
        return $html;
    }

    private static function email(
        string $kicker,
        string $headline,
        string $intro,
        string $detailsHtml,
        string $ctaLabel = '',
        string $ctaHref = '',
        string $closing = ''
    ): string {
        $cta = '';
        if ($ctaLabel !== '' && $ctaHref !== '') {
            $cta = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 28px;"><tr><td style="border-radius:10px;background:#1c1917;">'
                . '<a href="' . $ctaHref . '" style="display:inline-block;padding:14px 22px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;font-weight:600;color:#faf8f5;text-decoration:none;">'
                . htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8')
                . '</a></td></tr></table>';
        }

        $close = $closing !== ''
            ? '<p style="margin:0 0 8px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.65;color:#57534e;">' . $closing . '</p>'
            : '';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3efe8;padding:0;margin:0;">'
            . '<tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">'
            . '<tr><td style="padding:0 8px 18px;font-family:Georgia,\'Times New Roman\',serif;font-size:20px;letter-spacing:-0.02em;color:#1c1917;">{hotel_name}</td></tr>'
            . '<tr><td style="background:#ffffff;border-radius:16px;overflow:hidden;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="height:4px;background:#1c1917;font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:36px 32px 32px;">'
            . '<p style="margin:0 0 10px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#a39e96;">' . htmlspecialchars($kicker, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<h1 style="margin:0 0 14px;font-family:Georgia,\'Times New Roman\',serif;font-size:26px;line-height:1.3;font-weight:normal;color:#1c1917;">' . $headline . '</h1>'
            . '<p style="margin:0 0 28px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.7;color:#44403c;">' . $intro . '</p>'
            . $detailsHtml
            . $cta
            . $close
            . '<p style="margin:28px 0 0;font-family:Georgia,\'Times New Roman\',serif;font-size:15px;color:#1c1917;">The team at {hotel_name}</p>'
            . '</td></tr></table></td></tr>'
            . '<tr><td style="padding:18px 8px 0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#a39e96;">Sent by {hotel_name}. This is a transactional message about your reservation.</td></tr>'
            . '</table></td></tr></table>';
    }

    private static function tg(string $title, string $body): string {
        return '<b>' . $title . '</b> · {hotel_name}' . "\n\n" . $body;
    }
}
