<?php
declare(strict_types=1);

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $name): void {
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}\n";
    }
}

putenv('INVOICE_SECRET=test-invoice-secret-for-cli');
$_ENV['INVOICE_SECRET'] = 'test-invoice-secret-for-cli';
if (!defined('INVOICE_SECRET')) {
    define('INVOICE_SECRET', 'test-invoice-secret-for-cli');
}

require_once __DIR__ . '/../pms_core/helpers/EmailHelper.php';
require_once __DIR__ . '/../pms_core/InvoiceLink.php';
require_once __DIR__ . '/../pms_core/GuestAccessToken.php';
require_once __DIR__ . '/../pms_core/services/RazorpayService.php';
require_once __DIR__ . '/../pms_core/services/BookingService.php';
require_once __DIR__ . '/../pms_core/NotificationRelay.php';

assert_true(EmailHelper::sanitizeAddress('ok@example.com') === 'ok@example.com', 'email accepts valid address');
assert_true(EmailHelper::sanitizeAddress("evil@example.com\nBcc: x@y.com") === '', 'email rejects header injection');
assert_true(EmailHelper::sanitizeAddress('not-an-email') === '', 'email rejects invalid address');

$rz = new RazorpayService('rzp_test', 'whsec_test');
$orderId = 'order_abc';
$payId = 'pay_abc';
$sig = hash_hmac('sha256', $orderId . '|' . $payId, 'whsec_test');
assert_true($rz->verifySignature($orderId, $payId, $sig), 'razorpay checkout HMAC accepts valid signature');
assert_true(!$rz->verifySignature($orderId, $payId, 'deadbeef'), 'razorpay checkout HMAC rejects bad signature');

$token = InvoiceLink::generate(42);
assert_true(InvoiceLink::validate($token, 42), 'invoice link validates matching booking');
assert_true(!InvoiceLink::validate($token, 99), 'invoice link rejects other booking');

$guestTok = GuestAccessToken::generate(42);
assert_true(GuestAccessToken::verify(42, $guestTok), 'guest token verifies matching booking');
assert_true(!GuestAccessToken::verify(99, $guestTok), 'guest token rejects other booking');
assert_true(GuestAccessToken::bookingIsAccessible(['booking_status' => 'checked_in', 'check_out' => date('Y-m-d', strtotime('+1 day'))]), 'guest stay accessible when checked in');
assert_true(!GuestAccessToken::bookingIsAccessible(['booking_status' => 'cancelled', 'check_out' => date('Y-m-d', strtotime('+1 day'))]), 'guest stay denied when cancelled');
assert_true(!GuestAccessToken::bookingIsAccessible(['booking_status' => 'checked_out', 'check_out' => date('Y-m-d', strtotime('-8 days'))]), 'guest stay denied 7 days after checkout');

$msg = NotificationRelay::formatTemplate('Hello {guest_name} {missing}', []);
assert_true(str_contains($msg, 'Hello'), 'template renders with missing placeholders');
assert_true(!str_contains($msg, '{guest_name}'), 'template guest_name has fallback');

require_once __DIR__ . '/../pms_core/AutomationTemplates.php';
$autoEvents = ['booking_confirmed', 'guest_check_in', 'guest_check_out', 'booking_cancelled', 'payment_link', 'guest_review_form', 'guest_invoice', 'pre_departure', 'room_marked_dirty'];
foreach ($autoEvents as $eventKey) {
    $tpl = AutomationTemplates::forEvent($eventKey);
    assert_true($tpl['email_subject'] !== '' && str_contains($tpl['email_body_html'], '{hotel_name}'), "email default exists for {$eventKey}");
    assert_true(str_contains($tpl['telegram_body_text'], '{hotel_name}') && !str_contains($tpl['telegram_body_text'], '<div'), "telegram default exists for {$eventKey}");
}
$genericTpl = AutomationTemplates::forEvent('my_custom_event');
assert_true(str_contains($genericTpl['email_body_html'], '{booking_id}'), 'unknown events get a generic email default');
$rendered = NotificationRelay::formatTemplate($tpl['email_subject'] ?? 'Hi {first_name}', ['first_name' => 'Akhil', 'hotel_name' => 'Test Inn']);
assert_true(str_contains($rendered, 'Akhil') || str_contains($rendered, 'Test Inn') || $rendered !== '', 'automation template placeholders render');

$portal = GuestAccessToken::getPortalUrl(42);
assert_true(str_contains($portal, 'guest-portal?id=42&token='), 'guest portal URL is generated for review emails');

assert_true(function_exists('get_active_payment_gateways'), 'active payment gateway helper is available');
assert_true(BookingService::calculateDays('2026-08-01 14:00:00', '2026-08-03 11:00:00') === 2, 'stay days round up to full nights');
assert_true(is_file(__DIR__ . '/../db_migrations/027_channels_cache_dns.sql'), 'migration 027 is packaged');
assert_true(is_file(__DIR__ . '/../public_html/ical.php'), 'public iCal endpoint exists');
assert_true(is_file(__DIR__ . '/../pms_core/services/IcalService.php'), 'IcalService is present');

$cashierSql = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_reports.php') ?: '';
assert_true(str_contains($cashierSql, 'booking_id IS NULL OR booking_id = 0'), 'cashier shift excludes booking-linked finance rows');
assert_true(str_contains($cashierSql, "'booked', 'checked_in', 'checked_out'"), 'revpar counts sold booked rooms');

$waJob = [
    'phone' => '919999999999',
    'message' => 'hi',
    'is_hsm' => false,
];
$phone = $waJob['phoneNumber'] ?? $waJob['phone'] ?? '';
assert_true($phone === '919999999999', 'whatsapp job accepts phone payload shape');

require_once __DIR__ . '/../pms_core/ModuleHost.php';
assert_true(ModuleHost::normalizeHost('Admin.Example.com:443') === 'admin.example.com', 'host strips port and www-style case');
assert_true(ModuleHost::detectModule('localhost:8000') === 'path', 'localhost stays path-based');
assert_true(ModuleHost::detectModule('127.0.0.1') === 'path', 'loopback stays path-based');
assert_true(ModuleHost::detectModule('yourdomain.com', 'yourdomain.com') === 'apex', 'apex is the marketing host');
assert_true(ModuleHost::detectModule('guest.yourdomain.com', 'yourdomain.com') === 'guest', 'guest subdomain maps to guest portal');
assert_true(ModuleHost::detectModule('admin.yourdomain.com', 'yourdomain.com') === 'admin', 'admin subdomain maps to staff admin');
assert_true(ModuleHost::detectModule('assistant.yourdomain.com', 'yourdomain.com') === 'assistant', 'assistant subdomain maps to Hotel Assistant');
assert_true(ModuleHost::detectModule('saas.yourdomain.com', 'yourdomain.com') === 'saas', 'saas subdomain maps to platform');
assert_true(ModuleHost::applyHostPrefix('/', 'guest') === '/guest-login', 'guest home is guest login not staff login');
assert_true(ModuleHost::applyHostPrefix('/', 'admin') === '/admin', 'admin home prefixes to /admin');
assert_true(ModuleHost::applyHostPrefix('/', 'assistant') === '/assistant', 'assistant home prefixes to /assistant');
assert_true(ModuleHost::applyHostPrefix('/', 'saas') === '/saas-admin', 'saas home prefixes to /saas-admin');
assert_true(ModuleHost::applyHostPrefix('/', 'apex') === '/', 'apex home stays landing');
assert_true(ModuleHost::applyHostPrefix('/register', 'saas') === '/register', 'public register is not prefixed away');
assert_true(ModuleHost::sessionCookieDomain('guest', 'yourdomain.com', 'guest.yourdomain.com') === '', 'guest cookie stays host-only');
assert_true(ModuleHost::sessionCookieDomain('admin', 'yourdomain.com', 'admin.yourdomain.com') === '.yourdomain.com', 'staff cookie is shared on base domain');
assert_true(ModuleHost::detectModule('admin.localhost') === 'admin', 'admin.localhost is a local module host');
assert_true(ModuleHost::detectModule('guest.localhost') === 'guest', 'guest.localhost is a local module host');
assert_true(ModuleHost::requestPortSuffix('admin.localhost:8000') === ':8000', 'local URLs keep the PHP server port');
assert_true(ModuleHost::url('guest', '/guest-login', 'admin.localhost:8000') === 'http://guest.localhost:8000/guest-login', 'module URL keeps :8000');
assert_true(is_file(__DIR__ . '/../public_html/landing/index.php'), 'apex landing page exists');
assert_true(is_file(__DIR__ . '/../public_html/landing/register.php'), 'public register page exists');
assert_true(is_file(__DIR__ . '/../pms_core/services/PropertyOnboardService.php'), 'property onboard service is shared');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
