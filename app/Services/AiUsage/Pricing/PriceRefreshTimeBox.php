<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

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
     */
    public const int AGENT_STEP_SECONDS = 90;

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
}
