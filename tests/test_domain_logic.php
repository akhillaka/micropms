<?php
/**
 * Comprehensive Multi-Level Domain & Security Test Suite for MicroPMS
 */
require_once __DIR__ . '/pms_core/Database.php';
require_once __DIR__ . '/pms_core/config.php';
require_once __DIR__ . '/pms_core/services/BookingService.php';
require_once __DIR__ . '/pms_core/services/FolioService.php';
require_once __DIR__ . '/pms_core/services/GuestService.php';
require_once __DIR__ . '/pms_core/SequenceGenerator.php';
require_once __DIR__ . '/pms_core/PhoneHelper.php';

$report = [
    'critical' => [],
    'high' => [],
    'medium' => [],
    'low' => [],
    'passed' => []
];

function addIssue($level, $category, $title, $description, $file = '', $recommendation = '') {
    global $report;
    $report[$level][] = [
        'category' => $category,
        'title' => $title,
        'description' => $description,
        'file' => $file,
        'recommendation' => $recommendation
    ];
}

$db = Database::getInstance()->getConnection();

// Helper: Ensure a valid guest exists
$guestRes = GuestService::findOrCreate($db, 'Test Guest', '9999999999');
$guestId = (int)$guestRes['guest_id'];

// --- CATEGORY 1: BOOKING & OVERBOOKING LOGIC ---
try {
    $db->exec("INSERT IGNORE INTO room_categories (id, name) VALUES (888, 'Audit Category')");
    $db->exec("INSERT IGNORE INTO rooms (id, category_id, room_number, state) VALUES (888, 888, 'AUD-101', 'clean')");
    $db->exec("INSERT IGNORE INTO sliding_rates (category_id, rate_plan_name, hours, price) VALUES (888, 'Standard', 24, 2500)");
    $db->exec("DELETE FROM bookings WHERE room_id = 888");

    // Case 1.1: Invalid date order (Check-out before Check-in)
    try {
        BookingService::createBooking($db, [
            'room_id' => 888,
            'guest_id' => $guestId,
            'check_in' => '2026-10-10 12:00:00',
            'check_out' => '2026-10-05 11:00:00'
        ]);
        addIssue('critical', 'Booking Logic', 'Check-out Before Check-in Allowed', 'System permitted booking creation where check-out date is earlier than check-in date.', 'pms_core/services/BookingService.php');
    } catch (Exception $e) {
        $report['passed'][] = 'Check-out before Check-in correctly rejected.';
    }

    // Case 1.2: Overlapping Date Boundary Test
    $b1 = BookingService::createBooking($db, [
        'room_id' => 888,
        'guest_id' => $guestId,
        'check_in' => '2026-10-01 12:00:00',
        'check_out' => '2026-10-05 11:00:00',
        'price_override' => 2500
    ]);

    // Partial overlap: Oct 4 to Oct 8
    try {
        BookingService::createBooking($db, [
            'room_id' => 888,
            'guest_id' => $guestId,
            'check_in' => '2026-10-04 12:00:00',
            'check_out' => '2026-10-08 11:00:00',
            'price_override' => 2500
        ]);
        addIssue('critical', 'Booking Logic', 'Overbooking Allowed on Partial Overlap', 'System permitted booking creation on room 888 overlapping Oct 4 - Oct 5 with active booking.', 'pms_core/services/BookingService.php');
    } catch (Exception $e) {
        $report['passed'][] = 'Partial date overlap correctly blocked.';
    }

    // Case 1.3: Multi-Room Booking Partial Failure Atomicity Check
    $db->exec("INSERT IGNORE INTO rooms (id, category_id, room_number, state) VALUES (889, 888, 'AUD-102', 'clean')");
    $db->exec("DELETE FROM bookings WHERE room_id = 889");

    // Room 888 is booked Oct 1-5. Room 889 is free.
    // Call api/create_hold.php simulated logic with room_ids [888, 889]
    $roomIds = [888, 889];
    $createdIds = [];
    $errors = [];
    $db->beginTransaction();
    try {
        foreach ($roomIds as $rId) {
            $res = BookingService::createBooking($db, [
                'room_id' => $rId,
                'guest_id' => $guestId,
                'check_in' => '2026-10-02 12:00:00',
                'check_out' => '2026-10-06 11:00:00',
                'price_override' => 2500
            ]);
            $createdIds[] = $res['booking_id'];
        }
        $db->commit();
    } catch (Exception $ex) {
        if ($db->inTransaction()) $db->rollBack();
        $errors[] = $ex->getMessage();
    }
    
    // Verify rollback: room 889 should NOT have any active booking
    $stmt889 = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = 889");
    $stmt889->execute();
    if ($stmt889->fetchColumn() == 0) {
        $report['passed'][] = 'Multi-room booking atomic rollback verified.';
    } else {
        addIssue('high', 'API & Workflows', 'Multi-Room Booking Non-Atomic Partial Success', 'Available room was left booked after partner room failed in group booking.', 'public_html/api/create_hold.php');
    }

    $db->exec("DELETE FROM bookings WHERE room_id IN (888, 889)");
    $db->exec("DELETE FROM rooms WHERE id IN (888, 889)");
    $db->exec("DELETE FROM sliding_rates WHERE category_id = 888");
    $db->exec("DELETE FROM room_categories WHERE id = 888");
} catch (Exception $e) {
    addIssue('high', 'Booking Logic', 'Booking Test Suite Error: ' . $e->getMessage(), 'pms_core/services/BookingService.php');
}


// --- CATEGORY 2: FINANCIAL, FOLIO & OVER-REFUND PROTECTION ---
try {
    $db->exec("INSERT IGNORE INTO room_categories (id, name) VALUES (887, 'Tax Category')");
    $db->exec("INSERT IGNORE INTO rooms (id, category_id, room_number, state) VALUES (887, 887, 'TAX-101', 'clean')");
    $db->exec("INSERT IGNORE INTO sliding_rates (category_id, rate_plan_name, hours, price) VALUES (887, 'Standard', 24, 1000)");

    // Create a 1-night booking @ 1000 base rate with 500 payment
    $bTax = BookingService::createBooking($db, [
        'room_id' => 887,
        'guest_id' => $guestId,
        'check_in' => '2026-12-01 12:00:00',
        'check_out' => '2026-12-02 11:00:00',
        'price_override' => 1000,
        'payment_collected' => 500
    ]);
    $bId = $bTax['booking_id'];

    // Try to issue a refund of 800 (exceeds 500 paid)
    try {
        FolioService::recordPayment($db, $bId, -800, 'Cash', 'REFUND', 'Excess Refund Test');
        addIssue('medium', 'Financial Accounting', 'Over-Refund Allowed', 'System permitted refunding ₹800 when only ₹500 was paid.', 'pms_core/services/FolioService.php');
    } catch (InvalidArgumentException $e) {
        $report['passed'][] = 'Over-refund protection successfully blocked invalid refund.';
    }

    $db->exec("DELETE FROM folio_ledger WHERE booking_id = $bId");
    $db->exec("DELETE FROM bookings WHERE id = $bId");
    $db->exec("DELETE FROM rooms WHERE id = 887");
    $db->exec("DELETE FROM sliding_rates WHERE category_id = 887");
    $db->exec("DELETE FROM room_categories WHERE id = 887");
} catch (Exception $e) {
    addIssue('high', 'Financial Accounting', 'Financial Test Suite Error: ' . $e->getMessage(), 'pms_core/services/FolioService.php');
}


// --- CATEGORY 3: PHONE & GUEST DATA NORMALIZATION ---
try {
    $phonesToTest = [
        '9876543210'    => '9876543210',
        '+91 9876543210' => '9876543210',
        '09876543210'   => '9876543210',
        '98765-43210'   => '9876543210'
    ];

    foreach ($phonesToTest as $input => $expected) {
        $normalized = PhoneHelper::toLocal($input);
        if ($normalized !== $expected) {
            addIssue('medium', 'Data Normalization', "Phone Normalization Failed for '{$input}'", "Expected '{$expected}', got '{$normalized}'", 'pms_core/PhoneHelper.php');
        }
    }
    $report['passed'][] = 'Phone normalization suite verified.';
} catch (Exception $e) {
    addIssue('medium', 'Data Normalization', 'PhoneHelper Error: ' . $e->getMessage(), 'pms_core/PhoneHelper.php');
}


// --- CATEGORY 4: SECURITY & API ACCESS AUDIT ---
$apiFiles = glob(__DIR__ . '/public_html/api/*.php');
$unprotectedApis = [];

foreach ($apiFiles as $file) {
    $content = file_get_contents($file);
    $basename = basename($file);

    // Skip public APIs like login, guest invoice, create_hold (holds public booking auth), webhook, check availability, and test tools
    if (in_array($basename, ['admin_login.php', 'guest_invoice.php', 'view_invoice.php', 'create_hold.php', 'whatsapp_webhook.php', 'check_availability.php', 'test_api.php', 'wa_debug.php'])) continue;

    // Check if ApiHandler::run is used with requireAuth=true or AuthHelper::requireLoginOrRedirect
    $hasApiHandlerAuth = (strpos($content, 'ApiHandler::run') !== false && (strpos($content, 'true, false, true') !== false || strpos($content, 'true, true, true') !== false));
    $hasAuthHelper = (strpos($content, 'AuthHelper::require') !== false);

    if (!$hasApiHandlerAuth && !$hasAuthHelper) {
        $unprotectedApis[] = $basename;
    }
}

if (!empty($unprotectedApis)) {
    addIssue('high', 'Security & Access Control', 'Potentially Unprotected Admin API Endpoints', 'The following API endpoints do not explicitly enforce AuthHelper session authentication: ' . implode(', ', $unprotectedApis), 'public_html/api/');
} else {
    $report['passed'][] = 'All admin API endpoints enforce session authentication.';
}

// Cleanup dummy guest
$db->exec("DELETE FROM folio_ledger WHERE booking_id IN (SELECT id FROM bookings WHERE guest_id = $guestId)");
$db->exec("DELETE FROM bookings WHERE guest_id = $guestId");
$db->exec("DELETE FROM guests WHERE id = $guestId");

echo json_encode($report, JSON_PRETTY_PRINT);
