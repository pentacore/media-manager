<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token-gates the Prometheus /metrics endpoint. The expected token comes from
 * `mediamanager.metrics.token`; an empty expected token denies all access so an
 * unconfigured deployment never exposes the scrape surface. The comparison is
 * constant-time to avoid leaking the token through timing.
 */
class VerifyMetricsToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('mediamanager.metrics.token', '');
        $provided = $request->bearerToken() ?? (string) $request->query('token', '');

        abort_unless($expected !== '' && hash_equals($expected, (string) $provided), 403);

        return $next($request);
    }
}
