<?php

declare(strict_types=1);

namespace App\Listeners\Ai;

use App\Services\AiUsage\AiRateLimitGuard;
use Laravel\Ai\Events\PromptingAgent;

/**
 * Vetoes a provider call whose model has exhausted a configured rate limit.
 *
 * PromptingAgent (and its StreamingAgent subclass) fire inside the SDK's
 * failover loop immediately before the provider request, with the provider
 * and model already resolved — so this one listener covers every agent and
 * every call site, and a refused primary falls over to the configured
 * failover provider exactly like a provider-side 429 would.
 */
class EnforceAiRateLimit
{
    public function __construct(private readonly AiRateLimitGuard $aiRateLimitGuard) {}

    public function handle(PromptingAgent $promptingAgent): void
    {
        $this->aiRateLimitGuard->enforce(
            $promptingAgent->prompt->provider->name(),
            $promptingAgent->prompt->model,
        );
    }
}
