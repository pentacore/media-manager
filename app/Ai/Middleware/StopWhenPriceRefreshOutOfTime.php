<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Services\AiUsage\Pricing\PriceRefreshOutOfTime;
use App\Services\AiUsage\Pricing\PriceRefreshTimeBox;
use Closure;
use Laravel\Ai\PendingStep;

/**
 * Stop the price refresh agent before its next step once the queued refresh's
 * time box no longer fits one more step, so RefreshAiPricesJob ends with a
 * report instead of being killed at its timeout. Throwing makes the SDK
 * dispatch AgentFailed (completed steps are still billed) and
 * PriceVerifierPhase folds in whatever the agent already verified and wrote,
 * as for a budget stop. A step that runs has its provider timeout cut to the
 * time left (as StopWhenDecisionRunOutOfTime does), and its WebFetchTool
 * calls check the box themselves, so a step with many parallel fetches
 * cannot overrun it either. The provider-native WebFetch mode
 * (mediamanager.ai.price_fetcher_provider_webfetch) cannot be bounded this
 * way: the provider fetches inside the model call, so only the clamped step
 * timeout limits it.
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
        $priceRefreshTimeBox = resolve(PriceRefreshTimeBox::class);

        throw_unless($priceRefreshTimeBox->hasRoomFor(PriceRefreshTimeBox::AGENT_STEP_SECONDS), PriceRefreshOutOfTime::class);

        $secondsLeft = $priceRefreshTimeBox->secondsLeft();

        if ($secondsLeft === null) {
            return $next($pendingStep);
        }

        $timeout = min($pendingStep->timeout ?? PriceRefreshTimeBox::MODEL_CALL_SECONDS, $secondsLeft);

        return $next(new PendingStep(...[...get_object_vars($pendingStep), 'timeout' => $timeout]));
    }
}
