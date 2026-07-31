<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleInventoryService;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Timeout(120)]
#[Tries(4)]
#[UniqueFor(300)]
final class ReconcileBazarrConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('bazarr-reconciliation')->releaseAfter(60)];
    }

    public function handle(
        SubtitleInventoryService $subtitleInventoryService,
        BazarrAutomationSettings $bazarrAutomationSettings,
    ): void {
        if (! $bazarrAutomationSettings->enabled()) {
            return;
        }

        $connection = ServiceConnection::query()->find($this->connectionId);

        if (! $connection instanceof ServiceConnection
            || $connection->type !== ServiceType::Bazarr
            || ! $connection->is_active) {
            return;
        }

        // Overlap protection for a single run only. The once-per-interval gate is
        // the interval marker below, written after a successful discovery so a
        // failed discovery leaves declared tries/backoff free to retry rather than
        // silently exiting on a prematurely written marker.
        $lock = Cache::lock('bazarr-reconciliation-run:'.$connection->id, 300);

        if (! $lock->get()) {
            return;
        }

        try {
            if (Cache::has('bazarr-reconciliation-interval:'.$connection->id)) {
                return;
            }

            $this->discoverAndDispatch($connection, $subtitleInventoryService, $bazarrAutomationSettings);

            Cache::put(
                'bazarr-reconciliation-interval:'.$connection->id,
                now()->getTimestamp(),
                now()->addMinutes($bazarrAutomationSettings->reconciliationIntervalMinutes()),
            );
        } finally {
            $lock->release();
        }
    }

    private function discoverAndDispatch(
        ServiceConnection $connection,
        SubtitleInventoryService $subtitleInventoryService,
        BazarrAutomationSettings $bazarrAutomationSettings,
    ): void {
        $maximumCases = $bazarrAutomationSettings->maxCasesPerCycle();
        $perPage = min(100, $maximumCases);
        $cursorKey = 'bazarr-reconciliation-cursor:'.$connection->id;
        $page = max(1, (int) Cache::get($cursorKey, 1));
        $processed = 0;
        $total = 0;

        do {
            $candidates = $subtitleInventoryService->caseCandidates($connection, $page, $perPage);

            // An incomplete read must not advance the cursor or write the interval
            // marker; retrying keeps reconciliation live through a transient outage.
            throw_if($candidates['partial'], RuntimeException::class, 'Bazarr subtitle discovery was incomplete; retrying.');

            $total = $candidates['total'];

            foreach ($candidates['data'] as $candidate) {
                if (! is_array($candidate) || $processed >= $maximumCases) {
                    break;
                }

                dispatch(new ReconcileSubtitleCase($candidate));
                $processed++;
            }

            $page++;
            $hasMore = ($page - 1) * $perPage < $total && $candidates['data'] !== [];
        } while ($processed < $maximumCases && $hasMore);

        // Resume where this cycle stopped so a stream larger than the per-cycle cap
        // eventually visits every title; wrap to the start once the stream is spent.
        $nextOffset = ($page - 1) * $perPage;
        $nextCursor = ($total === 0 || $nextOffset >= $total) ? 1 : $page;
        Cache::put($cursorKey, $nextCursor, now()->addDay());
    }

    public function failed(?Throwable $throwable): void
    {
        Log::error('Bazarr connection reconciliation failed.', [
            'connection_id' => $this->connectionId,
            'exception' => $throwable instanceof Throwable ? $throwable::class : null,
        ]);
    }
}
