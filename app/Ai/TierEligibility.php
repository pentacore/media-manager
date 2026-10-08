<?php

declare(strict_types=1);

namespace App\Ai;

use App\Services\AiUsage\AiRateLimitGuard;
use App\Services\AiUsage\PoolHeadroom;
use App\Services\AiUsage\PoolHeadroomFigures;
use Throwable;

/**
 * Whether a non-last tier may run now: its model is not rate-limited and,
 * when the tier has pool minimums, its model's free pool has that much left.
 * Fails closed: if either check cannot be answered, the tier is skipped and
 * the list falls through to its unconditional last tier.
 */
final readonly class TierEligibility
{
    public function __construct(
        private AiRateLimitGuard $aiRateLimitGuard,
        private PoolHeadroom $poolHeadroom,
    ) {}

    /**
     * Why the tier is skipped, or null when it may run.
     */
    public function skipReason(string $provider, string $model, ?int $minPercent, ?int $minTokens): ?string
    {
        try {
            $rateLimited = $this->aiRateLimitGuard->exhaustedReason($provider, $model);

            if ($rateLimited !== null) {
                return $rateLimited;
            }

            if ($minPercent === null && $minTokens === null) {
                return null;
            }

            $figures = $this->poolHeadroom->forModel($provider, $model);
        } catch (Throwable $throwable) {
            report($throwable);

            return 'free pool status unavailable';
        }

        if (! $figures instanceof PoolHeadroomFigures) {
            return null;
        }

        if ($minPercent !== null && $figures->percentLeft < $minPercent) {
            return sprintf('%s below %d%% (%d%% left)', $figures->name, $minPercent, (int) floor($figures->percentLeft));
        }

        if ($minTokens !== null && $figures->tokensLeft < $minTokens) {
            return sprintf('%s below %s tokens (%s left)', $figures->name, number_format($minTokens), number_format($figures->tokensLeft));
        }

        return null;
    }
}
