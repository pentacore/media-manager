<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Ai\Agents\PriceFetcherAgent;
use App\Events\AiPriceRefreshStateChanged;
use App\Models\AiModelPrice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshAiPricesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Concurrency is enforced via {@see lock()} at dispatch time so the
     * controller can reject overlap with a clear error. We never retry — a
     * partial agent run leaves prices in a defined state and the user can
     * trigger another refresh manually if they want.
     */
    public int $tries = 1;

    public const string LOCK_KEY = 'ai-price-refresh:lock';

    /**
     * Seconds the lock survives if a job dies without releasing it. Long
     * enough to cover a slow agent run (40 steps, multiple WebFetch calls)
     * but short enough that a wedged worker doesn't permanently block.
     */
    public const int LOCK_TTL = 1800;

    public function __construct(public User $triggeredBy) {}

    public function handle(): void
    {
        event(new AiPriceRefreshStateChanged(
            state: AiPriceRefreshStateChanged::STATE_RUNNING,
            triggeredBy: $this->triggeredBy,
        ));

        $before = AiModelPrice::query()->count();

        try {
            $response = (new PriceFetcherAgent)
                ->forUser($this->triggeredBy)
                ->prompt(
                    'Refresh the catalog now. Visit the canonical pricing page for OpenAI, Anthropic, Google Gemini, DeepSeek, xAI, and Mistral. Upsert one row per generally-available text/chat model with up-to-date input, output, cache, and reasoning rates. Skip image / audio / embedding products.'
                );

            $after = AiModelPrice::query()->count();

            event(new AiPriceRefreshStateChanged(
                state: AiPriceRefreshStateChanged::STATE_SUCCEEDED,
                triggeredBy: $this->triggeredBy,
                summary: mb_substr($response->text, 0, 500),
                added: max(0, $after - $before),
                total: $after,
            ));
        } catch (Throwable $throwable) {
            Log::error('RefreshAiPricesJob failed.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'user_id' => $this->triggeredBy->id,
            ]);

            event(new AiPriceRefreshStateChanged(
                state: AiPriceRefreshStateChanged::STATE_FAILED,
                triggeredBy: $this->triggeredBy,
                error: $throwable->getMessage(),
            ));
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    /**
     * Called by Laravel when the job throws past the configured retries. We
     * already broadcast STATE_FAILED in handle() and never rethrow, so this
     * only fires for unexpected paths (e.g. queue worker crash). Mirror the
     * same cleanup so the lock + UI don't get stuck "running".
     */
    public function failed(?Throwable $throwable): void
    {
        Cache::forget(self::LOCK_KEY);

        event(new AiPriceRefreshStateChanged(
            state: AiPriceRefreshStateChanged::STATE_FAILED,
            triggeredBy: $this->triggeredBy,
            error: $throwable?->getMessage() ?? 'Job failed',
        ));
    }

    /**
     * Atomically claim the global refresh slot. Returns true only for the
     * caller that successfully acquired the lock; subsequent callers see
     * false until the running job releases it.
     */
    public static function tryLock(int $userId): bool
    {
        return Cache::add(self::LOCK_KEY, $userId, self::LOCK_TTL);
    }

    public static function isRunning(): bool
    {
        return Cache::has(self::LOCK_KEY);
    }
}
