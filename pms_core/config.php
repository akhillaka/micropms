<?php
// Timezone Configuration
$timezone = 'Asia/Kolkata';
date_default_timezone_set($timezone);

// Simple .env loader
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue; // BUG-10 fix: skip malformed lines
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Strip surrounding quotes if present (both single and double)
        if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
            $value = $matches[2];
        }
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
        if (function_exists('putenv')) {
            putenv(sprintf('%s=%s', $name, $value));
        }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Database Credentials
define('DB_HOST', getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1'));
define('DB_NAME', getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'pms_db'));
define('DB_USER', getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root'));
define('DB_PASS', (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($_ENV['DB_PASS'] ?? '')));

// Helper to define constant from DB or fallback
if (!function_exists('define_setting')) {
    function define_setting($key, $default) {
        global $dbConfig;
        if (!defined($key)) {
            // Priority: Database -> Env -> Default
            $val = $dbConfig[$key] ?? (getenv($key) !== false && getenv($key) !== '' ? getenv($key) : ($_ENV[$key] ?? $default));
            define($key, $val);
        }
    }
}

if (!function_exists('get_db_setting')) {
    function get_db_setting(\PDO $db, string $keyName, int $propertyId, string $default = ''): string {
        $stmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = ? AND property_id = ?");
        $stmt->execute([$keyName, $propertyId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $default;
    }
}

if (!function_exists('get_payment_methods')) {
    /**
     * Payment method names from Settings → Payment Config for this property.
     * @return list<string>
     */
    function get_payment_methods(\PDO $db, int $propertyId): array {
        $raw = get_db_setting($db, 'payment_methods', $propertyId, '');
        $methods = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $name = trim((string)$item);
                    if ($name !== '') {
                        $methods[] = $name;
                    }
                }
            }
        }
        $methods = array_values(array_unique($methods));
        return $methods !== [] ? $methods : ['Cash', 'UPI'];
    }
}

if (!function_exists('get_active_payment_gateways')) {
    /**
     * Gateways marked active in Settings → Payment Gateways.
     * @return array<string, array{gateway: string, key_id: string}>
     */
    function get_active_payment_gateways(\PDO $db, int $propertyId): array {
        try {
            $stmt = $db->prepare("SELECT gateway, key_id FROM payment_gateway_configs WHERE property_id = ? AND is_active = 1");
            $stmt->execute([$propertyId]);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $gw = strtolower(trim((string)($row['gateway'] ?? '')));
                if ($gw === '') {
                    continue;
                }
                $out[$gw] = [
                    'gateway' => $gw,
                    'key_id' => (string)($row['key_id'] ?? ''),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('get_payment_categories')) {
    /**
     * Revenue / folio categories from Settings → Payment Config.
     * @return list<string>
     */
    function get_payment_categories(\PDO $db, int $propertyId): array {
        $raw = get_db_setting($db, 'payment_categories', $propertyId, '');
        $cats = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $name = trim((string)$item);
                    if ($name !== '') {
                        $cats[] = $name;
                    }
                }
            }
        }
        $cats = array_values(array_unique($cats));
        return $cats !== [] ? $cats : ['Room Revenue', 'F&B', 'Other'];
    }
}

if (!function_exists('default_folio_quick_charges')) {
    /**
     * @return list<array{name:string,icon:string,amount:float,desc:string}>
     */
    function default_folio_quick_charges(): array {
        return [
            ['name' => 'Breakfast', 'icon' => 'ph-coffee', 'amount' => 150, 'desc' => 'Morning Buffet'],
            ['name' => 'Laundry', 'icon' => 'ph-washing-machine', 'amount' => 100, 'desc' => 'Per Bag'],
            ['name' => 'Room Service', 'icon' => 'ph-fork-knife', 'amount' => 200, 'desc' => 'In-Room Dining'],
            ['name' => 'Mini Bar', 'icon' => 'ph-wine', 'amount' => 300, 'desc' => 'Beverages & Snacks'],
            ['name' => 'Parking', 'icon' => 'ph-car', 'amount' => 100, 'desc' => 'Valet Parking'],
            ['name' => 'Extra Person', 'icon' => 'ph-user-plus', 'amount' => 500, 'desc' => 'Extra Bed'],
        ];
    }
}

if (!function_exists('get_folio_quick_charges')) {
    /**
     * Quick-charge presets from Settings → Folio Items.
     * @return list<array<string,mixed>>
     */
    function get_folio_quick_charges(\PDO $db, int $propertyId): array {
        $raw = get_db_setting($db, 'folio_quick_charges', $propertyId, '');
        $presets = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $name = trim((string)($item['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $presets[] = [
                        'name' => $name,
                        'icon' => trim((string)($item['icon'] ?? 'ph-receipt')) ?: 'ph-receipt',
                        'amount' => round((float)($item['amount'] ?? 0), 2),
                        'desc' => trim((string)($item['desc'] ?? '')),
                    ];
                }
            }
        }
        return $presets !== [] ? $presets : default_folio_quick_charges();
    }
}

if (!function_exists('booking_public_id')) {
    /**
     * Guest-facing booking code (display_id), falling back to numeric id.
     */
    function booking_public_id(array $booking): string {
        $d = trim((string)($booking['display_id'] ?? ''));
        return $d !== '' ? $d : (string)($booking['id'] ?? '');
    }
}

if (!function_exists('folio_href')) {
    function folio_href(array $booking, string $prefix = ''): string {
        return $prefix . 'folio.php?id=' . rawurlencode(booking_public_id($booking));
    }
}

if (!function_exists('find_property_booking')) {
    /**
     * Resolve a booking by display_id (e.g. 2608-1) or numeric primary key.
     */
    function find_property_booking(\PDO $db, int $propertyId, string $rawId): ?array {
        $rawId = trim($rawId);
        if ($rawId === '') {
            return null;
        }
        $sql = "SELECT b.*, r.room_number, c.name as category_name, c.id as category_id,
                       g.name as guest_name, g.phone as guest_phone, g.age, g.city, g.state,
                       g.country, g.pincode, g.id_proof_front, g.id_proof_back, g.photo as guest_photo
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                JOIN room_categories c ON r.category_id = c.id
                LEFT JOIN guests g ON b.guest_id = g.id
                WHERE b.property_id = :prop_id AND %s
                LIMIT 1";
        $stmt = $db->prepare(sprintf($sql, 'b.display_id = :id'));
        $stmt->execute(['id' => $rawId, 'prop_id' => $propertyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        if (preg_match('/^\d+$/', $rawId)) {
            $stmt = $db->prepare(sprintf($sql, 'b.id = :id'));
            $stmt->execute(['id' => (int)$rawId, 'prop_id' => $propertyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('load_db_settings')) {
    function load_db_settings($pdo, $propertyId = null) {
        global $dbConfig;
        $dbConfig = [];
        if ($propertyId === null && class_exists('AuthHelper')) {
            try {
                $propertyId = AuthHelper::getPropertyId();
            } catch (Exception $e) {
                $propertyId = null;
            }
        }
        if ($propertyId !== null && (int)$propertyId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT key_name, key_value FROM system_settings WHERE property_id = ?");
                $stmt->execute([(int)$propertyId]);
                while ($row = $stmt->fetch()) {
                    $dbConfig[$row['key_name']] = $row['key_value'];
                }
            } catch (Exception $e) {
                // Silently ignore if DB/table doesn't exist yet
            }
        }

        // Razorpay API Keys
        define_setting('RAZORPAY_KEY_ID', 'rzp_test_placeholder');
        define_setting('RAZORPAY_KEY_SECRET', 'rzp_secret_placeholder');
        define_setting('RAZORPAY_WEBHOOK_SECRET', 'your_webhook_secret');

        // Meta WhatsApp Cloud API
        define_setting('WHATSAPP_TOKEN', 'your_whatsapp_token_here');
        define_setting('WHATSAPP_PHONE_NUMBER_ID', 'your_phone_number_id');
        define_setting('WHATSAPP_WABA_ID', 'your_waba_id_here');

        define_setting('WA_WEBHOOK_VERIFY_TOKEN', '');
        define_setting('WA_APP_SECRET', '');
        define_setting('TELEGRAM_WEBHOOK_SECRET', '');
        define_setting('TELEGRAM_BOT_TOKEN', '');
        define_setting('TELEGRAM_CHAT_ID', '');
        define_setting('TELEGRAM_ROLES', '');
        define_setting('TELEGRAM_OPERATIONS_BOT_TOKEN', '');
        define_setting('TELEGRAM_OPERATIONS_CHAT_IDS', '');

        define_setting('GOOGLE_SHEETS_ENABLED', 'false');
        define_setting('GOOGLE_SHEETS_WEBHOOK_URL', '');
        define_setting('GOOGLE_SHEETS_FIELDS', '');

        // Email / SMTP
        define_setting('SMTP_HOST', '');
        define_setting('SMTP_PORT', '587');
        define_setting('SMTP_USER', '');
        define_setting('SMTP_PASS', '');
        define_setting('SMTP_ENCRYPTION', 'tls');
        define_setting('SMTP_FROM', '');

        // Telegram Message Templates
        define_setting('TG_TEMPLATE_BOOKING_CONFIRMED', "⚡ <b>Online Booking Confirmed</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Check-in:</b> {check_in_date}\n<b>Check-out:</b> {check_out_date}\n<b>Paid:</b> ₹{paid_amount}");
        define_setting('TG_TEMPLATE_CHECK_IN', "🔑 <b>Guest Checked In</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Total Folio:</b> ₹{total_amount}");
        define_setting('TG_TEMPLATE_CHECK_OUT', "🚪 <b>Guest Checked Out</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Total Paid:</b> ₹{paid_amount}");
        define_setting('TG_TEMPLATE_OVERSTAY', "🕛 <b>Overstay Alert</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Checkout was:</b> {check_out_date}");
        define_setting('TG_TEMPLATE_PAYMENT_RECEIVED', "💰 <b>Payment Recorded</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Amount:</b> ₹{amount}\n<b>Method:</b> {method}\n<b>Ref:</b> {ref}");
        define_setting('TG_TEMPLATE_ROOM_DIRTY', "🧹 <b>Room marked Dirty (Checkout)</b>\n\n<b>Room:</b> {room_number}\n<b>Category:</b> {room_type}");
        define_setting('TG_TEMPLATE_DAILY_SUMMARY', "📊 <b>Daily Summary Report</b>\n\n<b>Revenue:</b> ₹{total_amount}\n<b>Occupancy:</b> {occupancy_pct}%\n<b>Dirty Rooms:</b> {dirty_count}");
        define_setting('TG_TEMPLATE_FOLIO_ACTIVITY', "🧾 <b>Folio Activity Alert</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Activity:</b> {description}\n<b>Amount:</b> ₹{amount}");
        define_setting('TG_TEMPLATE_PRE_DEPARTURE', "🔔 <b>Pre-Departure Notice</b>\n\n<b>Guest:</b> {guest_name}\n<b>Room:</b> {room_number}\n<b>Checkout scheduled at:</b> {check_out_date}");



        // Notification Preferences (JSON) — which events trigger Telegram alerts
        define_setting('NOTIFY_EVENTS', json_encode([
            'booking_confirmed'   => true,
            'check_in'            => true,
            'check_out'           => true,
            'overstay'            => true,
            'payment_received'    => true,
            'room_dirty'          => true,
            'daily_summary'       => true,
            'pre_departure'       => false,
            'folio_activity'      => true,
        ]));

        // Property & Branding Settings
        define_setting('PROPERTY_NAME', 'MicroPMS Hotel');
        define_setting('PROPERTY_ADDRESS', '');
        define_setting('PROPERTY_PHONE', '');
        define_setting('PROPERTY_EMAIL', '');
        define_setting('PROPERTY_WIFI_NAME', 'Hotel_Guest_WiFi');
        define_setting('PROPERTY_WIFI_PASS', '');
        define_setting('PROPERTY_LOGO_BASE64', '');

        // Taxation Settings
        define_setting('TAX_ENABLED', 'false');
        define_setting('TAX_LABEL', 'GST');
        define_setting('TAX_RATE', '12');

        // Sequence Formats
        define_setting('SEQ_BOOKING_FORMAT', 'BKG-{YY}{MM}-{ID}');
        define_setting('SEQ_GUEST_FORMAT', 'GST-{YY}{MM}-{ID}');
        define_setting('SEQ_RECEIPT_FORMAT', 'RCPT-{YY}{MM}-{ID}');
        define_setting('SEQ_TRANSACTION_FORMAT', 'TXN-{YY}{MM}-{ID}');
        define_setting('SEQ_FOLIO_FORMAT', '{ID}');
        define_setting('SEQ_POS_ORDER_FORMAT', 'ORD-{YY}{MM}-{ID}');

        // Sequence Reset Rules
        define_setting('SEQ_BOOKING_RESET', 'never');
        define_setting('SEQ_GUEST_RESET', 'never');
        define_setting('SEQ_RECEIPT_RESET', 'never');
        define_setting('SEQ_TRANSACTION_RESET', 'never');
        define_setting('SEQ_FOLIO_RESET', 'never');
        define_setting('SEQ_POS_ORDER_RESET', 'never');
        
        // Sequence Max Limits (Loops back to 1 if exceeded)
        define_setting('SEQ_FOLIO_MAX', 150);

        $stableSecret = getenv('INVOICE_SECRET') !== false && getenv('INVOICE_SECRET') !== '' ? getenv('INVOICE_SECRET') : ($_ENV['INVOICE_SECRET'] ?? '');
        if (empty($stableSecret)) {
            throw new Exception("CRITICAL ERROR: INVOICE_SECRET environment variable is missing.");
        }
        define_setting('INVOICE_SECRET', $stableSecret);

        // Google Vision API
        define_setting('GOOGLE_VISION_API_KEY', '');

        // Guest Portal Settings
        define_setting('GUEST_PORTAL_UPSELL_ENABLED', 'false');
        define_setting('GUEST_PORTAL_POS_ENABLED', 'false');
        define_setting('GUEST_PORTAL_HOUSEKEEPING_ENABLED', 'false');
        define_setting('GUEST_PORTAL_SELF_CHECKOUT_ENABLED', 'false');
        define_setting('GUEST_PORTAL_EARLY_LATE_FEE', '0.00');
        define_setting('GUEST_PORTAL_WIFI_ENABLED', 'true');
        define_setting('GUEST_PORTAL_SIGHTSEEING_ENABLED', 'true');
        define_setting('GUEST_PORTAL_WAKEUP_ENABLED', 'true');
        define_setting('GUEST_PORTAL_EXTEND_STAY_ENABLED', 'true');
        define_setting('GUEST_PORTAL_UPGRADE_ENABLED', 'true');
        define_setting('GUEST_PORTAL_CONTACT_ENABLED', 'true');
    }
}

