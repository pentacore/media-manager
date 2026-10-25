<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAIEnabled;
use App\Http\Middleware\EnsurePasswordIsSet;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitWebhookPayloadSize;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerifyAgentToken;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);
        $middleware->append(SetSecurityHeaders::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Priority middleware (auth, throttle) is sorted ahead of everything else, including the
        // payload limit; put the limit first so oversized bodies are refused before authentication
        // and before they count against a rate limit.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, LimitWebhookPayloadSize::class);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'password.set' => EnsurePasswordIsSet::class,
            'ai.enabled' => EnsureAIEnabled::class,
            'webhook.payload-limit' => LimitWebhookPayloadSize::class,
            'agent.token' => VerifyAgentToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The Uptime Kuma push URL carries its token in the path.
        $exceptions->dontFlash(['push_url']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e): bool => $request->is('api/agent/*') || $request->expectsJson());
    })->create();
