<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Models\AiModelPrice;
use App\Settings\AiSettings;

/**
 * Enforces the per-model rate limits configured on AiModelPrice rows.
 *
 * Each limit is measured over its rolling window (last minute/hour/day)
 * against the same ai_usage_records aggregate the AI Usage page shows, so
 * what the admin sees as "used" is exactly what the guard checks. The check
 * is pre-flight only: a request is refused when past usage already meets
 * the cap, and the request that crosses a token cap still runs because its
 * token count is unknown until the response arrives.
 *
 * Enforcement is gated by AiSettings::rateLimitsEnforced() so existing
 * installs keep the display-only behaviour until an admin opts in.
 */
class AiRateLimitGuard
{
    public function __construct(
        private readonly AiSettings $aiSettings,
        private readonly AiUsageReporting $aiUsageReporting,
    ) {}

    /**
     * @throws AiModelRateLimitExceededException when a configured limit for the model is exhausted
     */
    public function enforce(string $provider, string $model): void
    {
        if (! $this->aiSettings->rateLimitsEnforced()) {
            return;
        }

        $baseModel = AiUsageReporting::baseModel($model);

        $price = AiModelPrice::query()
            ->with('rateLimits')
            ->where('provider', $provider)
            ->where('model', $baseModel)
            ->first();

        if ($price === null || $price->rateLimits->isEmpty()) {
            return;
        }

        $usage = $this->aiUsageReporting->rateLimitUsageByPeriod(
            $price->rateLimits->pluck('period')->unique(),
            $provider,
            $baseModel,
        );

        foreach ($price->rateLimits as $rateLimit) {
            $used = AiUsageReporting::rateLimitUsed(
                $rateLimit,
                $usage[$rateLimit->period->value][$provider.'|'.$baseModel] ?? null,
            );

            if ($used >= $rateLimit->limit_value) {
                throw new AiModelRateLimitExceededException(
                    provider: $provider,
                    model: $baseModel,
                    metric: $rateLimit->metric,
                    period: $rateLimit->period,
                    limitValue: $rateLimit->limit_value,
                    used: $used,
                );
            }
        }
    }
}
