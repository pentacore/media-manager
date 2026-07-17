<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
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
    /**
     * @return array<int, string>|string|null
     */
    #[Override]
    protected function proxies(): array|string|null
    {
        $proxies = config('mediamanager.trusted_proxies');

        if (! is_string($proxies) || $proxies === '') {
            return null;
        }

        return $proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies));
    }
}
