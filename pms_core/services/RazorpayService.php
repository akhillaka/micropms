<?php
declare(strict_types=1);

/**
 * Razorpay Payment Gateway Service
 * 
 * Supports both SaaS subscription payments and property-level payment collection.
 * Uses Razorpay Orders API for one-time payments.
 * Uses Razorpay Subscriptions API for recurring SaaS billing.
 * 
 * API Docs: https://razorpay.com/docs/api/
 */
class RazorpayService {

    private string $keyId;
    private string $keySecret;
    private bool   $isLive;

    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function __construct(string $keyId, string $keySecret, bool $isLive = false) {
        $this->keyId     = $keyId;
        $this->keySecret = $keySecret;
        $this->isLive    = $isLive;
    }

    /**
     * Load gateway config for a property from the database.
     */
    public static function forProperty(\PDO $db, int $propertyId): ?self {
        $stmt = $db->prepare("
            SELECT key_id, key_secret, mode
            FROM payment_gateway_configs
            WHERE property_id = ? AND gateway = 'razorpay' AND is_active = 1
        ");
        $stmt->execute([$propertyId]);
        $config = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$config) return null;

        return new self($config['key_id'], $config['key_secret'], $config['mode'] === 'live');
    }

    /**
     * Create a Razorpay Order (one-time payment).
     * 
     * @param int    $amountPaise Amount in paise (INR × 100)
     * @param string $currency    Currency code (default: INR)
     * @param string $receipt     Your internal reference ID
     * @param array  $notes       Key-value notes (visible in Razorpay dashboard)
     * @return array ['success'=>bool, 'order_id'=>string, 'key_id'=>string, 'amount'=>int, 'error'=>string]
     */
    public function createOrder(
        int    $amountPaise,
        string $currency = 'INR',
        string $receipt = '',
        array  $notes = []
    ): array {
        $payload = [
            'amount'   => $amountPaise,
            'currency' => $currency,
            'receipt'  => $receipt ?: ('rcpt_' . time()),
            'notes'    => $notes,
        ];

        $response = $this->httpPost('/orders', $payload);
        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }

        $data = $response['body'];
        if (isset($data['id'])) {
            return [
                'success'  => true,
                'order_id' => $data['id'],
                'key_id'   => $this->keyId,
                'amount'   => (int)$data['amount'],
                'currency' => $data['currency'],
                'raw'      => $data,
            ];
        }

        return [
            'success' => false,
            'error'   => $data['error']['description'] ?? 'Razorpay order creation failed',
        ];
    }

    /**
     * Verify Razorpay payment signature after checkout.
     * Call this on the payment success callback to confirm authenticity.
     * 
     * @param string $orderId    Razorpay Order ID (from createOrder)
     * @param string $paymentId  Razorpay Payment ID (from frontend)
     * @param string $signature  Razorpay Signature (from frontend)
     */
    public function verifySignature(string $orderId, string $paymentId, string $signature): bool {
        $expectedSignature = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Fetch payment details by Razorpay Payment ID.
     */
    public function fetchPayment(string $paymentId): array {
        $response = $this->httpGet('/payments/' . $paymentId);
        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }
        $data = $response['body'];
        return [
            'success' => true,
            'status'  => $data['status'] ?? 'unknown',
            'amount'  => (int)($data['amount'] ?? 0),
            'method'  => $data['method'] ?? '',
            'raw'     => $data,
        ];
    }

    /**
     * Capture a payment (required for manual capture mode).
     */
    public function capturePayment(string $paymentId, int $amountPaise): array {
        $response = $this->httpPost('/payments/' . $paymentId . '/capture', [
            'amount'   => $amountPaise,
            'currency' => 'INR',
        ]);
        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }
        return ['success' => true, 'raw' => $response['body']];
    }

    /**
     * Create a Razorpay Subscription (recurring billing for SaaS).
     * 
     * @param string $planId    Razorpay Plan ID (created in Razorpay dashboard)
     * @param int    $totalCount Number of billing cycles (0 = infinite)
     * @param array  $notes      Notes
     * @return array ['success'=>bool, 'subscription_id'=>string, 'short_url'=>string]
     */
    public function createSubscription(string $planId, int $totalCount = 12, array $notes = []): array {
        $payload = [
            'plan_id'     => $planId,
            'total_count' => $totalCount,
            'quantity'    => 1,
            'notes'       => $notes,
        ];

        $response = $this->httpPost('/subscriptions', $payload);
        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error']];
        }

        $data = $response['body'];
        if (isset($data['id'])) {
            return [
                'success'         => true,
                'subscription_id' => $data['id'],
                'short_url'       => $data['short_url'] ?? '',
                'status'          => $data['status'] ?? '',
                'raw'             => $data,
            ];
        }

        return [
            'success' => false,
            'error'   => $data['error']['description'] ?? 'Subscription creation failed',
        ];
    }

    /**
     * Validate a Razorpay webhook signature.
     * X-Razorpay-Signature header must match SHA256(rawBody, webhookSecret).
     */
    public function validateWebhook(string $rawBody, string $signature, string $webhookSecret): bool {
        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);
        return hash_equals($expected, $signature);
    }

    /**
     * Returns the public Key ID (for embedding in frontend checkout JS).
     */
    public function getKeyId(): string {
        return $this->keyId;
    }

    private function httpPost(string $path, array $body): array {
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['success' => false, 'error' => $err];
        return ['success' => true, 'body' => json_decode($resp, true) ?? []];
    }

    private function httpGet(string $path): array {
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['success' => false, 'error' => $err];
        return ['success' => true, 'body' => json_decode($resp, true) ?? []];
    }
}
