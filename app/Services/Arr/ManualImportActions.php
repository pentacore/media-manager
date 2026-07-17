<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Cache\Services\RadarrCache;
use App\Cache\Services\SonarrCache;
use App\Enums\ServiceType;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Actions\ActionExecutor;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use InvalidArgumentException;

/**
 * Executes a resolve_manual_import ActionRequest: re-enumerates the stuck
 * download's candidates server-side and triggers Sonarr/Radarr's ManualImport
 * command. Re-fetching the candidates (rather than trusting payload paths)
 * keeps the executor authoritative over what actually gets imported.
 */
class ManualImportActions implements ActionExecutor
{
    public function __construct(private readonly ManualImportResolver $manualImportResolver) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(ActionRequest $actionRequest): array
    {
        throw_if(
            $actionRequest->type !== 'resolve_manual_import',
            InvalidArgumentException::class,
            sprintf('ManualImportActions cannot execute type "%s"', $actionRequest->type),
        );

        $payload = $actionRequest->payload;
        $service = (string) ($payload['service'] ?? $actionRequest->target_service);
        $downloadId = (string) ($payload['download_id'] ?? '');

        throw_if($downloadId === '', InvalidArgumentException::class, 'download_id is required');

        $type = match ($service) {
            'sonarr' => ServiceType::Sonarr,
            'radarr' => ServiceType::Radarr,
            default => throw new InvalidArgumentException(sprintf('Unsupported manual-import service "%s"', $service)),
        };

        $serviceConnection = ServiceConnection::resolvePinned($payload, $type);
        $client = $type === ServiceType::Sonarr
            ? new SonarrClient($serviceConnection)
            : new RadarrClient($serviceConnection);

        $candidates = $client->getManualImport(['downloadId' => $downloadId]);
        $files = $this->manualImportResolver->toImportPayload($candidates, $service, $downloadId);

        throw_if($files === [], InvalidArgumentException::class, 'No importable files for this download.');

        $client->runCommand('ManualImport', [
            'files' => $files,
            'importMode' => 'auto',
        ]);

        $type === ServiceType::Sonarr
            ? new SonarrCache($serviceConnection)->bustAll()
            : new RadarrCache($serviceConnection)->bustAll();

        return [
            'service' => $service,
            'download_id' => $downloadId,
            'files_imported' => count($files),
        ];
    }
}
