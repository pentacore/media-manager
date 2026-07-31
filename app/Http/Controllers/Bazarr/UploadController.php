<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Enums\SubtitleCaseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bazarr\UploadRequest;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleUpload;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\SubtitleCaseFingerprint;
use App\Services\Bazarr\SubtitleInventoryService;
use App\Settings\BazarrAutomationSettings;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UploadController extends Controller
{
    public function __construct(
        private readonly SubtitleCaseFingerprint $subtitleCaseFingerprint,
    ) {}

    public function __invoke(
        UploadRequest $uploadRequest,
        SubtitleInventoryService $subtitleInventoryService,
        ActionOrchestrator $actionOrchestrator,
        BazarrAutomationSettings $bazarrAutomationSettings,
    ): JsonResponse {
        $validated = $uploadRequest->validated();
        $connection = $this->connection((int) $validated['connection']);
        $mediaType = (string) $validated['media_type'];
        $inspection = $subtitleInventoryService->inspect(
            $connection,
            $mediaType,
            (int) $validated['media_id'],
        );
        $item = $inspection['item'];

        // Capability is the server's gate, not just a disabled button: an
        // auto-approved upload would otherwise fail deep inside the executor.
        if ((new BazarrClient($connection)->getCapabilities()['upload'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'subtitle_file' => 'This Bazarr version does not support subtitle uploads.',
            ]);
        }

        if (($item['target_fingerprint'] ?? null) !== $validated['target_fingerprint']) {
            throw ValidationException::withMessages([
                'target_fingerprint' => 'The media file changed. Refresh the Subtitle Center before uploading.',
            ]);
        }

        // The linked case has to carry the Arr file identity that
        // BazarrActions::revalidateTarget recomputes before every write; the Bazarr
        // media fingerprint above only proves the browser's view is current.
        $candidate = $subtitleInventoryService->caseCandidateForMedia(
            $connection,
            $mediaType,
            (int) $validated['media_id'],
        );

        if ($candidate === null) {
            throw ValidationException::withMessages([
                'connection' => 'The managing service could not identify this media file.',
            ]);
        }

        $managingConnection = $this->managingConnection($connection, $mediaType);
        $subtitleFile = $validated['subtitle_file'];

        if (! $subtitleFile instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'subtitle_file' => 'A valid subtitle file is required.',
            ]);
        }

        $extension = strtolower($subtitleFile->getClientOriginalExtension());
        $path = 'bazarr-subtitle-uploads/'.Str::uuid().'.'.$extension;
        $contents = file_get_contents($subtitleFile->getRealPath());
        $mimeType = new finfo(FILEINFO_MIME_TYPE)->file($subtitleFile->getRealPath());

        if (! is_string($contents)
            || ! is_string($mimeType)
            || ! Storage::disk('local')->put($path, $contents)) {
            throw ValidationException::withMessages([
                'subtitle_file' => 'The subtitle file could not be staged.',
            ]);
        }

        // The case and the staged row are committed before the action is created, so
        // a refused or failing dispatch cannot roll the row away and leave the
        // user-supplied file on disk with nothing left to prune it.
        [$subtitleCase, $subtitleUpload] = DB::transaction(function () use (
            $bazarrAutomationSettings,
            $candidate,
            $connection,
            $contents,
            $extension,
            $managingConnection,
            $mimeType,
            $path,
            $uploadRequest,
            $subtitleFile,
            $validated,
        ): array {
            $subtitleCase = $this->subtitleCase(
                $connection,
                $managingConnection,
                $candidate,
                $validated,
            );

            return [$subtitleCase, SubtitleUpload::query()->create([
                'user_id' => $uploadRequest->user()?->id,
                'subtitle_case_id' => $subtitleCase->id,
                'path' => $path,
                'display_name' => Str::limit($subtitleFile->getClientOriginalName(), 255, ''),
                'checksum' => hash('sha256', $contents),
                'mime_type' => $mimeType,
                'format' => $extension,
                'size_bytes' => strlen($contents),
                'expires_at' => now()->addHours($bazarrAutomationSettings->uploadExpiryHours()),
            ])];
        });

        try {
            $actionRequest = DB::transaction(function () use (
                $actionOrchestrator,
                $connection,
                $item,
                $managingConnection,
                $subtitleCase,
                $subtitleUpload,
                $validated,
            ): ActionRequest {
                $actionRequest = $actionOrchestrator->dispatch(
                    type: 'bazarr_upload_subtitle',
                    sourceService: ServiceType::Bazarr->value,
                    targetService: ServiceType::Bazarr->value,
                    payload: [
                        ...$this->commonPayload(
                            $connection,
                            $managingConnection,
                            $subtitleCase,
                            $item,
                        ),
                        'subtitle_upload_id' => $subtitleUpload->id,
                        'language' => $validated['language'],
                        'forced' => (bool) $validated['forced'],
                        'hearing_impaired' => (bool) $validated['hearing_impaired'],
                    ],
                );

                throw_unless($actionRequest instanceof ActionRequest, ValidationException::withMessages([
                    'subtitle_file' => 'Subtitle uploads are disabled in Action Rules.',
                ]));

                $subtitleUpload->update(['action_request_id' => $actionRequest->id]);

                return $actionRequest;
            });
        } catch (Throwable $throwable) {
            $this->cancelStagedUpload($subtitleUpload);

            throw $throwable;
        }

        return response()->json([
            'id' => $actionRequest->id,
            'upload_id' => $subtitleUpload->id,
            'type' => $actionRequest->type,
            'status' => $actionRequest->status->value,
            'message' => 'Subtitle upload added to the Action Queue.',
        ], 201);
    }

    /**
     * Cancelling keeps the row inside the prune sweep, and cleanup is only recorded
     * when the staged file really went away — a failed unlink stays retryable rather
     * than leaving user-supplied data on disk with no row to find it by.
     */
    private function cancelStagedUpload(SubtitleUpload $subtitleUpload): void
    {
        $deleted = Storage::disk('local')->delete($subtitleUpload->path);

        $subtitleUpload->update(array_filter([
            'cancelled_at' => now(),
            'cleaned_up_at' => $deleted ? now() : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * The identity columns come from the reconciliation projection, not from the
     * Bazarr media fingerprint: an approved upload is revalidated against the live
     * Arr file identity, so a case keyed any other way could never execute. An
     * upload for the media's own required languages therefore lands on the case
     * reconciliation already tracks.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $validated
     */
    private function subtitleCase(
        ServiceConnection $bazarr,
        ServiceConnection $managingConnection,
        array $candidate,
        array $validated,
    ): SubtitleCase {
        $language = (string) $validated['language'];
        $requirements = [[
            'code' => $language,
            'forced' => (bool) $validated['forced'],
            'hearing_impaired' => (bool) $validated['hearing_impaired'],
        ]];
        $scope = (string) $candidate['scope'];

        return SubtitleCase::query()->firstOrCreate([
            'bazarr_connection_id' => $bazarr->id,
            'service_connection_id' => $managingConnection->id,
            'file_fingerprint' => $candidate['file_fingerprint'],
            'requirements_fingerprint' => $this->subtitleCaseFingerprint->requirements($scope, [$language]),
        ], [
            'media_type' => $candidate['media_type'],
            'scope' => $scope,
            'target_ids' => $candidate['target_ids'],
            'required_languages' => $requirements,
            'status' => SubtitleCaseStatus::Observing,
            'evidence' => ['source' => 'manual_upload'],
            'observed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function commonPayload(
        ServiceConnection $bazarr,
        ServiceConnection $managingConnection,
        SubtitleCase $subtitleCase,
        array $item,
    ): array {
        return [
            'title' => 'Upload a subtitle for '.$item['title'],
            'bazarr_connection_id' => $bazarr->id,
            'service_connection_id' => $managingConnection->id,
            'subtitle_case_id' => $subtitleCase->id,
            'media_type' => $subtitleCase->media_type,
            // A linked action is revalidated against its case, so both the target
            // IDs and the fingerprint must be the case's own values.
            'target_ids' => $subtitleCase->target_ids,
            'target_fingerprint' => $subtitleCase->file_fingerprint,
        ];
    }

    private function connection(int $connectionId): ServiceConnection
    {
        return ServiceConnection::query()
            ->whereKey($connectionId)
            ->where('type', ServiceType::Bazarr)
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function managingConnection(ServiceConnection $serviceConnection, string $mediaType): ServiceConnection
    {
        $role = $mediaType === 'episode' ? BazarrServiceRole::Sonarr : BazarrServiceRole::Radarr;
        $connection = $serviceConnection->mappedConnection($role);

        if (! $connection instanceof ServiceConnection
            || ! $connection->is_active
            || $connection->type !== $role->serviceType()) {
            throw ValidationException::withMessages([
                'connection' => 'The managing service connection is missing or inactive.',
            ]);
        }

        return $connection;
    }
}
