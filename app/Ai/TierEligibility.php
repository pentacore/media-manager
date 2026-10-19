<?php

declare(strict_types=1);

namespace App\Ai;

use App\Services\AiUsage\AiRateLimitGuard;
use App\Services\AiUsage\PoolHeadroom;
use App\Services\AiUsage\PoolHeadroomFigures;
use App\Services\AiUsage\PoolHeadroomUnavailable;
use Throwable;

/**
 * Whether a non-last tier may run now: its model is not rate-limited and,
 * when the tier has pool minimums, its model's free pool has that much left.
 * Fails closed: if a check cannot be answered, the tier is skipped with
 * 'rate limit status unavailable' or 'free pool status unavailable' and the
 * list falls through to its unconditional last tier.
 */
final class TierEligibility
{
    /**
     * Failures already reported by this instance, keyed "class|message", so an
     * outage hit by several conditional tiers is reported once. Cleared by
     * forgetReported() whenever the owning resolver flushes.
     *
     * @var array<string, true>
     */
    private array $reported = [];

    public function __construct(
        private readonly AiRateLimitGuard $aiRateLimitGuard,
        private readonly PoolHeadroom $poolHeadroom,
    ) {}

    /**
     * Let the next failure be reported again.
     */
    public function forgetReported(): void
    {
        $this->reported = [];
    }

    /**
     * Why the tier is skipped, or null when it may run.
     *
     * A rate-limit lookup that throws is reported (once per distinct failure)
     * and the tier skipped. A headroom lookup that throws
     * PoolHeadroomUnavailable is skipped silently: PoolHeadroom already
     * reported the underlying failure once and caches the unavailability, so
     * reporting it again per tier would only repeat it. Any other throwable
     * from the headroom lookup is unexpected and reported here.
     */
    public function skipReason(string $provider, string $model, ?int $minPercent, ?int $minTokens): ?string
    {
        try {
            $rateLimited = $this->aiRateLimitGuard->exhaustedReason($provider, $model);
        } catch (Throwable $throwable) {
            $this->reportOnce($throwable);

            return 'rate limit status unavailable';
        }

        if ($rateLimited !== null) {
            return $rateLimited;
        }

        if ($minPercent === null && $minTokens === null) {
            return null;
        }

        try {
            $figures = $this->poolHeadroom->forModel($provider, $model);
        } catch (PoolHeadroomUnavailable) {
            return 'free pool status unavailable';
        } catch (Throwable $throwable) {
            $this->reportOnce($throwable);

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

    private function reportOnce(Throwable $throwable): void
    {
        $key = $throwable::class.'|'.$throwable->getMessage();

        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;

        report($throwable);
    }
}
