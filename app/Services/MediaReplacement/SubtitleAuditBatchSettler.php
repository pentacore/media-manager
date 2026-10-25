<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\SubtitleAuditBatch;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Not final: the searcher and job tests mock it to assert a batch is settled.
 *
 * A batch is ready once no episode is still searching. Exactly one of two
 * things then happens: the chat tool shows the card (the wait saw it ready),
 * or the tool handed the batch off first and admins get a notification.
 */
class SubtitleAuditBatchSettler
{
    public function __construct(private readonly AdminNotifier $adminNotifier) {}

    /**
     * Mark the batch ready when its last episode has an outcome, notifying admins
     * once if the chat tool already handed it off.
     */
    public function settle(SubtitleAuditBatch $subtitleAuditBatch): void
    {
        $shouldNotify = DB::transaction(function () use ($subtitleAuditBatch): bool {
            $locked = SubtitleAuditBatch::query()->lockForUpdate()->findOrFail($subtitleAuditBatch->id);

            if ($locked->status !== SubtitleAuditBatchStatus::Searching) {
                return false;
            }

            $stillSearching = $locked->episodes()
                ->where('outcome', SubtitleAuditEpisodeOutcome::Searching)
                ->exists();

            if ($stillSearching) {
                return false;
            }

            $locked->status = SubtitleAuditBatchStatus::Ready;
            $locked->completed_at = now();
            $locked->save();

            return $locked->handed_off_at !== null;
        });

        $subtitleAuditBatch->refresh();

        if ($shouldNotify) {
            $this->adminNotifier->send(new MediaReplacementStatusChanged(
                service: 'sonarr',
                title: sprintf('Subtitle audit: %s', $subtitleAuditBatch->series_title),
                message: $this->summary($subtitleAuditBatch),
                level: 'info',
                url: route('actions.requests.index', ['subtitle_audit_batch' => $subtitleAuditBatch->id], absolute: false),
            ));
        }
    }

    /**
     * Hand a still-searching batch off to the notification path. Returns false
     * when the batch is already ready, so the caller shows the card instead.
     */
    public function handOff(SubtitleAuditBatch $subtitleAuditBatch): bool
    {
        $handedOff = DB::transaction(function () use ($subtitleAuditBatch): bool {
            $locked = SubtitleAuditBatch::query()->lockForUpdate()->findOrFail($subtitleAuditBatch->id);

            if ($locked->status === SubtitleAuditBatchStatus::Ready) {
                return false;
            }

            $locked->handed_off_at = now();
            $locked->save();

            return true;
        });

        $subtitleAuditBatch->refresh();

        return $handedOff;
    }

    public function summary(SubtitleAuditBatch $subtitleAuditBatch): string
    {
        $counts = $subtitleAuditBatch->episodes()
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $count = static fn (SubtitleAuditEpisodeOutcome $outcome): int => (int) ($counts[$outcome->value] ?? 0);

        $queued = $count(SubtitleAuditEpisodeOutcome::Queued);
        $manualPick = $count(SubtitleAuditEpisodeOutcome::ManualPick);
        $nothingFound = $count(SubtitleAuditEpisodeOutcome::NothingFound);
        $noLongerMissing = $count(SubtitleAuditEpisodeOutcome::NoLongerMissing);
        $failed = $count(SubtitleAuditEpisodeOutcome::Failed);

        $parts = array_values(array_filter([
            $queued > 0 ? sprintf('%d %s ready to approve', $queued, $queued === 1 ? 'replacement' : 'replacements') : null,
            $manualPick > 0 ? sprintf('%d %s a manual pick', $manualPick, $manualPick === 1 ? 'needs' : 'need') : null,
            $nothingFound > 0 ? sprintf('%d had no usable release', $nothingFound) : null,
            $noLongerMissing > 0 ? sprintf('%d no longer missing subtitles', $noLongerMissing) : null,
            $failed > 0 ? sprintf('%d failed', $failed) : null,
        ]));

        if ($parts === []) {
            return 'Nothing to do.';
        }

        return implode(', ', $parts).'.';
    }
}
