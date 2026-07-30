<?php
declare(strict_types=1);

/**
 * PhonePe Payment Gateway Service
 * 
 * Supports both SaaS subscription payments (platform-level)
 * and property-level payment collection (guest payments, folio payments).
 * 
 * PhonePe API: https://developer.phonepe.com/
 * Auth: SHA256(base64(payload) + "/pg/v1/pay" + saltKey) + "###" + saltIndex
 */
class PhonePeService {

    private string $merchantId;
    private string $saltKey;
    private int    $saltIndex;
    private bool   $isLive;

    private const BASE_URL_PROD = 'https://api.phonepe.com/apis/hermes';
    private const BASE_URL_UAT  = 'https://api-preprod.phonepe.com/apis/hermes';

    public function __construct(string $merchantId, string $saltKey, int $saltIndex = 1, bool $isLive = false) {
        $this->merchantId = $merchantId;
        $this->saltKey    = $saltKey;
        $this->saltIndex  = $saltIndex;
        $this->isLive     = $isLive;
    }

    /**
     * Load gateway config for a property from the database.
     */
    public static function forProperty(\PDO $db, int $propertyId): ?self {
        $stmt = $db->prepare("
            SELECT key_id, key_secret, mode, extra_config 
            FROM payment_gateway_configs 
            WHERE property_id = ? AND gateway = 'phonepe' AND is_active = 1
        ");
        $stmt->execute([$propertyId]);
        $config = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$config) return null;

        $extra = json_decode($config['extra_config'] ?? '{}', true);
        $saltIndex = (int)($extra['salt_index'] ?? 1);

        return new self(
            $config['key_id'],
            $config['key_secret'],
            $saltIndex,
            $config['mode'] === 'live'
        );
    }

    private function baseUrl(): string {
        return $this->isLive ? self::BASE_URL_PROD : self::BASE_URL_UAT;
    }

    /**
     * Build X-VERIFY checksum.
     * SHA256(base64EncodedPayload + apiEndpoint + saltKey) + "###" + saltIndex
     */
    private function buildChecksum(string $base64Payload, string $endpoint): string {
        $hash = hash('sha256', $base64Payload . $endpoint . $this->saltKey);
        return $hash . '###' . $this->saltIndex;
    }

    /**
     * Initiate a payment request.
     * Returns redirect URL for user to complete payment.
     * 
     * @param int    $amountPaise    Amount in paise (INR × 100)
     * @param string $merchantTxnId  Unique transaction ID for this payment
     * @param string $callbackUrl    URL PhonePe POSTs status to
     * @param string $redirectUrl    URL to redirect user after payment
     * @param string $mobileNumber   Guest's mobile number (optional)
     * @return array ['success'=>bool, 'redirect_url'=>string, 'transaction_id'=>string, 'error'=>string]
     */
    public function initiatePayment(
        int    $amountPaise,
        string $merchantTxnId,
        string $callbackUrl,
        string $redirectUrl,
        string $mobileNumber = ''
    ): array {
        $payload = [
            'merchantId'            => $this->merchantId,
            'merchantTransactionId' => $merchantTxnId,
            'merchantUserId'        => 'MUID_' . md5($merchantTxnId),
            'amount'                => $amountPaise,
            'redirectUrl'           => $redirectUrl,
            'redirectMode'          => 'POST',
            'callbackUrl'           => $callbackUrl,
            'paymentInstrument'     => ['type' => 'PAY_PAGE'],
        ];

        if (!empty($mobileNumber)) {
            $payload['mobileNumber'] = preg_replace('/[^0-9]/', '', $mobileNumber);
        }

        $base64 = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $endpoint = '/pg/v1/pay';
        $checksum = $this->buildChecksum($base64, $endpoint);

        $response = $this->httpPost($this->baseUrl() . $endpoint, [
            'request' => $base64
        ], [
            'Content-Type: application/json',
            'X-VERIFY: ' . $checksum,
            'X-MERCHANT-ID: ' . $this->merchantId,
        ]);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error'] ?? 'PhonePe request failed'];
        }

        $data = $response['body'];
        if (($data['success'] ?? false) && isset($data['data']['instrumentResponse']['redirectInfo']['url'])) {
            return [
                'success'        => true,
                'redirect_url'   => $data['data']['instrumentResponse']['redirectInfo']['url'],
                'transaction_id' => $merchantTxnId,
            ];
        }

        return [
            'success' => false,
            'error'   => $data['message'] ?? 'Unknown PhonePe error',
            'code'    => $data['code'] ?? '',
        ];
    }

    /**
     * Verify payment status by transaction ID.
     * 
     * @return array ['success'=>bool, 'status'=>string, 'amount'=>int, 'error'=>string]
     */
    public function verifyPayment(string $merchantTxnId): array {
        $endpoint = '/pg/v1/status/' . $this->merchantId . '/' . $merchantTxnId;
        $checksum = $this->buildChecksum('', $endpoint);

        $response = $this->httpGet($this->baseUrl() . $endpoint, [
            'Content-Type: application/json',
            'X-VERIFY: ' . $checksum,
            'X-MERCHANT-ID: ' . $this->merchantId,
        ]);

        if (!$response['success']) {
            return ['success' => false, 'error' => $response['error'] ?? 'Status check failed'];
        }

        $data = $response['body'];
        $paymentData = $data['data'] ?? [];

        return [
            'success'        => ($data['success'] ?? false) && ($paymentData['state'] === 'COMPLETED'),
            'status'         => $paymentData['state'] ?? 'UNKNOWN',
            'amount_paise'   => (int)($paymentData['amount'] ?? 0),
            'transaction_id' => $merchantTxnId,
            'raw'            => $data,
        ];
    }

    /**
     * Validate a PhonePe webhook callback.
     * Verifies X-VERIFY header against the callback payload.
     */
    public function validateWebhook(string $rawBody, string $xVerifyHeader): bool {
        $base64 = base64_encode($rawBody);
        [$theirHash] = explode('###', $xVerifyHeader . '###');
        $ourHash = hash('sha256', $base64 . $this->saltKey);
        return hash_equals($ourHash, $theirHash);
    }

    private function httpPost(string $url, array $body, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['success' => false, 'error' => $err];
        $decoded = json_decode($resp, true);
        return ['success' => true, 'body' => $decoded ?? []];
    }

    private function httpGet(string $url, array $headers): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['success' => false, 'error' => $err];
        $decoded = json_decode($resp, true);
        return ['success' => true, 'body' => $decoded ?? []];
    }
}
