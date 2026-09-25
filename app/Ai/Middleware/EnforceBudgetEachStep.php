<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\BatchPricingContext;
use App\Services\AiUsage\ModelPriceLookup;
use App\Services\AiUsage\UsageColumns;
use Closure;
use Laravel\Ai\PendingStep;

/**
 * The budget guard runs once before a run; a 24-step chat turn could spend
 * well past the hard cap. Re-check before every later step, counting what
 * this run has already used (PendingStep::$usage) on top of the month total.
 */
final class EnforceBudgetEachStep
{
    public function handle(PendingStep $pendingStep, Closure $next): mixed
    {
        if (! $pendingStep->isFirstStep()) {
            resolve(AiBudgetGuard::class)->enforceIncluding($this->runCostSoFar($pendingStep));
        }

        return $next($pendingStep);
    }

    private function runCostSoFar(PendingStep $pendingStep): float
    {
        $modelPriceLookup = resolve(ModelPriceLookup::class);
        $snapshot = $modelPriceLookup->snapshotFor($pendingStep->provider, $pendingStep->model, resolve(BatchPricingContext::class)->enabled);

        return $snapshot === null ? 0.0 : $modelPriceLookup->costOf(UsageColumns::fromText($pendingStep->usage), $snapshot);
    }
}
