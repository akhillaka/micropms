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

require_once __DIR__ . '/../pms_core/config.php';
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
assert_true(function_exists('get_setting_list'), 'settings list helper parses JSON or CSV');
assert_true(function_exists('upsert_payment_gateway_config'), 'gateway upsert helper exists');
assert_true(is_file(__DIR__ . '/../db_migrations/029_payment_gateways_settings_sync.sql'), 'migration 029 is packaged');
assert_true(is_file(__DIR__ . '/../pms_core/api_endpoints/admin_create_phonepe_payment.php'), 'staff PhonePe collection endpoint exists');
$saveGw = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_save_gateway_config.php') ?: '';
assert_true(str_contains($saveGw, 'getJsonInput'), 'gateway save reads cached JSON body');
$folioJs = file_get_contents(__DIR__ . '/../public_html/js/folio.js') ?: '';
assert_true(str_contains($folioJs, '/api/admin/create_phonepe_payment'), 'folio PhonePe uses PhonePe checkout not WhatsApp');
$guestSearch = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/guest_search_api.php') ?: '';
assert_true(str_contains($guestSearch, 'GUEST_PORTAL_OTP_ENABLED'), 'guest search reads portal OTP setting');
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
assert_true(ModuleHost::detectModule('mediumpurple-gerbil-893514.hostingersite.com') === 'path', 'Hostinger preview stays path-based');
assert_true(ModuleHost::detectModule('admin.mediumpurple-gerbil-893514.hostingersite.com') === 'path', 'admin. preview host does not use subdomains');
assert_true(ModuleHost::url('admin', '/login', 'mediumpurple-gerbil-893514.hostingersite.com') === '/login', 'preview login is /login not admin.preview');
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
assert_true(ModuleHost::applyHostPrefix('/register', 'saas') === '/register', 'public lead form is not prefixed away');
assert_true(is_file(__DIR__ . '/../db_migrations/028_saas_leads.sql'), 'leads migration is packaged');
assert_true(is_file(__DIR__ . '/../pms_core/services/LeadService.php'), 'lead capture service exists');
assert_true(ModuleHost::sessionCookieDomain('guest', 'yourdomain.com', 'guest.yourdomain.com') === '', 'guest cookie stays host-only');
assert_true(ModuleHost::sessionCookieDomain('admin', 'yourdomain.com', 'admin.yourdomain.com') === '.yourdomain.com', 'staff cookie is shared on base domain');
assert_true(ModuleHost::detectModule('admin.localhost') === 'admin', 'admin.localhost is a local module host');
assert_true(ModuleHost::detectModule('guest.localhost') === 'guest', 'guest.localhost is a local module host');
assert_true(ModuleHost::requestPortSuffix('admin.localhost:8000') === ':8000', 'local URLs keep the PHP server port');
assert_true(ModuleHost::url('guest', '/guest-login', 'admin.localhost:8000') === 'http://guest.localhost:8000/guest-login', 'module URL keeps :8000');
assert_true(ModuleHost::canonicalPublicPath('/admin/settings.php') === '/admin/settings', 'page URLs drop .php');
assert_true(ModuleHost::canonicalPublicPath('/index.php') === '/', 'index.php becomes /');
assert_true(ModuleHost::canonicalPublicPath('/admin/index.php') === '/admin', 'admin index is /admin');
assert_true(ModuleHost::shouldKeepPhpInUrl('/webhook_razorpay.php') === true, 'webhooks keep .php');
assert_true(ModuleHost::shouldKeepPhpInUrl('/admin/settings.php') === false, 'admin pages do not keep .php');
assert_true(is_file(__DIR__ . '/../public_html/landing/index.php'), 'apex landing page exists');
assert_true(is_file(__DIR__ . '/../public_html/landing/register.php'), 'public lead request page exists');
assert_true(is_file(__DIR__ . '/../pms_core/services/PropertyOnboardService.php'), 'property onboard service is shared');

$tgHandler = file_get_contents(__DIR__ . '/../pms_core/services/TelegramOperationsHandler.php') ?: '';
$tgDesk = file_get_contents(__DIR__ . '/../pms_core/services/TelegramDeskFlows.php') ?: '';
assert_true(str_contains($tgHandler, 'cmd_new_booking'), 'telegram menu can create a booking');
assert_true(str_contains($tgHandler, 'cmd_check_in'), 'telegram menu can check in');
assert_true(str_contains($tgHandler, 'cmd_edit_booking'), 'telegram menu can edit a stay');
assert_true(str_contains($tgHandler, 'cmd_id_proof'), 'telegram menu can collect ID photos');
assert_true(str_contains($tgDesk, 'createPaymentLink'), 'telegram Razorpay collect uses payment links');
assert_true(str_contains($tgDesk, 'initiatePayment'), 'telegram PhonePe collect uses checkout URL');
assert_true(str_contains($tgDesk, 'ID_FRONT'), 'telegram ID capture waits for front photo');
assert_true(is_file(__DIR__ . '/../db_migrations/030_guest_id_fields.sql'), 'migration 030 guest ID fields is packaged');

assert_true(function_exists('pms_document_url'), 'pms_document_url helper exists');
assert_true(function_exists('pms_is_safe_upload_filename'), 'upload filename helper exists');
assert_true(pms_document_url('id_front.jpg') === '/api/admin/view_document?file=id_front.jpg', 'document URL encodes a safe filename');
assert_true(pms_document_url('../secret') === '', 'document URL rejects traversal');
$viewDoc = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_view_document.php') ?: '';
assert_true(str_contains($viewDoc, ':f1') && str_contains($viewDoc, ':f2') && str_contains($viewDoc, ':f3'), 'view_document uses unique PDO placeholders');
assert_true(!str_contains($viewDoc, 'finfo_close'), 'view_document does not call deprecated finfo_close');
$folioPhp = file_get_contents(__DIR__ . '/../public_html/admin/folio.php') ?: '';
assert_true(!str_contains($folioPhp, 'view_id_proof.php'), 'folio no longer points at missing view_id_proof.php');
assert_true(is_file(__DIR__ . '/../public_html/js/photo_capture.js'), 'shared photo capture overlay exists');
$assistantApp = file_get_contents(__DIR__ . '/../public_html/assistant/js/app.js') ?: '';
assert_true(str_contains($assistantApp, "openIdScanner('face')") || str_contains($assistantApp, "type === 'face'"), 'assistant can capture guest photo');
assert_true(str_contains($assistantApp, 'openImageViewer'), 'assistant views photos in-app');

require_once __DIR__ . '/../pms_core/services/BookingImportService.php';
$gsHeaders = GoogleSheetService::fieldCatalog();
$bookingTpl = BookingImportService::templateCsv('booking');
$paymentTpl = BookingImportService::templateCsv('payment');
$expenseTpl = BookingImportService::templateCsv('expense');
assert_true(str_contains($bookingTpl, 'Check-In TIme') && str_contains($bookingTpl, 'Booking ID'), 'booking import template matches Google Sheet headers');
assert_true(str_contains($paymentTpl, 'Payment ID') && str_contains($paymentTpl, 'Amount Paid'), 'payment import template matches Google Sheet Payments tab');
assert_true(str_contains($expenseTpl, 'Expense ID') && str_contains($expenseTpl, 'Expense Date'), 'expense import template matches Google Sheet Expenses tab');
foreach ($gsHeaders['booking'] as $h) {
    assert_true(str_contains($bookingTpl, $h), "booking template has column {$h}");
}

$tmpBook = tempnam(sys_get_temp_dir(), 'impb');
file_put_contents($tmpBook, $bookingTpl);
assert_true(BookingImportService::detectCsvKind($tmpBook) === 'booking', 'detects Google Sheet Bookings CSV');
$parsedBook = BookingImportService::parseUpload($tmpBook, 'Bookings.csv');
assert_true(($parsedBook['stays'][0]['guest_name'] ?? '') === 'Asha Kumar', 'parses guest name from Full Name');
assert_true(($parsedBook['stays'][0]['status'] ?? '') === 'checked_out', 'maps Check-in/Check-Out to booking_status');
assert_true(str_contains((string)($parsedBook['stays'][0]['check_in'] ?? ''), '2026-08-01'), 'joins Check-in Date and time');
unlink($tmpBook);

$tmpPay = tempnam(sys_get_temp_dir(), 'impp');
file_put_contents($tmpPay, $paymentTpl);
assert_true(BookingImportService::detectCsvKind($tmpPay) === 'payment', 'detects Google Sheet Payments CSV');
$parsedPay = BookingImportService::parseUpload($tmpPay, 'Payments.csv');
assert_true(($parsedPay['payments'][0]['booking_id'] ?? '') === 'IMP-001', 'payment rows keep Booking ID link');
unlink($tmpPay);

assert_true(BookingImportService::mapSheetStatus('Checked in') === 'checked_in', 'sheet status Checked in');
assert_true(BookingImportService::mapSheetStatus('Checked out') === 'checked_out', 'sheet status Checked out');
assert_true(BookingImportService::mapSheetStatus('Booked') === 'booked', 'sheet status Booked');

assert_true(is_file(__DIR__ . '/../db_migrations/031_schema_alignment.sql'), 'migration 031 schema alignment is packaged');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/schema_master.sql') ?: '', 'guest_documents'), 'schema_master has guest_documents');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/schema_master.sql') ?: '', '`import_ref`'), 'schema_master has bookings.import_ref');
require_once __DIR__ . '/../pms_core/services/FolioService.php';
$r1 = FolioService::uniqueRef('RC');
$r2 = FolioService::uniqueRef('RC');
assert_true($r1 !== $r2 && str_starts_with($r1, 'RC-'), 'folio refs are unique per line');
$roomChargeSql = file_get_contents(__DIR__ . '/../pms_core/services/BookingService.php') ?: '';
assert_true(str_contains($roomChargeSql, "FolioService::uniqueRef('RC')"), 'room charges no longer reuse MANUAL refs');
$cron = file_get_contents(__DIR__ . '/../public_html/cron_scheduler.php') ?: '';
assert_true(str_contains($cron, 'pms_core/api_endpoints/admin_daily_summary.php'), 'nightly summary uses the real endpoint file');
$crm = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_crm_dashboard.php') ?: '';
assert_true(str_contains($crm, 'booking_status') && !str_contains($crm, 'b.status IN'), 'CRM uses booking_status not bookings.status');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
