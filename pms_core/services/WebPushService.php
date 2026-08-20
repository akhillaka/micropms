<?php
declare(strict_types=1);

/**
 * Web Push (RFC 8291 aes128gcm + VAPID) without Composer.
 */
class WebPushService {

    private static function keysFile(): string {
        return dirname(__DIR__) . '/vapid_local.json';
    }

    private static function applyKeys(string $public, string $private): void {
        putenv('VAPID_PUBLIC_KEY=' . $public);
        putenv('VAPID_PRIVATE_KEY=' . $private);
        $_ENV['VAPID_PUBLIC_KEY'] = $public;
        $_ENV['VAPID_PRIVATE_KEY'] = $private;
        if (!defined('VAPID_PUBLIC_KEY')) {
            define('VAPID_PUBLIC_KEY', $public);
        }
        if (!defined('VAPID_PRIVATE_KEY')) {
            define('VAPID_PRIVATE_KEY', $private);
        }
    }

    private static function loadFileKeys(): void {
        $file = self::keysFile();
        if (!is_readable($file)) {
            return;
        }
        $json = json_decode((string)file_get_contents($file), true);
        $public = trim((string)($json['publicKey'] ?? ''));
        $private = trim((string)($json['privateKey'] ?? ''));
        if ($public !== '' && $private !== '') {
            self::applyKeys($public, $private);
        }
    }

    /**
     * Use .env keys, else a server-local generated pair so push works without manual VAPID setup.
     */
    public static function ensureKeys(): void {
        if (self::publicKey() !== '' && self::privateKey() !== '') {
            return;
        }
        self::loadFileKeys();
        if (self::publicKey() !== '' && self::privateKey() !== '') {
            return;
        }
        try {
            $keys = self::generateKeys();
            @file_put_contents(self::keysFile(), json_encode($keys), LOCK_EX);
            self::applyKeys($keys['publicKey'], $keys['privateKey']);
        } catch (\Throwable $e) {
            error_log('VAPID key generation failed: ' . $e->getMessage());
        }
    }

    public static function publicKey(): string {
        $key = getenv('VAPID_PUBLIC_KEY') ?: ($_ENV['VAPID_PUBLIC_KEY'] ?? (defined('VAPID_PUBLIC_KEY') ? (string)VAPID_PUBLIC_KEY : ''));
        $key = trim($key);
        if ($key === '') {
            self::loadFileKeys();
            $key = getenv('VAPID_PUBLIC_KEY') ?: ($_ENV['VAPID_PUBLIC_KEY'] ?? '');
            $key = trim((string)$key);
        }
        return $key;
    }

    public static function privateKey(): string {
        $key = getenv('VAPID_PRIVATE_KEY') ?: ($_ENV['VAPID_PRIVATE_KEY'] ?? (defined('VAPID_PRIVATE_KEY') ? (string)VAPID_PRIVATE_KEY : ''));
        $key = trim($key);
        if ($key === '') {
            self::loadFileKeys();
            $key = getenv('VAPID_PRIVATE_KEY') ?: ($_ENV['VAPID_PRIVATE_KEY'] ?? '');
            $key = trim((string)$key);
        }
        return $key;
    }

    public static function subject(): string {
        $sub = getenv('VAPID_SUBJECT') ?: ($_ENV['VAPID_SUBJECT'] ?? (defined('VAPID_SUBJECT') ? (string)VAPID_SUBJECT : 'mailto:ops@localhost'));
        $sub = trim($sub);
        return $sub !== '' ? $sub : 'mailto:ops@localhost';
    }

    public static function generateKeys(): array {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            throw new \RuntimeException('Could not generate VAPID keys');
        }
        $details = openssl_pkey_get_details($key);
        $x = str_pad((string)($details['ec']['x'] ?? ''), 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string)($details['ec']['y'] ?? ''), 32, "\0", STR_PAD_LEFT);
        $d = str_pad((string)($details['ec']['d'] ?? ''), 32, "\0", STR_PAD_LEFT);
        return [
            'publicKey' => self::b64url("\x04" . $x . $y),
            'privateKey' => self::b64url($d),
        ];
    }

    public static function ensureClientColumn(\PDO $db): void {
        try {
            $db->exec("ALTER TABLE push_subscriptions ADD COLUMN client VARCHAR(16) NOT NULL DEFAULT 'admin'");
        } catch (\PDOException $e) {
        }
    }

    public static function saveSubscription(\PDO $db, int $staffUserId, int $propertyId, string $endpoint, string $p256dh, string $auth, string $client = 'admin'): void {
        $client = $client === 'assistant' ? 'assistant' : 'admin';
        $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
        self::ensureClientColumn($db);
        try {
            $stmt = $db->prepare("INSERT INTO push_subscriptions (staff_user_id, property_id, endpoint, p256dh, auth_key, client) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$staffUserId, $propertyId, $endpoint, $p256dh, $auth, $client]);
        } catch (\PDOException $e) {
            $stmt = $db->prepare("INSERT INTO push_subscriptions (staff_user_id, property_id, endpoint, p256dh, auth_key) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$staffUserId, $propertyId, $endpoint, $p256dh, $auth]);
        }
    }

    public static function deleteSubscription(\PDO $db, string $endpoint): void {
        $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
    }

    public static function notifyProperty(\PDO $db, int $propertyId, string $title, string $message, string $linkUrl = '/admin'): void {
        self::ensureKeys();
        if (self::publicKey() === '' || self::privateKey() === '') {
            return;
        }
        try {
            $stmt = $db->prepare("SELECT id, endpoint, p256dh, auth_key, client FROM push_subscriptions WHERE property_id = ?");
            $stmt->execute([$propertyId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            try {
                $stmt = $db->prepare("SELECT id, endpoint, p256dh, auth_key FROM push_subscriptions WHERE property_id = ?");
                $stmt->execute([$propertyId]);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\PDOException $e2) {
                return;
            }
        }
        foreach ($rows as $row) {
            $client = (string)($row['client'] ?? 'admin');
            $url = $linkUrl !== '' ? $linkUrl : '/admin';
            if ($client === 'assistant') {
                $url = '/assistant/index.html';
            }
            $payload = json_encode([
                'title' => $title,
                'message' => $message,
                'url' => $url,
            ], JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                continue;
            }
            $ok = self::send((string)$row['endpoint'], (string)$row['p256dh'], (string)$row['auth_key'], $payload);
            if (!$ok) {
                try {
                    $db->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([(int)$row['id']]);
                } catch (\PDOException $e) {
                }
            }
        }
    }

    public static function send(string $endpoint, string $p256dh, string $auth, string $payload): bool {
        try {
            $body = self::encrypt($payload, $p256dh, $auth);
            $jwt = self::vapidJwt($endpoint);
            $headers = [
                'TTL: 86400',
                'Urgency: normal',
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Authorization: vapid t=' . $jwt . ', k=' . self::publicKey(),
            ];
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers),
                    'content' => $body,
                    'timeout' => 4,
                    'ignore_errors' => true,
                ],
            ]);
            $result = @file_get_contents($endpoint, false, $ctx);
            $hdrs = function_exists('http_get_last_response_headers')
                ? (http_get_last_response_headers() ?: [])
                : ($GLOBALS['http_response_header'] ?? []);
            $status = 0;
            if (isset($hdrs[0]) && preg_match('/\s(\d{3})\s/', (string)$hdrs[0], $m)) {
                $status = (int)$m[1];
            }
            if ($status === 404 || $status === 410) {
                return false;
            }
            return $status === 0 || ($status >= 200 && $status < 300) || $result !== false;
        } catch (\Throwable $e) {
            error_log('WebPush send failed: ' . $e->getMessage());
            return true;
        }
    }

    private static function encrypt(string $payload, string $p256dh, string $auth): string {
        $uaPublic = self::b64urlDecode($p256dh);
        $authSecret = self::b64urlDecode($auth);
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('Invalid subscription keys');
        }

        $local = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $localDetails = openssl_pkey_get_details($local);
        $asPublic = "\x04"
            . str_pad((string)$localDetails['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad((string)$localDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $uaPem = self::publicKeyToPem($uaPublic);
        $shared = openssl_pkey_derive($uaPem, $local);
        if ($shared === false || $shared === '') {
            throw new \RuntimeException('ECDH failed');
        }

        $ikmInfo = 'WebPush: info' . "\0" . $uaPublic . $asPublic;
        $ikm = self::hkdf($authSecret, $shared, $ikmInfo, 32);

        $salt = random_bytes(16);
        $cek = self::hkdf($salt, $ikm, 'Content-Encoding: aes128gcm' . "\0", 16);
        $nonce = self::hkdf($salt, $ikm, 'Content-Encoding: nonce' . "\0", 12);

        $padded = $payload . "\x02";
        $tag = '';
        $cipher = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('AES-GCM failed');
        }

        $rs = pack('N', 4096);
        $idlen = chr(strlen($asPublic));
        return $salt . $rs . $idlen . $asPublic . $cipher . $tag;
    }

    private static function vapidJwt(string $endpoint): string {
        $header = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64url(json_encode([
            'aud' => self::audience($endpoint),
            'exp' => time() + 12 * 3600,
            'sub' => self::subject(),
        ]));
        $signingInput = $header . '.' . $claims;
        $pem = self::privateKeyToPem(self::b64urlDecode(self::privateKey()));
        $der = '';
        if (!openssl_sign($signingInput, $der, $pem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID sign failed');
        }
        return $signingInput . '.' . self::b64url(self::derToJose($der));
    }

    private static function audience(string $endpoint): string {
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    private static function hkdf(string $salt, string $ikm, string $info, int $length): string {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $okm = '';
        $block = '';
        $n = 1;
        while (strlen($okm) < $length) {
            $block = hash_hmac('sha256', $block . $info . chr($n), $prk, true);
            $okm .= $block;
            $n++;
        }
        return substr($okm, 0, $length);
    }

    private static function publicKeyToPem(string $uncompressed): mixed {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uncompressed;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem);
    }

    private static function privateKeyToPem(string $d): mixed {
        $d = str_pad($d, 32, "\0", STR_PAD_LEFT);
        $pub = self::b64urlDecode(self::publicKey());
        $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        return openssl_pkey_get_private($pem);
    }

    private static function derToJose(string $der): string {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $sOff = $offset + 2 + $rLen;
        $sLen = ord($der[$sOff + 1]);
        $s = substr($der, $sOff + 2, $sLen);
        $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
        return $r . $s;
    }

    public static function b64url(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string {
        $pad = 4 - (strlen($data) % 4);
        if ($pad !== 4) {
            $data .= str_repeat('=', $pad);
        }
        $out = base64_decode(strtr($data, '-_', '+/'), true);
        return $out === false ? '' : $out;
    }
}
