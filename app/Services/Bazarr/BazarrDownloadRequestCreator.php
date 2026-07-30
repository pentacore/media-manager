<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\SubtitleCase;
use App\Services\Actions\ActionOrchestrator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class BazarrDownloadRequestCreator
{
    /**
     * Statuses in which a recorded download request is still expected to act on
     * the case, and may therefore be reused instead of queueing another.
     */
    private const array IN_FLIGHT_STATUSES = [
        ActionRequestStatus::Pending,
        ActionRequestStatus::Approved,
        ActionRequestStatus::Executing,
    ];

    public function __construct(
        private ActionOrchestrator $actionOrchestrator,
        private SubtitleCaseLifecycle $subtitleCaseLifecycle,
    ) {}

    /**
     * @param  array<string, mixed>  $requirement
     */
    public function create(SubtitleCase $subtitleCase, array $requirement): ?ActionRequest
    {
        $language = $requirement['code'] ?? null;
        $forced = $requirement['forced'] ?? false;
        $hearingImpaired = $requirement['hearing_impaired'] ?? false;
        throw_unless(is_string($language) && preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D', $language) === 1, InvalidArgumentException::class, 'Subtitle requirement language is invalid.');
        throw_unless(is_bool($forced) && is_bool($hearingImpaired), InvalidArgumentException::class, 'Subtitle requirement qualifiers are invalid.');

        return DB::transaction(function () use ($subtitleCase, $language, $forced, $hearingImpaired): ?ActionRequest {
            $lockedCase = SubtitleCase::query()->lockForUpdate()->find($subtitleCase->id);

            if (! $lockedCase instanceof SubtitleCase) {
                return null;
            }

            // Per-language uniqueness: a case missing several languages needs one
            // request per language, so the linked request is keyed by language and
            // qualifiers in evidence rather than the single scalar column.
            $requestKey = $this->requestKey($language, $forced, $hearingImpaired);
            $downloadRequests = $this->downloadRequests($lockedCase);

            if (isset($downloadRequests[$requestKey])) {
                $recorded = ActionRequest::query()->find($downloadRequests[$requestKey]);

                // Only an in-flight request may be handed back. The map survives
                // reconciliation, so returning a request that already ran would
                // report a fresh download to the probe: the attempt would score
                // Succeeded, no empty probe would accumulate, and the case could
                // never reach the replacement escalation threshold. A recorded
                // attempt that has finished yields null instead — the requirement
                // was already tried, and re-queueing it would only repeat a
                // download that did not satisfy the case.
                if ($recorded instanceof ActionRequest) {
                    return in_array($recorded->status, self::IN_FLIGHT_STATUSES, true)
                        ? $recorded
                        : null;
                }
            }

            // Additional languages may be requested while the case already sits in
            // download_requested from an earlier language in the same probe.
            if (! in_array($lockedCase->status, [SubtitleCaseStatus::BazarrSearching, SubtitleCaseStatus::DownloadRequested], true)) {
                return null;
            }

            $displayName = is_string($lockedCase->evidence['display_name'] ?? null)
                ? mb_substr($lockedCase->evidence['display_name'], 0, 200)
                : 'subtitle case '.$lockedCase->id;
            $actionRequest = $this->actionOrchestrator->dispatch(
                type: 'bazarr_download_best',
                sourceService: 'bazarr',
                targetService: 'bazarr',
                payload: [
                    'title' => sprintf('Download %s subtitles for %s', $language, $displayName),
                    'bazarr_connection_id' => $lockedCase->bazarr_connection_id,
                    'service_connection_id' => $lockedCase->service_connection_id,
                    'subtitle_case_id' => $lockedCase->id,
                    'media_type' => $lockedCase->media_type,
                    'target_ids' => $lockedCase->target_ids,
                    'target_fingerprint' => $lockedCase->file_fingerprint,
                    'language' => $language,
                    'forced' => $forced,
                    'hearing_impaired' => $hearingImpaired,
                ],
            );

            if (! $actionRequest instanceof ActionRequest) {
                return null;
            }

            $downloadRequests[$requestKey] = $actionRequest->id;
            $evidence = is_array($lockedCase->evidence) ? $lockedCase->evidence : [];
            $evidence['download_requests'] = $downloadRequests;

            // The scalar column keeps the most-recent request for existing
            // correlation; the evidence map records every per-language request.
            if ($lockedCase->status === SubtitleCaseStatus::BazarrSearching) {
                $this->subtitleCaseLifecycle->transition(
                    $lockedCase,
                    SubtitleCaseStatus::DownloadRequested,
                    [
                        'download_action_request_id' => $actionRequest->id,
                        'evidence' => $evidence,
                    ],
                );
            } else {
                $lockedCase->forceFill([
                    'download_action_request_id' => $actionRequest->id,
                    'evidence' => $evidence,
                ])->save();
            }

            return $actionRequest;
        }, attempts: 3);
    }

    private function requestKey(string $language, bool $forced, bool $hearingImpaired): string
    {
        return sprintf('%s|%d|%d', $language, (int) $forced, (int) $hearingImpaired);
    }

    /**
     * @return array<string, int>
     */
    private function downloadRequests(SubtitleCase $subtitleCase): array
    {
        $downloadRequests = $subtitleCase->evidence['download_requests'] ?? null;

        if (! is_array($downloadRequests)) {
            return [];
        }

        return array_filter($downloadRequests, is_int(...));
    }
}
