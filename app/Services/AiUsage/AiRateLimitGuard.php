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
        private readonly RateLimitUsage $rateLimitUsage,
    ) {}

    /**
     * @throws AiModelRateLimitExceededException when a configured limit for the model is exhausted
     */
    public function enforce(string $provider, string $model): void
    {
        $exceeded = $this->exceeded($provider, $model);

        throw_if($exceeded instanceof AiModelRateLimitExceededException, $exceeded);
    }

    /**
     * Why the model may not run right now (tier selection skips it), or null
     * when it may — always null while enforcement is off.
     */
    public function exhaustedReason(string $provider, string $model): ?string
    {
        $exceeded = $this->exceeded($provider, $model);

        if (! $exceeded instanceof AiModelRateLimitExceededException) {
            return null;
        }

        return sprintf(
            'rate-limited (%d of %d %s per %s)',
            $exceeded->used,
            $exceeded->limitValue,
            $exceeded->metric->value,
            $exceeded->period->value,
        );
    }

    private function exceeded(string $provider, string $model): ?AiModelRateLimitExceededException
    {
        if (! $this->aiSettings->rateLimitsEnforced()) {
            return null;
        }

        $baseModel = BaseModelName::of($model);

        $price = AiModelPrice::query()
            ->with('rateLimits')
            ->where('provider', $provider)
            ->where('model', $baseModel)
            ->first();

        if ($price === null || $price->rateLimits->isEmpty()) {
            return null;
        }

        $usage = $this->rateLimitUsage->byPeriod(
            $price->rateLimits->pluck('period')->unique(),
            $provider,
            $baseModel,
        );

        foreach ($price->rateLimits as $rateLimit) {
            $used = RateLimitUsage::used(
                $rateLimit,
                $usage[$rateLimit->period->value][$provider.'|'.$baseModel] ?? null,
            );

            if ($used >= $rateLimit->limit_value) {
                return new AiModelRateLimitExceededException(
                    provider: $provider,
                    model: $baseModel,
                    metric: $rateLimit->metric,
                    period: $rateLimit->period,
                    limitValue: $rateLimit->limit_value,
                    used: $used,
                );
            }
        }

        return null;
    }
}
