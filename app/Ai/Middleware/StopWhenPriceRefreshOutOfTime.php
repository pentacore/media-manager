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
 * PriceVerifierPhase folds in whatever the agent already verified and wrote,
 * as for a budget stop.
 *
 * Checked on EVERY step, including step 0: a provider failover
 * (UsesFailoverChain) re-runs the whole agent loop on the fallback provider
 * starting at step 0 again (laravel/ai's Promptable::withModelFailover()),
 * so exempting the first step would let that restarted step run unchecked,
 * possibly well past the deadline. On the very first provider's step 0 this
 * duplicates the phase's own pre-prompt check, which is harmless.
 */
final class StopWhenPriceRefreshOutOfTime
{
    public function handle(PendingStep $pendingStep, Closure $next): mixed
    {
        throw_unless(resolve(PriceRefreshTimeBox::class)->hasRoomFor(PriceRefreshTimeBox::AGENT_STEP_SECONDS), PriceRefreshOutOfTime::class);

        return $next($pendingStep);
    }
}
