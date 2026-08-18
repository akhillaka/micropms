<?php
require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';
require_once __DIR__ . '/../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../pms_core/services/NightAudit.php';

// Only allow CLI execution (cron jobs)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access denied - CLI only");
}

// Acquire a file-based lock to prevent overlapping cron runs
$lockFile = sys_get_temp_dir() . '/micropms_cron.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[CRON] Another cron instance is already running. Exiting.\n";
    exit(0);
}

$db = Database::getInstance()->getConnection();

echo "MicroPMS Cron Scheduler\n";
echo str_repeat('-', 40) . "\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ═══════════════════════════════════════════════════════════════
// 0. NIGHT AUDIT — Configurable end-of-day process (Per Property)
// ═══════════════════════════════════════════════════════════════

$propStmt = $db->query("SELECT id, name FROM properties WHERE is_active = 1");
$properties = $propStmt->fetchAll();

foreach ($properties as $prop) {
    $propId = (int)$prop['id'];
    $propName = $prop['name'];
    
    $auditEnabled = getSetting($db, 'night_audit_enabled', 'false', $propId);
    $auditTime = getSetting($db, 'night_audit_time', '02:00', $propId);
    $currentHour = (int)date('G');
    $currentMinute = (int)date('i');
    $auditHour = (int)explode(':', $auditTime)[0];
    $auditMinute = (int)explode(':', $auditTime)[1];

    if ($auditEnabled === 'true' && $currentHour === $auditHour && abs($currentMinute - $auditMinute) < 2) {
        echo "[NIGHT AUDIT] Running night audit for Property {$propId} ({$propName})...\n";
        $audit = new NightAudit($db, $propId);
        $result = $audit->run('cron');
        
        if ($result['status'] === 'success') {
            echo "[NIGHT AUDIT][$propName] Completed successfully.\n";
            echo "  Total rooms: {$result['total_rooms']}\n";
            echo "  Occupied: {$result['occupied_rooms']}\n";
            echo "  Revenue pending: ₹{$result['revenue_pending']}\n";
        } elseif ($result['status'] === 'skipped') {
            echo "[NIGHT AUDIT][$propName] Skipped: {$result['message']}\n";
        } else {
            echo "[NIGHT AUDIT][$propName] Failed: {$result['error_message']}\n";
        }
    } else {
        // Uncomment to debug
        // echo "[NIGHT AUDIT][$propName] Not scheduled for this time (configured: {$auditTime}, current: " . date('H:i') . ")\n";
    }
}

echo "\n";

require_once __DIR__ . '/../pms_core/services/IcalService.php';
require_once __DIR__ . '/../pms_core/services/ReportingCacheService.php';

$minute = (int)date('i');
echo "[CHANNELS] Syncing iCal feeds and report cache...\n";
foreach ($properties as $prop) {
    $propId = (int)$prop['id'];
    $propName = $prop['name'];
    if ($minute % 15 === 0) {
        try {
            $sync = IcalService::syncProperty($db, $propId);
            echo "[ICAL][$propName] feeds={$sync['feeds']} imported={$sync['imported']} errors={$sync['errors']}\n";
        } catch (\Throwable $e) {
            echo "[ICAL][$propName] Failed: " . $e->getMessage() . "\n";
        }
    }
    try {
        $days = ReportingCacheService::refreshProperty($db, $propId, 2);
        echo "[REPORTS][$propName] Cached {$days} day(s).\n";
    } catch (\Throwable $e) {
        echo "[REPORTS][$propName] Failed: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// ═══════════════════════════════════════════════════════════════
// 1. DAILY SUMMARY — send at 11 PM IST
// ═══════════════════════════════════════════════════════════════

$hour = (int)date('G');
if ($hour === 23) {
    echo "[DAILY SUMMARY] Sending daily summary...\n";
    $summaryFile = dirname(__DIR__) . '/pms_core/api_endpoints/admin_daily_summary.php';
    if (file_exists($summaryFile)) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        require $summaryFile;
        ob_get_clean();
        echo "[DAILY SUMMARY] Sent.\n";
    } else {
        echo "[DAILY SUMMARY] Summary file not found at: {$summaryFile}\n";
    }
}

// ═══════════════════════════════════════════════════════════════
// 2. ABANDONED HOLDS — Revert pending_hold older than 15 mins
// ═══════════════════════════════════════════════════════════════

echo "[SWEEP] Checking abandoned holds...\n";
$sweepStmt = $db->prepare("UPDATE bookings SET payment_status = 'cancelled', booking_status = 'cancelled' 
                           WHERE payment_status = 'pending_hold' 
                           AND booking_status = 'booked'
                           AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
$sweepStmt->execute();
$sweptCount = $sweepStmt->rowCount();
echo "[SWEEP] Swept {$sweptCount} abandoned holds.\n";

// ═══════════════════════════════════════════════════════════════
// 3. PRE-DEPARTURE WARNING — 30 minutes before check_out
// ═══════════════════════════════════════════════════════════════

echo "[PRE-DEPARTURE] Checking upcoming checkouts...\n";
$warnStmt = $db->prepare("SELECT b.*, r.room_number, g.phone as guest_phone FROM bookings b 
                          JOIN rooms r ON b.room_id = r.id 
                          LEFT JOIN guests g ON b.guest_id = g.id
                          WHERE b.payment_status = 'completed_paid'
                          AND b.booking_status = 'checked_in'
                          AND b.check_out BETWEEN DATE_ADD(NOW(), INTERVAL 25 MINUTE) AND DATE_ADD(NOW(), INTERVAL 30 MINUTE)");
$warnStmt->execute();
$warnings = $warnStmt->fetchAll();

foreach ($warnings as $w) {
    $msg = "Reminder: Your checkout for Room {$w['room_number']} is at " . date('H:i', strtotime($w['check_out'])) . ".";
    $phoneE164 = PhoneHelper::toE164($w['guest_phone'] ?? '');
    if ($phoneE164 === null) {
        $phoneE164 = preg_replace('/[^0-9]/', '', $w['guest_phone'] ?? '');
    }
    if (empty($phoneE164)) continue;
    
    $autoTriggered = NotificationRelay::triggerAutomation('pre_departure', $phoneE164, (int)$w['id'], [
        'checkout_time' => date('h:i A', strtotime($w['check_out']))
    ]);
    
    if (!$autoTriggered) {
        $waRes = NotificationRelay::sendWhatsApp($phoneE164, $msg, false);
        echo "[PRE-DEPARTURE] Sent fallback warning for booking {$w['id']}.\n";
    } else {
        echo "[PRE-DEPARTURE] Triggered template warning for booking {$w['id']}.\n";
    }
}

// ═══════════════════════════════════════════════════════════════
// 3.5. POS ABANDONED ORDERS — Pending delivery > 30 mins
// ═══════════════════════════════════════════════════════════════

echo "[POS ABANDONED] Checking abandoned POS orders...\n";
$posStmt = $db->prepare("SELECT o.id, o.property_id, r.room_number FROM pos_orders o
                         JOIN bookings b ON o.booking_id = b.id
                         JOIN rooms r ON b.room_id = r.id
                         WHERE o.status = 'paid' AND o.delivery_status = 'pending'
                         AND o.recorded_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
$posStmt->execute();
$abandonedOrders = $posStmt->fetchAll();

foreach ($abandonedOrders as $order) {
    $msg = "Alert: POS Order #{$order['id']} for Room {$order['room_number']} has been pending delivery for over 30 minutes.";
    NotificationRelay::sendTelegram($msg, 'system_alert');
    echo "[POS ABANDONED] Sent alert for order {$order['id']}.\n";
}

// ═══════════════════════════════════════════════════════════════
// 4. OVERSTAY FLAG — Current time surpassed check_out
// ═══════════════════════════════════════════════════════════════

echo "[OVERSTAY] Checking for overstays...\n";
$overstayStmt = $db->prepare("SELECT b.*, r.room_number, g.name as guest_name, g.phone as guest_phone FROM bookings b 
                              JOIN rooms r ON b.room_id = r.id
                              LEFT JOIN guests g ON b.guest_id = g.id
                              WHERE b.booking_status = 'checked_in'
                              AND b.check_out BETWEEN DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND NOW()");
$overstayStmt->execute();
$overstays = $overstayStmt->fetchAll();

foreach ($overstays as $o) {
    // Room stays occupied until actual checkout; we only alert staff here.

    $msg = "\u26a0\ufe0f <b>Overstay Alert</b>\n\nRoom: {$o['room_number']}\nGuest: " . htmlspecialchars((string)($o['guest_name'] ?? 'N/A')) . "\nCheckout was: {$o['check_out']}\nGuest has not checked out. Please investigate.";

    $context = [
        'guest_name'     => $o['guest_name'] ?? 'N/A',
        'room_number'    => $o['room_number'],
        'check_out_date' => $o['check_out']
    ];
    NotificationRelay::sendTelegram($msg, 'overstay', $context);
    echo "[OVERSTAY] Flagged overstay for room {$o['room_number']}.\n";
}

echo "\n" . str_repeat('-', 40) . "\n";
echo "Cron completed at " . date('H:i:s') . "\n";

// Release the lock
if (isset($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

// ═══════════════════════════════════════════════════════════════
// HELPER: Get system setting
// ═══════════════════════════════════════════════════════════════
function getSetting(PDO $db, string $key, string $default = '', int $propertyId = 1): string {
    $stmt = $db->prepare("SELECT key_value FROM system_settings WHERE property_id = ? AND key_name = ?");
    $stmt->execute([$propertyId, $key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}
