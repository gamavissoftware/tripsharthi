<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * SSRF guard for tenant-supplied outbound URLs (flow webhook_call node and
 * outbound webhook subscriptions).
 *
 * Rejects non-http(s) schemes and any host that resolves to a private,
 * loopback, link-local, or otherwise reserved IP range — preventing a tenant
 * from pointing a webhook at cloud metadata (169.254.169.254) or internal
 * services to pivot from the server.
 *
 * Residual risk: DNS rebinding (resolve-time vs connect-time TOCTOU) is not
 * fully closed here; callers also keep redirects disabled. For TravelPilot's
 * threat model (authenticated owner/admin, blind responses) this is adequate;
 * a pinned-IP connect would be the deeper fix.
 */
final class UrlGuard
{
    /** Is this URL safe to fetch server-side? */
    public static function isSafePublicUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host   = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        // Obvious loopback hostnames.
        $hostLower = strtolower($host);
        if (in_array($hostLower, ['localhost', 'localhost.localdomain', 'ip6-localhost'], true)) {
            return false;
        }

        // Resolve to IP(s). A literal IP is checked directly; a hostname is
        // resolved (IPv4 + IPv6) and ALL results must be public.
        $ips = self::resolve($host);
        if ($ips === []) {
            return false; // unresolvable → refuse
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Is this IP in a private / reserved / loopback / link-local range? Pure.
     */
    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // not a valid IP → block
        }
        // Public range = passes both NO_PRIV_RANGE and NO_RES_RANGE.
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        return ! $isPublic;
    }

    /** @return string[] resolved IPs (literal IP returned as-is) */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        $v4  = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $recs = @dns_get_record($host, DNS_AAAA);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        return $ips;
    }
}
