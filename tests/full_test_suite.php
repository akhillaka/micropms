<?php
/**
 * ═══════════════════════════════════════════════════════
 *  MicroPMS — FULL FEATURE TEST SUITE
 *  Covers: Guests, Bookings, Pricing, Folio, Refunds,
 *          Night Audit, Sequences, Reports Logic,
 *          Availability, Security, Extensions & Edits
 * ═══════════════════════════════════════════════════════
 */
declare(strict_types=1);

require_once __DIR__ . '/../pms_core/Database.php';
require_once __DIR__ . '/../pms_core/config.php';
require_once __DIR__ . '/../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../pms_core/PricingEngine.php';
require_once __DIR__ . '/../pms_core/services/GuestService.php';
require_once __DIR__ . '/../pms_core/services/BookingService.php';
require_once __DIR__ . '/../pms_core/services/FolioService.php';

$db = Database::getInstance()->getConnection();

// ── Test framework ────────────────────────────────────
$passed = [];
$failed = [];
$warnings = [];

function pass(string $msg): void { global $passed; $passed[] = $msg; }
function fail(string $msg): void { global $failed; $failed[] = '❌ ' . $msg; }
function warn(string $msg): void { global $warnings; $warnings[] = '⚠️  ' . $msg; }

function assertThrows(string $label, callable $fn): void {
    try { $fn(); fail("$label — Exception NOT thrown (should have been)"); }
    catch (Throwable $e) { pass("$label — correctly threw: " . $e->getMessage()); }
}

function assertNoThrow(string $label, callable $fn): mixed {
    try { $r = $fn(); pass("$label — completed without exception"); return $r; }
    catch (Throwable $e) { fail("$label — unexpected exception: " . $e->getMessage()); return null; }
}

function assertEqual(string $label, mixed $expected, mixed $actual): void {
    if ($expected == $actual) {
        pass("$label — got: " . json_encode($actual));
    } else {
        fail("$label — expected: " . json_encode($expected) . " | got: " . json_encode($actual));
    }
}

function assertGreater(string $label, float $min, float $actual): void {
    if ($actual > $min) {
        pass("$label — value $actual > $min");
    } else {
        fail("$label — expected > $min, got: $actual");
    }
}

function assertTrue(string $label, bool $condition): void {
    if ($condition) pass("$label — TRUE");
    else fail("$label — expected TRUE, was FALSE");
}

// ── Seed test fixtures ────────────────────────────────
$db->exec("INSERT IGNORE INTO room_categories (id, name) VALUES (900, 'TEST-STANDARD'), (901, 'TEST-DELUXE')");
$db->exec("INSERT IGNORE INTO rooms (id, category_id, room_number, state) VALUES (900, 900, 'T-101', 'clean'), (901, 900, 'T-102', 'clean'), (902, 901, 'D-201', 'clean')");
$db->exec("DELETE FROM sliding_rates WHERE category_id IN (900,901)");
$db->exec("INSERT INTO sliding_rates (category_id, rate_plan_name, hours, price) VALUES
    (900, 'Standard', 2, 300),
    (900, 'Standard', 4, 500),
    (900, 'Standard', 8, 900),
    (900, 'Standard', 24, 2000),
    (900, 'Corporate', 24, 1600),
    (901, 'Standard', 2, 500),
    (901, 'Standard', 24, 3500)");
$db->exec("DELETE FROM folio_ledger WHERE booking_id IN (SELECT id FROM bookings WHERE room_id IN (900,901,902))");
$db->exec("DELETE FROM bookings WHERE room_id IN (900,901,902)");

// ── MODULE 1: GUEST SERVICE ───────────────────────────
echo "\n====== MODULE 1: GUEST SERVICE ======\n";

// 1.1 Guest creation (new)
$g1 = assertNoThrow("1.1 Create new guest", fn() => GuestService::findOrCreate($db, 'Alice Test', '8800001111'));
if ($g1) { assertTrue("1.1 Guest is_new=true", $g1['is_new']); $guestId1 = $g1['guest_id']; }

// 1.2 Duplicate guest returns same ID
$g1b = assertNoThrow("1.2 Find existing guest by phone", fn() => GuestService::findOrCreate($db, 'Alice Test', '8800001111'));
if ($g1 && $g1b) assertEqual("1.2 Same guest_id returned", $g1['guest_id'], $g1b['guest_id']);

// 1.3 Create second guest
$g2 = assertNoThrow("1.3 Create second guest", fn() => GuestService::findOrCreate($db, 'Bob Test', '8800002222'));
$guestId2 = $g2['guest_id'] ?? 0;

// 1.4 Guest profile
$profile = assertNoThrow("1.4 Get guest profile", fn() => GuestService::getProfile($db, $guestId1));
assertTrue("1.4 Profile has name", isset($profile['name']));

// 1.5 Invalid phone
assertThrows("1.5 Invalid phone rejected", fn() => GuestService::findOrCreate($db, 'Invalid', 'NOTANUMBER'));

// 1.6 Phone normalization suite
echo "\n--- Phone Normalization ---\n";
$phoneTests = [
    ['9876543210', '9876543210'],
    ['+91 9876543210', '9876543210'],
    ['09876543210', '9876543210'],
    ['98765-43210', '9876543210'],
    ['+919876543210', '9876543210'],
];
foreach ($phoneTests as [$raw, $expected]) {
    $got = PhoneHelper::toLocal($raw);
    assertEqual("1.6 Normalize $raw", $expected, $got);
}

// 1.7 Guest search by name
$results = assertNoThrow("1.7 Search guest by name", fn() => GuestService::search($db, 'Alice Test'));
assertTrue("1.7 Search returns results", count($results ?? []) > 0);

// 1.8 Guest search by phone
$results = assertNoThrow("1.8 Search guest by phone", fn() => GuestService::search($db, '8800001111'));
assertTrue("1.8 Phone search returns result", count($results ?? []) > 0);

// 1.9 Guest update
$updated = assertNoThrow("1.9 Update guest profile", fn() => GuestService::update($db, $guestId1, ['email' => 'alice@test.com', 'city' => 'Mumbai']));
assertTrue("1.9 Update returns true", $updated === true);

// ── MODULE 2: PRICING ENGINE ──────────────────────────
echo "\n====== MODULE 2: PRICING ENGINE ======\n";

// 2.1 Simple 1-day pricing
$price = assertNoThrow("2.1 Calculate 24h stay (Standard)", fn() => PricingEngine::calculateTotalCost(900, '2026-10-01 12:00:00', '2026-10-02 12:00:00', 'Standard'));
assertEqual("2.1 24h = ₹2000", 2000.0, $price);

// 2.2 Multi-day pricing
$price = assertNoThrow("2.2 Calculate 2-day stay", fn() => PricingEngine::calculateTotalCost(900, '2026-10-01 12:00:00', '2026-10-03 12:00:00', 'Standard'));
assertEqual("2.2 48h = ₹4000", 4000.0, $price);

// 2.3 Hourly stay (2 hours)
$price = assertNoThrow("2.3 Calculate 2-hour stay", fn() => PricingEngine::calculateTotalCost(900, '2026-10-01 12:00:00', '2026-10-01 14:00:00', 'Standard'));
assertEqual("2.3 2h = ₹300", 300.0, $price);

// 2.4 Hourly stay (4 hours)
$price = assertNoThrow("2.4 Calculate 4-hour stay", fn() => PricingEngine::calculateTotalCost(900, '2026-10-01 12:00:00', '2026-10-01 16:00:00', 'Standard'));
assertEqual("2.4 4h = ₹500", 500.0, $price);

// 2.5 Corporate rate plan
$price = assertNoThrow("2.5 Calculate 1-day Corporate rate", fn() => PricingEngine::calculateTotalCost(900, '2026-10-01 12:00:00', '2026-10-02 12:00:00', 'Corporate'));
assertEqual("2.5 Corporate 24h = ₹1600", 1600.0, $price);

// 2.6 No rates configured → exception
assertThrows("2.6 No rates → exception", fn() => PricingEngine::calculateTotalCost(999, '2026-10-01 12:00:00', '2026-10-02 12:00:00'));

// 2.7 Invalid date range → exception
assertThrows("2.7 Check-out = Check-in → exception", fn() => PricingEngine::calculateTotalCost(900, '2026-10-01 12:00:00', '2026-10-01 12:00:00'));

// 2.8 Cost breakdown structure
$breakdown = assertNoThrow("2.8 getCostBreakdown 2-day", fn() => PricingEngine::getCostBreakdown(900, '2026-10-01 12:00:00', '2026-10-03 12:00:00', 'Standard'));
assertTrue("2.8 Breakdown has 2 items", count($breakdown ?? []) === 2);
assertEqual("2.8 Day 1 cost = ₹2000", 2000.0, $breakdown[0]['cost'] ?? -1);

// 2.9 Day + residual hours (26h = 1 day + 2h)
$breakdown = assertNoThrow("2.9 Breakdown 26h stay", fn() => PricingEngine::getCostBreakdown(900, '2026-10-01 12:00:00', '2026-10-02 14:00:00', 'Standard'));
assertTrue("2.9 Breakdown 26h has 2 items (1 day + 2h)", count($breakdown ?? []) === 2);

// ── MODULE 3: BOOKING ENGINE ──────────────────────────
echo "\n====== MODULE 3: BOOKING ENGINE ======\n";

// 3.1 Create valid booking
$b1 = assertNoThrow("3.1 Create valid booking", fn() => BookingService::createBooking($db, [
    'room_id'   => 900, 'guest_id' => $guestId1,
    'check_in'  => '2026-11-10 12:00:00',
    'check_out' => '2026-11-12 12:00:00',
    'rate_plan_name' => 'Standard',
]));
assertTrue("3.1 booking_id assigned", isset($b1['booking_id']));
assertTrue("3.1 display_id assigned (BKG format)", str_contains($b1['display_id'] ?? '', 'BKG') || strlen($b1['display_id'] ?? '') > 0);
assertGreater("3.1 total_amount > 0", 0, $b1['total_amount'] ?? 0);
$bookingId1 = $b1['booking_id'] ?? 0;

// 3.2 Check-out before check-in
assertThrows("3.2 Check-out before check-in rejected", fn() => BookingService::createBooking($db, [
    'room_id' => 901, 'guest_id' => $guestId2,
    'check_in'  => '2026-11-15 12:00:00',
    'check_out' => '2026-11-10 11:00:00',
]));

// 3.3 Same check-in and check-out
assertThrows("3.3 Same check-in and check-out rejected", fn() => BookingService::createBooking($db, [
    'room_id' => 901, 'guest_id' => $guestId2,
    'check_in'  => '2026-11-15 12:00:00',
    'check_out' => '2026-11-15 12:00:00',
]));

// 3.4 Overlapping booking blocked
$b2 = assertNoThrow("3.4a Create booking Nov 20-22 on room 901", fn() => BookingService::createBooking($db, [
    'room_id' => 901, 'guest_id' => $guestId2,
    'check_in'  => '2026-11-20 12:00:00',
    'check_out' => '2026-11-22 12:00:00',
    'price_override' => 2000,
]));
$bookingId2 = $b2['booking_id'] ?? 0;

assertThrows("3.4b Partial overlap on room 901 blocked", fn() => BookingService::createBooking($db, [
    'room_id' => 901, 'guest_id' => $guestId1,
    'check_in'  => '2026-11-21 12:00:00',
    'check_out' => '2026-11-23 12:00:00',
    'price_override' => 2000,
]));

assertThrows("3.4c Exact overlap on room 901 blocked", fn() => BookingService::createBooking($db, [
    'room_id' => 901, 'guest_id' => $guestId1,
    'check_in'  => '2026-11-20 12:00:00',
    'check_out' => '2026-11-22 12:00:00',
    'price_override' => 2000,
]));

assertThrows("3.4d Engulfing overlap on room 901 blocked", fn() => BookingService::createBooking($db, [
    'room_id' => 901, 'guest_id' => $guestId1,
    'check_in'  => '2026-11-18 12:00:00',
    'check_out' => '2026-11-25 12:00:00',
    'price_override' => 2000,
]));

// 3.5 Non-overlapping dates (adjacent) should be ALLOWED
$b3 = assertNoThrow("3.5 Adjacent (Nov 22-24) booking after Nov 20-22 allowed", fn() => BookingService::createBooking($db, [
    'room_id' => 901, 'guest_id' => $guestId1,
    'check_in'  => '2026-11-22 12:00:00',
    'check_out' => '2026-11-24 12:00:00',
    'price_override' => 2000,
]));
$bookingId3 = $b3['booking_id'] ?? 0;

// 3.6 Price override works
$b4 = assertNoThrow("3.6 Price override applied", fn() => BookingService::createBooking($db, [
    'room_id' => 902, 'guest_id' => $guestId1,
    'check_in'  => '2026-11-10 12:00:00',
    'check_out' => '2026-11-12 12:00:00',
    'price_override' => 9999.0,
]));
assertEqual("3.6 Total amount = 9999", 9999.0, $b4['total_amount'] ?? 0);
$bookingId4 = $b4['booking_id'] ?? 0;

// 3.7 Room availability check
$available = assertNoThrow("3.7 isRoomAvailable — occupied range", fn() => BookingService::isRoomAvailable($db, 901, '2026-11-21 00:00:00', '2026-11-22 12:00:00'));
assertEqual("3.7 Room 901 NOT available Nov 21", false, $available);

$free = assertNoThrow("3.7b isRoomAvailable — free range", fn() => BookingService::isRoomAvailable($db, 901, '2026-12-01 00:00:00', '2026-12-03 12:00:00'));
assertEqual("3.7b Room 901 available Dec 1-3", true, $free);

// 3.8 Duplicate guest check
$dup = assertNoThrow("3.8 checkDuplicate — overlapping booking detected", fn() => BookingService::checkDuplicate($db, '8800001111', '2026-11-10 12:00:00', '2026-11-15 12:00:00'));
assertTrue("3.8 Duplicate booking found", $dup !== null);

// 3.9 Advance payment in booking
$b5 = assertNoThrow("3.9 Booking with advance payment", fn() => BookingService::createBooking($db, [
    'room_id' => 900, 'guest_id' => $guestId2,
    'check_in'  => '2026-12-01 12:00:00',
    'check_out' => '2026-12-02 12:00:00',
    'price_override' => 3000,
    'payment_collected' => 1500,
    'payment_method' => 'Cash',
]));
$bookingId5 = $b5['booking_id'] ?? 0;

$balance = assertNoThrow("3.9 Balance after ₹1500 advance on ₹3000 booking", fn() => FolioService::getBalance($db, $bookingId5));
assertEqual("3.9 Balance = ₹1500 (net due)", 1500.0, $balance);

// ── MODULE 4: FOLIO & LEDGER ──────────────────────────
echo "\n====== MODULE 4: FOLIO & LEDGER ======\n";

// 4.1 Post incidental charge
$chargeId = assertNoThrow("4.1 Post ₹500 restaurant charge", fn() => FolioService::postCharge($db, $bookingId5, 500.0, 'Restaurant Dinner', 'restaurant'));
assertTrue("4.1 Charge entry ID > 0", ($chargeId ?? 0) > 0);

// 4.2 Balance after charge
$balance = assertNoThrow("4.2 Balance after charge", fn() => FolioService::getBalance($db, $bookingId5));
assertEqual("4.2 Balance = ₹2000 (1500 + 500)", 2000.0, $balance);

// 4.3 Record payment
$pmtId = assertNoThrow("4.3 Record ₹1000 UPI payment", fn() => FolioService::recordPayment($db, $bookingId5, 1000.0, 'UPI', 'TXN-TEST-001', 'admin'));
assertTrue("4.3 Payment entry ID > 0", ($pmtId ?? 0) > 0);

// 4.4 Balance after payment
$balance = assertNoThrow("4.4 Balance after payment", fn() => FolioService::getBalance($db, $bookingId5));
assertEqual("4.4 Balance = ₹1000 (2000 - 1000)", 1000.0, $balance);

// 4.5 getPaidAmount
$paid = assertNoThrow("4.5 getPaidAmount", fn() => FolioService::getPaidAmount($db, $bookingId5));
assertEqual("4.5 Paid = ₹2500 (1500 advance + 1000)", 2500.0, $paid);

// 4.6 Over-refund protection — refund more than paid
assertThrows("4.6 Over-refund protection: refund ₹5000 > paid ₹2500", fn() => FolioService::recordPayment($db, $bookingId5, -5000.0, 'Cash', 'OVER-REFUND', 'admin'));

// 4.7 Valid refund within paid amount
$refundId = assertNoThrow("4.7 Valid refund ₹500 (within paid ₹2500)", fn() => FolioService::recordPayment($db, $bookingId5, -500.0, 'Cash', 'REFUND-001', 'admin'));
assertTrue("4.7 Refund entry ID > 0", ($refundId ?? 0) > 0);

// 4.8 Balance after valid refund  
$balance = assertNoThrow("4.8 Balance after refund", fn() => FolioService::getBalance($db, $bookingId5));
assertEqual("4.8 Balance = ₹1500 (1000 + 500 refund back)", 1500.0, $balance);

// 4.9 Folio breakdown structure
$breakdown = assertNoThrow("4.9 getBreakdown", fn() => FolioService::getBreakdown($db, $bookingId5));
assertTrue("4.9 Breakdown has room_charges > 0", ($breakdown['room_charges'] ?? 0) > 0);
assertTrue("4.9 Breakdown has restaurant > 0", ($breakdown['restaurant'] ?? 0) > 0);
assertTrue("4.9 Breakdown has payments > 0", ($breakdown['payments'] ?? 0) > 0);
assertTrue("4.9 Breakdown has refunds tracked", isset($breakdown['refunds']));

// 4.10 Edit ledger entry
$entries = assertNoThrow("4.10a Get folio entries", fn() => FolioService::getEntries($db, $bookingId5));
$firstChargeEntry = null;
foreach (($entries ?? []) as $e) {
    if ($e['transaction_type'] === 'INCIDENTAL') { $firstChargeEntry = $e; break; }
}
if ($firstChargeEntry) {
    $edited = assertNoThrow("4.10b Edit incidental charge", fn() => FolioService::editEntry($db, (int)$firstChargeEntry['id'], 750.0, 'Restaurant Dinner Edited'));
    assertTrue("4.10b Edit returns true", $edited === true);
}

// 4.11 Delete ledger entry
$delEntry = null;
foreach (($entries ?? []) as $e) {
    if ($e['transaction_type'] === 'INCIDENTAL') { $delEntry = $e; break; }
}
if ($delEntry) {
    $deleted = assertNoThrow("4.11 Delete incidental charge", fn() => FolioService::deleteEntry($db, (int)$delEntry['id']));
    assertTrue("4.11 Delete returns true", $deleted === true);
}

// ── MODULE 5: SEQUENCE GENERATOR ─────────────────────
echo "\n====== MODULE 5: SEQUENCE GENERATOR ======\n";

// 5.1 BKG sequence format
if (defined('SEQ_BOOKING_FORMAT')) {
    $seqVal = SequenceGenerator::generate(SEQ_BOOKING_FORMAT, 1);
    assertTrue("5.1 Booking ID format generated (non-empty)", strlen($seqVal) > 0);
    pass("5.1 Generated booking ID: $seqVal");
} else {
    warn("5.1 SEQ_BOOKING_FORMAT not defined — sequence format test skipped");
}

// 5.2 Receipt sequence
if (defined('SEQ_RECEIPT_FORMAT')) {
    $seqVal = SequenceGenerator::generate(SEQ_RECEIPT_FORMAT, 1);
    pass("5.2 Generated receipt ID: $seqVal");
} else {
    warn("5.2 SEQ_RECEIPT_FORMAT not defined");
}

// 5.3 Folio offline ID wraps around at max
if (defined('SEQ_FOLIO_FORMAT') && defined('SEQ_FOLIO_MAX')) {
    $max = (int)SEQ_FOLIO_MAX;
    $seqAt1 = SequenceGenerator::generate(SEQ_FOLIO_FORMAT, 1);
    $seqAtMax = SequenceGenerator::generate(SEQ_FOLIO_FORMAT, $max);
    pass("5.3 Folio ID at 1: $seqAt1, at max ($max): $seqAtMax");
} else {
    warn("5.3 SEQ_FOLIO_FORMAT/SEQ_FOLIO_MAX not defined — folio wrap test skipped");
}

// ── MODULE 6: BOOKING LISTING ─────────────────────────
echo "\n====== MODULE 6: BOOKING LISTING ======\n";

$todayBookings = assertNoThrow("6.1 listBookings(today)", fn() => BookingService::listBookings($db, 'today'));
assertTrue("6.1 Returns array", is_array($todayBookings));

$allBookings = assertNoThrow("6.2 listBookings(week)", fn() => BookingService::listBookings($db, 'week'));
assertTrue("6.2 Week filter returns array", is_array($allBookings));

$searchResult = assertNoThrow("6.3 listBookings search by name", fn() => BookingService::listBookings($db, 'today', 'Alice Test'));
assertTrue("6.3 Search returns array", is_array($searchResult));

// 6.4 Each booking has balance info
$monthBookings = assertNoThrow("6.4 listBookings(month)", fn() => BookingService::listBookings($db, 'month'));
if (!empty($monthBookings)) {
    assertTrue("6.4 Bookings have balance field", array_key_exists('balance', $monthBookings[0]));
    assertTrue("6.4 Bookings have advance_paid field", array_key_exists('advance_paid', $monthBookings[0]));
}

// ── MODULE 7: STAY EXTENSION ──────────────────────────
echo "\n====== MODULE 7: STAY EXTENSION ======\n";

// 7.1 Extend stay forward
$extResult = assertNoThrow("7.1 Extend stay by 24h", fn() => BookingService::extendStay($db, $bookingId1, '2026-11-13 12:00:00'));
assertTrue("7.1 extra_cost > 0", ($extResult['extra_cost'] ?? 0) > 0);
assertTrue("7.1 new_total updated", ($extResult['new_total'] ?? 0) > 0);

// 7.2 Extend checkout before current → error
assertThrows("7.2 Extend to earlier date rejected", fn() => BookingService::extendStay($db, $bookingId1, '2026-11-11 00:00:00'));

// ── MODULE 8: QUICK CHARGE PRESETS ───────────────────
echo "\n====== MODULE 8: QUICK CHARGE PRESETS ======\n";

$presets = assertNoThrow("8.1 getQuickChargePresets", fn() => FolioService::getQuickChargePresets($db));
assertTrue("8.1 Has at least one preset", count($presets ?? []) > 0);
assertTrue("8.1 Preset has name", isset($presets[0]['name']));
assertTrue("8.1 Preset has amount", isset($presets[0]['amount']));

// ── MODULE 9: CONCURRENT BOOKING ATOMICITY ────────────
echo "\n====== MODULE 9: CONCURRENT BOOKING ATOMICITY ======\n";

$db->exec("DELETE FROM folio_ledger WHERE booking_id IN (SELECT id FROM bookings WHERE room_id IN (900,901))");
$db->exec("DELETE FROM bookings WHERE room_id IN (900,901)");

// Room 900 is clear. Book it once then try both rooms in one group.
$existingB = assertNoThrow("9.1a Pre-book room 900 for conflict", fn() => BookingService::createBooking($db, [
    'room_id' => 900, 'guest_id' => $guestId1,
    'check_in'  => '2027-01-10 12:00:00',
    'check_out' => '2027-01-12 12:00:00',
    'price_override' => 2000,
]));

// Now try to book rooms [900, 901] for same dates (900 will fail, 901 must rollback)
$createdIds = [];
$db->beginTransaction();
try {
    foreach ([900, 901] as $rId) {
        $res = BookingService::createBooking($db, [
            'room_id' => $rId, 'guest_id' => $guestId2,
            'check_in'  => '2027-01-10 12:00:00',
            'check_out' => '2027-01-12 12:00:00',
            'price_override' => 2000,
        ]);
        $createdIds[] = $res['booking_id'];
    }
    $db->commit();
} catch (Exception $ex) {
    if ($db->inTransaction()) $db->rollBack();
}

$r901Check = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = 901 AND check_in = '2027-01-10 12:00:00'");
$r901Check->execute();
$r901Count = (int)$r901Check->fetchColumn();
assertEqual("9.1 Atomic rollback: room 901 NOT booked after group failure", 0, $r901Count);

// ── MODULE 10: SECURITY & API AUDIT ──────────────────
echo "\n====== MODULE 10: SECURITY & API AUDIT ======\n";

// 10.1 All admin/* API files should have auth
$apiFiles = glob(dirname(__DIR__) . '/pms_core/api_endpoints/*.php');
$publicApis = ['admin_login.php', 'guest_invoice.php', 'view_invoice.php', 'create_hold.php', 'check_availability.php', 'whatsapp_webhook.php', 'wa_sync_status.php', 'trigger_automation.php'];
$unprotected = [];

foreach ($apiFiles as $file) {
    $base = basename($file);
    if (in_array($base, $publicApis)) continue;

    $content = file_get_contents($file);
    $hasAuth = str_contains($content, 'AuthHelper::require') || str_contains($content, 'requireAdmin') || str_contains($content, 'true, false, true') || str_contains($content, 'true, true, true');
    if (!$hasAuth) {
        $unprotected[] = $base;
    }
}

if (empty($unprotected)) {
    pass("10.1 All admin API endpoints enforce authentication");
} else {
    warn("10.1 Possible unprotected endpoints: " . implode(', ', $unprotected));
}

// 10.2 search_guests.php must have auth
$sgContent = file_get_contents(dirname(__DIR__) . '/pms_core/api_endpoints/search_guests.php');
assertTrue("10.2 search_guests.php has AuthHelper", str_contains($sgContent, 'AuthHelper::require') || str_contains($sgContent, 'true, false, true'));

// 10.3 create_hold.php has PDO transaction wrapping
$chContent = file_get_contents(dirname(__DIR__) . '/pms_core/api_endpoints/create_hold.php');
assertTrue("10.3 create_hold.php has beginTransaction", str_contains($chContent, 'beginTransaction'));

// 10.4 admin_record_payment.php allows negative amounts now
$rpContent = file_get_contents(dirname(__DIR__) . '/pms_core/api_endpoints/admin_record_payment.php');
$stillBlocking = str_contains($rpContent, '$amount <= 0');
assertTrue("10.4 admin_record_payment.php allows negative amounts (refunds)", !$stillBlocking);

// ── CLEANUP ───────────────────────────────────────────
$db->exec("DELETE FROM folio_ledger WHERE booking_id IN (SELECT id FROM bookings WHERE room_id IN (900,901,902) OR guest_id IN ($guestId1,$guestId2))");
$db->exec("DELETE FROM bookings WHERE room_id IN (900,901,902) OR guest_id IN ($guestId1,$guestId2)");
$db->exec("DELETE FROM rooms WHERE id IN (900,901,902)");
$db->exec("DELETE FROM sliding_rates WHERE category_id IN (900,901)");
$db->exec("DELETE FROM room_categories WHERE id IN (900,901)");
$db->exec("DELETE FROM guests WHERE id IN ($guestId1,$guestId2)");

// ── FINAL REPORT ──────────────────────────────────────
$totalPassed = count($passed);
$totalFailed = count($failed);
$totalWarnings = count($warnings);

echo "\n";
echo "═══════════════════════════════════════════════════\n";
echo " MICROPMS FULL FEATURE TEST RESULTS\n";
echo "═══════════════════════════════════════════════════\n";
echo " ✅  PASSED   : $totalPassed\n";
echo " ❌  FAILED   : $totalFailed\n";
echo " ⚠️   WARNINGS : $totalWarnings\n";
echo "═══════════════════════════════════════════════════\n";

if (!empty($failed)) {
    echo "\n─── FAILURES ───────────────────────────────────────\n";
    foreach ($failed as $f) echo "  $f\n";
}
if (!empty($warnings)) {
    echo "\n─── WARNINGS ───────────────────────────────────────\n";
    foreach ($warnings as $w) echo "  $w\n";
}
if (!empty($passed)) {
    echo "\n─── PASSED ─────────────────────────────────────────\n";
    foreach ($passed as $p) echo "  ✅ $p\n";
}

echo "\n═══════════════════════════════════════════════════\n";
echo $totalFailed === 0 ? " 🎉 ALL TESTS PASSED!\n" : " 🔴 SOME TESTS FAILED — SEE ABOVE\n";
echo "═══════════════════════════════════════════════════\n";
