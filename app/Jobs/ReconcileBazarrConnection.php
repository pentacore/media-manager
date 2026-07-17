<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Bazarr\SubtitleInventoryService;
use App\Settings\BazarrAutomationSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

        if (! Cache::add(
            'bazarr-reconciliation-interval:'.$connection->id,
            now()->getTimestamp(),
            now()->addMinutes($bazarrAutomationSettings->reconciliationIntervalMinutes()),
        )) {
            return;
        }

        $maximumCases = $bazarrAutomationSettings->maxCasesPerCycle();
        $processed = 0;
        $probeSlotsUsed = 0;
        $page = 1;
        $perPage = min(100, $maximumCases);

        do {
            $candidates = $subtitleInventoryService->caseCandidates($connection, $page, $perPage);

            foreach ($candidates['data'] as $candidate) {
                if (! is_array($candidate) || $processed >= $maximumCases) {
                    break;
                }

                $probeAllowed = $probeSlotsUsed < $bazarrAutomationSettings->maxProbesPerCycle();
                dispatch(new ReconcileSubtitleCase($candidate, $probeAllowed));
                $processed++;
                $probeSlotsUsed += (int) $probeAllowed;
            }

            $page++;
            $hasMore = ($page - 1) * $perPage < $candidates['total']
                && $candidates['data'] !== []
                && ! $candidates['partial'];
        } while ($processed < $maximumCases && $hasMore);
    }

    public function failed(?Throwable $throwable): void
    {
        Log::error('Bazarr connection reconciliation failed.', [
            'connection_id' => $this->connectionId,
            'exception' => $throwable instanceof Throwable ? $throwable::class : null,
        ]);
    }
}
