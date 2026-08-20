<?php
declare(strict_types=1);

require_once __DIR__ . '/BookingService.php';
require_once __DIR__ . '/GuestService.php';
require_once __DIR__ . '/FolioService.php';
require_once __DIR__ . '/RazorpayService.php';
require_once __DIR__ . '/PhonePeService.php';
require_once __DIR__ . '/../PhoneHelper.php';
require_once __DIR__ . '/../GuestAccessToken.php';
require_once __DIR__ . '/../AuditLogger.php';
require_once __DIR__ . '/../NotificationRelay.php';
require_once __DIR__ . '/StayPolicy.php';
require_once __DIR__ . '/TelegramCalendar.php';

/**
 * Front-desk flows for the Telegram operations bot.
 * Used as a trait by TelegramOperationsHandler.
 */
trait TelegramDeskFlows {

    private function startNewBookingFlow(string $chatId): void {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) {
            return;
        }
        $this->setSession($chatId, 'NB_NAME', ['property_id' => $propertyId]);
        $this->sendMessage($chatId, "📝 *New booking*\n\nType the guest's full name:");
    }

    private function startCheckInFlow(string $chatId): void {
        $this->promptStayPicker($chatId, 'ci_room_', "Select a *booked* room to check in:", ['booked']);
    }

    private function startEditBookingFlow(string $chatId): void {
        $this->promptStayPicker($chatId, 'eb_room_', "Select a stay to edit:", ['booked', 'checked_in']);
    }

    private function startIdProofFlow(string $chatId): void {
        $this->promptStayPicker($chatId, 'id_room_', "Select a guest to attach ID photos:", ['booked', 'checked_in']);
    }

    private function startCancelBookingFlow(string $chatId): void {
        $this->promptStayPicker($chatId, 'cx_room_', "Select a *booked* stay to cancel:", ['booked']);
    }

    private function beginCancelStayForRoom(string $chatId, int $roomId): void {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) {
            return;
        }
        $booking = $this->activeStayForRoom($roomId, $propertyId, ['booked']);
        if (!$booking) {
            $this->sendMessage($chatId, "No booked reservation found for that room.");
            return;
        }
        $this->setSession($chatId, 'EB_CANCEL', [
            'property_id' => $propertyId,
            'booking_id' => $booking['id'],
            'room_name' => $booking['room_number'],
        ]);
        $this->sendMessage($chatId, "Type the cancellation reason for Room {$booking['room_number']} — {$booking['guest_name']}:");
    }

    private function promptStayPicker(string $chatId, string $callbackPrefix, string $title, array $statuses): void {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $params = array_merge([$propertyId], $statuses);
        $stmt = $this->db->prepare("
            SELECT b.id, r.id as room_id, r.room_number, g.name, b.booking_status
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            JOIN guests g ON b.guest_id = g.id
            WHERE b.property_id = ? AND b.booking_status IN ($placeholders)
              AND b.payment_status != 'cancelled'
            ORDER BY r.room_number ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            $this->sendMessage($chatId, "No matching stays found.");
            return;
        }
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($rows as $b) {
            $row[] = [
                'text' => "{$b['room_number']} {$b['name']}",
                'callback_data' => $callbackPrefix . $b['room_id'],
            ];
            if (count($row) === 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if ($row) {
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Cancel', 'callback_data' => 'main_menu']];
        $this->sendMessage($chatId, $title, $keyboard);
    }

    private function handleDeskStatefulInput(string $chatId, string $text, array $session): bool {
        $state = (string)($session['state'] ?? '');
        $context = json_decode((string)($session['context_data'] ?? '{}'), true) ?: [];

        if ($state === 'NB_NAME') {
            $name = trim($text);
            if (strlen($name) < 2) {
                $this->sendMessage($chatId, "Please type a valid guest name.");
                return true;
            }
            $context['guest_name'] = $name;
            $this->setSession($chatId, 'NB_PHONE', $context);
            $this->sendMessage($chatId, "Phone number for *{$name}* (10-digit mobile):");
            return true;
        }

        if ($state === 'NB_PHONE') {
            $phone = PhoneHelper::toLocal($text);
            if (!$phone) {
                $this->sendMessage($chatId, "Invalid phone. Send a 10-digit Indian mobile number.");
                return true;
            }
            $context['guest_phone'] = $phone;
            $this->promptCheckInDate($chatId, $context);
            return true;
        }

        if ($state === 'NB_CHECKIN') {
            $date = $this->parseDeskDate($text);
            if (!$date) {
                $this->sendMessage($chatId, "Send check-in as `YYYY-MM-DD` (example: " . date('Y-m-d') . ").");
                return true;
            }
            $this->askNewBookingTime($chatId, $context, $date);
            return true;
        }

        if ($state === 'NB_TIME') {
            $time = $this->parseDeskTime($text, (string)($context['check_in_date'] ?? date('Y-m-d')));
            if (!$time) {
                $this->sendMessage($chatId, "Send check-in time as `HH:MM` (example: `14:00`), or tap a button.");
                return true;
            }
            $this->askNewBookingCheckout($chatId, $context, $time);
            return true;
        }

        if ($state === 'NB_CHECKOUT') {
            $date = $this->parseDeskDate($text);
            if (!$date) {
                $this->sendMessage($chatId, "Send checkout date as `YYYY-MM-DD` (example: " . date('Y-m-d', strtotime('+1 day')) . ").");
                return true;
            }
            $this->askStayTime($chatId, $context, 'nb', 'out', $date);
            return true;
        }

        if ($state === 'NB_OUT_TIME') {
            $time = $this->parseDeskTime($text, (string)($context['check_out_date'] ?? date('Y-m-d')));
            if (!$time) {
                $this->sendMessage($chatId, "Send checkout time as `HH:MM` (example: `11:00`).");
                return true;
            }
            $this->finishNewBookingCheckoutTime($chatId, $context, $time);
            return true;
        }

        if ($state === 'NB_NIGHTS') {
            $nights = (int)$text;
            if ($nights < 1 || $nights > 30) {
                $this->sendMessage($chatId, "Type nights as a number from 1 to 30.");
                return true;
            }
            $this->showAvailableRoomsForNewBooking($chatId, $context, $nights);
            return true;
        }

        if ($state === 'EB_DATES' || $state === 'EB_CHECKIN' || $state === 'EB_CHECKOUT') {
            if (preg_match('/(\d{4}-\d{2}-\d{2})\s+(\d{4}-\d{2}-\d{2})/', $text, $m)) {
                $this->applyEditDates($chatId, $context, $m[1], $m[2]);
                return true;
            }
            $date = $this->parseDeskDate($text);
            if ($date && ($state === 'EB_CHECKIN' || ($context['edit_which'] ?? '') === 'in')) {
                $this->askStayTime($chatId, $context, 'eb', 'in', $date);
                return true;
            }
            if ($date && ($state === 'EB_CHECKOUT' || ($context['edit_which'] ?? '') === 'out')) {
                $this->askStayTime($chatId, $context, 'eb', 'out', $date);
                return true;
            }
            $this->sendMessage($chatId, "Tap a date on the calendar, or type `YYYY-MM-DD`. You can still send both dates as `YYYY-MM-DD YYYY-MM-DD`.");
            return true;
        }

        if ($state === 'EB_TIME') {
            $which = (string)($context['edit_which'] ?? 'in');
            $dateKey = $which === 'out' ? 'check_out_date' : 'check_in_date';
            $time = $this->parseDeskTime($text, (string)($context[$dateKey] ?? date('Y-m-d')));
            if (!$time) {
                $this->sendMessage($chatId, "Send time as `HH:MM` (example: `14:00`).");
                return true;
            }
            $this->applyEditTime($chatId, $context, $which, $time);
            return true;
        }

        if ($state === 'EB_CANCEL') {
            $reason = trim($text);
            if (strlen($reason) < 3) {
                $this->sendMessage($chatId, "Type a cancellation reason (at least 3 characters).");
                return true;
            }
            try {
                $result = BookingService::cancelBooking(
                    $this->db,
                    (int)$context['booking_id'],
                    (int)$context['property_id'],
                    $reason,
                    ['source' => 'telegram']
                );
                $this->clearSession($chatId);
                $this->sendMessage($chatId, "✅ " . $result['message'], $this->menuKeyboard());
            } catch (\Throwable $e) {
                $this->sendMessage($chatId, "Could not cancel: " . $e->getMessage());
            }
            return true;
        }

        if ($state === 'AWAITING_PAYMENT_AMOUNT' && $this->isOnlineGateway((string)($context['method'] ?? ''))) {
            $amount = (float)$text;
            if ($amount <= 0) {
                $this->sendMessage($chatId, "Type an amount greater than 0.");
                return true;
            }
            $this->createOnlinePaymentLink($chatId, $context, $amount);
            return true;
        }

        return false;
    }

    private function promptCheckInDate(string $chatId, array $context): void {
        $this->sendStayCalendar($chatId, $context, 'nb', 'in', date('Ym'), 'NB_CHECKIN');
    }

    private function sendStayCalendar(string $chatId, array $context, string $flow, string $which, string $yearMonth, string $state): void {
        $context['cal_flow'] = $flow;
        $context['cal_which'] = $which;
        $this->setSession($chatId, $state, $context);
        $label = $which === 'in' ? 'check-in' : 'checkout';
        $this->sendMessage(
            $chatId,
            "Pick a {$label} date on the calendar, or type `YYYY-MM-DD`.",
            TelegramCalendar::monthKeyboard($flow, $which, $yearMonth)
        );
    }

    private function askStayTime(string $chatId, array $context, string $flow, string $which, string $date): void {
        if ($which === 'in') {
            $context['check_in_date'] = $date;
        } else {
            $context['check_out_date'] = $date;
        }
        $context['cal_flow'] = $flow;
        $context['cal_which'] = $which;
        $context['edit_which'] = $which;
        $state = $flow === 'eb' ? 'EB_TIME' : ($which === 'out' ? 'NB_OUT_TIME' : 'NB_TIME');
        $this->setSession($chatId, $state, $context);
        $pretty = date('D, d M Y', strtotime($date));
        $kb = TelegramCalendar::hourKeyboard($flow, $which);
        if ($which === 'out') {
            array_unshift($kb['inline_keyboard'], [[
                'text' => '11:00 default',
                'callback_data' => "t:{$flow}:out:m1100",
            ]]);
        }
        $this->sendMessage($chatId, ($which === 'in' ? 'Check-in' : 'Checkout') . " *{$pretty}*. Pick the hour, then minutes — or type `HH:MM`.", $kb);
    }

    private function askNewBookingTime(string $chatId, array $context, string $checkInDate): void {
        $this->askStayTime($chatId, $context, 'nb', 'in', $checkInDate);
    }

    private function askNewBookingCheckout(string $chatId, array $context, string $checkInTime): void {
        $inDate = (string)($context['check_in_date'] ?? date('Y-m-d'));
        $context['check_in'] = $inDate . ' ' . $checkInTime;
        $this->sendStayCalendar($chatId, $context, 'nb', 'out', date('Ym', strtotime($inDate . ' +1 day') ?: time()), 'NB_CHECKOUT');
    }

    private function finishNewBookingCheckoutTime(string $chatId, array $context, string $checkOutTime): void {
        $outDate = (string)($context['check_out_date'] ?? '');
        if ($outDate === '') {
            $this->sendMessage($chatId, "Pick a checkout date first.");
            return;
        }
        $context['check_out'] = $outDate . ' ' . $checkOutTime;
        $this->showAvailableRoomsForStay($chatId, $context, $outDate);
    }

    private function handleCalendarCallback(string $chatId, array $parsed): void {
        $session = $this->getSession($chatId);
        $context = $session ? (json_decode((string)$session['context_data'], true) ?: []) : [];
        $flow = $parsed['flow'];
        $which = $parsed['which'];
        $kind = $parsed['kind'];

        if ($kind === 'month') {
            $state = (string)($session['state'] ?? ($flow === 'eb' ? 'EB_CHECKIN' : 'NB_CHECKIN'));
            $this->sendStayCalendar($chatId, $context, $flow, $which, $parsed['year_month'], $state);
            return;
        }
        if ($kind === 'day') {
            $this->askStayTime($chatId, $context, $flow, $which, $parsed['date']);
            return;
        }
        if ($kind === 'hour') {
            $kb = TelegramCalendar::minuteKeyboard($flow, $which, (int)$parsed['hour']);
            $hh = sprintf('%02d', (int)$parsed['hour']);
            $this->sendMessage($chatId, "Hour *{$hh}:00*. Pick minutes:", $kb);
            return;
        }
        if ($kind === 'minute') {
            $time = sprintf('%02d:%02d:00', (int)$parsed['hour'], (int)$parsed['minute']);
            if ($flow === 'nb' && $which === 'in') {
                $this->askNewBookingCheckout($chatId, $context, $time);
                return;
            }
            if ($flow === 'nb' && $which === 'out') {
                $this->finishNewBookingCheckoutTime($chatId, $context, $time);
                return;
            }
            if ($flow === 'eb') {
                $this->applyEditTime($chatId, $context, $which, $time);
            }
        }
    }

    private function askNewBookingNights(string $chatId, array $context, string $checkInDate): void {
        $this->askNewBookingTime($chatId, $context, $checkInDate);
    }

    private function showAvailableRoomsForNewBooking(string $chatId, array $context, int $nights): void {
        $inDate = date('Y-m-d', strtotime((string)($context['check_in'] ?? $context['check_in_date'] ?? 'now')));
        $outDate = date('Y-m-d', strtotime($inDate . " +{$nights} days"));
        $this->showAvailableRoomsForStay($chatId, $context, $outDate);
    }

    private function showAvailableRoomsForStay(string $chatId, array $context, string $checkoutDate): void {
        $propertyId = (int)$context['property_id'];
        $checkIn = (string)($context['check_in'] ?? '');
        if ($checkIn === '') {
            $this->sendMessage($chatId, "Session expired. Type /start.");
            return;
        }
        $inDate = date('Y-m-d', strtotime($checkIn));
        if (!empty($context['check_out']) && strlen((string)$context['check_out']) > 10) {
            $checkOut = (string)$context['check_out'];
        } else {
            $outTime = ($checkoutDate === $inDate) ? '18:00:00' : '11:00:00';
            $checkOut = $checkoutDate . ' ' . $outTime;
        }
        if (strtotime($checkOut) <= strtotime($checkIn)) {
            $this->sendMessage(
                $chatId,
                "Checkout must be after check-in (" . date('d M Y, g:i A', strtotime($checkIn)) . "). Pick a later date or type `YYYY-MM-DD`."
            );
            return;
        }
        $nights = max(1, (int)round((strtotime($checkoutDate . ' 12:00:00') - strtotime($inDate . ' 12:00:00')) / 86400));
        $context['check_out'] = $checkOut;
        $context['nights'] = $nights;

        $rooms = $this->listAvailableRooms($propertyId, $checkIn, $checkOut);
        if (!$rooms) {
            $this->sendMessage($chatId, "No rooms free for that stay. Type /start and try other dates.");
            return;
        }
        $this->setSession($chatId, 'NB_ROOM', $context);
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($rooms as $r) {
            $row[] = ['text' => "{$r['room_number']} {$r['cat_name']}", 'callback_data' => 'nb_r' . $r['id']];
            if (count($row) === 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if ($row) {
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Cancel', 'callback_data' => 'main_menu']];
        $inShort = date('d M, g:i A', strtotime($checkIn));
        $outShort = date('d M', strtotime($checkOut));
        $nightLabel = $nights === 1 ? '1 night' : "{$nights} nights";
        $this->sendMessage($chatId, "Stay {$inShort} → {$outShort} ({$nightLabel}). Pick a room:", $keyboard);
    }

    private function confirmCreateBooking(string $chatId, int $roomId): void {
        $session = $this->getSession($chatId);
        if (!$session || $session['state'] !== 'NB_ROOM') {
            $this->sendMessage($chatId, "Session expired. Type /start.");
            return;
        }
        $context = json_decode((string)$session['context_data'], true) ?: [];
        $propertyId = (int)$context['property_id'];
        try {
            $guest = GuestService::findOrCreate($this->db, (string)$context['guest_name'], (string)$context['guest_phone'], $propertyId);
            $result = BookingService::createBooking($this->db, [
                'property_id' => $propertyId,
                'room_id' => $roomId,
                'guest_id' => $guest['guest_id'],
                'check_in' => $context['check_in'],
                'check_out' => $context['check_out'],
                'booking_source' => 'Telegram',
                'booking_status' => 'booked',
                'guest_name' => $context['guest_name'],
            ]);
            $context['booking_id'] = $result['booking_id'];
            $context['room_id'] = $roomId;
            $roomNo = $this->roomNumber($roomId, $propertyId);
            $this->setSession($chatId, 'NB_DONE', $context);
            $total = number_format((float)$result['total_amount'], 2);
            $inPretty = date('d M, g:i A', strtotime((string)$context['check_in']));
            $outPretty = date('d M', strtotime((string)$context['check_out']));
            $this->sendMessage(
                $chatId,
                "✅ Booking *{$result['display_id']}*\nGuest: {$context['guest_name']}\nRoom: {$roomNo}\nStay: {$inPretty} → {$outPretty}\nTotal: ₹{$total}",
                [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ Check in now', 'callback_data' => 'nb_ci'],
                            ['text' => '🪪 ID proof', 'callback_data' => 'nb_id'],
                        ],
                        [
                            ['text' => '💳 Collect payment', 'callback_data' => 'nb_pay'],
                            ['text' => '🔙 Menu', 'callback_data' => 'main_menu'],
                        ],
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Could not create booking: " . $e->getMessage());
        }
    }

    private function processQuickCheckIn(string $chatId, int $roomId): void {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) {
            return;
        }
        $booking = $this->activeStayForRoom($roomId, $propertyId, ['booked']);
        if (!$booking) {
            $this->sendMessage($chatId, "No booked stay found for that room.");
            return;
        }
        try {
            BookingService::checkIn($this->db, (int)$booking['id'], $propertyId, [
                'source' => 'telegram',
                'notify' => false,
            ]);
            $this->sendMessage($chatId, "✅ Room {$booking['room_number']} — {$booking['guest_name']} is checked in.", $this->menuKeyboard());
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Check-in failed: " . $e->getMessage());
        }
    }

    private function showEditMenu(string $chatId, int $roomId): void {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) {
            return;
        }
        $booking = $this->activeStayForRoom($roomId, $propertyId, ['booked', 'checked_in']);
        if (!$booking) {
            $this->sendMessage($chatId, "Stay not found.");
            return;
        }
        $ui = StayPolicy::ui($booking);
        $this->setSession($chatId, 'EB_MENU', [
            'property_id' => $propertyId,
            'booking_id' => $booking['id'],
            'room_id' => $roomId,
            'guest_id' => $booking['guest_id'],
            'room_name' => $booking['room_number'],
            'check_in' => $booking['check_in'],
            'check_out' => $booking['check_out'],
        ]);
        $rows = [];
        if ($ui['check_in'] || $ui['check_out']) {
            $rows[] = [['text' => '📅 Change dates', 'callback_data' => 'eb_dates']];
        }
        if ($ui['room']) {
            $rows[] = [['text' => '🛏 Change room', 'callback_data' => 'eb_chgroom']];
        }
        if ($ui['guest']) {
            $rows[] = [['text' => '👤 Guest name/phone', 'callback_data' => 'eb_guest']];
        }
        if ($ui['cancel']) {
            $rows[] = [['text' => '❌ Cancel booking', 'callback_data' => 'eb_cancel']];
        }
        $rows[] = [['text' => '🔙 Menu', 'callback_data' => 'main_menu']];
        $this->sendMessage(
            $chatId,
            "Edit Room {$booking['room_number']} — {$booking['guest_name']}\nIn: {$booking['check_in']}\nOut: {$booking['check_out']}"
            . ($ui['check_in'] ? '' : "\n_Check-in time is locked after arrival._"),
            ['inline_keyboard' => $rows]
        );
    }

    private function beginEditDates(string $chatId): void {
        $session = $this->getSession($chatId);
        if (!$session) {
            $this->sendMessage($chatId, "Session expired. Type /start.");
            return;
        }
        $context = json_decode((string)$session['context_data'], true) ?: [];
        $booking = $this->bookingRow((int)$context['booking_id'], (int)$context['property_id']);
        if (!$booking) {
            $this->sendMessage($chatId, "Booking not found.");
            return;
        }
        $ui = StayPolicy::ui($booking);
        $context['check_in'] = $booking['check_in'];
        $context['check_out'] = $booking['check_out'];
        $context['check_in_date'] = date('Y-m-d', strtotime((string)$booking['check_in']));
        $context['check_out_date'] = date('Y-m-d', strtotime((string)$booking['check_out']));
        if ($ui['check_in']) {
            $this->sendStayCalendar($chatId, $context, 'eb', 'in', date('Ym', strtotime((string)$booking['check_in']) ?: time()), 'EB_CHECKIN');
            return;
        }
        if ($ui['check_out']) {
            $this->sendStayCalendar($chatId, $context, 'eb', 'out', date('Ym', strtotime((string)$booking['check_out']) ?: time()), 'EB_CHECKOUT');
            return;
        }
        $this->sendMessage($chatId, "Dates cannot be changed on this stay.");
    }

    private function applyEditTime(string $chatId, array $context, string $which, string $time): void {
        if ($which === 'in') {
            $date = (string)($context['check_in_date'] ?? date('Y-m-d', strtotime((string)($context['check_in'] ?? 'now'))));
            $context['check_in'] = $date . ' ' . $time;
            $this->sendStayCalendar($chatId, $context, 'eb', 'out', date('Ym', strtotime($date . ' +1 day') ?: time()), 'EB_CHECKOUT');
            return;
        }
        $date = (string)($context['check_out_date'] ?? date('Y-m-d', strtotime((string)($context['check_out'] ?? 'now'))));
        $context['check_out'] = $date . ' ' . $time;
        $this->applyEditDates($chatId, $context, (string)$context['check_in'], (string)$context['check_out']);
    }

    private function applyEditDates(string $chatId, array $context, string $checkIn, string $checkOut): void {
        try {
            $result = BookingService::reschedule(
                $this->db,
                (int)$context['booking_id'],
                (int)$context['property_id'],
                $checkIn,
                $checkOut
            );
            $this->clearSession($chatId);
            $total = number_format((float)$result['new_total'], 2);
            $this->sendMessage($chatId, "✅ Stay updated.\nRoom {$result['room_number']}\n{$result['check_in']} → {$result['check_out']}\nNew total: ₹{$total}", $this->menuKeyboard());
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Could not update dates: " . $e->getMessage());
        }
    }

    private function beginCancelStay(string $chatId): void {
        $session = $this->getSession($chatId);
        if (!$session) {
            $this->sendMessage($chatId, "Session expired. Type /start.");
            return;
        }
        $context = json_decode((string)$session['context_data'], true) ?: [];
        $booking = $this->bookingRow((int)$context['booking_id'], (int)$context['property_id']);
        if (!$booking) {
            $this->sendMessage($chatId, "Booking not found.");
            return;
        }
        StayPolicy::assert($booking, StayPolicy::CANCEL);
        $this->setSession($chatId, 'EB_CANCEL', $context);
        $this->sendMessage($chatId, "Type the cancellation reason for Room {$context['room_name']}:");
    }

    private function beginChangeRoom(string $chatId): void {
        $session = $this->getSession($chatId);
        if (!$session) {
            $this->sendMessage($chatId, "Session expired. Type /start.");
            return;
        }
        $context = json_decode((string)$session['context_data'], true) ?: [];
        $booking = $this->bookingRow((int)$context['booking_id'], (int)$context['property_id']);
        if (!$booking) {
            $this->sendMessage($chatId, "Booking not found.");
            return;
        }
        StayPolicy::assert($booking, StayPolicy::ROOM);
        $rooms = $this->listAvailableRooms((int)$context['property_id'], (string)$booking['check_in'], (string)$booking['check_out'], (int)$booking['id']);
        if (!$rooms) {
            $this->sendMessage($chatId, "No other rooms free for this stay.");
            return;
        }
        $this->setSession($chatId, 'EB_ROOM', $context);
        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($rooms as $r) {
            $row[] = ['text' => "{$r['room_number']} {$r['cat_name']}", 'callback_data' => 'eb_r' . $r['id']];
            if (count($row) === 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if ($row) {
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Cancel', 'callback_data' => 'main_menu']];
        $this->sendMessage($chatId, "Pick the new room:", $keyboard);
    }

    private function applyChangeRoom(string $chatId, int $roomId): void {
        $session = $this->getSession($chatId);
        if (!$session) {
            $this->sendMessage($chatId, "Session expired. Type /start.");
            return;
        }
        $context = json_decode((string)$session['context_data'], true) ?: [];
        $booking = $this->bookingRow((int)$context['booking_id'], (int)$context['property_id']);
        if (!$booking) {
            $this->sendMessage($chatId, "Booking not found.");
            return;
        }
        try {
            $result = BookingService::reschedule(
                $this->db,
                (int)$booking['id'],
                (int)$context['property_id'],
                (string)$booking['check_in'],
                (string)$booking['check_out'],
                $roomId
            );
            $this->clearSession($chatId);
            $this->sendMessage($chatId, "✅ Moved to room {$result['room_number']}. New total ₹" . number_format((float)$result['new_total'], 2), $this->menuKeyboard());
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Could not change room: " . $e->getMessage());
        }
    }

    private function beginEditGuest(string $chatId): void {
        $session = $this->getSession($chatId);
        if (!$session) {
            $this->sendMessage($chatId, "Session expired. Type /start.");
            return;
        }
        $context = json_decode((string)$session['context_data'], true) ?: [];
        $this->setSession($chatId, 'EB_GUEST', $context);
        $this->sendMessage($chatId, "Send guest details as:\n`Name, 9876543210`");
    }

    private function applyEditGuest(string $chatId, array $context, string $text): void {
        $name = $text;
        $phone = '';
        if (preg_match('/^(.+?)[, ]+(\d{10,15})\s*$/', trim($text), $m)) {
            $name = trim($m[1]);
            $phone = (string)$m[2];
        }
        if (strlen($name) < 2) {
            $this->sendMessage($chatId, "Send `Name, 9876543210`.");
            return;
        }
        $guestId = (int)($context['guest_id'] ?? 0);
        $propertyId = (int)$context['property_id'];
        if ($guestId <= 0) {
            $this->sendMessage($chatId, "Guest not found.");
            return;
        }
        try {
            $sets = ['name = :name'];
            $params = ['name' => $name, 'id' => $guestId, 'pid' => $propertyId];
            if ($phone !== '') {
                $local = PhoneHelper::toLocal($phone);
                if (!$local) {
                    $this->sendMessage($chatId, "Invalid phone number.");
                    return;
                }
                $sets[] = 'phone = :phone';
                $params['phone'] = $local;
            }
            $sql = 'UPDATE guests SET ' . implode(', ', $sets) . ' WHERE id = :id AND property_id = :pid';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $this->clearSession($chatId);
            $this->sendMessage($chatId, "✅ Guest updated to *{$name}*.", $this->menuKeyboard());
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Could not update guest: " . $e->getMessage());
        }
    }

    private function beginIdCapture(string $chatId, int $roomId): void {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) {
            return;
        }
        $booking = $this->activeStayForRoom($roomId, $propertyId, ['booked', 'checked_in']);
        if (!$booking) {
            $this->sendMessage($chatId, "Stay not found.");
            return;
        }
        $this->setSession($chatId, 'ID_FRONT', [
            'property_id' => $propertyId,
            'booking_id' => $booking['id'],
            'guest_id' => $booking['guest_id'],
            'room_name' => $booking['room_number'],
        ]);
        $this->sendMessage($chatId, "Send a *photo* of the ID *front* for {$booking['guest_name']} (Room {$booking['room_number']}).");
    }

    private function handleIncomingMedia(string $chatId, array $message): bool {
        $session = $this->getSession($chatId);
        if (!$session) {
            return false;
        }
        $state = (string)$session['state'];
        if ($state !== 'ID_FRONT' && $state !== 'ID_BACK') {
            return false;
        }
        $fileId = $this->telegramFileIdFromMessage($message);
        if ($fileId === '') {
            $this->sendMessage($chatId, "Please send a photo or an image/PDF file.");
            return true;
        }
        $context = json_decode((string)$session['context_data'], true) ?: [];
        $side = $state === 'ID_FRONT' ? 'id_proof_front' : 'id_proof_back';
        try {
            $saved = $this->storeTelegramIdFile((int)$context['guest_id'], (int)$context['property_id'], $fileId, $side);
            if ($state === 'ID_FRONT') {
                $this->setSession($chatId, 'ID_BACK', $context);
                $this->sendMessage($chatId, "✅ Front saved (`{$saved}`).\nNow send the ID *back* photo, or skip.", [
                    'inline_keyboard' => [
                        [['text' => 'Skip back', 'callback_data' => 'id_skip']],
                        [['text' => '🔙 Menu', 'callback_data' => 'main_menu']],
                    ],
                ]);
            } else {
                $this->clearSession($chatId);
                $this->sendMessage($chatId, "✅ Back saved (`{$saved}`). ID proof is on the guest folio.", $this->menuKeyboard());
            }
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Could not save ID: " . $e->getMessage());
        }
        return true;
    }

    private function skipIdBack(string $chatId): void {
        $this->clearSession($chatId);
        $this->sendMessage($chatId, "Front ID kept. Back side skipped.", $this->menuKeyboard());
    }

    private function createOnlinePaymentLink(string $chatId, array $context, float $amount): void {
        $method = strtolower((string)$context['method']);
        $bookingId = (int)$context['booking_id'];
        $propertyId = (int)$context['property_id'];
        $booking = $this->bookingRow($bookingId, $propertyId);
        if (!$booking) {
            $this->sendMessage($chatId, "Booking not found.");
            return;
        }
        try {
            load_db_settings($this->db, $propertyId);
            $link = '';
            $label = '';
            if (in_array($method, ['razorpay', 'rzp'], true)) {
                $label = 'Razorpay';
                $rz = RazorpayService::forProperty($this->db, $propertyId);
                if (!$rz) {
                    throw new \Exception('Razorpay is not configured in Settings → Payments.');
                }
                $guestUrl = GuestAccessToken::getPortalUrl($bookingId);
                $phone = PhoneHelper::toE164((string)($booking['guest_phone'] ?? '')) ?? (string)($booking['guest_phone'] ?? '');
                $res = $rz->createPaymentLink([
                    'amount' => (int)round($amount * 100),
                    'currency' => 'INR',
                    'accept_partial' => false,
                    'description' => 'Stay payment #' . $bookingId,
                    'customer' => [
                        'name' => (string)($booking['guest_name'] ?? 'Guest'),
                        'contact' => $phone,
                    ],
                    'notify' => ['sms' => false, 'email' => false],
                    'reminder_enable' => false,
                    'callback_url' => $guestUrl,
                    'callback_method' => 'get',
                ]);
                if (empty($res['success']) || empty($res['short_url'])) {
                    throw new \Exception($res['error'] ?? 'Razorpay link failed');
                }
                $link = (string)$res['short_url'];
            } else {
                $label = 'PhonePe';
                $pp = PhonePeService::forProperty($this->db, $propertyId);
                if (!$pp) {
                    throw new \Exception('PhonePe is not configured in Settings → Payments.');
                }
                $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                $scheme = $https ? 'https' : 'http';
                $redirectUrl = GuestAccessToken::getPortalUrl($bookingId);
                $callbackUrl = ($host !== '' ? ($scheme . '://' . $host) : '') . '/webhook_phonepe';
                $txn = 'tg_' . $bookingId . '_' . time();
                $res = $pp->initiatePayment((int)round($amount * 100), $txn, $callbackUrl, $redirectUrl, (string)($booking['guest_phone'] ?? ''));
                if (empty($res['success']) || empty($res['redirect_url'])) {
                    throw new \Exception($res['error'] ?? 'PhonePe link failed');
                }
                $link = (string)$res['redirect_url'];
            }

            $waPhone = PhoneHelper::toE164((string)($booking['guest_phone'] ?? '')) ?? '';
            if ($waPhone !== '') {
                $msg = "Hi {$booking['guest_name']}, please pay Rs.{$amount} for your stay: {$link}";
                try {
                    NotificationRelay::triggerAutomation('payment_link', $waPhone, $bookingId, [
                        'payment_link' => $link,
                        'balance_amount' => number_format($amount, 2),
                    ], $propertyId);
                } catch (\Throwable $t) {
                    // ignore
                }
            }

            $this->clearSession($chatId);
            $this->sendMessage(
                $chatId,
                "{$label} collect ₹" . number_format($amount, 2) . " for Room {$context['room_name']}.\n\nOpen or forward this link:\n{$link}",
                $this->menuKeyboard(),
                null
            );
        } catch (\Throwable $e) {
            $this->sendMessage($chatId, "Could not start {$method} collect: " . $e->getMessage(), [], null);
        }
    }

    private function continueAfterCreate(string $chatId, string $next): void {
        $session = $this->getSession($chatId);
        $context = $session ? (json_decode((string)$session['context_data'], true) ?: []) : [];
        $roomId = (int)($context['room_id'] ?? 0);
        if ($next === 'ci') {
            if ($roomId) {
                $this->processQuickCheckIn($chatId, $roomId);
            }
            return;
        }
        if ($next === 'id') {
            if ($roomId) {
                $this->beginIdCapture($chatId, $roomId);
            }
            return;
        }
        if ($next === 'pay') {
            if ($roomId) {
                $this->askPaymentMethod($chatId, $roomId);
            }
            return;
        }
    }

    private function isOnlineGateway(string $method): bool {
        return in_array(strtolower($method), ['razorpay', 'rzp', 'phonepe', 'ppe', 'phone_pe'], true);
    }

    private function parseDeskDate(string $text): ?string {
        $text = trim($text);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $text, $m)) {
            return $m[1];
        }
        $ts = strtotime($text);
        if ($ts) {
            return date('Y-m-d', $ts);
        }
        return null;
    }

    private function parseDeskTime(string $text, string $checkInDate): ?string {
        $text = strtolower(trim($text));
        if ($text === 'now' || $text === 'now()') {
            return $this->checkInTimeNow($checkInDate);
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $text, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d:00', $h, $min);
            }
        }
        if (preg_match('/^(\d{1,2})\s*(am|pm)$/', $text, $m)) {
            $h = (int)$m[1];
            if ($h < 1 || $h > 12) {
                return null;
            }
            if ($m[2] === 'pm' && $h !== 12) {
                $h += 12;
            }
            if ($m[2] === 'am' && $h === 12) {
                $h = 0;
            }
            return sprintf('%02d:00:00', $h);
        }
        return null;
    }

    private function checkInTimeNow(string $checkInDate): string {
        if ($checkInDate === date('Y-m-d')) {
            return date('H:i:00');
        }
        return '14:00:00';
    }

    private function timeFromCallback(string $data, string $checkInDate): ?string {
        if ($data === 'nb_tnow') {
            return $this->checkInTimeNow($checkInDate);
        }
        if (preg_match('/^nb_t(\d{2})(\d{2})$/', $data, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d:00', $h, $min);
            }
        }
        return null;
    }

    private function listAvailableRooms(int $propertyId, string $checkIn, string $checkOut, ?int $excludeBookingId = null): array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.room_number, c.name as cat_name
            FROM rooms r
            JOIN room_categories c ON c.id = r.category_id
            WHERE r.property_id = ?
              AND IFNULL(r.state, '') != 'out_of_order'
            ORDER BY r.room_number ASC
        ");
        $stmt->execute([$propertyId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $room) {
            if (BookingService::isRoomAvailable($this->db, (int)$room['id'], $checkIn, $checkOut, $excludeBookingId, $propertyId)) {
                $out[] = $room;
            }
        }
        return $out;
    }

    private function activeStayForRoom(int $roomId, int $propertyId, array $statuses): ?array {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $params = array_merge([$roomId, $propertyId], $statuses);
        $stmt = $this->db->prepare("
            SELECT b.*, r.room_number, g.name as guest_name, g.id as guest_id, g.phone as guest_phone
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN guests g ON g.id = b.guest_id
            WHERE r.id = ? AND b.property_id = ? AND b.booking_status IN ($placeholders)
              AND b.payment_status != 'cancelled'
            ORDER BY b.id DESC
            LIMIT 1
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function bookingRow(int $bookingId, int $propertyId): ?array {
        $stmt = $this->db->prepare("
            SELECT b.*, r.room_number, g.name as guest_name, g.phone as guest_phone
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN guests g ON g.id = b.guest_id
            WHERE b.id = ? AND b.property_id = ?
        ");
        $stmt->execute([$bookingId, $propertyId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function roomNumber(int $roomId, int $propertyId): string {
        $stmt = $this->db->prepare("SELECT room_number FROM rooms WHERE id = ? AND property_id = ?");
        $stmt->execute([$roomId, $propertyId]);
        return (string)($stmt->fetchColumn() ?: $roomId);
    }

    private function menuKeyboard(): array {
        return ['inline_keyboard' => [[['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]]];
    }

    private function telegramFileIdFromMessage(array $message): string {
        if (!empty($message['photo']) && is_array($message['photo'])) {
            $best = $message['photo'][count($message['photo']) - 1];
            return (string)($best['file_id'] ?? '');
        }
        $doc = $message['document'] ?? [];
        $mime = strtolower((string)($doc['mime_type'] ?? ''));
        if ($doc && (str_starts_with($mime, 'image/') || $mime === 'application/pdf')) {
            return (string)($doc['file_id'] ?? '');
        }
        return '';
    }

    private function storeTelegramIdFile(int $guestId, int $propertyId, string $fileId, string $column): string {
        $allowed = ['id_proof_front', 'id_proof_back', 'photo'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid ID side');
        }
        $guestStmt = $this->db->prepare("SELECT id FROM guests WHERE id = ? AND property_id = ?");
        $guestStmt->execute([$guestId, $propertyId]);
        if (!$guestStmt->fetchColumn()) {
            throw new \Exception('Guest not found');
        }

        $metaRaw = $this->telegramApiGet('getFile', ['file_id' => $fileId]);
        $meta = json_decode($metaRaw, true);
        $filePath = (string)($meta['result']['file_path'] ?? '');
        if ($filePath === '') {
            throw new \Exception('Telegram did not return a file path');
        }
        $url = 'https://api.telegram.org/file/bot' . $this->botToken . '/' . $filePath;
        $bin = $this->httpGetBinary($url);
        if ($bin === '') {
            throw new \Exception('Could not download the photo from Telegram');
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf', 'webp'], true)) {
            $ext = 'jpg';
        }
        $uploadDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new \Exception('Upload directory is missing');
        }
        $filename = uniqid($column . '_', true) . '.' . $ext;
        if (file_put_contents($uploadDir . '/' . $filename, $bin) === false) {
            throw new \Exception('Failed to write ID file');
        }
        $upd = $this->db->prepare("UPDATE guests SET `{$column}` = :fn WHERE id = :id AND property_id = :pid");
        $upd->execute(['fn' => $filename, 'id' => $guestId, 'pid' => $propertyId]);
        return $filename;
    }

    private function telegramApiGet(string $method, array $query): string {
        $url = 'https://api.telegram.org/bot' . $this->botToken . '/' . $method . '?' . http_build_query($query);
        return $this->httpGetBinary($url);
    }

    private function httpGetBinary(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $out = curl_exec($ch);
        curl_close($ch);
        return is_string($out) ? $out : '';
    }
}
