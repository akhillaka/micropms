<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/config.php';

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

        if (!$isEnabled || empty($webhookUrl) || filter_var($webhookUrl, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8); // Max 8 sec
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode < 200 || $httpCode >= 400) {
            error_log("GoogleSheetService Webhook Error: " . ($error ?: "HTTP $httpCode - $response"));
            return false;
        }

        return json_decode($response, true) ?: true;
    }

    /**
     * Test ping the Google Sheets Webhook
     */
    public static function testConnection($webhookUrl) {
        if (empty($webhookUrl) || filter_var($webhookUrl, FILTER_VALIDATE_URL) === false) {
            return ['success' => false, 'message' => 'Invalid Webhook URL provided.'];
        }

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['action' => 'ping']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => "cURL Error: " . $error];
        }

        if ($httpCode >= 400) {
            return ['success' => false, 'message' => "Google Apps Script returned HTTP status $httpCode"];
        }

        $data = json_decode($response, true);
        if (isset($data['status']) && $data['status'] === 'success') {
            return ['success' => true, 'message' => $data['message'] ?? 'Successfully connected to Google Sheets!'];
        }

        return ['success' => false, 'message' => 'Unexpected response from Webhook: ' . substr($response, 0, 200)];
    }

    /**
     * Sync single booking to Google Sheets
     */
    public static function syncBooking($pdo, $bookingId) {
        $data = self::buildBookingData($pdo, $bookingId);
        if (!$data) return false;

        require_once __DIR__ . '/services/QueueService.php';
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

        require_once __DIR__ . '/services/QueueService.php';
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

        require_once __DIR__ . '/services/QueueService.php';
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
        $items = [];

        if ($type === 'all' || $type === 'booking') {
            $stmt = $pdo->prepare("SELECT id FROM bookings WHERE property_id = ? ORDER BY id ASC");
            $stmt->execute([$propertyId]);
            while ($row = $stmt->fetch()) {
                $bData = self::buildBookingData($pdo, $row['id']);
                if ($bData) {
                    $items[] = ['sheet_type' => 'booking', 'data' => $bData];
                }
            }
        }

        if ($type === 'all' || $type === 'payment') {
            // Need to join bookings to get property_id
            $stmt = $pdo->prepare("SELECT l.id FROM folio_ledger l JOIN bookings b ON l.booking_id = b.id WHERE b.property_id = ? AND l.transaction_type IN ('cash','card','online','payment') ORDER BY l.id ASC");
            $stmt->execute([$propertyId]);
            while ($row = $stmt->fetch()) {
                $pData = self::buildPaymentData($pdo, $row['id']);
                if ($pData) {
                    $items[] = ['sheet_type' => 'payment', 'data' => $pData];
                }
            }
        }

        if ($type === 'all' || $type === 'expense') {
            $stmt = $pdo->prepare("SELECT id FROM finance_transactions WHERE property_id = ? AND type = 'expense' ORDER BY id ASC");
            $stmt->execute([$propertyId]);
            while ($row = $stmt->fetch()) {
                $eData = self::buildExpenseData($pdo, $row['id']);
                if ($eData) {
                    $items[] = ['sheet_type' => 'expense', 'data' => $eData];
                }
            }
        }

        if (empty($items)) {
            return ['success' => true, 'count' => 0, 'message' => 'No items found to sync.'];
        }

        // Chunk bulk items into batches of 50 to avoid payload limits
        $chunks = array_chunk($items, 50);
        $totalSynced = 0;

        require_once __DIR__ . '/services/QueueService.php';
        foreach ($chunks as $chunk) {
            QueueService::push('google_sheets', [
                'action' => 'bulk_sync',
                'items' => $chunk
            ], 0, $propertyId);
            $totalSynced += count($chunk);
        }

        return ['success' => true, 'count' => $totalSynced, 'message' => "Synced $totalSynced records to Google Sheets."];
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
        $payStmt = $pdo->prepare("SELECT SUM(amount) as paid FROM folio_ledger WHERE booking_id = :bid AND transaction_type IN ('cash','card','online','payment')");
        $payStmt->execute(['bid' => $bookingId]);
        $paidRow = $payStmt->fetch(PDO::FETCH_ASSOC);
        $totalCollected = (float)($paidRow['paid'] ?? 0);

        // Staff user info from audit log or current session
        $staffUser = self::getBookingStaffUser($pdo, $bookingId);

        return [
            "property_id"            => (int)$b['property_id'], // hidden from sheet, used for routing
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
            "user"                   => $staffUser
        ];
    }

    private static function buildPaymentData($pdo, $ledgerId) {
        $sql = "SELECT l.*, b.display_id, b.offline_folio_id,
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

        $recTs = strtotime($l['recorded_at']);
        $paymentType = $l['payment_method'] ?: ucfirst($l['transaction_type']);
        $staffUser = self::getLedgerStaffUser($pdo, $ledgerId);

        return [
            "Booking ID"   => $l['display_id'] ?: ("BKG-" . $l['booking_id']),
            "Folio No"      => $l['offline_folio_id'] ?: ("FOL-" . $l['booking_id']),
            "Room No"      => $l['room_number'] ?: "-",
            "Room Type"    => $l['category_name'] ?: "-",
            "Full Name"    => $l['guest_name'] ?: "-",
            "Amount Paid"  => (float)$l['amount'],
            "Payment Type" => $paymentType,
            "Month"        => date('M-Y', $recTs),
            "Payment Date" => date('Y-m-d H:i:s', $recTs),
            "Category"     => $l['description'] ?: ucfirst($l['transaction_type']),
            "user"         => $staffUser
        ];
    }

    private static function buildExpenseData($pdo, $expenseId) {
        $sql = "SELECT f.*, s.username, s.full_name as staff_name
                FROM finance_transactions f
                LEFT JOIN staff_users s ON f.staff_id = s.id
                WHERE f.id = :id AND f.type = 'expense'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $expenseId]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$f) return null;

        $recTs = strtotime($f['recorded_at']);
        $staffUser = $f['staff_name'] ?: ($f['username'] ?: 'Admin');

        return [
            "Expense ID"     => "EXP-" . $f['id'],
            "Category"       => $f['category'],
            "Amount"         => (float)$f['amount'],
            "Description"    => $f['description'],
            "Payment Method" => $f['payment_method'] ?: 'Cash',
            "Month"          => date('M-Y', $recTs),
            "Expense Date"   => date('Y-m-d H:i:s', $recTs),
            "User"           => $staffUser
        ];
    }

    private static function getBookingStaffUser($pdo, $bookingId) {
        if (!empty($_SESSION['staff_user'])) {
            return $_SESSION['staff_user'];
        }
        try {
            $stmt = $pdo->prepare("SELECT s.username FROM audit_logs a JOIN staff_users s ON a.staff_id = s.id WHERE a.entity_type = 'BOOKING' AND a.entity_id = :bid ORDER BY a.id ASC LIMIT 1");
            $stmt->execute(['bid' => $bookingId]);
            $row = $stmt->fetch();
            if ($row) return $row['username'];
        } catch (Exception $e) {}

        return 'Admin';
    }

    private static function getLedgerStaffUser($pdo, $ledgerId) {
        if (!empty($_SESSION['staff_user'])) {
            return $_SESSION['staff_user'];
        }
        try {
            $stmt = $pdo->prepare("SELECT s.username FROM audit_logs a JOIN staff_users s ON a.staff_id = s.id WHERE a.details LIKE :lid ORDER BY a.id DESC LIMIT 1");
            $stmt->execute(['lid' => '%"ledger_id":' . $ledgerId . '%']);
            $row = $stmt->fetch();
            if ($row) return $row['username'];
        } catch (Exception $e) {}

        return 'Admin';
    }
}
