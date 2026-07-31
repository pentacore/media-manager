<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bazarr;

use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bazarr\OperationRequest;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionOrchestrator;
use App\Services\Bazarr\BazarrCapabilityRegistry;
use App\Services\Bazarr\BazarrClient;
use App\Services\Bazarr\SubtitleInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class OperationController extends Controller
{
    public function __invoke(
        OperationRequest $operationRequest,
        SubtitleInventoryService $subtitleInventoryService,
        ActionOrchestrator $actionOrchestrator,
    ): JsonResponse {
        $validated = $operationRequest->validated();
        $connection = $this->connection((int) $validated['connection']);
        $mediaType = (string) $validated['media_type'];
        $mediaId = (int) $validated['media_id'];
        $inspection = $subtitleInventoryService->inspect($connection, $mediaType, $mediaId);
        $item = $inspection['item'];

        if (($item['target_fingerprint'] ?? null) !== $validated['target_fingerprint']) {
            throw ValidationException::withMessages([
                'target_fingerprint' => 'The media file changed. Refresh the Subtitle Center before requesting an operation.',
            ]);
        }

        $operation = (string) $validated['operation'];
        $bazarrClient = new BazarrClient($connection);
        $this->validateCapability($operation, $mediaType, $bazarrClient);
        $this->validateOpaqueSelection($operation, $validated, $item, $bazarrClient, $mediaType, $mediaId);
        $managingConnection = $this->managingConnection($connection, $mediaType);
        $type = 'bazarr_'.$operation;
        $actionRequest = $actionOrchestrator->dispatch(
            type: $type,
            sourceService: ServiceType::Bazarr->value,
            targetService: ServiceType::Bazarr->value,
            payload: [
                ...$this->commonPayload($connection, $managingConnection, $item, $operation),
                ...$this->operationPayload($operation, $validated),
            ],
        );

        if (! $actionRequest instanceof ActionRequest) {
            throw ValidationException::withMessages([
                'operation' => 'This Bazarr operation is disabled in Action Rules.',
            ]);
        }

        return response()->json([
            'id' => $actionRequest->id,
            'type' => $actionRequest->type,
            'status' => $actionRequest->status->value,
            'message' => 'Subtitle operation added to the Action Queue.',
        ], 201);
    }

    /**
     * The UI hides operations this Bazarr cannot perform, but the capability is the
     * server's gate: a queued request may auto-execute, and an unsupported write
     * would only fail once it reached the provider.
     */
    private function validateCapability(string $operation, string $mediaType, BazarrClient $bazarrClient): void
    {
        $capability = BazarrCapabilityRegistry::capabilityForOperation($operation, $mediaType);

        if ($capability === null) {
            throw ValidationException::withMessages(['operation' => 'Unsupported Bazarr operation.']);
        }

        if (($bazarrClient->getCapabilities()[$capability] ?? false) !== true) {
            throw ValidationException::withMessages([
                'operation' => 'This Bazarr version does not support that subtitle operation.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $item
     */
    private function validateOpaqueSelection(
        string $operation,
        array $validated,
        array $item,
        BazarrClient $bazarrClient,
        string $mediaType,
        int $mediaId,
    ): void {
        if ($operation === 'download_exact') {
            $candidates = $mediaType === 'episode'
                ? $bazarrClient->searchEpisode($mediaId)
                : $bazarrClient->searchMovie($mediaId);

            if (collect($candidates)->doesntContain('fingerprint', $validated['candidate_fingerprint'])) {
                throw ValidationException::withMessages([
                    'candidate_fingerprint' => 'The selected subtitle candidate is no longer available.',
                ]);
            }
        }

        if (in_array($operation, ['delete_subtitle', 'sync_subtitle', 'translate_subtitle', 'modify_subtitle'], true)
            && collect($item['subtitle_tracks'] ?? [])->doesntContain('fingerprint', $validated['subtitle_fingerprint'])) {
            throw ValidationException::withMessages([
                'subtitle_fingerprint' => 'The selected subtitle track is no longer available.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function commonPayload(
        ServiceConnection $bazarr,
        ServiceConnection $managingConnection,
        array $item,
        string $operation,
    ): array {
        $mediaType = (string) $item['media_type'];
        $mediaId = (int) $item['media_id'];

        return [
            'title' => $this->title($operation, (string) $item['title']),
            'bazarr_connection_id' => $bazarr->id,
            'service_connection_id' => $managingConnection->id,
            'media_type' => $mediaType,
            'target_ids' => $mediaType === 'episode'
                ? ['series_id' => (int) $item['series_id'], 'episode_id' => $mediaId]
                : ['radarr_id' => $mediaId],
            'target_fingerprint' => (string) $item['target_fingerprint'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function operationPayload(string $operation, array $validated): array
    {
        return match ($operation) {
            'download_best' => [
                'language' => $validated['language'],
                'forced' => (bool) $validated['forced'],
                'hearing_impaired' => (bool) $validated['hearing_impaired'],
            ],
            'download_exact' => ['candidate_fingerprint' => $validated['candidate_fingerprint']],
            'delete_subtitle' => ['subtitle_fingerprint' => $validated['subtitle_fingerprint']],
            'sync_subtitle', 'translate_subtitle' => array_filter([
                'subtitle_fingerprint' => $validated['subtitle_fingerprint'],
                'options' => $validated['options'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'modify_subtitle' => array_filter([
                'subtitle_fingerprint' => $validated['subtitle_fingerprint'],
                'tool_action' => $validated['tool_action'],
                'options' => $validated['options'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            'scan_media' => ['media_action' => $validated['media_action']],
            default => throw ValidationException::withMessages(['operation' => 'Unsupported Bazarr operation.']),
        };
    }

    private function title(string $operation, string $mediaTitle): string
    {
        return match ($operation) {
            'download_best' => 'Download the best subtitle for '.$mediaTitle,
            'download_exact' => 'Download a selected subtitle for '.$mediaTitle,
            'delete_subtitle' => 'Delete a subtitle for '.$mediaTitle,
            'sync_subtitle' => 'Synchronize a subtitle for '.$mediaTitle,
            'translate_subtitle' => 'Translate a subtitle for '.$mediaTitle,
            'modify_subtitle' => 'Modify a subtitle for '.$mediaTitle,
            'scan_media' => 'Scan subtitles for '.$mediaTitle,
            default => 'Run a subtitle operation for '.$mediaTitle,
        };
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
