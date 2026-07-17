<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\SubtitleCase;
use App\Services\Actions\ActionOrchestrator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class BazarrDownloadRequestCreator
{
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

            if ($lockedCase->download_action_request_id !== null) {
                return ActionRequest::query()->find($lockedCase->download_action_request_id);
            }

            if ($lockedCase->status !== SubtitleCaseStatus::BazarrSearching) {
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

            $this->subtitleCaseLifecycle->transition(
                $lockedCase,
                SubtitleCaseStatus::DownloadRequested,
                ['download_action_request_id' => $actionRequest->id],
            );

            return $actionRequest;
        }, attempts: 3);
    }
}
