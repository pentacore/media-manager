<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Cache\Services\BazarrCache;
use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleUpload;
use App\Services\Actions\ActionExecutor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

final readonly class BazarrActions implements ActionExecutor
{
    private const array TYPES = [
        'bazarr_download_best',
        'bazarr_download_exact',
        'bazarr_upload_subtitle',
        'bazarr_delete_subtitle',
        'bazarr_sync_subtitle',
        'bazarr_translate_subtitle',
        'bazarr_modify_subtitle',
        'bazarr_scan_media',
        'bazarr_run_task',
    ];

    private const array COMMON_MEDIA_FIELDS = [
        'title',
        'bazarr_connection_id',
        'service_connection_id',
        'subtitle_case_id',
        'media_type',
        'target_ids',
        'target_fingerprint',
    ];

    private const array REVIEWABLE_CASE_STATUSES = [
        SubtitleCaseStatus::BazarrSearching,
        SubtitleCaseStatus::DownloadRequested,
        SubtitleCaseStatus::AdvisorRunning,
        SubtitleCaseStatus::ReplacementRequested,
    ];

    public function __construct(
        private BazarrSubtitleFingerprint $bazarrSubtitleFingerprint,
        private BazarrMediaFingerprint $bazarrMediaFingerprint,
        private SubtitleCaseLifecycle $subtitleCaseLifecycle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        throw_unless(
            in_array($actionRequest->type, self::TYPES, true),
            InvalidArgumentException::class,
            sprintf('BazarrActions cannot execute type "%s".', $actionRequest->type),
        );

        $payload = $actionRequest->payload;
        $this->validatePayload($actionRequest->type, $payload);

        $serviceConnection = $this->resolveBazarr($payload);
        $targetFingerprint = $this->requiredFingerprint($payload, 'target_fingerprint');
        $lock = Cache::lock(
            sprintf('bazarr-action:%d:%s', $serviceConnection->id, $targetFingerprint),
            120,
        );

        throw_unless($lock->get(), RuntimeException::class, 'This Bazarr target is already being modified.');

        try {
            $this->revalidateTarget($payload, $serviceConnection);
            $bazarrClient = new BazarrClient($serviceConnection);

            try {
                $result = $this->write($actionRequest->type, $payload, $bazarrClient);
            } catch (ConnectionException $connectionException) {
                $this->markCaseNeedsReview($actionRequest, $payload, $connectionException->getMessage());

                throw new BazarrIndeterminateOutcomeException('Bazarr may have accepted the operation before the connection was lost.', $connectionException->getCode(), previous: $connectionException);
            } catch (RequestException $requestException) {
                if (! $requestException->response->serverError()) {
                    throw new InvalidArgumentException('Bazarr rejected the approved operation.', $requestException->getCode(), previous: $requestException);
                }

                $this->markCaseNeedsReview($actionRequest, $payload, $requestException->getMessage());

                throw new BazarrIndeterminateOutcomeException('Bazarr returned a server error after the operation was submitted; its outcome requires reconciliation.', $requestException->getCode(), previous: $requestException);
            }

            new BazarrCache($serviceConnection)->bustAll();

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveBazarr(array $payload): ServiceConnection
    {
        $bazarrId = $this->requiredPositiveInteger($payload, 'bazarr_connection_id');
        $bazarr = ServiceConnection::query()->find($bazarrId);

        throw_unless(
            $bazarr instanceof ServiceConnection
                && $bazarr->type === ServiceType::Bazarr
                && $bazarr->is_active,
            InvalidArgumentException::class,
            'The pinned Bazarr connection is missing or inactive.',
        );

        return $bazarr;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function revalidateTarget(array $payload, ServiceConnection $bazarr): void
    {
        if (isset($payload['task_id'])) {
            $expected = hash('sha256', sprintf('bazarr-task:%d:%s', $bazarr->id, $payload['task_id']));
            throw_unless(
                hash_equals($expected, $this->requiredFingerprint($payload, 'target_fingerprint')),
                InvalidArgumentException::class,
                'The Bazarr task target changed after approval.',
            );

            return;
        }

        $mediaType = $this->requiredMediaType($payload);
        $serviceConnectionId = $this->requiredPositiveInteger($payload, 'service_connection_id');
        $serviceConnection = ServiceConnection::query()->find($serviceConnectionId);
        $expectedRole = $mediaType === 'episode' ? BazarrServiceRole::Sonarr : BazarrServiceRole::Radarr;

        throw_unless(
            $serviceConnection instanceof ServiceConnection
                && $serviceConnection->is_active
                && $serviceConnection->type === $expectedRole->serviceType(),
            InvalidArgumentException::class,
            'The pinned managing service connection is missing or inactive.',
        );

        $mapped = $bazarr->mappedConnection($expectedRole);
        throw_unless(
            $mapped instanceof ServiceConnection && $mapped->is($serviceConnection),
            InvalidArgumentException::class,
            'The pinned managing service connection is no longer mapped to Bazarr.',
        );

        if (! isset($payload['subtitle_case_id'])) {
            new BazarrCache($bazarr)->bustAll();
            $targetIds = $this->targetIds($payload, $mediaType);
            $items = $mediaType === 'episode'
                ? new BazarrClient($bazarr)->getEpisodes(episodeIds: [$targetIds['episode_id']])['data']
                : new BazarrClient($bazarr)->getMovies(length: 100)['data'];
            $mediaId = $mediaType === 'episode' ? $targetIds['episode_id'] : $targetIds['radarr_id'];
            $item = collect($items)->first(function (array $candidate) use ($mediaType, $mediaId): bool {
                $key = $mediaType === 'episode' ? 'sonarrEpisodeId' : 'radarrId';

                return ($candidate[$key] ?? null) === $mediaId;
            });

            throw_unless(
                is_array($item)
                    && $this->bazarrMediaFingerprint->verify(
                        $mediaType,
                        $item,
                        $this->requiredFingerprint($payload, 'target_fingerprint'),
                    ),
                InvalidArgumentException::class,
                'The installed media file changed after approval.',
            );

            return;
        }

        $subtitleCase = SubtitleCase::query()->find($this->requiredPositiveInteger($payload, 'subtitle_case_id'));
        throw_unless(
            $subtitleCase instanceof SubtitleCase
                && $subtitleCase->bazarr_connection_id === $bazarr->id
                && $subtitleCase->service_connection_id === $serviceConnection->id
                && $subtitleCase->media_type === $mediaType
                && $subtitleCase->target_ids === $payload['target_ids'],
            InvalidArgumentException::class,
            'The linked subtitle case no longer identifies this target.',
        );
        throw_unless(
            hash_equals($subtitleCase->file_fingerprint, $this->requiredFingerprint($payload, 'target_fingerprint')),
            InvalidArgumentException::class,
            'The installed media file changed after approval.',
        );

    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function write(
        string $type,
        array $payload,
        BazarrClient $bazarrClient,
    ): array {
        $mediaType = isset($payload['media_type']) ? $this->requiredMediaType($payload) : null;
        $mediaId = $mediaType === null ? null : $this->mediaId($payload, $mediaType);

        match ($type) {
            'bazarr_download_best' => $this->downloadBest($bazarrClient, $payload, $mediaType, $mediaId),
            'bazarr_download_exact' => $this->downloadExact($bazarrClient, $payload, $mediaType),
            'bazarr_upload_subtitle' => $this->upload($bazarrClient, $payload, $mediaType),
            'bazarr_delete_subtitle' => $this->deleteSubtitle($bazarrClient, $payload, $mediaType),
            'bazarr_sync_subtitle' => $this->applySubtitleTool($bazarrClient, $payload, $mediaType, 'sync'),
            'bazarr_translate_subtitle' => $this->applySubtitleTool($bazarrClient, $payload, $mediaType, 'translate'),
            'bazarr_modify_subtitle' => $this->applySubtitleTool(
                $bazarrClient,
                $payload,
                $mediaType,
                $this->requiredString($payload, 'tool_action', '/^[a-zA-Z0-9_-]{1,64}$/D'),
            ),
            'bazarr_scan_media' => $this->scanMedia($bazarrClient, $payload, $mediaType),
            'bazarr_run_task' => $bazarrClient->runTask($this->requiredString($payload, 'task_id', '/^[a-zA-Z0-9_.:-]{1,150}$/D')),
            default => throw new InvalidArgumentException('Unsupported Bazarr action type.'),
        };

        return array_filter([
            'operation' => $type,
            'media_type' => $mediaType,
            'media_id' => $mediaId,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function downloadBest(BazarrClient $bazarrClient, array $payload, ?string $mediaType, ?int $mediaId): void
    {
        throw_unless($mediaType !== null && $mediaId !== null, InvalidArgumentException::class, 'A media target is required.');
        $arguments = [
            $mediaId,
            $this->requiredLanguage($payload),
            $this->requiredBoolean($payload, 'forced'),
            $this->requiredBoolean($payload, 'hearing_impaired'),
        ];

        if ($mediaType === 'episode') {
            $bazarrClient->downloadBestEpisode(...$arguments);

            return;
        }

        $bazarrClient->downloadBestMovie(...$arguments);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function downloadExact(BazarrClient $bazarrClient, array $payload, ?string $mediaType): void
    {
        $targetIds = $this->targetIds($payload, (string) $mediaType);
        $selection = [
            ...$targetIds,
            'fingerprint' => $this->requiredFingerprint($payload, 'candidate_fingerprint'),
        ];

        try {
            if ($mediaType === 'episode') {
                $bazarrClient->downloadExactEpisode($selection);
            } else {
                $bazarrClient->downloadExactMovie($selection);
            }
        } catch (UnexpectedValueException $unexpectedValueException) {
            throw new InvalidArgumentException('The approved subtitle candidate is no longer available.', $unexpectedValueException->getCode(), previous: $unexpectedValueException);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upload(BazarrClient $bazarrClient, array $payload, ?string $mediaType): void
    {
        $upload = SubtitleUpload::query()->find($this->requiredPositiveInteger($payload, 'subtitle_upload_id'));
        throw_unless(
            $upload instanceof SubtitleUpload
                && $upload->consumed_at === null
                && $upload->cancelled_at === null
                && $upload->cleaned_up_at === null
                && $upload->expires_at->isFuture()
                && Storage::disk('local')->exists($upload->path),
            InvalidArgumentException::class,
            'The staged subtitle upload is unavailable.',
        );

        $contents = Storage::disk('local')->get($upload->path);
        throw_unless(hash_equals($upload->checksum, hash('sha256', (string) $contents)), InvalidArgumentException::class, 'The staged subtitle upload checksum changed.');

        $uploadedFile = new UploadedFile(
            Storage::disk('local')->path($upload->path),
            $upload->display_name,
            $upload->mime_type,
            test: true,
        );
        $targetIds = $this->targetIds($payload, (string) $mediaType);
        $arguments = [
            $this->requiredLanguage($payload),
            $this->requiredBoolean($payload, 'forced'),
            $this->requiredBoolean($payload, 'hearing_impaired'),
            $uploadedFile,
        ];

        if ($mediaType === 'episode') {
            $bazarrClient->uploadEpisode($targetIds['series_id'], $targetIds['episode_id'], ...$arguments);
        } else {
            $bazarrClient->uploadMovie($targetIds['radarr_id'], ...$arguments);
        }

        Storage::disk('local')->delete($upload->path);
        $upload->update([
            'consumed_at' => now(),
            'cleaned_up_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deleteSubtitle(BazarrClient $bazarrClient, array $payload, ?string $mediaType): void
    {
        $selection = $this->freshSubtitleSelection(
            $bazarrClient,
            $payload,
            (string) $mediaType,
            $this->requiredFingerprint($payload, 'subtitle_fingerprint'),
        );

        if ($mediaType === 'episode') {
            $bazarrClient->deleteEpisodeSubtitle($selection);
        } else {
            $bazarrClient->deleteMovieSubtitle($selection);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applySubtitleTool(
        BazarrClient $bazarrClient,
        array $payload,
        ?string $mediaType,
        string $action,
    ): void {
        $selection = $this->freshSubtitleSelection(
            $bazarrClient,
            $payload,
            (string) $mediaType,
            $this->requiredFingerprint($payload, 'subtitle_fingerprint'),
        );

        $bazarrClient->applySubtitleTool([
            ...$selection,
            'action' => $action,
            ...$this->toolOptions($payload),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function scanMedia(BazarrClient $bazarrClient, array $payload, ?string $mediaType): void
    {
        $targetIds = $this->targetIds($payload, (string) $mediaType);
        $bazarrClient->runMediaAction(
            (string) $mediaType,
            $mediaType === 'episode' ? $targetIds['series_id'] : $targetIds['radarr_id'],
            $this->requiredString($payload, 'media_action', '/^(scan-disk|search-missing|search-wanted|sync)$/D'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function freshSubtitleSelection(
        BazarrClient $bazarrClient,
        array $payload,
        string $mediaType,
        string $fingerprint,
    ): array {
        $targetIds = $this->targetIds($payload, $mediaType);
        $items = $mediaType === 'episode'
            ? $bazarrClient->getEpisodes(episodeIds: [$targetIds['episode_id']])['data']
            : $bazarrClient->getMovies(length: 100)['data'];
        $mediaId = $mediaType === 'episode' ? $targetIds['episode_id'] : $targetIds['radarr_id'];
        $item = collect($items)->first(function (array $candidate) use ($mediaType, $mediaId): bool {
            $key = $mediaType === 'episode' ? 'sonarrEpisodeId' : 'radarrId';

            return ($candidate[$key] ?? null) === $mediaId;
        });

        throw_unless(is_array($item), InvalidArgumentException::class, 'The Bazarr media target is no longer available.');
        $tracks = is_array($item['subtitles'] ?? null) ? $item['subtitles'] : [];

        foreach ($tracks as $track) {
            if (! is_array($track)) {
                continue;
            }

            $selection = $this->subtitleSelection($mediaType, $mediaId, $targetIds, $track);

            if ($this->bazarrSubtitleFingerprint->verify($selection, $fingerprint)) {
                return $selection;
            }
        }

        throw new InvalidArgumentException('The selected subtitle is no longer available.');
    }

    /**
     * @param  array<string, int>  $targetIds
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function subtitleSelection(string $mediaType, int $mediaId, array $targetIds, array $track): array
    {
        $language = collect(['code3', 'code2', 'language', 'name'])
            ->map(fn (string $key): mixed => $track[$key] ?? null)
            ->first(fn (mixed $value): bool => is_string($value) && trim($value) !== '');
        $path = $track['path'] ?? null;

        throw_unless(is_string($language) && is_string($path) && $path !== '', InvalidArgumentException::class, 'Bazarr returned a malformed subtitle track.');

        return [
            ...$targetIds,
            'media_type' => $mediaType,
            'media_id' => $mediaId,
            'path' => $path,
            'language' => trim($language),
            'forced' => ($track['forced'] ?? false) === true,
            'hearing_impaired' => ($track['hi'] ?? $track['hearing_impaired'] ?? false) === true,
            'display_name' => basename(str_replace('\\', '/', $path)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar>
     */
    private function toolOptions(array $payload): array
    {
        $options = $payload['options'] ?? [];
        throw_unless(is_array($options), InvalidArgumentException::class, 'Bazarr subtitle tool options must be an object.');
        $allowed = ['reference', 'max_offset_seconds', 'no_fix_framerate', 'gss', 'original_format'];
        throw_if(array_diff(array_keys($options), $allowed) !== [], InvalidArgumentException::class, 'Bazarr subtitle tool options contain unexpected fields.');

        return array_filter(
            $options,
            is_scalar(...),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markCaseNeedsReview(ActionRequest $actionRequest, array $payload, string $reason): void
    {
        $subtitleCaseId = isset($payload['subtitle_case_id'])
            ? $this->requiredPositiveInteger($payload, 'subtitle_case_id')
            : null;
        $subtitleCase = $subtitleCaseId === null
            ? SubtitleCase::query()
                ->where('download_action_request_id', $actionRequest->id)
                ->orWhere('replacement_action_request_id', $actionRequest->id)
                ->first()
            : SubtitleCase::query()->find($subtitleCaseId);

        if (! $subtitleCase instanceof SubtitleCase || ! in_array($subtitleCase->status, self::REVIEWABLE_CASE_STATUSES, true)) {
            return;
        }

        $this->subtitleCaseLifecycle->needsReview($subtitleCase, $reason);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(string $type, array $payload): void
    {
        $specificFields = match ($type) {
            'bazarr_download_best' => ['language', 'forced', 'hearing_impaired'],
            'bazarr_download_exact' => ['candidate_fingerprint'],
            'bazarr_upload_subtitle' => ['subtitle_upload_id', 'language', 'forced', 'hearing_impaired'],
            'bazarr_delete_subtitle' => ['subtitle_fingerprint'],
            'bazarr_sync_subtitle', 'bazarr_translate_subtitle' => ['subtitle_fingerprint', 'options'],
            'bazarr_modify_subtitle' => ['subtitle_fingerprint', 'tool_action', 'options'],
            'bazarr_scan_media' => ['media_action'],
            'bazarr_run_task' => ['title', 'bazarr_connection_id', 'target_fingerprint', 'task_id'],
            default => [],
        };
        $allowed = $type === 'bazarr_run_task'
            ? $specificFields
            : [...self::COMMON_MEDIA_FIELDS, ...$specificFields];
        $unexpected = array_diff(array_keys($payload), $allowed);

        throw_if(
            $unexpected !== [],
            InvalidArgumentException::class,
            'Bazarr action payload contains unexpected fields: '.implode(', ', $unexpected).'.',
        );

        $this->requiredPositiveInteger($payload, 'bazarr_connection_id');
        $this->requiredFingerprint($payload, 'target_fingerprint');

        if ($type === 'bazarr_run_task') {
            $this->requiredString($payload, 'task_id', '/^[a-zA-Z0-9_.:-]{1,150}$/D');

            return;
        }

        $mediaType = $this->requiredMediaType($payload);
        $this->requiredPositiveInteger($payload, 'service_connection_id');
        $this->targetIds($payload, $mediaType);

        if (isset($payload['subtitle_case_id'])) {
            $this->requiredPositiveInteger($payload, 'subtitle_case_id');
        }

        if (in_array($type, ['bazarr_download_best', 'bazarr_upload_subtitle'], true)) {
            $this->requiredLanguage($payload);
            $this->requiredBoolean($payload, 'forced');
            $this->requiredBoolean($payload, 'hearing_impaired');
        }

        if ($type === 'bazarr_download_exact') {
            $this->requiredFingerprint($payload, 'candidate_fingerprint');
        }

        if (in_array($type, ['bazarr_delete_subtitle', 'bazarr_sync_subtitle', 'bazarr_translate_subtitle', 'bazarr_modify_subtitle'], true)) {
            $this->requiredFingerprint($payload, 'subtitle_fingerprint');
        }

        if ($type === 'bazarr_scan_media') {
            $this->requiredString($payload, 'media_action', '/^(scan-disk|search-missing|search-wanted|sync)$/D');
        }

        if ($type === 'bazarr_upload_subtitle') {
            $this->requiredPositiveInteger($payload, 'subtitle_upload_id');
        }

        if ($type === 'bazarr_modify_subtitle') {
            $this->requiredString($payload, 'tool_action', '/^[a-zA-Z0-9_-]{1,64}$/D');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function targetIds(array $payload, string $mediaType): array
    {
        $targetIds = $payload['target_ids'] ?? null;
        throw_unless(is_array($targetIds), InvalidArgumentException::class, 'Bazarr target IDs must be an object.');
        $allowed = $mediaType === 'episode'
            ? ['series_id', 'episode_id', 'episode_file_id']
            : ['radarr_id', 'movie_file_id'];
        throw_if(array_diff(array_keys($targetIds), $allowed) !== [], InvalidArgumentException::class, 'Bazarr target IDs contain unexpected fields.');

        foreach (array_keys($targetIds) as $key) {
            $this->requiredPositiveInteger($targetIds, $key);
        }

        return array_map(static fn (mixed $value): int => (int) $value, $targetIds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredMediaType(array $payload): string
    {
        $mediaType = $payload['media_type'] ?? null;
        throw_unless(in_array($mediaType, ['episode', 'movie'], true), InvalidArgumentException::class, 'Bazarr media type must be episode or movie.');

        return $mediaType;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mediaId(array $payload, string $mediaType): int
    {
        $targetIds = $this->targetIds($payload, $mediaType);

        return $mediaType === 'episode' ? $targetIds['episode_id'] : $targetIds['radarr_id'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredLanguage(array $payload): string
    {
        return $this->requiredString($payload, 'language', '/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})?$/D');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredPositiveInteger(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        throw_unless(is_int($value) && $value > 0, InvalidArgumentException::class, sprintf('Bazarr action field "%s" must be a positive integer.', $key));

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredBoolean(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? null;
        throw_unless(is_bool($value), InvalidArgumentException::class, sprintf('Bazarr action field "%s" must be boolean.', $key));

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredFingerprint(array $payload, string $key): string
    {
        return $this->requiredString($payload, $key, '/^[a-f0-9]{64}$/D');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key, string $pattern): string
    {
        $value = $payload[$key] ?? null;
        throw_unless(
            is_string($value) && preg_match($pattern, $value) === 1,
            InvalidArgumentException::class,
            sprintf('Bazarr action field "%s" is invalid.', $key),
        );

        return $value;
    }
}
