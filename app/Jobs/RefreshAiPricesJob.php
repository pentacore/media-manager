<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\AiPriceRefreshStateChanged;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Services\AiUsage\Pricing\AiPriceRefreshCoordinator;
use App\Services\AiUsage\Pricing\Data\RefreshReport;
use App\Services\AiUsage\Pricing\RefreshScope;
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
     * Concurrency is enforced via {@see tryLock()} at dispatch time so the
     * controller can reject overlap with a clear error. We never retry — a
     * partial refresh leaves prices in a defined state and the user can
     * trigger another refresh manually if they want.
     */
    public int $tries = 1;

    public const string LOCK_KEY = 'ai-price-refresh:lock';

    /**
     * Seconds the lock survives if a job dies without releasing it. Long
     * enough to cover a slow hybrid run (feed fetch plus a bounded verifier
     * agent run) but short enough that a wedged worker doesn't permanently
     * block.
     */
    public const int LOCK_TTL = 1800;

    /**
     * Audit trigger recorded on the run for admin-initiated refreshes.
     */
    public const string TRIGGER = 'admin';

    public function __construct(public User $triggeredBy) {}

    public function handle(): void
    {
        event(new AiPriceRefreshStateChanged(
            state: AiPriceRefreshStateChanged::STATE_RUNNING,
            triggeredBy: $this->triggeredBy,
        ));

        $before = AiModelPrice::query()->count();

        try {
            // The coordinator owns the whole pipeline (feed, per-provider
            // writes, verifier fallback, and budget enforcement) and folds
            // every source/write failure into the report rather than throwing.
            $report = resolve(AiPriceRefreshCoordinator::class)->run(
                mode: AiPriceRefreshCoordinator::MODE_APPLY,
                source: AiPriceRefreshCoordinator::SOURCE_HYBRID,
                scope: RefreshScope::all(),
                triggeredBy: $this->triggeredBy,
                trigger: self::TRIGGER,
            );

            if ($report->finalResult === RefreshReport::RESULT_FAILED) {
                Log::error('RefreshAiPricesJob failed.', [
                    'run_id' => $report->runId,
                    'final_result' => $report->finalResult,
                    'error' => $report->errorMessage,
                    'user_id' => $this->triggeredBy->id,
                ]);

                event(new AiPriceRefreshStateChanged(
                    state: AiPriceRefreshStateChanged::STATE_FAILED,
                    triggeredBy: $this->triggeredBy,
                    error: $report->errorMessage ?? 'Price refresh failed.',
                    report: $report,
                ));

                return;
            }

            $after = AiModelPrice::query()->count();

            // A partial run still rides the succeeded event; the attached
            // report carries `final_result=partial` so the UI can distinguish
            // it. `added`/`total` stay row-count derived for payload
            // compatibility with the existing admin toast.
            event(new AiPriceRefreshStateChanged(
                state: AiPriceRefreshStateChanged::STATE_SUCCEEDED,
                triggeredBy: $this->triggeredBy,
                summary: mb_substr(implode(' ', $report->toConsoleLines()), 0, 500),
                added: max(0, $after - $before),
                total: $after,
                report: $report,
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
     * false until the running job releases it. The owner is either a user id
     * (admin-initiated) or a string label ('cli', 'schedule') for unattended
     * runs, and is stored only for diagnostics.
     */
    public static function tryLock(int|string $owner): bool
    {
        return Cache::add(self::LOCK_KEY, $owner, self::LOCK_TTL);
    }

    public static function isRunning(): bool
    {
        return Cache::has(self::LOCK_KEY);
    }
}
