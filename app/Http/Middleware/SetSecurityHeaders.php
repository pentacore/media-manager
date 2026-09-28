<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser hardening for every response. Deliberately no full
 * Content-Security-Policy: Vite and Inertia rely on inline scripts, so only
 * framing is restricted (frame-ancestors, mirrored by X-Frame-Options for
 * older browsers). A header a controller already set wins — chat attachment
 * downloads send their own sandboxing CSP. HSTS is opt-in and only sent on
 * requests the app sees as HTTPS (which needs TRUSTED_PROXIES behind a proxy).
 */
final class SetSecurityHeaders
{
    /** @var array<string, string> */
    private const array BASELINE = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach (self::BASELINE as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure()
            && (bool) config('mediamanager.security.hsts_enabled', false)
            && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', sprintf('max-age=%d', (int) config('mediamanager.security.hsts_max_age', 31536000)));
        }

        return $response;
    }
}
