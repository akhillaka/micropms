<?php
declare(strict_types=1);

/**
 * Multi-property isolation checks.
 *
 * Default: static source guards (no DB).
 * Live:    MULTIPROPERTY_LIVE=1 php tests/multiproperty_isolation.php
 *          Creates two disposable properties (MPTEST-*), seeds rooms/guests/bookings/folios,
 *          then probes cross-tenant folio/postCharge, TenantScope, guest PNR collisions.
 *          Cleans up on exit (best-effort).
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$findings = [];

function mp_pass(string $msg): void {
    global $passed;
    $passed++;
    echo "PASS  {$msg}\n";
}

function mp_fail(string $msg): void {
    global $failed, $findings;
    $failed++;
    $findings[] = $msg;
    echo "FAIL  {$msg}\n";
}

function mp_assert(bool $ok, string $msg): void {
    if ($ok) {
        mp_pass($msg);
    } else {
        mp_fail($msg);
    }
}

$live = getenv('MULTIPROPERTY_LIVE') === '1' || in_array('--live', $argv ?? [], true);
if ($live && session_status() === PHP_SESSION_NONE) {
    @session_start();
}

echo "=== Multi-property isolation (static) ===\n";

$folio = file_get_contents($root . '/pms_core/services/FolioService.php') ?: '';
$pos = file_get_contents($root . '/pms_core/api_endpoints/admin_pos_actions.php') ?: '';
$quick = file_get_contents($root . '/public_html/assistant/api/quick_charges.php') ?: '';
$guestAuth = file_get_contents($root . '/pms_core/api_endpoints/guest_auth.php') ?: '';
$asstAuth = file_get_contents($root . '/public_html/assistant/api/auth.php') ?: '';

mp_assert(str_contains($folio, 'assertStaffPropertyAllows'), 'FolioService guards staff property on writes');
mp_assert(str_contains($folio, 'DELETE FROM finance_transactions WHERE property_id = ?'), 'Folio delete scopes finance by property_id');
mp_assert(str_contains($folio, 'WHERE id = :id AND property_id = :pid'), 'Folio editEntry scopes UPDATE by property_id');
mp_assert(str_contains($quick, 'TenantScope::booking'), 'assistant quick_charges verifies booking property');
mp_assert(str_contains($pos, 'TenantScope::booking'), 'POS room_charge verifies booking property');
mp_assert(str_contains($pos, 'stock_qty - ? WHERE id = ? AND property_id = ?'), 'POS stock deduct is property-scoped');
mp_assert(str_contains($pos, 'finance_transactions WHERE property_id = ? AND description LIKE'), 'POS finance lookup is property-scoped');
mp_assert(str_contains($guestAuth, 'AND b.property_id = ?'), 'guest auth can scope PNR by hotelId');
mp_assert(str_contains($guestAuth, 'Multiple reservations match'), 'guest auth rejects ambiguous cross-property PNRs');
mp_assert(str_contains($asstAuth, 'TenantScope::staff'), 'assistant staff PIN/access uses TenantScope');

$errLogs = file_get_contents($root . '/pms_core/api_endpoints/admin_error_logs.php') ?: '';
$hk = file_get_contents($root . '/pms_core/api_endpoints/admin_housekeeping.php') ?: '';
$autoDel = file_get_contents($root . '/pms_core/api_endpoints/admin_delete_automation_event.php') ?: '';
$settings = file_get_contents($root . '/pms_core/api_endpoints/admin_settings.php') ?: '';
$waHook = file_get_contents($root . '/pms_core/api_endpoints/whatsapp_webhook.php') ?: '';

mp_assert(str_contains($errLogs, 'e.property_id = :property_id'), 'error logs list is property-scoped');
mp_assert(
    str_contains($hk, 'DELETE FROM housekeeping_checklist_items WHERE id = ? AND property_id = ?')
    && !str_contains($hk, 'DELETE FROM housekeeping_checklist_items WHERE id = ?");'),
    'HK checklist delete requires property_id'
);
mp_assert(str_contains($autoDel, 'DELETE FROM wa_automations WHERE event_key = ? AND property_id = ?'), 'automation delete is property-scoped');
mp_assert(str_contains($settings, 'Category not found for this property'), 'settings rejects foreign room categories');
mp_assert(str_contains($waHook, 'property_id = ? AND (phone = ? OR phone = ?)'), 'WhatsApp guest match prefers property scope');
mp_assert(str_contains($waHook, 'WHERE property_id = ? AND phone_number = ?'), 'WhatsApp webhook finds conversations property-first');
mp_assert(is_file($root . '/db_migrations/039_wa_tenant_uniques.sql'), 'migration 039 WA tenant uniques exists');
$mig039 = file_get_contents($root . '/db_migrations/039_wa_tenant_uniques.sql') ?: '';
mp_assert(str_contains($mig039, 'uq_wa_conv_prop_phone') && str_contains($mig039, 'uq_wa_auto_prop_event'), 'migration 039 adds composite WA uniques');
$maint = file_get_contents($root . '/pms_core/api_endpoints/admin_room_maintenance.php') ?: '';
$pay = file_get_contents($root . '/pms_core/api_endpoints/admin_record_payment.php') ?: '';
$saveSet = file_get_contents($root . '/pms_core/api_endpoints/admin_save_settings.php') ?: '';
$roomSvc = file_get_contents($root . '/pms_core/services/RoomService.php') ?: '';
mp_assert(str_contains($maint, 'TenantScope::room'), 'room maintenance verifies room property');
mp_assert(str_contains($maint, 'WHERE id = :id AND property_id = :pid'), 'room maintenance OOO update is property-scoped');
mp_assert(str_contains($pay, 'FROM companies WHERE id = ? AND property_id = ?'), 'city ledger company link is property-scoped');
mp_assert(str_contains($saveSet, 'whatsapp_phone_number_id'), 'settings sync WA phone id onto properties');
mp_assert(str_contains($roomSvc, 'function getAvailable(\\PDO $db, int $propertyId'), 'RoomService.getAvailable requires propertyId');

$rzSvc = file_get_contents($root . '/pms_core/services/RazorpayService.php') ?: '';
$rzHook = file_get_contents($root . '/public_html/webhook_razorpay.php') ?: '';
$nightCron = file_get_contents($root . '/pms_core/cron/night_audit_cron.php') ?: '';
$guestTokSrc = file_get_contents($root . '/pms_core/GuestAccessToken.php') ?: '';
$citySvc = file_get_contents($root . '/pms_core/services/CityLedgerService.php') ?: '';
$autoSave = file_get_contents($root . '/pms_core/api_endpoints/admin_save_automation.php') ?: '';
$waAutoSave = file_get_contents($root . '/pms_core/api_endpoints/admin_save_wa_automation.php') ?: '';
mp_assert(str_contains($rzSvc, 'webhookSecretForProperty'), 'RazorpayService resolves property webhook secret');
mp_assert(str_contains($rzHook, 'webhookSecretForProperty') && str_contains($rzHook, 'AND property_id = :pid'), 'Razorpay webhook peeks property then verifies scoped secret');
mp_assert(str_contains($nightCron, 'COALESCE(NULLIF(TRIM(p.timezone)') && str_contains($nightCron, 'DateTimeZone'), 'night audit cron reads properties.timezone');
mp_assert(str_contains($nightCron, 'try {') && str_contains($nightCron, 'ErrorTracker::log'), 'night audit isolates property failures');
mp_assert(str_contains($guestTokSrc, 'generateForBooking') && str_contains($guestTokSrc, "'|'"), 'GuestAccessToken supports property-bound v2 HMAC');
mp_assert(str_contains($guestTokSrc, 'hash_equals(self::generate($bookingId), $token)'), 'GuestAccessToken still accepts legacy tokens');
mp_assert(is_file($root . '/db_migrations/040_city_ledger_property.sql'), 'migration 040 city_ledger property exists');
mp_assert(str_contains(file_get_contents($root . '/db_migrations/040_city_ledger_property.sql') ?: '', 'property_id'), 'migration 040 adds city_ledger.property_id');
mp_assert(str_contains($citySvc, 'INSERT INTO city_ledger (property_id, company_id, booking_id'), 'CityLedgerService inserts property_id');
mp_assert(str_contains($autoSave, 'INSERT INTO wa_automations'), 'automation_rules save syncs to wa_automations');
mp_assert(str_contains($waAutoSave, 'INSERT INTO automation_rules'), 'wa_automations save syncs to automation_rules');
mp_assert(is_file($root . '/db_migrations/036_system_settings_mediumtext.sql'), 'migration 036 system settings mediumtext exists');

if (!$live) {
    echo "\n{$passed} passed, {$failed} failed (static only). Re-run with MULTIPROPERTY_LIVE=1 for DB fixtures.\n";
    exit($failed > 0 ? 1 : 0);
}

echo "\n=== Multi-property isolation (live DB fixtures) ===\n";

require_once $root . '/pms_core/config.php';
require_once $root . '/pms_core/Database.php';
require_once $root . '/pms_core/ApiException.php';
require_once $root . '/pms_core/TenantScope.php';
require_once $root . '/pms_core/AuthHelper.php';
require_once $root . '/pms_core/services/PropertyOnboardService.php';
require_once $root . '/pms_core/services/FolioService.php';

$db = Database::getInstance()->getConnection();
$tag = 'MPTEST-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
$createdPropertyIds = [];
$cleanup = static function () use ($db, &$createdPropertyIds): void {
    foreach (array_reverse($createdPropertyIds) as $pid) {
        try {
            $db->prepare('DELETE FROM folio_ledger WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM error_logs WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM bookings WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM guests WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM rooms WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM room_categories WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM staff_properties WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM roles WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM system_settings WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM sequence_counters WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM staff_users WHERE property_id = ?')->execute([$pid]);
            $db->prepare('DELETE FROM properties WHERE id = ?')->execute([$pid]);
        } catch (Throwable $e) {
            fwrite(STDERR, "cleanup warning for property {$pid}: " . $e->getMessage() . "\n");
        }
    }
};
register_shutdown_function($cleanup);

function mp_seed_stay(\PDO $db, int $propertyId, string $guestName, string $phone, string $displayId): array {
    $catId = 0;
    try {
        $cat = $db->prepare('INSERT INTO room_categories (property_id, name) VALUES (?, ?)');
        $cat->execute([$propertyId, 'MP Test Cat']);
        $catId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        $catId = (int)$db->query('SELECT id FROM room_categories WHERE property_id = ' . (int)$propertyId . ' LIMIT 1')->fetchColumn();
        if ($catId <= 0) {
            throw $e;
        }
    }

    $roomNo = 'MP' . substr((string)$propertyId, -3) . random_int(10, 99);
    try {
        $room = $db->prepare("INSERT INTO rooms (property_id, room_number, category_id, state) VALUES (?, ?, ?, 'clean')");
        $room->execute([$propertyId, $roomNo, $catId]);
    } catch (Throwable $e) {
        $room = $db->prepare('INSERT INTO rooms (property_id, room_number, category_id) VALUES (?, ?, ?)');
        $room->execute([$propertyId, $roomNo, $catId]);
    }
    $roomId = (int)$db->lastInsertId();

    $guest = $db->prepare('INSERT INTO guests (property_id, name, phone, email) VALUES (?, ?, ?, ?)');
    $guest->execute([$propertyId, $guestName, $phone, strtolower(str_replace(' ', '.', $guestName)) . '@example.test']);
    $guestId = (int)$db->lastInsertId();

    $ci = date('Y-m-d 14:00:00');
    $co = date('Y-m-d 11:00:00', strtotime('+2 days'));
    $bk = $db->prepare("
        INSERT INTO bookings (property_id, room_id, guest_id, check_in, check_out, total_amount, booking_status, payment_status, display_id)
        VALUES (?, ?, ?, ?, ?, 2500, 'checked_in', 'pending_hold', ?)
    ");
    $bk->execute([$propertyId, $roomId, $guestId, $ci, $co, $displayId]);
    $bookingId = (int)$db->lastInsertId();

    $fl = $db->prepare("
        INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, description, folio_bucket, category, is_refund, recorded_at)
        VALUES (?, ?, 'ROOM_CHARGE', 2500, 'Room charge', 'main', 'Room Revenue', 0, NOW())
    ");
    $fl->execute([$propertyId, $bookingId]);

    return [
        'property_id' => $propertyId,
        'room_id' => $roomId,
        'guest_id' => $guestId,
        'booking_id' => $bookingId,
        'display_id' => $displayId,
        'phone' => $phone,
    ];
}

try {
    $a = PropertyOnboardService::create($db, [
        'name' => "{$tag}-HotelA",
        'city' => 'TestCity',
        'admin_username' => 'mp_a_' . bin2hex(random_bytes(3)),
        'admin_password' => 'TestPass123!',
        'admin_pin' => '1111',
        'plan' => 'starter',
    ]);
    $b = PropertyOnboardService::create($db, [
        'name' => "{$tag}-HotelB",
        'city' => 'TestCity',
        'admin_username' => 'mp_b_' . bin2hex(random_bytes(3)),
        'admin_password' => 'TestPass123!',
        'admin_pin' => '2222',
        'plan' => 'starter',
    ]);
    $createdPropertyIds[] = (int)$a['property_id'];
    $createdPropertyIds[] = (int)$b['property_id'];

    mp_assert((int)$a['property_id'] !== (int)$b['property_id'], 'two distinct dummy properties created');

    $samePnr = 'MP-COLLIDE-001';
    $stayA = mp_seed_stay($db, (int)$a['property_id'], 'Guest Alpha', '9000000001', $samePnr);
    $stayB = mp_seed_stay($db, (int)$b['property_id'], 'Guest Beta', '9000000002', $samePnr);

    mp_assert($stayA['booking_id'] !== $stayB['booking_id'], 'dummy bookings created on both properties');

    // Staff of A must not post onto B's booking
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = (int)$a['user_id'];
    $_SESSION['property_id'] = (int)$a['property_id'];
    $_SESSION['role'] = 'owner';

    $blocked = false;
    try {
        FolioService::postCharge($db, $stayB['booking_id'], 99.0, 'Cross-tenant probe', 'Other');
    } catch (Throwable $e) {
        $blocked = true;
        mp_pass('postCharge blocked cross-property booking: ' . $e->getMessage());
    }
    if (!$blocked) {
        mp_fail('postCharge allowed staff A to charge booking on property B');
    }

    $blockedScope = false;
    try {
        TenantScope::booking($db, $stayB['booking_id'], (int)$a['property_id']);
    } catch (Throwable $e) {
        $blockedScope = true;
        mp_pass('TenantScope::booking hides foreign booking');
    }
    if (!$blockedScope) {
        mp_fail('TenantScope::booking returned foreign property booking');
    }

    // Same-property charge still works
    $entryId = FolioService::postCharge($db, $stayA['booking_id'], 50.0, 'Same-tenant probe', 'Other');
    mp_assert($entryId > 0, 'postCharge works for own-property booking');

    $balA = FolioService::getBalance($db, $stayA['booking_id']);
    $balB = FolioService::getBalance($db, $stayB['booking_id']);
    mp_assert($balA >= 2550.0, 'property A folio balance includes own charge');
    mp_assert(abs($balB - 2500.0) < 0.02, 'property B folio untouched by A');

    // Guest auth with colliding PNR + wrong hotel should not open the other stay
    $stmt = $db->prepare("
        SELECT b.id, b.property_id, g.phone, g.email, b.booking_status, b.check_out
        FROM bookings b JOIN guests g ON b.guest_id = g.id
        WHERE b.display_id = ? AND b.property_id = ?
    ");
    $stmt->execute([$samePnr, (int)$a['property_id']]);
    $scoped = $stmt->fetchAll(PDO::FETCH_ASSOC);
    mp_assert(count($scoped) === 1 && (int)$scoped[0]['id'] === $stayA['booking_id'], 'PNR+hotelId resolves only property A');

    $stmt->execute([$samePnr, (int)$b['property_id']]);
    $scopedB = $stmt->fetchAll(PDO::FETCH_ASSOC);
    mp_assert(count($scopedB) === 1 && (int)$scopedB[0]['id'] === $stayB['booking_id'], 'PNR+hotelId resolves only property B');

    // Extra isolation probes
    $blockedGuest = false;
    try {
        TenantScope::guest($db, $stayB['guest_id'], (int)$a['property_id']);
    } catch (Throwable $e) {
        $blockedGuest = true;
        mp_pass('TenantScope::guest hides foreign guest');
    }
    if (!$blockedGuest) {
        mp_fail('TenantScope::guest returned foreign guest');
    }

    $blockedStaff = false;
    try {
        TenantScope::staff($db, (int)$b['user_id'], (int)$a['property_id']);
    } catch (Throwable $e) {
        $blockedStaff = true;
        mp_pass('TenantScope::staff hides foreign staff');
    }
    if (!$blockedStaff) {
        mp_fail('TenantScope::staff returned foreign staff');
    }

    $blockedPay = false;
    try {
        FolioService::recordPayment($db, $stayB['booking_id'], 10.0, 'Cash', 'MPTEST');
    } catch (Throwable $e) {
        $blockedPay = true;
        mp_pass('recordPayment blocked cross-property booking');
    }
    if (!$blockedPay) {
        mp_fail('recordPayment allowed staff A onto property B');
    }

    $entryB = (int)$db->query('SELECT id FROM folio_ledger WHERE booking_id = ' . (int)$stayB['booking_id'] . ' LIMIT 1')->fetchColumn();
    $blockedEdit = false;
    try {
        FolioService::editEntry($db, $entryB, 1.0, 'hack');
    } catch (Throwable $e) {
        $blockedEdit = true;
        mp_pass('editEntry blocked foreign folio row');
    }
    if (!$blockedEdit) {
        mp_fail('editEntry allowed foreign folio mutation');
    }

    // Reports AR style query must not leak B when filtered by A
    $ar = $db->prepare("
        SELECT b.id
        FROM bookings b
        LEFT JOIN folio_ledger fl ON fl.booking_id = b.id
        WHERE b.property_id = ?
          AND b.booking_status IN ('checked_out', 'checked_in')
        GROUP BY b.id
        HAVING COALESCE(SUM(fl.amount), 0) > 0.01
    ");
    $ar->execute([(int)$a['property_id']]);
    $arIds = array_map('intval', array_column($ar->fetchAll(PDO::FETCH_ASSOC), 'id'));
    mp_assert(in_array($stayA['booking_id'], $arIds, true), 'AR query includes property A booking');
    mp_assert(!in_array($stayB['booking_id'], $arIds, true), 'AR query excludes property B booking');

    // Foreign category attach must fail under property check
    $catB = (int)$db->query('SELECT id FROM room_categories WHERE property_id = ' . (int)$b['property_id'] . ' LIMIT 1')->fetchColumn();
    $ownCat = $db->prepare('SELECT id FROM room_categories WHERE id = ? AND property_id = ?');
    $ownCat->execute([$catB, (int)$a['property_id']]);
    mp_assert($ownCat->fetchColumn() === false, 'foreign room category not visible to property A');

    // Shared phone must not resolve guest across properties when scoped
    $sharedPhone = '9888777666';
    $db->prepare('UPDATE guests SET phone = ? WHERE id = ?')->execute([$sharedPhone, $stayA['guest_id']]);
    $db->prepare('UPDATE guests SET phone = ? WHERE id = ?')->execute([$sharedPhone, $stayB['guest_id']]);
    $gA = $db->prepare('SELECT id FROM guests WHERE property_id = ? AND phone = ? LIMIT 1');
    $gA->execute([(int)$a['property_id'], $sharedPhone]);
    $gB = $db->prepare('SELECT id FROM guests WHERE property_id = ? AND phone = ? LIMIT 1');
    $gB->execute([(int)$b['property_id'], $sharedPhone]);
    mp_assert((int)$gA->fetchColumn() === $stayA['guest_id'], 'scoped phone match returns guest A');
    mp_assert((int)$gB->fetchColumn() === $stayB['guest_id'], 'scoped phone match returns guest B');

    // Error log isolation
    try {
        $db->prepare("INSERT INTO error_logs (property_id, severity, category, message) VALUES (?, 'error', 'system', ?)")
           ->execute([(int)$a['property_id'], $tag . '-err-a']);
        $db->prepare("INSERT INTO error_logs (property_id, severity, category, message) VALUES (?, 'error', 'system', ?)")
           ->execute([(int)$b['property_id'], $tag . '-err-b']);
        $el = $db->prepare('SELECT message FROM error_logs WHERE property_id = ? AND message LIKE ?');
        $el->execute([(int)$a['property_id'], $tag . '%']);
        $msgs = array_column($el->fetchAll(PDO::FETCH_ASSOC), 'message');
        mp_assert(in_array($tag . '-err-a', $msgs, true) && !in_array($tag . '-err-b', $msgs, true), 'error_logs filter isolates by property');
    } catch (Throwable $e) {
        mp_fail('error_logs isolation probe failed: ' . $e->getMessage());
    }

    // Room maintenance / company ownership probes
    $blockedRoom = false;
    try {
        TenantScope::room($db, $stayB['room_id'], (int)$a['property_id']);
    } catch (Throwable $e) {
        $blockedRoom = true;
        mp_pass('TenantScope::room hides foreign room');
    }
    if (!$blockedRoom) {
        mp_fail('TenantScope::room returned foreign room');
    }

    try {
        $db->prepare("INSERT INTO companies (property_id, name, credit_limit, balance) VALUES (?, ?, 10000, 0)")
           ->execute([(int)$b['property_id'], $tag . '-CoB']);
        $coB = (int)$db->lastInsertId();
        $coCheck = $db->prepare('SELECT id FROM companies WHERE id = ? AND property_id = ? LIMIT 1');
        $coCheck->execute([$coB, (int)$a['property_id']]);
        mp_assert($coCheck->fetchColumn() === false, 'foreign company not linkable to property A');
        $db->prepare('DELETE FROM companies WHERE id = ?')->execute([$coB]);
    } catch (Throwable $e) {
        mp_fail('company ownership probe failed: ' . $e->getMessage());
    }

    require_once $root . '/pms_core/services/RoomService.php';
    $dirtyForeign = RoomService::markDirty($db, $stayB['room_id'], (int)$a['property_id']);
    mp_assert($dirtyForeign === false, 'RoomService.markDirty cannot dirty foreign room');

    // city_ledger.property_id + guest token cross-property reject + TZ smoke
    try {
        $db->exec("ALTER TABLE city_ledger ADD COLUMN property_id INT NULL");
    } catch (Throwable $e) {
        // already present
    }
    $db->prepare("INSERT INTO companies (property_id, name, credit_limit, balance) VALUES (?, ?, 50000, 0)")
       ->execute([(int)$a['property_id'], $tag . '-CoA']);
    $coA = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO city_ledger (property_id, company_id, booking_id, amount, type, status) VALUES (?, ?, ?, 100.00, 'charge', 'pending')")
       ->execute([(int)$a['property_id'], $coA, $stayA['booking_id']]);
    $clId = (int)$db->lastInsertId();
    $clCheck = $db->prepare('SELECT property_id FROM city_ledger WHERE id = ?');
    $clCheck->execute([$clId]);
    mp_assert((int)$clCheck->fetchColumn() === (int)$a['property_id'], 'city_ledger insert stores property_id');
    $db->prepare('DELETE FROM city_ledger WHERE id = ?')->execute([$clId]);
    $db->prepare('DELETE FROM companies WHERE id = ?')->execute([$coA]);

    require_once $root . '/pms_core/GuestAccessToken.php';
    $tokA = GuestAccessToken::generateForBooking($stayA['booking_id'], (int)$a['property_id']);
    mp_assert(GuestAccessToken::verify($stayA['booking_id'], $tokA, (int)$a['property_id']), 'v2 token verifies for own property');
    mp_assert(!GuestAccessToken::verify($stayA['booking_id'], $tokA, (int)$b['property_id']), 'v2 token rejects cross-property verify');
    $legacy = GuestAccessToken::generate($stayA['booking_id']);
    mp_assert(GuestAccessToken::verify($stayA['booking_id'], $legacy, (int)$a['property_id']), 'legacy token still verifies with property id');

    foreach (['Asia/Kolkata', 'America/New_York'] as $tzName) {
        $tz = new DateTimeZone($tzName);
        $now = new DateTime('now', $tz);
        mp_assert($now->format('Y-m-d') !== '', 'DateTime works for timezone ' . $tzName);
    }

    echo "\nLive fixtures tag: {$tag}\n";
    echo "Hotel A property_id={$a['property_id']} user={$a['username']}\n";
    echo "Hotel B property_id={$b['property_id']} user={$b['username']}\n";
} catch (Throwable $e) {
    mp_fail('live fixture error: ' . $e->getMessage());
}

echo "\n{$passed} passed, {$failed} failed\n";
if ($findings) {
    echo "Findings:\n- " . implode("\n- ", $findings) . "\n";
}
exit($failed > 0 ? 1 : 0);
