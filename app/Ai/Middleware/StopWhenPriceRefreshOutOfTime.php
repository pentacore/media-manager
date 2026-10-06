<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Services\AiUsage\Pricing\PriceRefreshOutOfTime;
use App\Services\AiUsage\Pricing\PriceRefreshTimeBox;
use Closure;
use Laravel\Ai\PendingStep;

/**
 * Stop the price verifier before its next step once the queued refresh's
 * time box no longer fits one more step, so RefreshAiPricesJob ends with a
 * report instead of being killed at its timeout. Throwing makes the SDK
 * dispatch AgentFailed (completed steps are still billed) and
 * PriceVerifierPhase fails the remaining providers, as for a budget stop.
 * The first step is covered by the phase's own check before prompting.
 */
final class StopWhenPriceRefreshOutOfTime
{
    public function handle(PendingStep $pendingStep, Closure $next): mixed
    {
        throw_if(
            ! $pendingStep->isFirstStep() && ! resolve(PriceRefreshTimeBox::class)->hasRoomFor(PriceRefreshTimeBox::AGENT_STEP_SECONDS),
            PriceRefreshOutOfTime::class,
        );

        return $next($pendingStep);
    }
}
