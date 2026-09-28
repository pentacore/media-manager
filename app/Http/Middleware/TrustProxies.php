<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TrustedProxyRanges;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Override;

/**
 * Resolves the trusted proxy list from configuration at request time instead
 * of `Middleware::trustProxies()` at bootstrap: the bootstrap closure can run
 * before the config repository is available (console kernel resolution), and
 * the framework helper writes static state that would leak across Octane
 * workers and parallel test processes.
 */
class TrustProxies extends Middleware
{
    /** Cache key that limits the broad-range warning to one log line a day. */
    public const string BROAD_RANGE_WARNING_CACHE_KEY = 'trusted-proxies:broad-range-warning';

    /**
     * @return array<int, string>|string|null
     */
    #[Override]
    protected function proxies(): array|string|null
    {
        $proxies = config('mediamanager.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return null;
        }

        $resolved = $proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies));

        $this->warnAboutBroadRanges($resolved);

        return $resolved;
    }

    /**
     * Upgraded installs keep whatever TRUSTED_PROXIES their own .env had, so
     * the log is the only place a too-wide value can be pointed out.
     *
     * @param  array<int, string>|string  $proxies
     */
    private function warnAboutBroadRanges(array|string $proxies): void
    {
        $broadEntries = TrustedProxyRanges::broadEntries($proxies);

        if ($broadEntries === [] || ! Cache::add(self::BROAD_RANGE_WARNING_CACHE_KEY, true, now()->addDay())) {
            return;
        }

        Log::warning('TRUSTED_PROXIES trusts broad address ranges: any host inside them can forge X-Forwarded-For and dodge IP-based login throttling. Set it to the reverse proxy\'s exact IP.', [
            'broad_entries' => $broadEntries,
        ]);
    }
}
