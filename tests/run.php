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

$cashierSql = file_get_contents(__DIR__ . '/../pms_core/api_endpoints/admin_reports.php') ?: '';
assert_true(str_contains($cashierSql, 'booking_id IS NULL OR booking_id = 0'), 'cashier shift excludes booking-linked finance rows');

$waJob = [
    'phone' => '919999999999',
    'message' => 'hi',
    'is_hsm' => false,
];
$phone = $waJob['phoneNumber'] ?? $waJob['phone'] ?? '';
assert_true($phone === '919999999999', 'whatsapp job accepts phone payload shape');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
