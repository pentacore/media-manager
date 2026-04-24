<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ServiceConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $serviceType = $request->route('service');
        $connectionId = $request->route('connection');

        $connection = ServiceConnection::query()
            ->where('id', $connectionId)
            ->where('type', $serviceType)
            ->where('is_active', true)
            ->first();

        abort_unless($connection, 404);

        $token = $request->header('X-Webhook-Token');

        abort_if(
            ! is_string($token) || $token === '' || ! hash_equals((string) $connection->webhook_token, $token),
            401
        );

        $request->attributes->set('service_connection', $connection);

        return $next($request);
    }
}
