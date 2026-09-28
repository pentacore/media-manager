<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spots TRUSTED_PROXIES entries that trust far more than one reverse proxy.
 * Any host inside a trusted range can send its own X-Forwarded-For and so
 * pick the IP the login throttles key on.
 */
final class TrustedProxyRanges
{
    /** IPv4 CIDRs wider than this prefix count as broad. */
    public const int NARROWEST_BROAD_IPV4_PREFIX = 24;

    /** IPv6 CIDRs wider than this prefix count as broad. */
    public const int NARROWEST_BROAD_IPV6_PREFIX = 64;

    /**
     * Symfony `Request::setTrustedProxies()` keywords that expand to
     * something far broader than a single proxy: `PRIVATE_SUBNETS` resolves
     * to every RFC1918/RFC4193 range, and `REMOTE_ADDR` trusts whoever the
     * socket sees as the caller, i.e. anyone who can reach the port.
     *
     * @var list<string>
     */
    private const array BROAD_KEYWORDS = ['PRIVATE_SUBNETS', 'REMOTE_ADDR'];

    /**
     * @param  array<int, string>|string  $proxies
     * @return list<string>
     */
    public static function broadEntries(array|string $proxies): array
    {
        $broad = [];

        foreach ((array) $proxies as $entry) {
            if ($entry === '*' || $entry === '**' || in_array(strtoupper($entry), self::BROAD_KEYWORDS, true)) {
                $broad[] = $entry;

                continue;
            }

            if (! str_contains($entry, '/')) {
                continue;
            }

            [$address, $prefix] = explode('/', $entry, 2);
            $narrowest = str_contains($address, ':') ? self::NARROWEST_BROAD_IPV6_PREFIX : self::NARROWEST_BROAD_IPV4_PREFIX;

            if (is_numeric($prefix) && (int) $prefix < $narrowest) {
                $broad[] = $entry;
            }
        }

        return $broad;
    }
}
