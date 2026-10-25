<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Jobs\Middleware\LimitSubtitleAuditSearchConcurrency;
use App\Models\SubtitleAuditEpisode;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use App\Services\MediaReplacement\SubtitleAuditEpisodeSearcher;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Runs one series subtitle audit episode's release search.
 *
 * Unlimited tries bounded by retryUntil(): the concurrency middleware releases
 * the job while both of the connection's search slots are busy, and every
 * release counts as an attempt. Two exceptions (or the window running out)
 * fail the job, and failed() then fails the still-searching episode. The
 * searcher skips a row that already has an outcome, so a retry is safe.
 */
#[Timeout(270)]
#[Tries(0)]
#[MaxExceptions(2)]
#[Backoff(30)]
final class SearchSubtitleAuditEpisode implements ShouldQueue
{
    use Queueable;

    private const int MAX_WAIT_MINUTES = 30;

    public function __construct(public readonly int $subtitleAuditEpisodeId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new LimitSubtitleAuditSearchConcurrency];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::MAX_WAIT_MINUTES);
    }

    /**
     * The Sonarr connection the episode's batch searches, for the concurrency
     * key; null once the row is gone.
     */
    public function serviceConnectionId(): ?int
    {
        return $this->episode()?->batch->service_connection_id;
    }

    public function handle(SubtitleAuditEpisodeSearcher $subtitleAuditEpisodeSearcher): void
    {
        $subtitleAuditEpisode = $this->episode();

        if (! $subtitleAuditEpisode instanceof SubtitleAuditEpisode) {
            return;
        }

        $subtitleAuditEpisodeSearcher->search($subtitleAuditEpisode);
    }

    /**
     * The search kept failing or never got a slot: fail the episode if it is
     * still searching, then settle its batch.
     */
    public function failed(?Throwable $throwable): void
    {
        $subtitleAuditEpisode = $this->episode();

        if (! $subtitleAuditEpisode instanceof SubtitleAuditEpisode) {
            return;
        }

        SubtitleAuditEpisode::query()
            ->whereKey($subtitleAuditEpisode->id)
            ->where('outcome', SubtitleAuditEpisodeOutcome::Searching)
            ->update([
                'outcome' => SubtitleAuditEpisodeOutcome::Failed,
                'failure_reason' => 'search_failed',
            ]);

        resolve(SubtitleAuditBatchSettler::class)->settle($subtitleAuditEpisode->batch);
    }

    private function episode(): ?SubtitleAuditEpisode
    {
        return SubtitleAuditEpisode::query()->with('batch')->find($this->subtitleAuditEpisodeId);
    }
}
