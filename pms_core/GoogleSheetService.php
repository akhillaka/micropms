<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/services/FolioService.php';

class GoogleSheetService {

    /**
     * Send HTTP POST to Google Apps Script Webhook
     */
    public static function sendWebhook($payload, $propertyId = null) {
        if ($propertyId === null) {
            require_once __DIR__ . '/AuthHelper.php';
            $propertyId = AuthHelper::getPropertyId();
        }

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT key_name, key_value FROM system_settings WHERE property_id = ? AND key_name IN ('GOOGLE_SHEETS_WEBHOOK_URL', 'GOOGLE_SHEETS_ENABLED')");
        $stmt->execute([$propertyId]);
        
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['key_name']] = $row['key_value'];
        }

        $webhookUrl = $settings['GOOGLE_SHEETS_WEBHOOK_URL'] ?? (defined('GOOGLE_SHEETS_WEBHOOK_URL') ? GOOGLE_SHEETS_WEBHOOK_URL : '');
        $isEnabled = isset($settings['GOOGLE_SHEETS_ENABLED']) ? ($settings['GOOGLE_SHEETS_ENABLED'] === 'true' || $settings['GOOGLE_SHEETS_ENABLED'] === '1') : (defined('GOOGLE_SHEETS_ENABLED') ? (GOOGLE_SHEETS_ENABLED === 'true' || GOOGLE_SHEETS_ENABLED === true || GOOGLE_SHEETS_ENABLED === '1') : false);

        if (!$isEnabled) {
            return false;
        }
        $webhookUrl = self::normalizeWebhookUrl((string)$webhookUrl);
        if ($webhookUrl === '') {
            return false;
        }

        $res = self::postToAppsScript($webhookUrl, is_array($payload) ? $payload : ['payload' => $payload]);
        if (empty($res['ok'])) {
            error_log('GoogleSheetService Webhook Error: ' . ($res['message'] ?? 'unknown'));
            return false;
        }
        return $res['data'] ?? true;
    }

    /**
     * Test ping the Google Sheets Webhook
     */
    public static function testConnection($webhookUrl) {
        if (empty($webhookUrl) || filter_var($webhookUrl, FILTER_VALIDATE_URL) === false) {
            return ['success' => false, 'message' => 'Invalid Webhook URL provided.'];
        }

        $webhookUrl = self::normalizeWebhookUrl($webhookUrl);
        if ($webhookUrl === '') {
            return ['success' => false, 'message' => 'Paste the Web app URL that ends with /exec (not the Library URL). Example: https://script.google.com/macros/s/AKfycb…/exec'];
        }

        $pingPayload = [
            'action' => 'ping',
            'sheets' => self::fieldCatalog(),
        ];
        $res = self::postToAppsScript($webhookUrl, $pingPayload);
        if (empty($res['ok']) && str_contains(strtolower((string)($res['message'] ?? '')), '405')) {
            $res = self::getAppsScriptPing($webhookUrl);
            if (!empty($res['ok'])) {
                $setup = self::postToAppsScript($webhookUrl, ['action' => 'setup', 'sheets' => self::fieldCatalog()]);
                if (!empty($setup['ok'])) {
                    $res = $setup;
                }
            }
        }
        if (empty($res['ok'])) {
            return ['success' => false, 'message' => $res['message'] ?? 'Could not reach Google Apps Script.'];
        }
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        if (($data['status'] ?? '') === 'success') {
            return ['success' => true, 'message' => $data['message'] ?? 'Successfully connected to Google Sheets!'];
        }
        if (($data['status'] ?? '') === 'error') {
            return ['success' => false, 'message' => (string)($data['message'] ?? 'Apps Script returned an error')];
        }
        return ['success' => false, 'message' => 'Unexpected response from Webhook. Redeploy the script from Extensions → Apps Script → Deploy → New deployment (Execute as: Me, Who has access: Anyone).'];
    }

    /**
     * Google web apps 401 if access is not "Anyone", and application/json POSTs often fail.
     * Send JSON as text/plain and keep POST across 302 redirects.
     *
     * @return array{ok: bool, message?: string, data?: mixed}
     */
    public static function postToAppsScript(string $webhookUrl, array $payload): array {
        $webhookUrl = self::normalizeWebhookUrl($webhookUrl);
        if ($webhookUrl === '') {
            return ['ok' => false, 'message' => 'Paste the Web app URL that ends with /exec, not the Library URL.'];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ch = curl_init($webhookUrl);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Could not start HTTP request to Google.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/plain;charset=utf-8',
                'Accept: application/json, text/plain, */*',
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            // Google 302s /exec to script.googleusercontent.com. That hop must be GET (default).
            // Forcing POST on the redirect returns HTTP 405.
            CURLOPT_POSTREDIR => 0,
            CURLOPT_USERAGENT => 'MicroPMS-GoogleSheets/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'message' => 'cURL Error: ' . $error];
        }

        $decoded = json_decode((string)$response, true);
        if (is_array($decoded) && $httpCode >= 200 && $httpCode < 400) {
            return ['ok' => true, 'data' => $decoded];
        }

        return ['ok' => false, 'message' => self::explainAppsScriptFailure($httpCode, (string)$response, $finalUrl)];
    }

    public static function normalizeWebhookUrl(string $webhookUrl): string {
        $url = trim($webhookUrl);
        if ($url === '') {
            return '';
        }
        $url = preg_replace('/\s+/', '', $url) ?? $url;
        $lower = strtolower($url);
        if (str_contains($lower, '/library/') || str_contains($lower, 'script.google.com/d/')) {
            return '';
        }
        if (str_ends_with($lower, '/dev') || str_contains($lower, '/dev?')) {
            return '';
        }
        if (!preg_match('#^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec/?(\?.*)?$#i', $url)) {
            if (preg_match('#^(https://script\.google\.com/macros/s/[A-Za-z0-9_-]+)(?:/)?$#i', $url, $m)) {
                $url = $m[1] . '/exec';
            } else {
                return '';
            }
        }
        return rtrim($url, '/');
    }

    private static function getAppsScriptPing(string $webhookUrl): array {
        $ch = curl_init($webhookUrl);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'Could not start HTTP request to Google.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_USERAGENT => 'MicroPMS-GoogleSheets/1.0',
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            return ['ok' => false, 'message' => 'cURL Error: ' . $error];
        }
        $decoded = json_decode((string)$response, true);
        if (is_array($decoded) && $httpCode >= 200 && $httpCode < 400) {
            return ['ok' => true, 'data' => $decoded];
        }
        return ['ok' => false, 'message' => self::explainAppsScriptFailure($httpCode, (string)$response, $finalUrl)];
    }

    public static function explainAppsScriptFailure(int $httpCode, string $response, string $finalUrl = ''): string {
        $blob = strtolower($response . ' ' . $finalUrl);
        if ($httpCode === 405) {
            return 'Google returned HTTP 405 (method not allowed). Use the Web app URL that ends with /exec — not the Library URL, not /dev. After changing Code.gs, Deploy → Manage deployments → pencil → New version so doPost is included.';
        }
        if ($httpCode === 401 || $httpCode === 403
            || str_contains($blob, 'accounts.google.com')
            || str_contains($blob, 'unable to open the file')
            || str_contains($blob, 'signin')
        ) {
            return 'Google blocked the webhook (HTTP ' . ($httpCode ?: '401') . '). Deploy as a Web app (not Library / API executable). Execute as: Me. Who has access: Anyone. Then paste the Web app URL ending in /exec.';
        }
        if ($httpCode >= 400) {
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($response)) ?? '');
            return 'Google Apps Script returned HTTP ' . $httpCode . ($plain !== '' ? (': ' . substr($plain, 0, 160)) : '');
        }
        return 'Unexpected response from Google Apps Script: ' . substr(trim(strip_tags($response)), 0, 200);
    }

    /**
     * Sync single booking to Google Sheets
     */
    public static function syncBooking($pdo, $bookingId) {
        $data = self::buildBookingData($pdo, $bookingId);
        if (!$data) return false;

        QueueService::push('google_sheets', [
            'action' => 'sync_row',
            'sheet_type' => 'booking',
            'data' => $data
        ], 0, $data['property_id'] ?? null);
        return true;
    }

    /**
     * Sync single payment to Google Sheets
     */
    public static function syncPayment($pdo, $ledgerId) {
        $data = self::buildPaymentData($pdo, $ledgerId);
        if (!$data) return false;

        QueueService::push('google_sheets', [
            'action' => 'sync_row',
            'sheet_type' => 'payment',
            'data' => $data
        ], 0, $data['property_id'] ?? null);
        return true;
    }

    /**
     * Sync single expense to Google Sheets
     */
    public static function syncExpense($pdo, $expenseId) {
        $data = self::buildExpenseData($pdo, $expenseId);
        if (!$data) return false;

        QueueService::push('google_sheets', [
            'action' => 'sync_row',
            'sheet_type' => 'expense',
            'data' => $data
        ], 0, $data['property_id'] ?? null);
        return true;
    }

    /**
     * Bulk sync bookings, payments, or expenses
     */
    public static function bulkSync($pdo, $propertyId, $type = 'all') {
        try {
            $items = [];

            if ($type === 'all' || $type === 'booking') {
                $stmt = $pdo->prepare("SELECT id FROM bookings WHERE property_id = ? ORDER BY id ASC");
                $stmt->execute([$propertyId]);
                while ($row = $stmt->fetch()) {
                    try {
                        $bData = self::buildBookingData($pdo, (int)$row['id']);
                        if ($bData) {
                            $items[] = ['sheet_type' => 'booking', 'data' => $bData];
                        }
                    } catch (\Throwable $e) {
                        error_log('GoogleSheet booking row skipped: ' . $e->getMessage());
                    }
                }
            }

            if ($type === 'all' || $type === 'payment') {
                $stmt = $pdo->prepare("SELECT l.id FROM folio_ledger l JOIN bookings b ON l.booking_id = b.id WHERE b.property_id = ? AND (l.amount < 0 OR l.entry_kind IN ('payment','REFUND')) ORDER BY l.id ASC");
                $stmt->execute([$propertyId]);
                while ($row = $stmt->fetch()) {
                    try {
                        $pData = self::buildPaymentData($pdo, (int)$row['id']);
                        if ($pData) {
                            $items[] = ['sheet_type' => 'payment', 'data' => $pData];
                        }
                    } catch (\Throwable $e) {
                        error_log('GoogleSheet payment row skipped: ' . $e->getMessage());
                    }
                }
            }

            if ($type === 'all' || $type === 'expense') {
                $stmt = $pdo->prepare("SELECT id FROM finance_transactions WHERE property_id = ? AND type = 'expense' ORDER BY id ASC");
                $stmt->execute([$propertyId]);
                while ($row = $stmt->fetch()) {
                    try {
                        $eData = self::buildExpenseData($pdo, (int)$row['id']);
                        if ($eData) {
                            $items[] = ['sheet_type' => 'expense', 'data' => $eData];
                        }
                    } catch (\Throwable $e) {
                        error_log('GoogleSheet expense row skipped: ' . $e->getMessage());
                    }
                }
            }
        } catch (\PDOException $e) {
            error_log('GoogleSheet bulkSync query failed: ' . $e->getMessage());
            return ['success' => false, 'count' => 0, 'message' => 'Could not load records for Google Sheets sync.'];
        }

        if (empty($items)) {
            return ['success' => true, 'count' => 0, 'message' => 'No items found to sync.'];
        }

        $webhookUrl = self::resolveWebhookUrl((int)$propertyId);
        $chunks = array_chunk($items, 40);
        $totalSynced = 0;
        $lastError = '';

        foreach ($chunks as $chunk) {
            $posted = false;
            if ($webhookUrl !== '') {
                $res = self::postToAppsScript($webhookUrl, [
                    'action' => 'bulk_sync',
                    'items' => $chunk,
                ]);
                if (!empty($res['ok'])) {
                    $posted = true;
                    $totalSynced += count($chunk);
                } else {
                    $lastError = (string)($res['message'] ?? 'Google Sheets webhook failed');
                }
            }
            if (!$posted) {
                try {
                    QueueService::push('google_sheets', [
                        'action' => 'bulk_sync',
                        'items' => $chunk,
                    ], 0, (int)$propertyId);
                    $totalSynced += count($chunk);
                } catch (\Throwable $e) {
                    error_log('GoogleSheet queue push failed: ' . $e->getMessage());
                    $lastError = $lastError !== '' ? $lastError : 'Could not queue Google Sheets sync.';
                }
            }
        }

        if ($totalSynced === 0) {
            return ['success' => false, 'count' => 0, 'message' => $lastError !== '' ? $lastError : 'Bulk sync failed.'];
        }

        $msg = "Synced {$totalSynced} records to Google Sheets.";
        if ($lastError !== '') {
            $msg .= ' Some batches were queued: ' . $lastError;
        }
        return ['success' => true, 'count' => $totalSynced, 'message' => $msg];
    }

    private static function resolveWebhookUrl(int $propertyId): string {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = 'GOOGLE_SHEETS_WEBHOOK_URL'");
        $stmt->execute([$propertyId]);
        $url = (string)($stmt->fetchColumn() ?: '');
        if ($url === '' && defined('GOOGLE_SHEETS_WEBHOOK_URL')) {
            $url = (string)GOOGLE_SHEETS_WEBHOOK_URL;
        }
        return self::normalizeWebhookUrl($url);
    }

    /**
     * Helpers to build formatted dictionary matching sheet headers
     */
    private static function buildBookingData($pdo, $bookingId) {
        $sql = "SELECT b.*, 
                       g.name as guest_name, g.phone as guest_phone,
                       r.room_number, c.name as category_name
                FROM bookings b
                LEFT JOIN guests g ON b.guest_id = g.id
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN room_categories c ON r.category_id = c.id
                WHERE b.id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $bookingId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$b) return null;

        $checkInTs = strtotime($b['check_in']);
        $checkOutTs = strtotime($b['check_out']);
        $diffSec = max(0, $checkOutTs - $checkInTs);

        $days = max(1, ceil($diffSec / 86400));
        $hrs = max(1, round($diffSec / 3600));

        // Rate per night calculation
        $ratePerNight = $b['price_override'] ?: ($days > 0 ? round($b['total_amount'] / $days, 2) : $b['total_amount']);

        // Sum of payments collected in folio ledger
        $payStmt = $pdo->prepare("SELECT SUM(amount) as paid FROM folio_ledger WHERE booking_id = :bid AND (entry_kind = 'payment' OR amount < 0)");
        $payStmt->execute(['bid' => $bookingId]);
        $paidRow = $payStmt->fetch(PDO::FETCH_ASSOC);
        $totalCollected = (float)($paidRow['paid'] ?? 0);

        // Staff user info from audit log or current session
        $staffUser = self::getBookingStaffUser($pdo, $bookingId);

        $row = [
            "property_id"            => (int)$b['property_id'],
            "Booking ID"             => $b['display_id'] ?: ("BKG-" . $b['id']),
            "Folio No"                => $b['offline_folio_id'] ?: ("FOL-" . $b['id']),
            "Room No"                => $b['room_number'] ?: "-",
            "Room Type"              => $b['category_name'] ?: "-",
            "Full Name"              => $b['guest_name'] ?: "Walk-in Guest",
            "Phone No"               => $b['guest_phone'] ?: "-",
            "Rate per night"         => (float)$ratePerNight,
            "Month"                  => date('M-Y', $checkInTs),
            "Check-in Date"          => date('Y-m-d', $checkInTs),
            "Check-In TIme"          => date('H:i:s', $checkInTs),
            "Check-Out-Date"         => date('Y-m-d', $checkOutTs),
            "Check-Out Time"         => date('H:i:s', $checkOutTs),
            "Duration in days"       => (int)$days,
            "Duration in hrs"        => (int)$hrs,
            "Total Amount Collected" => (float)$totalCollected,
            "Check-in/Check-Out"     => ucfirst(str_replace('_', ' ', $b['booking_status'])),
            "user"                   => $staffUser,
            "User"                   => $staffUser
        ];
        return self::applyFieldFilter($pdo, (int)$b['property_id'], 'booking', $row);
    }

    private static function buildPaymentData($pdo, $ledgerId) {
        $sql = "SELECT l.*, l.display_id AS ledger_display_id, l.id AS ledger_id,
                       b.display_id AS booking_display_id, b.offline_folio_id,
                       g.name as guest_name, r.room_number, c.name as category_name
                FROM folio_ledger l
                LEFT JOIN bookings b ON l.booking_id = b.id
                LEFT JOIN guests g ON b.guest_id = g.id
                LEFT JOIN rooms r ON b.room_id = r.id
                LEFT JOIN room_categories c ON r.category_id = c.id
                WHERE l.id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $ledgerId]);
        $l = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$l) return null;

        $recTs = strtotime((string)$l['recorded_at']) ?: time();
        $paymentType = $l['payment_method'] ?: (FolioService::isPaymentLike($l) ? 'Payment' : ucfirst((string)FolioService::resolveEntryKind($l)));
        $staffUser = self::getLedgerStaffUser($pdo, $ledgerId);
        // Stable upsert key — never switch between LED-{id} and display_id (that caused duplicate rows).
        $stablePaymentId = 'LED-' . (int)$l['ledger_id'];
        $receiptNo = trim((string)($l['ledger_display_id'] ?? ''));
        if ($receiptNo === '' || $receiptNo === $stablePaymentId) {
            $receiptNo = $stablePaymentId;
        }
        $amountAbs = abs((float)$l['amount']);
        $isRefund = !empty($l['is_refund']) || (float)$l['amount'] > 0;
        $payCat = self::paymentCategoryLabel((string)($l['payment_category'] ?? ''), (string)($l['description'] ?? ''));

        return self::applyFieldFilter($pdo, (int)($l['property_id'] ?? 0), 'payment', [
            "Payment ID"       => $stablePaymentId,
            "Receipt No"       => $receiptNo,
            "Booking ID"       => $l['booking_display_id'] ?: ("BKG-" . $l['booking_id']),
            "Folio No"         => $l['offline_folio_id'] ?: ("FOL-" . $l['booking_id']),
            "Room No"          => $l['room_number'] ?: "-",
            "Room Type"        => $l['category_name'] ?: "-",
            "Full Name"        => $l['guest_name'] ?: "-",
            "Amount Paid"      => $isRefund ? -$amountAbs : $amountAbs,
            "Payment Type"     => $paymentType,
            "Month"            => date('M-Y', $recTs),
            "Payment Date"     => date('Y-m-d H:i:s', $recTs),
            "Category"         => $payCat,
            "Payment Category" => $payCat,
            "Notes"            => (string)($l['description'] ?? ''),
            "user"             => $staffUser,
            "User"             => $staffUser,
            "property_id"      => (int)($l['property_id'] ?? 0),
        ]);
    }

    /**
     * Map folio_ledger.payment_category to a human payment-category label (Room Revenue, F&B, …).
     */
    private static function paymentCategoryLabel(string $category, string $description = ''): string {
        $raw = trim($category);
        $aliases = [
            'booking' => 'Room Revenue',
            'room rent' => 'Room Revenue',
            'room_revenue' => 'Room Revenue',
            'room revenue' => 'Room Revenue',
            'f&b' => 'F&B',
            'fb' => 'F&B',
            'pos' => 'F&B',
            'pos_order' => 'F&B',
            'incidentals' => 'Other',
            'other' => 'Other',
            'misc' => 'Other',
            'laundry' => 'Laundry',
        ];
        if ($raw !== '') {
            $key = strtolower($raw);
            if (isset($aliases[$key])) {
                return $aliases[$key];
            }
            return $raw;
        }
        $desc = strtolower($description);
        if (str_contains($desc, 'f&b') || str_contains($desc, 'pos') || str_contains($desc, 'dining')) {
            return 'F&B';
        }
        if (str_contains($desc, 'room') || str_contains($desc, 'rent') || str_contains($desc, 'booking')) {
            return 'Room Revenue';
        }
        return 'Other';
    }

    private static function buildExpenseData($pdo, $expenseId) {
        $sql = "SELECT f.*, s.username
                FROM finance_transactions f
                LEFT JOIN staff_users s ON f.staff_id = s.id
                WHERE f.id = :id AND f.type = 'expense'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $expenseId]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$f) return null;

        $recTs = strtotime($f['recorded_at']);
        $staffUser = self::normalizeStaffUsername($f['username'] ?? '') ?: self::sessionStaffUsername($pdo);

        return self::applyFieldFilter($pdo, (int)$f['property_id'], 'expense', [
            "Expense ID"     => "EXP-" . $f['id'],
            "Category"       => $f['category'],
            "Amount"         => (float)$f['amount'],
            "Description"    => $f['description'],
            "Payment Method" => $f['payment_method'] ?: 'Cash',
            "Month"          => date('M-Y', $recTs),
            "Expense Date"   => date('Y-m-d H:i:s', $recTs),
            "User"           => $staffUser,
            "user"           => $staffUser,
        ]);
    }

    /**
     * @return array{booking: list<string>, payment: list<string>, expense: list<string>}
     */
    public static function fieldCatalog(): array {
        return [
            'booking' => [
                'Booking ID', 'Folio No', 'Room No', 'Room Type', 'Full Name', 'Phone No',
                'Rate per night', 'Month', 'Check-in Date', 'Check-In TIme', 'Check-Out-Date',
                'Check-Out Time', 'Duration in days', 'Duration in hrs', 'Total Amount Collected',
                'Check-in/Check-Out', 'user',
            ],
            'payment' => [
                'Payment ID', 'Receipt No', 'Booking ID', 'Folio No', 'Room No', 'Room Type', 'Full Name', 'Amount Paid',
                'Payment Type', 'Month', 'Payment Date', 'Category', 'Payment Category', 'Notes', 'user',
            ],
            'expense' => [
                'Expense ID', 'Category', 'Amount', 'Description', 'Payment Method', 'Month',
                'Expense Date', 'User',
            ],
        ];
    }

    private static function applyFieldFilter($pdo, int $propertyId, string $type, array $row): array {
        // Upsert keys must always sync or Apps Script will append duplicates.
        $requiredKeys = match ($type) {
            'payment' => ['Payment ID'],
            'booking' => ['Booking ID'],
            'expense' => ['Expense ID'],
            default => [],
        };
        if ($propertyId <= 0) {
            return $row;
        }
        $raw = get_db_setting($pdo, 'GOOGLE_SHEETS_FIELDS', $propertyId, '');
        $map = $raw !== '' ? json_decode($raw, true) : null;
        $enabled = [];
        if (is_array($map) && isset($map[$type]) && is_array($map[$type]) && $map[$type] !== []) {
            foreach ($map[$type] as $key) {
                $key = trim((string)$key);
                if ($key !== '') {
                    $enabled[] = $key;
                }
            }
        }
        if ($enabled === []) {
            return $row;
        }
        foreach ($requiredKeys as $req) {
            if (!in_array($req, $enabled, true)) {
                $enabled[] = $req;
            }
        }
        // Prefer Payment Category when Category is enabled (legacy header).
        if ($type === 'payment' && in_array('Category', $enabled, true) && !in_array('Payment Category', $enabled, true)) {
            $enabled[] = 'Payment Category';
        }
        $out = [];
        foreach ($row as $k => $v) {
            if ($k === 'property_id' || in_array($k, $enabled, true) || in_array($k, $requiredKeys, true)) {
                $out[$k] = $v;
            }
        }
        return $out !== [] ? $out : $row;
    }

    private static function normalizeStaffUsername($value): string {
        $name = trim((string)$value);
        if ($name === '') {
            return '';
        }
        return $name;
    }

    private static function staffUsernameById($pdo, $staffId): string {
        $staffId = (int)$staffId;
        if ($staffId <= 0) {
            return '';
        }
        try {
            $stmt = $pdo->prepare('SELECT username FROM staff_users WHERE id = ? LIMIT 1');
            $stmt->execute([$staffId]);
            return self::normalizeStaffUsername((string)($stmt->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function sessionStaffUsername($pdo): string {
        $fromSession = self::normalizeStaffUsername($_SESSION['username'] ?? $_SESSION['staff_user'] ?? '');
        if ($fromSession !== '') {
            return $fromSession;
        }
        return self::staffUsernameById($pdo, (int)($_SESSION['user_id'] ?? 0));
    }

    private static function getBookingStaffUser($pdo, $bookingId) {
        $fromSession = self::sessionStaffUsername($pdo);
        try {
            $stmt = $pdo->prepare("SELECT s.username FROM audit_logs a JOIN staff_users s ON a.staff_id = s.id WHERE a.entity_type = 'BOOKING' AND a.entity_id = :bid ORDER BY a.id ASC LIMIT 1");
            $stmt->execute(['bid' => $bookingId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $fromAudit = self::normalizeStaffUsername($row['username'] ?? '');
            if ($fromAudit !== '') {
                return $fromAudit;
            }
        } catch (\Throwable $e) {
        }

        return $fromSession;
    }

    private static function getLedgerStaffUser($pdo, $ledgerId) {
        $fromSession = self::sessionStaffUsername($pdo);
        if ($fromSession !== '') {
            return $fromSession;
        }
        $entry = [];
        try {
            $led = $pdo->prepare('SELECT booking_id, amount, recorded_at FROM folio_ledger WHERE id = ? LIMIT 1');
            $led->execute([(int)$ledgerId]);
            $entry = $led->fetch(PDO::FETCH_ASSOC) ?: [];
            $bookingId = (int)($entry['booking_id'] ?? 0);
            $absAmt = abs((float)($entry['amount'] ?? 0));

            if ($bookingId > 0) {
                $pay = $pdo->prepare("
                    SELECT s.username
                    FROM finance_transactions f
                    JOIN staff_users s ON s.id = f.staff_id
                    WHERE f.booking_id = :bid
                      AND f.type = 'income'
                      AND ABS(ABS(f.amount) - :amt) < 0.05
                    ORDER BY ABS(TIMESTAMPDIFF(SECOND, f.recorded_at, :rec)) ASC, f.id DESC
                    LIMIT 1
                ");
                $pay->execute([
                    'bid' => $bookingId,
                    'amt' => $absAmt,
                    'rec' => (string)($entry['recorded_at'] ?? date('Y-m-d H:i:s')),
                ]);
                $fromFinance = self::normalizeStaffUsername((string)($pay->fetchColumn() ?: ''));
                if ($fromFinance !== '') {
                    return $fromFinance;
                }

                $audit = $pdo->prepare("
                    SELECT a.details, s.username
                    FROM audit_logs a
                    LEFT JOIN staff_users s ON s.id = a.staff_id
                    WHERE a.entity_id = :bid
                      AND a.entity_type IN ('FOLIO', 'BOOKING', 'PAYMENT')
                      AND a.action IN ('RECORD_PAYMENT', 'ADD_FOLIO_PAYMENT', 'RAZORPAY_PAYMENT', 'PORTAL_PAYMENT_RECORDED')
                    ORDER BY a.id DESC
                    LIMIT 8
                ");
                $audit->execute(['bid' => $bookingId]);
                while ($row = $audit->fetch(PDO::FETCH_ASSOC)) {
                    $fromJoin = self::normalizeStaffUsername($row['username'] ?? '');
                    if ($fromJoin !== '') {
                        return $fromJoin;
                    }
                    $details = json_decode((string)($row['details'] ?? ''), true);
                    if (is_array($details)) {
                        $fromDetails = self::normalizeStaffUsername($details['staff'] ?? $details['username'] ?? '');
                        if ($fromDetails !== '') {
                            return $fromDetails;
                        }
                    }
                }
            }

            $like = $pdo->prepare("SELECT s.username FROM audit_logs a JOIN staff_users s ON a.staff_id = s.id WHERE a.details LIKE :lid ORDER BY a.id DESC LIMIT 1");
            $like->execute(['lid' => '%"ledger_id":' . (int)$ledgerId . '%']);
            $fromLike = self::normalizeStaffUsername((string)($like->fetchColumn() ?: ''));
            if ($fromLike !== '') {
                return $fromLike;
            }
        } catch (\Throwable $e) {
        }

        if ($fromSession !== '') {
            return $fromSession;
        }

        return self::getBookingStaffUser($pdo, (int)($entry['booking_id'] ?? 0));
    }
}
