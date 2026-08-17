<?php
declare(strict_types=1);

/**
 * DNSValidator - Asynchronous validation helper to verify DNS records (CNAME / A) for white-label tenant domains.
 */
class DNSValidator {

    /**
     * Verifies if a tenant custom domain points to the SaaS target host.
     * Returns true if configured correctly, false otherwise.
     */
    public static function verifyDomain(string $customDomain, string $saasTargetHost): array {
        $customDomain = strtolower(trim($customDomain));
        $saasTargetHost = strtolower(trim($saasTargetHost));

        if (empty($customDomain) || empty($saasTargetHost)) {
            return ['ok' => false, 'message' => 'Domain details are not configured.'];
        }

        try {
            // Fetch DNS records of type CNAME or A
            $records = @dns_get_record($customDomain, DNS_CNAME | DNS_A);
            
            if ($records === false || empty($records)) {
                return ['ok' => false, 'message' => 'No active DNS records found for this domain.'];
            }

            foreach ($records as $r) {
                if ($r['type'] === 'CNAME' && strtolower(trim($r['target'])) === $saasTargetHost) {
                    return ['ok' => true, 'message' => 'CNAME record verified successfully!'];
                }
                
                if ($r['type'] === 'A') {
                    // Fetch target server IP to compare
                    $targetIPs = gethostbynamel($saasTargetHost);
                    if ($targetIPs && in_array($r['ip'], $targetIPs, true)) {
                        return ['ok' => true, 'message' => 'A record verified successfully!'];
                    }
                }
            }

            return [
                'ok' => false, 
                'message' => "DNS mismatch: Please point a CNAME for '{$customDomain}' to '{$saasTargetHost}'."
            ];

        } catch (\Exception $e) {
            return ['ok' => false, 'message' => 'DNS query lookup failed: ' . $e->getMessage()];
        }
    }

    /**
     * Verifies a TXT record `micropms-verify=<token>` on the custom domain (or _micropms-verify host).
     */
    public static function verifyTxt(string $customDomain, string $token): array {
        $customDomain = strtolower(trim($customDomain));
        $token = trim($token);
        if ($customDomain === '' || $token === '') {
            return ['ok' => false, 'message' => 'Domain or verification token is missing.'];
        }
        $expected = 'micropms-verify=' . $token;
        $hosts = [$customDomain, '_micropms-verify.' . $customDomain];
        foreach ($hosts as $host) {
            $records = @dns_get_record($host, DNS_TXT) ?: [];
            foreach ($records as $r) {
                $txt = is_array($r['txt'] ?? null) ? implode('', $r['txt']) : (string)($r['txt'] ?? $r['entries'][0] ?? '');
                if (strcasecmp(trim($txt), $expected) === 0) {
                    return ['ok' => true, 'message' => 'TXT verification record found.'];
                }
            }
        }
        return [
            'ok' => false,
            'message' => "Add a TXT record on {$customDomain} with value {$expected}"
        ];
    }

    public static function verifyForProperty(string $customDomain, string $saasTargetHost, string $txtToken): array {
        $cname = self::verifyDomain($customDomain, $saasTargetHost);
        $txt = self::verifyTxt($customDomain, $txtToken);
        $ssl = self::checkHttps($customDomain);
        $ok = $cname['ok'] && $txt['ok'];
        $parts = [$cname['message'], $txt['message']];
        if ($ssl['ok']) {
            $parts[] = 'HTTPS reachable.';
        } else {
            $parts[] = 'HTTPS not reachable yet (SSL after DNS).';
        }
        return [
            'ok' => $ok,
            'cname_ok' => $cname['ok'],
            'txt_ok' => $txt['ok'],
            'ssl_ok' => $ssl['ok'],
            'message' => implode(' ', $parts),
        ];
    }

    public static function checkHttps(string $customDomain): array {
        $customDomain = strtolower(trim($customDomain));
        if ($customDomain === '') {
            return ['ok' => false];
        }
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true]]);
        $fp = @fopen('https://' . $customDomain . '/', 'r', false, $ctx);
        if (is_resource($fp)) {
            fclose($fp);
            return ['ok' => true];
        }
        return ['ok' => false];
    }
}
