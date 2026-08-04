<?php

declare(strict_types=1);

namespace Store\Services;

use InvalidArgumentException;

/**
 * Blocks server-side requests to internal/private network destinations
 * (SSRF guard). Used both to validate a supplier's list_products_url when
 * an admin enters it, and again right before the import actually fetches
 * it — resolving the hostname once and pinning curl to that IP closes the
 * gap where DNS could point somewhere else between the two checks.
 */
class UrlGuard
{
    /**
     * Validates $url and returns ['scheme' => , 'host' => , 'port' => , 'ip' => ].
     * Throws InvalidArgumentException if the URL is missing, malformed, uses
     * a non-HTTP(S) scheme, or resolves to a private/reserved/loopback address.
     */
    public static function resolvePublicHttpUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new InvalidArgumentException('That URL could not be parsed.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http:// and https:// URLs are allowed.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : self::resolveHostname($host);

        if ($ip === null) {
            throw new InvalidArgumentException('Could not resolve that host.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('That URL points to a private or reserved network address, which is not allowed.');
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => (int) $port, 'ip' => $ip];
    }

    private static function resolveHostname(string $host): ?string
    {
        $records = dns_get_record($host, DNS_A + DNS_AAAA);
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                return $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                return $record['ipv6'];
            }
        }

        $ipv4 = gethostbyname($host);
        return $ipv4 !== $host ? $ipv4 : null;
    }
}
