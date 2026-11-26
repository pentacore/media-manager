<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Ai\Tools\PriceFetcher\WebFetchTool;
use Carbon\CarbonImmutable;

/**
 * The wall-clock budget of one queued price refresh. RefreshAiPricesJob
 * opens it; the feed fetcher, the verifier phase and the verifier agent's
 * step middleware ask whether their next worst-case operation still fits,
 * so the run ends with a report instead of being killed at the job
 * timeout. Never opened (the CLI command, the admin catalog page) it allows
 * everything.
 *
 * Holds per-job state: bound scoped() in AIServiceProvider, which Octane
 * flushes per request and the queue worker per job.
 */
final class PriceRefreshTimeBox
{
    /**
     * Room one verifier step needs: a model call (laravel/ai's 60 s default
     * timeout) plus about 30 s of the step's tool calls (two 15 s page fetches).
     * A step with more fetches than that is bounded by WebFetchTool itself,
     * which checks WEB_FETCH_SECONDS before every request.
     */
    public const int AGENT_STEP_SECONDS = 90;

    /** laravel/ai's text gateways' default request timeout, used when a step sets none. */
    public const int MODEL_CALL_SECONDS = 60;

    /**
     * Room one WebFetchTool request needs: its 15 s timeout plus a 5 s margin,
     * so a fetch started inside the box also ends inside it.
     */
    public const int WEB_FETCH_SECONDS = WebFetchTool::TIMEOUT_SECONDS + 5;

    private ?CarbonImmutable $deadline = null;

    public function open(int $seconds): void
    {
        $this->deadline = now()->addSeconds($seconds);
    }

    public function hasRoomFor(int $seconds): bool
    {
        return ! $this->deadline instanceof CarbonImmutable
            || now()->addSeconds($seconds)->lessThanOrEqualTo($this->deadline);
    }

    /**
     * Whole seconds left before the deadline (never negative), or null when
     * the box was never opened.
     */
    public function secondsLeft(): ?int
    {
        if (! $this->deadline instanceof CarbonImmutable) {
            return null;
        }

        return max(0, (int) floor(now()->diffInSeconds($this->deadline, false)));
    }
}
