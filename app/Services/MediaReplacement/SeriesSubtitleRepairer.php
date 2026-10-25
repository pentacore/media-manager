<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Jobs\SearchSubtitleAuditEpisode;
use App\Models\ActionTypeConfig;
use App\Models\SubtitleAuditBatch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turns a series subtitle audit's missing episodes into one batch and queues a
 * release search per episode. It never searches or dispatches a request itself.
 */
final readonly class SeriesSubtitleRepairer
{
    /** A searching batch younger than this blocks a new repair of the same series. */
    public const int IN_PROGRESS_MINUTES = 30;

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 5;

    /**
     * @throws SeriesSubtitleRepairRefused when nothing is missing, the replacement
     *                                     action type is disabled, or a batch for the
     *                                     series is still searching
     */
    public function start(SeriesSubtitleAudit $seriesSubtitleAudit, User $user): SubtitleAuditBatch
    {
        $missing = $seriesSubtitleAudit->missing();

        throw_if($missing === [], SeriesSubtitleRepairRefused::class, 'nothing_missing');
        throw_unless(
            ActionTypeConfig::query()->where('type', 'replace_media_file')->where('is_enabled', true)->exists(),
            SeriesSubtitleRepairRefused::class,
            'action_type_disabled',
        );

        // The lock makes the in-progress check and the create one step, so two
        // simultaneous repairs of one series cannot both pass the check.
        return Cache::lock(
            sprintf('subtitle-audit-repair:%d:%d', $seriesSubtitleAudit->serviceConnectionId, $seriesSubtitleAudit->seriesId),
            self::LOCK_SECONDS,
        )->block(self::LOCK_WAIT_SECONDS, function () use ($seriesSubtitleAudit, $user, $missing): SubtitleAuditBatch {
            throw_if($this->batchInProgress($seriesSubtitleAudit), SeriesSubtitleRepairRefused::class, 'batch_in_progress');

            return $this->createBatch($seriesSubtitleAudit, $user, $missing);
        });
    }

    private function batchInProgress(SeriesSubtitleAudit $seriesSubtitleAudit): bool
    {
        return SubtitleAuditBatch::query()
            ->where('service_connection_id', $seriesSubtitleAudit->serviceConnectionId)
            ->where('series_id', $seriesSubtitleAudit->seriesId)
            ->where('status', SubtitleAuditBatchStatus::Searching)
            ->where('created_at', '>=', now()->subMinutes(self::IN_PROGRESS_MINUTES))
            ->exists();
    }

    /**
     * @param  list<SeriesSubtitleAuditEpisode>  $missing
     */
    private function createBatch(SeriesSubtitleAudit $seriesSubtitleAudit, User $user, array $missing): SubtitleAuditBatch
    {
        return DB::transaction(function () use ($seriesSubtitleAudit, $user, $missing): SubtitleAuditBatch {
            $subtitleAuditBatch = SubtitleAuditBatch::query()->create([
                'user_id' => $user->id,
                'service_connection_id' => $seriesSubtitleAudit->serviceConnectionId,
                'series_id' => $seriesSubtitleAudit->seriesId,
                'series_title' => $seriesSubtitleAudit->seriesTitle,
                'season_number' => $seriesSubtitleAudit->seasonNumber,
                'status' => SubtitleAuditBatchStatus::Searching,
            ]);

            foreach ($missing as $episode) {
                $subtitleAuditEpisode = $subtitleAuditBatch->episodes()->create([
                    'episode_id' => $episode->episodeId,
                    'season_number' => $episode->seasonNumber,
                    'episode_number' => $episode->episodeNumber,
                    'missing_languages' => $episode->missingLanguages,
                    'outcome' => SubtitleAuditEpisodeOutcome::Searching,
                ]);

                dispatch(new SearchSubtitleAuditEpisode($subtitleAuditEpisode->id))->afterCommit();
            }

            return $subtitleAuditBatch;
        });
    }
}
