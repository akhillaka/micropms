<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';

class TelegramOperationsHandler {
    private $botToken;
    private $allowedChatIds;
    private $db;
    private ?int $defaultPropertyId;

    public function __construct(string $botToken, array $allowedChatIds, ?int $defaultPropertyId = null) {
        $this->botToken = $botToken;
        $this->allowedChatIds = array_values(array_filter(array_map('strval', $allowedChatIds)));
        $this->db = Database::getInstance()->getConnection();
        $this->defaultPropertyId = $defaultPropertyId;
    }

    /**
     * Entry point for incoming webhook data
     */
    public function handleWebhook(array $update) {
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        } elseif (isset($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    private function isAuthorized(string $chatId): bool {
        return in_array($chatId, $this->allowedChatIds);
    }

    private function handleMessage(array $message) {
        $chatId = (string)($message['chat']['id'] ?? '');
        $text = trim($message['text'] ?? '');

        if (!$this->isAuthorized($chatId)) {
            $this->sendMessage($chatId, "Unauthorized access.");
            return;
        }

        if ($text === '/start') {
            $this->clearSession($chatId);
            $this->sendMainMenu($chatId);
            return;
        }

        if ($text === '/report') {
            if ($this->getUserRole($chatId) !== 'admin') {
                $this->sendMessage($chatId, "Unauthorized. Only admins can request reports.");
                return;
            }
            $this->handleReportCommand($chatId);
            return;
        }

        // Check if there is an active session state
        $session = $this->getSession($chatId);
        if ($session) {
            $this->handleStatefulInput($chatId, $text, $session);
        } else {
            $this->sendMessage($chatId, "I didn't understand that. Type /start to open the menu.");
        }
    }

    private function handleCallbackQuery(array $callbackQuery) {
        $chatId = (string)($callbackQuery['message']['chat']['id'] ?? '');
        $data = $callbackQuery['data'] ?? '';
        $callbackQueryId = $callbackQuery['id'] ?? '';

        if (!$this->isAuthorized($chatId)) {
            $this->answerCallbackQuery($callbackQueryId, "Unauthorized.");
            return;
        }

        // Answer callback to remove loading state on button
        $this->answerCallbackQuery($callbackQueryId);

        if ($data === 'main_menu') {
            $this->clearSession($chatId);
            $this->sendMainMenu($chatId);
            return;
        }

        if ($data === 'cmd_room_status') {
            $this->handleRoomStatus($chatId);
        } elseif ($data === 'cmd_add_payment') {
            $this->startAddPaymentFlow($chatId);
        } elseif (strpos($data, 'pay_room_') === 0) {
            $roomId = (int)str_replace('pay_room_', '', $data);
            $this->askPaymentMethod($chatId, $roomId);
        } elseif (strpos($data, 'pay_method_') === 0) {
            $method = str_replace('pay_method_', '', $data);
            $this->askPaymentAmount($chatId, $method);
        } elseif ($data === 'cmd_quick_checkout') {
            $this->startQuickCheckoutFlow($chatId);
        } elseif (strpos($data, 'checkout_room_') === 0) {
            $roomId = (int)str_replace('checkout_room_', '', $data);
            $this->processQuickCheckout($chatId, $roomId);
        } elseif ($data === 'cmd_extend_stay') {
            $this->startExtendStayFlow($chatId);
        } elseif (strpos($data, 'extend_room_') === 0) {
            $roomId = (int)str_replace('extend_room_', '', $data);
            $this->askExtendStayDays($chatId, $roomId);
        } elseif ($data === 'cmd_today_revenue') {
            $this->handleTodayRevenue($chatId);
        } elseif ($data === 'cmd_arrivals_today') {
            $this->handleArrivalsToday($chatId);
        } elseif ($data === 'cmd_departures_today') {
            $this->handleDeparturesToday($chatId);
        } elseif ($data === 'cmd_mark_room_clean') {
            $this->startMarkRoomCleanFlow($chatId);
        } elseif (strpos($data, 'clean_room_') === 0) {
            $roomId = (int)str_replace('clean_room_', '', $data);
            $this->processMarkRoomClean($chatId, $roomId);
        } else {
            $this->sendMessage($chatId, "Unknown command.");
        }
    }

    private function handleStatefulInput(string $chatId, string $text, array $session) {
        if ($session['state'] === 'AWAITING_PAYMENT_AMOUNT') {
            $amount = (float)$text;
            if ($amount <= 0) {
                $this->sendMessage($chatId, "Please enter a valid amount greater than 0.");
                return;
            }

            $context = json_decode($session['context_data'], true);
            $roomId = $context['room_id'];
            $method = $context['method'];
            $bookingId = $context['booking_id'];
            $propertyId = $context['property_id'];

            // Add payment to folio_ledger
            try {
                $stmt = $this->db->prepare("INSERT INTO folio_ledger (property_id, booking_id, description, amount, ledger_type, recorded_by) VALUES (?, ?, ?, ?, 'payment', 'Telegram Bot')");
                // Payment is negative amount in the folio logic
                $stmt->execute([$propertyId, $bookingId, "Payment via Telegram ($method)", -$amount]);
                
                $this->clearSession($chatId);
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]
                    ]
                ];
                $this->sendMessage($chatId, "✅ Successfully added payment of $amount to Room {$context['room_name']} via $method.", $keyboard);
            } catch (\Exception $e) {
                $this->sendMessage($chatId, "Error adding payment: " . $e->getMessage());
            }
        } elseif ($session['state'] === 'AWAITING_EXTEND_DAYS') {
            $days = (int)$text;
            if ($days <= 0) {
                $this->sendMessage($chatId, "Please enter a valid number of days greater than 0.");
                return;
            }

            $context = json_decode($session['context_data'], true);
            $roomId = $context['room_id'];
            $bookingId = $context['booking_id'];
            $propertyId = $context['property_id'];

            try {
                // Get current check_out
                $stmt = $this->db->prepare("SELECT check_out FROM bookings WHERE id = ? AND property_id = ?");
                $stmt->execute([$bookingId, $propertyId]);
                $booking = $stmt->fetch();
                
                $currentCheckOut = new DateTime($booking['check_out']);
                $currentCheckOut->modify("+$days days");
                $newCheckOut = $currentCheckOut->format('Y-m-d');

                $stmt = $this->db->prepare("UPDATE bookings SET check_out = ? WHERE id = ? AND property_id = ?");
                $stmt->execute([$newCheckOut, $bookingId, $propertyId]);

                // Clear session
                $this->clearSession($chatId);
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]
                    ]
                ];
                $this->sendMessage($chatId, "✅ Successfully extended Room {$context['room_name']} by $days day(s). New Check-out: $newCheckOut.", $keyboard);
            } catch (\Exception $e) {
                $this->sendMessage($chatId, "Error extending stay: " . $e->getMessage());
            }
        }
    }

    private function sendMainMenu(string $chatId) {
        $text = "🏨 *MicroPMS Operations Menu*\nPlease choose an action:";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🛏 Room Status', 'callback_data' => 'cmd_room_status'],
                    ['text' => '💰 Add Payment', 'callback_data' => 'cmd_add_payment']
                ],
                [
                    ['text' => '🚪 Quick Check-Out', 'callback_data' => 'cmd_quick_checkout'],
                    ['text' => '📅 Extend Stay', 'callback_data' => 'cmd_extend_stay']
                ],
                [
                    ['text' => '📊 Today\'s Revenue', 'callback_data' => 'cmd_today_revenue'],
                    ['text' => '🧽 Mark Room Clean', 'callback_data' => 'cmd_mark_room_clean']
                ],
                [
                    ['text' => '🛬 Arrivals Today', 'callback_data' => 'cmd_arrivals_today'],
                    ['text' => '🛫 Departures Today', 'callback_data' => 'cmd_departures_today']
                ]
            ]
        ];
        $this->sendMessage($chatId, $text, $keyboard);
    }

    private function handleRoomStatus(string $chatId) {
        // Assume property_id = 1 for the bot for now, or derive it from the allowed chat ID if we map it
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        $stmt = $this->db->prepare("
            SELECT r.room_number, b.guest_id, g.name as guest_name
            FROM rooms r
            LEFT JOIN bookings b ON r.id = b.room_id AND b.booking_status = 'checked_in'
            LEFT JOIN guests g ON b.guest_id = g.id
            WHERE r.property_id = ?
            ORDER BY r.room_number ASC
        ");
        $stmt->execute([$propertyId]);
        $rooms = $stmt->fetchAll();

        $text = "🛏 *Current Room Status*\n\n";
        $occupied = 0;
        $total = count($rooms);
        foreach ($rooms as $r) {
            if ($r['guest_name']) {
                $occupied++;
                $text .= "🔴 Room {$r['room_number']}: {$r['guest_name']}\n";
            } else {
                $text .= "🟢 Room {$r['room_number']}: Available\n";
            }
        }
        $text .= "\nOccupancy: $occupied / $total";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]
            ]
        ];
        $this->sendMessage($chatId, $text, $keyboard);
    }

    private function startAddPaymentFlow(string $chatId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        // Fetch checked in rooms
        $stmt = $this->db->prepare("
            SELECT b.id as booking_id, r.id as room_id, r.room_number, g.name 
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN guests g ON b.guest_id = g.id
            WHERE b.property_id = ? AND b.booking_status = 'checked_in'
        ");
        $stmt->execute([$propertyId]);
        $bookings = $stmt->fetchAll();

        if (empty($bookings)) {
            $this->sendMessage($chatId, "No checked-in rooms found to add payments.");
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($bookings as $b) {
            $row[] = ['text' => "Room {$b['room_number']}", 'callback_data' => 'pay_room_' . $b['room_id']];
            if (count($row) === 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Cancel', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, "Select the room to add a payment:", $keyboard);
    }

    private function askPaymentMethod(string $chatId, int $roomId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;
        
        $stmt = $this->db->prepare("
            SELECT b.id, r.room_number FROM bookings b JOIN rooms r ON b.room_id = r.id 
            WHERE r.id = ? AND b.booking_status = 'checked_in' AND b.property_id = ?
        ");
        $stmt->execute([$roomId, $propertyId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $this->sendMessage($chatId, "Booking not found.");
            return;
        }

        $this->setSession($chatId, 'AWAITING_PAYMENT_METHOD', [
            'room_id' => $roomId,
            'room_name' => $booking['room_number'],
            'booking_id' => $booking['id'],
            'property_id' => $propertyId
        ]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💵 Cash', 'callback_data' => 'pay_method_Cash'],
                    ['text' => '📱 UPI', 'callback_data' => 'pay_method_UPI']
                ],
                [
                    ['text' => '💳 Card', 'callback_data' => 'pay_method_Card'],
                    ['text' => '🏦 Bank Transfer', 'callback_data' => 'pay_method_Bank Transfer']
                ],
                [['text' => '🔙 Cancel', 'callback_data' => 'main_menu']]
            ]
        ];

        $this->sendMessage($chatId, "Select payment method for Room {$booking['room_number']}:", $keyboard);
    }

    private function askPaymentAmount(string $chatId, string $method) {
        $session = $this->getSession($chatId);
        if (!$session || $session['state'] !== 'AWAITING_PAYMENT_METHOD') {
            $this->sendMessage($chatId, "Session expired. Type /start.");
            return;
        }

        $context = json_decode($session['context_data'], true);
        $context['method'] = $method;

        $this->setSession($chatId, 'AWAITING_PAYMENT_AMOUNT', $context);

        $this->sendMessage($chatId, "Method selected: $method\n\nPlease type the amount received (e.g. 1500):");
    }

    private function startQuickCheckoutFlow(string $chatId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        // Fetch checked in rooms
        $stmt = $this->db->prepare("
            SELECT b.id as booking_id, r.id as room_id, r.room_number, g.name 
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN guests g ON b.guest_id = g.id
            WHERE b.property_id = ? AND b.booking_status = 'checked_in'
        ");
        $stmt->execute([$propertyId]);
        $bookings = $stmt->fetchAll();

        if (empty($bookings)) {
            $this->sendMessage($chatId, "No checked-in rooms found to check out.");
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($bookings as $b) {
            $row[] = ['text' => "Room {$b['room_number']}", 'callback_data' => 'checkout_room_' . $b['room_id']];
            if (count($row) === 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Cancel', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, "Select the room to check out:", $keyboard);
    }

    private function processQuickCheckout(string $chatId, int $roomId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;
        
        $stmt = $this->db->prepare("
            SELECT b.id, r.room_number, b.total_amount 
            FROM bookings b JOIN rooms r ON b.room_id = r.id 
            WHERE r.id = ? AND b.booking_status = 'checked_in' AND b.property_id = ?
        ");
        $stmt->execute([$roomId, $propertyId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $this->sendMessage($chatId, "Booking not found.");
            return;
        }

        $bookingId = $booking['id'];

        // Calculate balance
        $stmt = $this->db->prepare("SELECT SUM(amount) FROM folio_ledger WHERE booking_id = ?");
        $stmt->execute([$bookingId]);
        $balance = (float)$stmt->fetchColumn();

        if ($balance > 0.01) {
            $this->sendMessage($chatId, "⚠️ Cannot check out Room {$booking['room_number']}. There is a pending balance of ₹" . number_format($balance, 2) . ". Please add a payment first.");
            return;
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE bookings SET booking_status = 'checked_out' WHERE id = ? AND property_id = ?")->execute([$bookingId, $propertyId]);
            $this->db->prepare("UPDATE rooms SET hk_status = 'dirty' WHERE id = ?")->execute([$roomId]);
            $this->db->commit();

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]
                ]
            ];
            $this->sendMessage($chatId, "✅ Room {$booking['room_number']} has been successfully checked out and marked as dirty.", $keyboard);

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->sendMessage($chatId, "Error processing check-out: " . $e->getMessage());
        }
    }

    private function startExtendStayFlow(string $chatId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        // Fetch checked in rooms
        $stmt = $this->db->prepare("
            SELECT b.id as booking_id, r.id as room_id, r.room_number, g.name 
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN guests g ON b.guest_id = g.id
            WHERE b.property_id = ? AND b.booking_status = 'checked_in'
        ");
        $stmt->execute([$propertyId]);
        $bookings = $stmt->fetchAll();

        if (empty($bookings)) {
            $this->sendMessage($chatId, "No checked-in rooms found to extend.");
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($bookings as $b) {
            $row[] = ['text' => "Room {$b['room_number']}", 'callback_data' => 'extend_room_' . $b['room_id']];
            if (count($row) === 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Cancel', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, "Select the room to extend:", $keyboard);
    }

    private function askExtendStayDays(string $chatId, int $roomId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;
        
        $stmt = $this->db->prepare("
            SELECT b.id, r.room_number, b.check_out 
            FROM bookings b JOIN rooms r ON b.room_id = r.id 
            WHERE r.id = ? AND b.booking_status = 'checked_in' AND b.property_id = ?
        ");
        $stmt->execute([$roomId, $propertyId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $this->sendMessage($chatId, "Booking not found.");
            return;
        }

        $this->setSession($chatId, 'AWAITING_EXTEND_DAYS', [
            'room_id' => $roomId,
            'room_name' => $booking['room_number'],
            'booking_id' => $booking['id'],
            'property_id' => $propertyId
        ]);

        $this->sendMessage($chatId, "Room {$booking['room_number']} currently checks out on {$booking['check_out']}.\n\nPlease type the number of days to extend (e.g. 1):");
    }

    private function handleTodayRevenue(string $chatId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ABS(amount)), 0) as rev 
            FROM folio_ledger 
            WHERE property_id = ? AND ledger_type = 'payment' AND DATE(recorded_at) = ?
        ");
        $stmt->execute([$propertyId, $today]);
        $revenue = $stmt->fetchColumn();

        $text = "📊 *Today's Revenue*\nTotal payments collected today: ₹" . number_format((float)$revenue, 2);
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]
            ]
        ];
        $this->sendMessage($chatId, $text, $keyboard);
    }

    private function handleArrivalsToday(string $chatId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT b.id, r.room_number, g.name 
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN guests g ON b.guest_id = g.id 
            WHERE b.property_id = ? AND b.check_in = ? AND b.booking_status = 'confirmed'
        ");
        $stmt->execute([$propertyId, $today]);
        $arrivals = $stmt->fetchAll();

        $text = "🛬 *Arrivals Today ($today)*\n\n";
        if (empty($arrivals)) {
            $text .= "No arrivals scheduled for today.";
        } else {
            foreach ($arrivals as $a) {
                $text .= "Room {$a['room_number']}: {$a['name']}\n";
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]
            ]
        ];
        $this->sendMessage($chatId, $text, $keyboard);
    }

    private function handleDeparturesToday(string $chatId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        $today = date('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT b.id, r.room_number, g.name 
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN guests g ON b.guest_id = g.id 
            WHERE b.property_id = ? AND b.check_out = ? AND b.booking_status = 'checked_in'
        ");
        $stmt->execute([$propertyId, $today]);
        $departures = $stmt->fetchAll();

        $text = "🛫 *Departures Today ($today)*\n\n";
        if (empty($departures)) {
            $text .= "No departures scheduled for today.";
        } else {
            foreach ($departures as $d) {
                $text .= "Room {$d['room_number']}: {$d['name']}\n";
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]
            ]
        ];
        $this->sendMessage($chatId, $text, $keyboard);
    }

    private function startMarkRoomCleanFlow(string $chatId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        $stmt = $this->db->prepare("SELECT id, room_number FROM rooms WHERE property_id = ? AND hk_status = 'dirty'");
        $stmt->execute([$propertyId]);
        $rooms = $stmt->fetchAll();

        if (empty($rooms)) {
            $this->sendMessage($chatId, "No dirty rooms found.");
            return;
        }

        $keyboard = ['inline_keyboard' => []];
        $row = [];
        foreach ($rooms as $r) {
            $row[] = ['text' => "Room {$r['room_number']}", 'callback_data' => 'clean_room_' . $r['id']];
            if (count($row) === 2) {
                $keyboard['inline_keyboard'][] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard['inline_keyboard'][] = $row;
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 Cancel', 'callback_data' => 'main_menu']];

        $this->sendMessage($chatId, "Select a dirty room to mark as clean:", $keyboard);
    }

    private function processMarkRoomClean(string $chatId, int $roomId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        $stmt = $this->db->prepare("UPDATE rooms SET hk_status = 'clean' WHERE id = ? AND property_id = ?");
        $stmt->execute([$roomId, $propertyId]);

        if ($stmt->rowCount() > 0) {
            $stmt = $this->db->prepare("SELECT room_number FROM rooms WHERE id = ?");
            $stmt->execute([$roomId]);
            $roomNumber = $stmt->fetchColumn();

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 Main Menu', 'callback_data' => 'main_menu']]
                ]
            ];
            $this->sendMessage($chatId, "✅ Room $roomNumber has been marked as clean.", $keyboard);
        } else {
            $this->sendMessage($chatId, "Failed to update room status or room not found.");
        }
    }

    // --- State Machine Helpers ---

    private function getSession(string $chatId) {
        $stmt = $this->db->prepare("SELECT * FROM telegram_bot_sessions WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        return $stmt->fetch();
    }

    private function setSession(string $chatId, string $state, array $context = []) {
        $stmt = $this->db->prepare("
            INSERT INTO telegram_bot_sessions (chat_id, state, context_data) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE state = VALUES(state), context_data = VALUES(context_data)
        ");
        $stmt->execute([$chatId, $state, json_encode($context)]);
    }

    private function clearSession(string $chatId) {
        $stmt = $this->db->prepare("DELETE FROM telegram_bot_sessions WHERE chat_id = ?");
        $stmt->execute([$chatId]);
    }

    public function broadcast(string $message, int $propertyId, string $role = 'all') {
        $stmt = $this->db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = 'TELEGRAM_ROLES'");
        $stmt->execute([$propertyId]);
        $rolesJson = $stmt->fetchColumn();
        if (!$rolesJson) return;

        $roles = json_decode($rolesJson, true);
        $targetChatIds = [];

        foreach ($roles as $cId => $userRole) {
            if ($role === 'all' || $userRole === $role) {
                $targetChatIds[] = (string)$cId;
            }
        }

        foreach ($targetChatIds as $cId) {
            $this->sendMessage($cId, $message);
        }
    }

    private function handleReportCommand(string $chatId) {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return;

        $this->sendMessage($chatId, "Generating Daily Shift Report...");
        
        require_once __DIR__ . '/PdfGenerator.php';
        $pdfGen = new PdfGenerator();
        $pdfPath = $pdfGen->generateDailyShiftReport($propertyId);
        
        $this->sendDocument($chatId, $pdfPath, "Daily Shift Report");
        
        if (file_exists($pdfPath)) {
            unlink($pdfPath);
        }
    }

    private function getUserRole(string $chatId): string {
        $propertyId = $this->getPropertyIdForChat($chatId);
        if (!$propertyId) return 'user';
        
        $stmt = $this->db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = 'TELEGRAM_ROLES'");
        $stmt->execute([$propertyId]);
        $rolesJson = $stmt->fetchColumn();
        if ($rolesJson) {
            $roles = json_decode($rolesJson, true);
            if (isset($roles[$chatId])) {
                return $roles[$chatId];
            }
        }
        return 'user';
    }

    // --- Utility Methods ---

    private function getPropertyIdForChat(string $chatId): ?int {
        $stmt = $this->db->query("SELECT property_id, key_value FROM system_settings WHERE key_name = 'TELEGRAM_OPERATIONS_CHAT_IDS'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ids = array_filter(array_map('trim', explode(',', (string)$row['key_value'])));
            if (in_array($chatId, $ids, true)) {
                return (int)$row['property_id'];
            }
        }
        return $this->defaultPropertyId;
    }

    private function sendMessage(string $chatId, string $text, array $replyMarkup = []) {
        $url = 'https://api.telegram.org/bot' . $this->botToken . '/sendMessage';
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        if (!empty($replyMarkup)) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }

        $this->makePostRequest($url, $data);
    }

    private function answerCallbackQuery(string $callbackQueryId, string $text = '') {
        $url = 'https://api.telegram.org/bot' . $this->botToken . '/answerCallbackQuery';
        $data = ['callback_query_id' => $callbackQueryId];
        if ($text !== '') {
            $data['text'] = $text;
            $data['show_alert'] = true;
        }
        $this->makePostRequest($url, $data);
    }

    private function makePostRequest(string $url, array $data) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    private function sendDocument(string $chatId, string $filePath, string $caption = '') {
        $url = 'https://api.telegram.org/bot' . $this->botToken . '/sendDocument';
        
        $cFile = curl_file_create($filePath);
        $data = [
            'chat_id' => $chatId,
            'document' => $cFile,
            'caption' => $caption
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}
