<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Server;
use App\Services\HostMonitoring\AgentTokens;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an agent by its `{server uuid}.{secret}` bearer token and exposes the server as the `server` request attribute.
 *
 * Implements AuthenticatesRequests so the middleware priority map runs it before `throttle:agent`, which keys report limits by that attribute.
 */
final class VerifyAgentToken implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $server = $this->serverFor((string) $request->bearerToken());

        abort_if(!$server instanceof Server, 401);

        $request->attributes->set('server', $server);

        return $next($request);
    }

    private function serverFor(string $bearer): ?Server
    {
        [$uuid, $secret] = array_pad(explode('.', $bearer, 2), 2, '');

        if ($secret === '' || ! Str::isUuid($uuid)) {
            return null;
        }

        $server = Server::query()->where('uuid', $uuid)->whereNotNull('token_hash')->first();

        if ($server === null || ! hash_equals((string) $server->token_hash, AgentTokens::hash($secret))) {
            return null;
        }

        return $server;
    }
}
