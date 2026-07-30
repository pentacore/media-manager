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

        if (($item['target_fingerprint'] ?? null) !== $validated['target_fingerprint']) {
            throw ValidationException::withMessages([
                'target_fingerprint' => 'The media file changed. Refresh the Subtitle Center before uploading.',
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

        try {
            [$upload, $actionRequest] = DB::transaction(function () use (
                $actionOrchestrator,
                $bazarrAutomationSettings,
                $connection,
                $contents,
                $extension,
                $item,
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
                    $item,
                    $validated,
                );
                $subtitleUpload = SubtitleUpload::query()->create([
                    'user_id' => $uploadRequest->user()?->id,
                    'subtitle_case_id' => $subtitleCase->id,
                    'path' => $path,
                    'display_name' => Str::limit($subtitleFile->getClientOriginalName(), 255, ''),
                    'checksum' => hash('sha256', $contents),
                    'mime_type' => $mimeType,
                    'format' => $extension,
                    'size_bytes' => strlen($contents),
                    'expires_at' => now()->addHours($bazarrAutomationSettings->uploadExpiryHours()),
                ]);
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

                if (! $actionRequest instanceof ActionRequest) {
                    throw ValidationException::withMessages([
                        'subtitle_file' => 'Subtitle uploads are disabled in Action Rules.',
                    ]);
                }

                $subtitleUpload->update(['action_request_id' => $actionRequest->id]);

                return [$subtitleUpload, $actionRequest];
            });
        } catch (Throwable $throwable) {
            Storage::disk('local')->delete($path);

            throw $throwable;
        }

        return response()->json([
            'id' => $actionRequest->id,
            'upload_id' => $upload->id,
            'type' => $actionRequest->type,
            'status' => $actionRequest->status->value,
            'message' => 'Subtitle upload added to the Action Queue.',
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $validated
     */
    private function subtitleCase(
        ServiceConnection $bazarr,
        ServiceConnection $managingConnection,
        array $item,
        array $validated,
    ): SubtitleCase {
        $requirements = [[
            'code' => $validated['language'],
            'forced' => (bool) $validated['forced'],
            'hearing_impaired' => (bool) $validated['hearing_impaired'],
        ]];

        return SubtitleCase::query()->firstOrCreate([
            'bazarr_connection_id' => $bazarr->id,
            'service_connection_id' => $managingConnection->id,
            'file_fingerprint' => $item['target_fingerprint'],
            'requirements_fingerprint' => hash('sha256', (string) json_encode($requirements)),
        ], [
            'media_type' => $item['media_type'],
            'scope' => $item['scope'] ?? $item['media_type'],
            'target_ids' => $this->targetIds($item),
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
            'media_type' => $item['media_type'],
            'target_ids' => $this->targetIds($item),
            'target_fingerprint' => $item['target_fingerprint'],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, int>
     */
    private function targetIds(array $item): array
    {
        return $item['media_type'] === 'episode'
            ? ['series_id' => (int) $item['series_id'], 'episode_id' => (int) $item['media_id']]
            : ['radarr_id' => (int) $item['media_id']];
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
