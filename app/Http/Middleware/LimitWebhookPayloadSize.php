<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook bodies are small JSON documents; php.ini allows 100 MB for
 * uploads. Reject oversized bodies before the connection lookup and token
 * decrypt, and before the controller copies the body into the database.
 * The declared Content-Length catches honest clients without reading the
 * body; the measured length catches chunked uploads.
 */
final class LimitWebhookPayloadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = max(1, (int) config('mediamanager.webhooks.max_payload_kb', 1024)) * 1024;
        $declaredLength = $request->headers->get('Content-Length');

        abort_if(is_numeric($declaredLength) && (int) $declaredLength > $maxBytes, 413);
        abort_if(mb_strlen($request->getContent(), '8bit') > $maxBytes, 413);

        return $next($request);
    }
}
