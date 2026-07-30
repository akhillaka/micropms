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
            $records = dns_get_record($customDomain, DNS_CNAME | DNS_A);
            
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
}
