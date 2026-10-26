<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\ActionRequest;
use App\Models\SubtitleAuditEpisode;
use App\Services\Actions\ActionOrchestrator;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Searches releases for one episode of a subtitle audit batch and records its
 * outcome. Only a confident, non-season-pack automatic candidate is queued, as
 * a pending replace_media_file request tagged with the batch; anything less is
 * left for a manual pick. A batch request never runs without approval.
 */
final readonly class SubtitleAuditEpisodeSearcher
{
    private const int CANDIDATE_LIMIT = 10;

    public function __construct(
        private MediaFileInspector $mediaFileInspector,
        private ReplacementCandidateFinder $replacementCandidateFinder,
        private ReplacementRequestBuilder $replacementRequestBuilder,
        private ActionOrchestrator $actionOrchestrator,
        private SubtitleCheckRules $subtitleCheckRules,
        private SubtitleAuditBatchSettler $subtitleAuditBatchSettler,
        private PendingReplacementGuard $pendingReplacementGuard,
    ) {}

    public function search(SubtitleAuditEpisode $subtitleAuditEpisode): void
    {
        // A retried job finds its row already decided; never search it twice.
        if ($subtitleAuditEpisode->outcome !== SubtitleAuditEpisodeOutcome::Searching) {
            return;
        }

        $decision = $this->outcome($subtitleAuditEpisode);

        // The expiry sweep may have failed this row while the search ran. Re-read it
        // under a lock so a late result neither overwrites that outcome nor queues
        // a request nobody tracks.
        $written = DB::transaction(function () use ($subtitleAuditEpisode, $decision): bool {
            $locked = SubtitleAuditEpisode::query()->lockForUpdate()->find($subtitleAuditEpisode->id);

            if (! $locked instanceof SubtitleAuditEpisode || $locked->outcome !== SubtitleAuditEpisodeOutcome::Searching) {
                return false;
            }

            $locked->update($decision instanceof Closure ? $decision() : $decision);

            return true;
        });

        if ($written) {
            $this->subtitleAuditBatchSettler->settle($subtitleAuditEpisode->batch);
        }
    }

    /**
     * The final write is a closure when it still has to dispatch an action request.
     *
     * @return Closure(): array{outcome: SubtitleAuditEpisodeOutcome, missing_languages?: list<string>, failure_reason?: string, action_request_id?: int, release_title?: string}|array{outcome: SubtitleAuditEpisodeOutcome, missing_languages?: list<string>, failure_reason?: string, action_request_id?: int, release_title?: string}
     */
    private function outcome(SubtitleAuditEpisode $subtitleAuditEpisode): array|Closure
    {
        $subtitleAuditBatch = $subtitleAuditEpisode->batch;
        $serviceConnection = $subtitleAuditBatch->serviceConnection;

        $snapshot = $this->mediaFileInspector->inspect(
            service: 'sonarr',
            itemId: $subtitleAuditBatch->series_id,
            seasonNumber: $subtitleAuditEpisode->season_number,
            episodeNumber: $subtitleAuditEpisode->episode_number,
            serviceConnection: $serviceConnection,
        );

        if (($snapshot['ambiguous'] ?? false) === true) {
            return ['outcome' => SubtitleAuditEpisodeOutcome::Failed, 'failure_reason' => (string) ($snapshot['reason'] ?? 'ambiguous')];
        }

        $scope = MediaReplacementScope::tryFrom((string) ($snapshot['scope'] ?? ''));

        if (! $scope instanceof MediaReplacementScope) {
            return ['outcome' => SubtitleAuditEpisodeOutcome::Failed, 'failure_reason' => 'unconfigured_root_scope'];
        }

        $missing = $this->subtitleCheckRules->missingLanguages(
            array_values(array_filter(
                is_array($snapshot['subtitles'] ?? null) ? $snapshot['subtitles'] : [],
                is_string(...),
            )),
            $scope,
        );

        if ($missing === []) {
            return ['outcome' => SubtitleAuditEpisodeOutcome::NoLongerMissing];
        }

        $result = $this->replacementCandidateFinder->find(
            target: $snapshot,
            limit: self::CANDIDATE_LIMIT,
            serviceConnection: $serviceConnection,
        );

        $candidate = is_array($result['automatic_candidate'] ?? null) ? $result['automatic_candidate'] : null;

        // The import check's `automatic_candidate ?? candidates[0]` fallback is
        // deliberately not used: a batch only carries releases nobody has to pick.
        if ($candidate === null || ($candidate['season_pack'] ?? false) === true) {
            return [
                'outcome' => $result['candidates'] === [] ? SubtitleAuditEpisodeOutcome::NothingFound : SubtitleAuditEpisodeOutcome::ManualPick,
                'missing_languages' => $missing,
            ];
        }

        $built = $this->replacementRequestBuilder->build(
            snapshot: $snapshot,
            candidate: $candidate,
            requiredLanguages: $result['effective_languages'],
            selectionMode: 'automatic',
            reason: sprintf('Series subtitle audit: the installed file is missing %s.', implode(', ', $missing)),
            autoCheckKey: $this->subtitleCheckRules->autoCheckKey($serviceConnection, $snapshot),
        );
        $built['payload']['subtitle_audit_batch_id'] = $subtitleAuditBatch->id;

        // A redelivered job, or a manual replace while a long batch searched,
        // may already have queued one: never queue a second for the file.
        if ($this->pendingReplacementGuard->inFlightFor($snapshot)) {
            return ['outcome' => SubtitleAuditEpisodeOutcome::Failed, 'failure_reason' => 'replacement_in_flight', 'missing_languages' => $missing];
        }

        return fn (): array => $this->queue($subtitleAuditEpisode, $built, $missing, $candidate);
    }

    /**
     * @param  array{payload: array<string, mixed>, description: mixed}  $built
     * @param  list<string>  $missing
     * @param  array<string, mixed>  $candidate
     * @return array{outcome: SubtitleAuditEpisodeOutcome, missing_languages: list<string>, failure_reason?: string, action_request_id?: int, release_title?: string}
     */
    private function queue(SubtitleAuditEpisode $subtitleAuditEpisode, array $built, array $missing, array $candidate): array
    {
        $subtitleAuditBatch = $subtitleAuditEpisode->batch;

        $actionRequest = $this->actionOrchestrator->dispatch(
            type: 'replace_media_file',
            sourceService: 'sonarr',
            targetService: 'sonarr',
            payload: $built['payload'],
            description: $built['description']->because(sprintf(
                'Requested by a series subtitle audit in chat by %s.',
                $subtitleAuditBatch->user->name ?? 'an unknown user',
            )),
            // A batch is reviewed as a whole before anything runs.
            forceRequiresApproval: true,
            origin: 'chat',
        );

        if (! $actionRequest instanceof ActionRequest) {
            return ['outcome' => SubtitleAuditEpisodeOutcome::Failed, 'failure_reason' => 'action_type_disabled', 'missing_languages' => $missing];
        }

        return [
            'outcome' => SubtitleAuditEpisodeOutcome::Queued,
            'missing_languages' => $missing,
            'action_request_id' => $actionRequest->id,
            'release_title' => (string) ($candidate['title'] ?? ''),
        ];
    }
}
