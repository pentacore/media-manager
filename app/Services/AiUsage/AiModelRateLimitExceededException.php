<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use Laravel\Ai\Exceptions\RateLimitedException;

/**
 * Thrown by AiRateLimitGuard when a configured per-model limit is already
 * exhausted over its rolling window. Extends the SDK's RateLimitedException
 * so the failover loop treats a locally exhausted primary exactly like a
 * provider-side 429 and moves on to the failover provider when one is set.
 */
class AiModelRateLimitExceededException extends RateLimitedException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly RateLimitMetric $metric,
        public readonly RateLimitPeriod $period,
        public readonly int $limitValue,
        public readonly int $used,
    ) {
        parent::__construct(sprintf(
            'Rate limit reached for %s/%s: %d of %d %s per %s used.',
            $provider,
            $model,
            $used,
            $limitValue,
            $metric->value,
            $period->value,
        ));
    }
}
