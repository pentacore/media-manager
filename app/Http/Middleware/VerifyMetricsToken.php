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
        // query('token') returns an array for ?token[]=x — guard so a crafted
        // request gets the same 403 instead of an array-to-string 500.
        $token = $request->query('token', '');
        $provided = $request->bearerToken() ?? (is_string($token) ? $token : '');

        abort_unless($expected !== '' && hash_equals($expected, $provided), 403);

        return $next($request);
    }
}
