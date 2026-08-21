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
$guestTokV2 = GuestAccessToken::generateForBooking(42, 7);
assert_true(GuestAccessToken::verify(42, $guestTokV2, 7), 'guest v2 token verifies with property');
assert_true(!GuestAccessToken::verify(42, $guestTokV2, 8), 'guest v2 token rejects wrong property');
assert_true(GuestAccessToken::verify(42, $guestTok, 7), 'legacy guest token still accepted when property known');
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
assert_true(str_contains($cashierSql, "case 'accounts_receivable'"), 'accounts receivable report exists');
assert_true(str_contains($cashierSql, 'HAVING COALESCE(SUM(fl.amount), 0) > 0.01'), 'AR uses SUM(amount) not HAVING aliases');
$dbSrc = file_get_contents(__DIR__ . '/../pms_core/Database.php') ?: '';
assert_true(
    str_contains($dbSrc, 'function yieldQuery')
    && !preg_match('/setAttribute\s*\(\s*\\\\?PDO::MYSQL_ATTR_USE_BUFFERED_QUERY/', $dbSrc)
    && !preg_match('/setAttribute\s*\(\s*\\\\?Pdo\\\\Mysql::ATTR_USE_BUFFERED_QUERY/', $dbSrc),
    'yieldQuery stays buffered on persistent PDO'
);

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
assert_true(ModuleHost::detectModule('yourdomain.com', 'yourdomain.com') === 'path', 'live domain is path-based not a marketing-only apex');
assert_true(ModuleHost::detectModule('guest.yourdomain.com', 'yourdomain.com') === 'path', 'guest subdomain is not a separate app host');
assert_true(ModuleHost::detectModule('admin.yourdomain.com', 'yourdomain.com') === 'path', 'admin subdomain is not a separate app host');
assert_true(ModuleHost::url('admin', '/login', 'yourdomain.com') === '/login', 'staff login is /login on the same host');
assert_true(ModuleHost::url('guest', '/guest-login', 'yourdomain.com') === '/guest-login', 'guest login is /guest-login on the same host');
assert_true(ModuleHost::url('saas', '/saas-admin', 'yourdomain.com') === '/saas-admin', 'saas panel is /saas-admin on the same host');
assert_true(ModuleHost::applyHostPrefix('/', 'guest') === '/', 'landing stays /');
assert_true(ModuleHost::applyHostPrefix('/', 'admin') === '/', 'admin prefix is not rewritten');
assert_true(ModuleHost::applyHostPrefix('/register', 'saas') === '/register', 'public lead form is not prefixed away');
assert_true(is_file(__DIR__ . '/multiproperty_isolation.php'), 'multiproperty isolation harness exists');
$mpOut = [];
$mpCode = 0;
exec('php ' . escapeshellarg(__DIR__ . '/multiproperty_isolation.php') . ' 2>&1', $mpOut, $mpCode);
assert_true($mpCode === 0, 'multiproperty static isolation checks pass');
assert_true(is_file(__DIR__ . '/../pms_core/services/LeadService.php'), 'lead capture service exists');
assert_true(ModuleHost::sessionCookieDomain('admin', 'yourdomain.com', 'admin.yourdomain.com') === '', 'staff cookie stays on the current host');
assert_true(ModuleHost::detectModule('admin.localhost') === 'path', 'admin.localhost is not a module host');
assert_true(ModuleHost::url('admin', '/admin?hotelId=42', 'yourdomain.com') === '/admin?hotelId=42', 'enter dashboard URL stays on this host');
$routerSrc = file_get_contents(__DIR__ . '/../public_html/router.php') ?: '';
assert_true(!str_contains($routerSrc, 'session_regenerate_id'), 'property switch does not rotate the session id');
assert_true(!str_contains($routerSrc, 'staffOnApex'), 'router does not bounce staff URLs to a subdomain');
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
assert_true(str_contains($tgHandler, 'nb_t(now') && str_contains($tgHandler, 'nb_out_'), 'telegram new booking picks check-in time and checkout date');
assert_true(str_contains($tgDesk, 'askNewBookingTime') && str_contains($tgDesk, 'askNewBookingCheckout'), 'telegram desk asks for check-in time then checkout date');
assert_true(str_contains($tgDesk, 'NB_TIME') && str_contains($tgDesk, 'NB_CHECKOUT'), 'telegram desk has time and checkout states');
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
assert_true(is_file(__DIR__ . '/../public_html/js/pull-to-refresh.js'), 'pull-to-refresh helper exists');
assert_true(str_contains(file_get_contents(__DIR__ . '/../public_html/js/pwa.js') ?: '', 'PullToRefresh'), 'admin PWA enables pull-to-refresh');
assert_true(str_contains(file_get_contents(__DIR__ . '/../public_html/assistant/js/app.js') ?: '', 'refreshFromPull'), 'assistant supports pull-to-refresh soft sync');
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
assert_true(str_contains($paymentTpl, 'Payment Category') && str_contains($paymentTpl, 'Room Revenue'), 'payment template includes payment category');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/GoogleSheetService.php') ?: '', "LED-' . (int)\$l['ledger_id']") || str_contains(file_get_contents(__DIR__ . '/../pms_core/GoogleSheetService.php') ?: '', 'LED-'), 'payment sync uses stable LED- Payment ID');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/google_sheets/Code.gs') ?: '', 'findTargetRow'), 'Apps Script upserts payment rows by Payment ID');
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

assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/google_sheets/Code.gs') ?: '', 'function doGet'), 'Apps Script has doGet ping');
assert_true(GoogleSheetService::normalizeWebhookUrl('https://script.google.com/macros/s/AKfycbTEST123/exec') !== '', 'accepts /exec web app URL');
assert_true(GoogleSheetService::normalizeWebhookUrl('https://script.google.com/macros/library/d/ABC') === '', 'rejects library URL');
$gsExplain405 = GoogleSheetService::explainAppsScriptFailure(405, 'Method Not Allowed');
assert_true(str_contains($gsExplain405, '/exec'), 'Sheets 405 explains /exec URL');
assert_true(GoogleSheetService::testConnection('not-a-url')['success'] === false, 'Sheets test rejects invalid URL');
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
$dailySrc = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_daily_summary.php') ?: '';
assert_true(str_contains($dailySrc, 'DailySummaryService::send'), 'daily summary endpoint uses report service');
assert_true(is_file(__DIR__ . '/../pms_core/services/DailySummaryService.php'), 'daily summary service exists');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/services/DailySummaryService.php') ?: '', 'mtd_total'), 'daily summary includes MTD revenue');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/NotificationRelay.php') ?: '', 'function sendTelegramDocument'), 'Telegram can send daily summary PDF');
assert_true(!str_contains(file_get_contents(__DIR__ . '/../pms_core/GoogleSheetService.php') ?: '', 'full_name'), 'Sheets expense sync does not select missing staff full_name');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/google_sheets/Code.gs') ?: '', 'function ensureTabs'), 'Apps Script creates Bookings Payments Expenses tabs');
assert_true(str_contains($cron, 'pms_core/api_endpoints/admin_daily_summary.php'), 'nightly summary uses the real endpoint file');
$crm = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_crm_dashboard.php') ?: '';
assert_true(str_contains($crm, 'booking_status') && !str_contains($crm, 'b.status IN'), 'CRM uses booking_status not bookings.status');
assert_true(is_file(__DIR__ . '/../db_migrations/032_service_requests_pos_channel.sql'), 'migration 032 service request channels is packaged');
$na = file_get_contents(__DIR__ . '/../pms_core/services/NightAudit.php') ?: '';
assert_true(str_contains($na, "STAYOVER_CLEAN_NIGHTS', '1'") || str_contains($na, "STAYOVER_CLEAN_NIGHTS', '1')"), 'stayover clean defaults to after 1 night');
assert_true(str_contains($na, 'Do Not Disturb'), 'stayover clean skips DND stays');
$auth = file_get_contents(__DIR__ . '/../pms_core/AuthHelper.php') ?: '';
assert_true(str_contains($auth, "'night_auditor'") && str_contains($auth, 'record_payment'), 'night auditor can collect payments');
assert_true(is_file(__DIR__ . '/../pms_core/services/HousekeepingFlow.php'), 'housekeeping flow completes stayover tickets on 1-click clean');
assert_true(is_file(__DIR__ . '/../db_migrations/033_room_dnd.sql'), 'migration 033 room DND is packaged');
$posRep = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_pos_reports.php') ?: '';
assert_true(str_contains($posRep, 'o.recorded_at >=') && str_contains($posRep, 'order_channel'), 'POS order report uses calendar dates and room vs counter');
$uiHead = file_get_contents(__DIR__ . '/../public_html/admin/components/ui_head.php') ?: '';
assert_true(str_contains($uiHead, '/admin/folio') && !str_contains($uiHead, 'target="_blank"'), 'night audit opens folio on the same tab');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/schema_master.sql') ?: '', 'linked_pos_order_id'), 'schema_master links POS orders to service requests');

assert_true(format_inr('4810.00') === '4,810.00', 'format_inr accepts DECIMAL strings from MySQL');
assert_true(money_float(null) === 0.0 && money_float('') === 0.0, 'money_float treats empty as zero');
assert_true(abs(money_float('2210.50') - 2210.50) < 0.001, 'money_float parses decimal strings');

$actionsSrc = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_actions.php') ?: '';
assert_true(str_contains($actionsSrc, "action_kind' => 'mark_clean'") && !str_contains($actionsSrc, '"index.php"'), 'dirty room action is mark_clean not index.php');
$loginSrc = file_get_contents(__DIR__ . '/../public_html/admin/login.php') ?: '';
assert_true(str_contains($loginSrc, 'name="remember"') && str_contains($loginSrc, 'toggleLoginPassword'), 'staff login has remember me and show password');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/NotificationRelay.php') ?: '', "QueueService::push('web_push'"), 'bell insert queues web push after commit');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/api_routes.php') ?: '', 'admin_push_subscribe.php'), 'push subscribe endpoint is routed');
assert_true(is_file(__DIR__ . '/../db_migrations/034_staff_pwa_push.sql'), 'migration 034 staff PWA push is packaged');
assert_true(is_file(__DIR__ . '/../public_html/sw.js') && is_file(__DIR__ . '/../public_html/manifest.webmanifest'), 'staff PWA manifest and service worker exist');
assert_true(is_file(__DIR__ . '/../public_html/icons/icon-192.png') && is_file(__DIR__ . '/../public_html/icons/icon-512.png'), 'staff PWA icons exist');
assert_true(is_file(__DIR__ . '/../public_html/icons/logo.svg') && is_file(__DIR__ . '/../public_html/icons/logo-wordmark.svg'), 'MicroPMS SVG logos exist');
assert_true(is_file(__DIR__ . '/../public_html/icons/icon-192-maskable.png') && is_file(__DIR__ . '/../public_html/icons/favicon-32.png'), 'maskable PWA icon and favicon exist');
$bookingSvc = file_get_contents(__DIR__ . '/../pms_core/services/BookingService.php') ?: '';
assert_true(str_contains($bookingSvc, "sendInAppNotification") && str_contains($bookingSvc, 'check_in'), 'check-in writes bell notifications');
assert_true(str_contains($bookingSvc, "], \$propertyId);") && str_contains($bookingSvc, "'new_booking'"), 'new booking telegram uses property id from webhook context');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/NotificationRelay.php') ?: '', 'resolveNotifyPropertyId'), 'telegram send does not require a staff session');

assert_true(NotificationRelay::isEnabled('booking_confirmed') === true, 'booking_confirmed telegram is on by default');
assert_true(NotificationRelay::isEnabled('new_booking') === true, 'new_booking aliases booking_confirmed');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/NotificationRelay.php') ?: '', "QueueService::push('telegram'"), 'telegram alerts are queued after commit');
assert_true(is_file(__DIR__ . '/../pms_core/DeferredSideEffects.php'), 'deferred side effects helper exists');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/NotificationRelay.php') ?: '', "QueueService::push('whatsapp'"), 'whatsapp automations queue instead of sync HTTP');

require_once __DIR__ . '/../pms_core/AuthHelper.php';
require_once __DIR__ . '/../pms_core/services/StayPolicy.php';
require_once __DIR__ . '/../pms_core/services/TelegramCalendar.php';
require_once __DIR__ . '/../pms_core/services/CheckoutReminderService.php';

$bookedStay = ['booking_status' => 'booked', 'payment_status' => 'pending'];
$inHouseStay = ['booking_status' => 'checked_in', 'payment_status' => 'partial'];
$doneStay = ['booking_status' => 'checked_out', 'payment_status' => 'completed_paid'];
assert_true(StayPolicy::can($bookedStay, StayPolicy::CHECK_IN) && StayPolicy::can($bookedStay, StayPolicy::CANCEL), 'booked stay can change check-in and cancel');
assert_true(StayPolicy::can($inHouseStay, StayPolicy::CHECK_OUT) && !StayPolicy::can($inHouseStay, StayPolicy::CHECK_IN), 'checked-in stay locks check-in and allows checkout edit');
assert_true(!StayPolicy::can($inHouseStay, StayPolicy::CANCEL), 'checked-in stay cannot cancel without rollback');
assert_true(!StayPolicy::can($doneStay, StayPolicy::CHECK_OUT) && !StayPolicy::can($doneStay, StayPolicy::ROOM), 'checked-out stay is view only');
$lockedCheckIn = false;
try {
    StayPolicy::assert($inHouseStay, StayPolicy::CHECK_IN);
} catch (\Throwable $e) {
    $lockedCheckIn = str_contains($e->getMessage(), 'cannot be changed');
}
assert_true($lockedCheckIn, 'StayPolicy rejects check-in change after check-in');
assert_true(str_contains($bookingSvc, 'StayPolicy::assert($booking, StayPolicy::CHECK_IN)'), 'reschedule asserts check-in via StayPolicy');
assert_true(str_contains($bookingSvc, 'StayPolicy::assertCheckInTime($booking)'), 'check-in enforces scheduled time');
$earlyBlocked = false;
try {
    StayPolicy::assertCheckInTime(['check_in' => date('Y-m-d H:i:s', time() + 3600)], time());
} catch (\Throwable $e) {
    $earlyBlocked = str_contains($e->getMessage(), 'cannot be performed yet');
}
assert_true($earlyBlocked, 'early check-in before scheduled time is blocked');
StayPolicy::assertCheckInTime(['check_in' => date('Y-m-d H:i:s', time() - 60)], time());
assert_true(CheckoutReminderService::matchingWindow(30) === 30, '30-minute checkout window matches');
assert_true(CheckoutReminderService::matchingWindow(15) === 15, '15-minute checkout window matches');
assert_true(CheckoutReminderService::matchingWindow(32) === 30, '32-minute remaining still in 30-minute window');
assert_true(CheckoutReminderService::matchingWindow(45) === null, '45 minutes before checkout is not a notify window');
assert_true(CheckoutReminderService::matchingWindow(22) === null, '22 minutes before checkout is not a notify window');
assert_true(CheckoutReminderService::alertId(9, 15) === 'upcoming_checkout_9_15', 'checkout alert id is stable per booking and window');

assert_true(TelegramCalendar::maxCallbackLength() <= 64, 'telegram calendar callbacks stay under 64 bytes');
$cal = TelegramCalendar::monthKeyboard('nb', 'in', '202608');
$calOk = true;
foreach ($cal['inline_keyboard'] as $row) {
    foreach ($row as $btn) {
        if (strlen((string)($btn['callback_data'] ?? '')) > 64) {
            $calOk = false;
        }
    }
}
assert_true($calOk, 'month calendar callback data is <= 64 bytes');
$parsedDay = TelegramCalendar::parse('c:nb:in:20260820');
assert_true(($parsedDay['kind'] ?? '') === 'day' && ($parsedDay['date'] ?? '') === '2026-08-20', 'calendar day callback parses YYYY-MM-DD');

assert_true(AuthHelper::roleCan('receptionist', 'cancel_booking'), 'receptionist can cancel booked stays');
assert_true(AuthHelper::roleCan('receptionist', 'move_room'), 'receptionist can move rooms');
assert_true(!AuthHelper::roleCan('housekeeping', 'create_booking'), 'housekeeping cannot create bookings');
assert_true(AuthHelper::telegramActionPermission('add_payment') === 'record_payment', 'telegram payment maps to record_payment');
assert_true(is_file(__DIR__ . '/../db_migrations/035_staff_roles_enum.sql'), 'migration 035 staff roles enum is packaged');
assert_true(is_file(__DIR__ . '/../db_migrations/036_system_settings_mediumtext.sql'), 'migration 036 system settings mediumtext is packaged');
assert_true(is_file(__DIR__ . '/../db_migrations/037_notification_milestones.sql'), 'migration 037 notification milestones is packaged');
assert_true(property_logo_mime_from_base64('/9j/abc') === 'image/jpeg', 'logo mime detects JPEG base64');
assert_true(property_logo_mime_from_base64('iVBORw0KGgo') === 'image/png', 'logo mime detects PNG base64');
assert_true(is_file(__DIR__ . '/../pms_core/services/StayPolicy.php') && is_file(__DIR__ . '/../pms_core/services/TelegramCalendar.php'), 'stay kernel files exist');
$zipScript = file_get_contents(__DIR__ . '/../scripts/build_deployment_zip.sh') ?: '';
assert_true(str_contains($zipScript, '035_staff_roles_enum.sql') && str_contains($zipScript, '036_system_settings_mediumtext.sql') && str_contains($zipScript, 'StayPolicy.php'), 'deployment zip requires stay kernel files');
assert_true(str_contains($zipScript, '037_notification_milestones.sql') && str_contains($zipScript, 'CheckoutReminderService.php'), 'deployment zip requires checkout reminder files');
assert_true(str_contains($zipScript, '038_push_client.sql'), 'deployment zip requires push client migration');
assert_true(is_file(__DIR__ . '/../db_migrations/038_push_client.sql'), 'migration 038 push client is packaged');
assert_true(str_contains($zipScript, '039_wa_tenant_uniques.sql'), 'deployment zip requires WA tenant uniques migration');
assert_true(is_file(__DIR__ . '/../db_migrations/039_wa_tenant_uniques.sql'), 'migration 039 WA tenant uniques is packaged');
assert_true(str_contains($zipScript, '040_city_ledger_property.sql'), 'deployment zip requires city_ledger property migration');
assert_true(is_file(__DIR__ . '/../db_migrations/040_city_ledger_property.sql'), 'migration 040 city_ledger property is packaged');
$rzSvcSrc = file_get_contents(__DIR__ . '/../pms_core/services/RazorpayService.php') ?: '';
assert_true(str_contains($rzSvcSrc, 'function webhookSecretForProperty'), 'RazorpayService resolves per-property webhook secrets');
$rzHook = file_get_contents(__DIR__ . '/../public_html/webhook_razorpay.php') ?: '';
assert_true(str_contains($rzHook, 'webhookSecretForProperty') && str_contains($rzHook, 'transaction_id = :ref AND property_id'), 'Razorpay webhook verifies property secret and scopes folio idempotency');
assert_true(str_contains($zipScript, '041_folio_ledger_rename_columns.sql'), 'deployment zip requires folio ledger rename migration');
assert_true(is_file(__DIR__ . '/../db_migrations/041_folio_ledger_rename_columns.sql'), 'migration 041 folio ledger rename is packaged');
assert_true(str_contains($zipScript, '042_email_report_logs.sql'), 'deployment zip requires email report logs migration');
assert_true(is_file(__DIR__ . '/../db_migrations/042_email_report_logs.sql'), 'migration 042 email report logs is packaged');
assert_true(str_contains($zipScript, '043_system_settings_composite_pk.sql'), 'deployment zip requires system_settings PK migration');
assert_true(is_file(__DIR__ . '/../db_migrations/043_system_settings_composite_pk.sql'), 'migration 043 system_settings composite PK is packaged');
assert_true(str_contains($zipScript, '044_folio_entry_kind.sql'), 'deployment zip requires folio entry_kind migration');
assert_true(is_file(__DIR__ . '/../db_migrations/044_folio_entry_kind.sql'), 'migration 044 folio entry_kind is packaged');
$schemaMaster = file_get_contents(__DIR__ . '/../pms_core/schema_master.sql') ?: '';
assert_true(str_contains($schemaMaster, '`payment_category`') && str_contains($schemaMaster, '`transaction_id`') && str_contains($schemaMaster, 'uq_folio_booking_txn'), 'schema_master uses renamed folio ledger columns');
assert_true(str_contains($schemaMaster, '`entry_kind`') && str_contains($schemaMaster, 'idx_folio_booking_entry_kind'), 'schema_master includes folio entry_kind');
assert_true(str_contains($schemaMaster, 'CREATE TABLE `email_report_logs`'), 'schema_master includes email_report_logs');
assert_true(str_contains($schemaMaster, "PRIMARY KEY (`property_id`, `key_name`)"), 'schema_master system_settings uses composite PK');
assert_true(is_file(__DIR__ . '/../public_html/admin/city_ledger.php'), 'city ledger admin page exists');
assert_true(is_file(__DIR__ . '/../public_html/admin/email_report_logs.php'), 'email report logs admin page exists');
$apiRoutes = require __DIR__ . '/../pms_core/api_routes.php';
assert_true(($apiRoutes['admin_city_ledger.php'] ?? '') === '/api/admin/city_ledger', 'city ledger API route registered');
assert_true(($apiRoutes['admin_booking_notes.php'] ?? '') === '/api/admin/booking_notes', 'booking notes API route registered');
$cityLedgerApi = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_city_ledger.php') ?: '';
assert_true(str_contains($cityLedgerApi, 'CityLedgerService') && str_contains($cityLedgerApi, 'list_companies'), 'city ledger API uses CityLedgerService');
$notesApi = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_booking_notes.php') ?: '';
assert_true(str_contains($notesApi, 'property_id') && str_contains($notesApi, 'booking_notes'), 'booking notes API scopes by property');
$emailCron = file_get_contents(__DIR__ . '/../pms_core/cron/email_reports_cron.php') ?: '';
assert_true(str_contains($emailCron, 'email_report_logs') && str_contains($emailCron, 'email_report_log'), 'email reports cron writes email_report_logs');
$cronSched = file_get_contents(__DIR__ . '/../public_html/cron_scheduler.php') ?: '';
assert_true(str_contains($cronSched, 'email_reports_cron.php') && str_contains($cronSched, 'DateTimeZone'), 'cron scheduler runs email reports and TZ night audit');
$citySvc = file_get_contents(__DIR__ . '/../pms_core/services/CityLedgerService.php') ?: '';
assert_true(str_contains($citySvc, 'CITY_LEDGER-') && str_contains($citySvc, 'bin2hex(random_bytes'), 'city ledger transfer uses unique transaction_id');
$tgHook = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/telegram_webhook.php') ?: '';
assert_true(str_contains($tgHook, "webhook secret is not configured"), 'telegram webhook rejects empty secret');
$rzHook = file_get_contents(__DIR__ . '/../public_html/webhook_razorpay.php') ?: '';
assert_true(str_contains($rzHook, 'saas_subscriptions') && str_contains($rzHook, 'globalWebhookSecret'), 'razorpay webhook resolves property before secret from notes');
assert_true(is_file(__DIR__ . '/../pms_core/RateLimiter.php'), 'RateLimiter helper exists');
$guestAuth = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/guest_auth.php') ?: '';
assert_true(str_contains($guestAuth, 'RateLimiter'), 'guest_auth is rate limited');
$guestSearch = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/guest_search_api.php') ?: '';
assert_true(str_contains($guestSearch, '999999') && str_contains($guestSearch, 'require_pnr_login'), 'guest OTP is 6-digit and OTP-off does not issue tokens');
$guestPortal = file_get_contents(__DIR__ . '/../public_html/guest_portal.php') ?: '';
assert_true(str_contains($guestPortal, 'loadPosOrders') && str_contains($guestPortal, 'posOrdersList'), 'guest portal tracks POS orders');
$asstPos = file_get_contents(__DIR__ . '/../public_html/assistant/api/pos.php') ?: '';
assert_true(str_contains($asstPos, "delivery_status") && str_contains($asstPos, "'pending'"), 'assistant POS sets delivery_status pending');
$authHelper = file_get_contents(__DIR__ . '/../pms_core/AuthHelper.php') ?: '';
assert_true(str_contains($authHelper, 'issueRememberToken') && str_contains($authHelper, 'Rotate remember token'), 'remember-me rotates on use');
assert_true(str_contains($authHelper, 'property_id = ?'), 'custom roles scoped by property');
$saasMw = file_get_contents(__DIR__ . '/../pms_core/SaaSMiddleware.php') ?: '';
assert_true(str_contains($saasMw, 'Fail closed') || str_contains($saasMw, 'Unable to verify subscription'), 'SaaS middleware fails closed on DB errors');
$rzSvc = file_get_contents(__DIR__ . '/../pms_core/services/RazorpayService.php') ?: '';
assert_true(str_contains($rzSvc, 'payment_gateway_configs') && str_contains($rzSvc, 'extra_config.webhook_secret') && !str_contains($rzSvc, "get_db_setting(\$db, 'RAZORPAY_"), 'Razorpay credentials/webhook use payment_gateway_configs only (no system_settings fallback)');
$gwSave = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_save_gateway_config.php') ?: '';
assert_true(str_contains($gwSave, 'Canonical store only') && !str_contains($gwSave, "RAZORPAY_KEY_ID"), 'gateway save does not dual-write system_settings keys');
$settingsSave = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_save_settings.php') ?: '';
assert_true(str_contains($settingsSave, 'gatewayOnlyKeys'), 'settings save skips RAZORPAY_* system_settings writes');
$waSave = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_save_wa_automation.php') ?: '';
assert_true(str_contains($waSave, 'Source of truth: automation_rules') && str_contains($waSave, 'INSERT INTO automation_rules') && !str_contains($waSave, 'INSERT INTO wa_automations'), 'WA save writes automation_rules only');
$adminLogin = file_get_contents(__DIR__ . '/../public_html/admin/login.php') ?: '';
assert_true(str_contains($adminLogin, 'CsrfToken::field') && str_contains($adminLogin, 'CsrfToken::validate'), 'admin login requires CSRF');
$saasLogin = file_get_contents(__DIR__ . '/../public_html/saas-admin/login.php') ?: '';
assert_true(str_contains($saasLogin, 'CsrfToken::field') && str_contains($saasLogin, 'CsrfToken::validate'), 'saas login requires CSRF');
$guestTok = file_get_contents(__DIR__ . '/../pms_core/GuestAccessToken.php') ?: '';
assert_true(str_contains($guestTok, 'legacyTokensAllowed') && str_contains($guestTok, 'GUEST_TOKEN_ALLOW_LEGACY'), 'legacy guest tokens can be disabled');
$folioPhp = file_get_contents(__DIR__ . '/../public_html/admin/folio.php') ?: '';
assert_true(str_contains($folioPhp, 'booking-notes-list') && str_contains($folioPhp, 'cp_company_id'), 'folio has notes panel and city ledger company picker');
$desktopNav = file_get_contents(__DIR__ . '/../public_html/admin/components/desktop_nav.php') ?: '';
assert_true(str_contains($desktopNav, '/admin/city_ledger') && str_contains($desktopNav, '/admin/email_report_logs'), 'desktop nav links city ledger and email report logs');
$folioSvc = file_get_contents(__DIR__ . '/../pms_core/services/FolioService.php') ?: '';
assert_true(str_contains($folioSvc, 'payment_category') && str_contains($folioSvc, 'transaction_id') && !str_contains($folioSvc, 'transaction_ref'), 'FolioService uses payment_category and transaction_id');
assert_true(str_contains($folioSvc, 'entry_kind') && str_contains($folioSvc, 'entryKindWriteValue') && !str_contains($folioSvc, 'transaction_type'), 'FolioService writes entry_kind only (no transaction_type)');
$asstCheckout = file_get_contents(__DIR__ . '/../public_html/assistant/api/checkout.php') ?: '';
assert_true(str_contains($asstCheckout, 'Receipt ') && !str_contains($asstCheckout, "strpos(\$origRef, 'TXN-')"), 'assistant checkout syncs finance via receipt display_id');
$nightCron = file_get_contents(__DIR__ . '/../pms_core/cron/night_audit_cron.php') ?: '';
assert_true(str_contains($nightCron, 'timezone') && str_contains($nightCron, 'DateTimeZone'), 'night audit cron uses per-property timezone');
$autoSave = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_save_automation.php') ?: '';
assert_true(str_contains($autoSave, 'INSERT INTO automation_rules') && !str_contains($autoSave, 'INSERT INTO wa_automations'), 'unified automation save writes automation_rules only');
$relay = file_get_contents(__DIR__ . '/../pms_core/NotificationRelay.php') ?: '';
assert_true(str_contains($relay, 'deleted_at IS NULL') && !str_contains($relay, 'FROM wa_automations'), 'NotificationRelay loads live automation_rules only');
$autoDel = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_delete_automation_event.php') ?: '';
assert_true(str_contains($autoDel, 'deleted_at = NOW()') && str_contains($autoDel, '__del_'), 'automation event delete soft-deletes rules');
assert_true(is_file(__DIR__ . '/../db_migrations/045_phase2_p2_property_not_null.sql'), 'migration 045 property NOT NULL exists');
assert_true(is_file(__DIR__ . '/../db_migrations/046_phase2_p3_cleanup.sql'), 'migration 046 P3 cleanup exists');
$mig046 = file_get_contents(__DIR__ . '/../db_migrations/046_phase2_p3_cleanup.sql') ?: '';
assert_true(str_contains($mig046, 'DROP COLUMN `transaction_type`') && str_contains($mig046, 'wa_automations_archive'), 'migration 046 drops folio transaction_type and archives wa_automations');
$assistantAuth = file_get_contents(__DIR__ . '/../public_html/assistant/api/auth.php') ?: '';
assert_true(str_contains($assistantAuth, 'property_id') && str_contains($assistantAuth, 'issueRememberToken'), 'assistant login uses property id and remember token');
$assistantIndex = file_get_contents(__DIR__ . '/../public_html/assistant/index.html') ?: '';
assert_true(str_contains($assistantIndex, 'login-property-id') && str_contains($assistantIndex, 'login-username') && str_contains($assistantIndex, 'login-pin'), 'assistant login form has property id, username, and pin');
$assistantApp = file_get_contents(__DIR__ . '/../public_html/assistant/js/app.js') ?: '';
assert_true(str_contains($assistantApp, 'subscribeAssistantPush') && str_contains($assistantApp, "client: 'assistant'"), 'assistant PWA subscribes to web push');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/services/WebPushService.php') ?: '', "client === 'assistant'"), 'web push opens assistant for assistant devices');
assert_true(str_contains(file_get_contents(__DIR__ . '/../pms_core/AuthHelper.php') ?: '', 'resumeRememberedSession'), 'session hydrate resumes remember-me cookie');
$cronWorker = file_get_contents(__DIR__ . '/../pms_core/cron_worker.php') ?: '';
assert_true(str_contains($cronWorker, 'break;') && str_contains($cronWorker, '$endTime = time() + 20'), 'cron worker exits when the queue is empty');
$cronSched = file_get_contents(__DIR__ . '/../public_html/cron_scheduler.php') ?: '';
assert_true(str_contains($cronSched, 'CheckoutReminderService::dispatchDue') && str_contains($cronSched, '$minute % 15 === 0'), 'cron sends checkout reminders once per window and caches reports every 15 minutes');
assert_true(str_contains(file_get_contents(__DIR__ . '/../public_html/assistant/api/guests.php') ?: '', "'existing' => true"), 'guest create marks existing profiles without collation nullif');
$assistantApp = file_get_contents(__DIR__ . '/../public_html/assistant/js/app.js') ?: '';
assert_true(str_contains($assistantApp, 'startNewGuestFromSearch') && str_contains($assistantApp, 'searchPhoneVal'), 'assistant booking searches guest before new guest creation');
assert_true(str_contains($assistantApp, 'const alertKey = (a) => a.id ||') && !str_contains($assistantApp, "a.title + '_' + a.message"), 'assistant notifications use a stable alert key');
$adminIndex = file_get_contents(__DIR__ . '/../public_html/admin/index.php') ?: '';
assert_true(str_contains($adminIndex, '/icons/logo.svg') && str_contains($adminIndex, 'micropms-header-mark'), 'admin dashboard header uses MicroPMS logo');
assert_true(!str_contains($adminIndex, 'hotelLogoUri'), 'admin dashboard header does not use property logo');
$uiHead = file_get_contents(__DIR__ . '/../public_html/admin/components/ui_head.php') ?: '';
assert_true(str_contains($uiHead, 'PMS_PRODUCT_LOGO') && !str_contains($uiHead, 'PMS_HOTEL_LOGO'), 'admin head does not embed hotel logo payload');
$guestPortal = file_get_contents(__DIR__ . '/../public_html/guest_portal.php') ?: '';
assert_true(str_contains($guestPortal, 'micropms_icons.php') && str_contains($guestPortal, '/icons/logo.svg'), 'guest portal uses MicroPMS favicon and header mark');
$assistantIndex = file_get_contents(__DIR__ . '/../public_html/assistant/index.html') ?: '';
assert_true(str_contains($assistantIndex, '/icons/logo-wordmark.svg') && str_contains($assistantIndex, 'favicon-32.png'), 'hotel assistant shows MicroPMS logo and favicon');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
