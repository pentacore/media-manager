<?php

declare(strict_types=1);

namespace App\Services\Arr;

use App\Models\ServiceConnection;

/**
 * Deterministic look at a stuck Sonarr/Radarr import: each candidate file's
 * mapping and the raw upstream rejection reasons. Shared by the
 * InspectStuckImportTool and the classifier fast path.
 */
final readonly class StuckImportInspector
{
    public function __construct(
        private ArrConnections $arrConnections,
        private ManualImportResolver $manualImportResolver,
    ) {}

    /**
     * @return array{service: string, download_id: string, total: int, importable: int, fully_mapped: bool, files: array<int, array<string, mixed>>}
     */
    public function inspect(ServiceConnection $serviceConnection, string $service, string $downloadId): array
    {
        $candidates = $this->arrConnections->client($serviceConnection)->getManualImport(['downloadId' => $downloadId]);
        $assessment = $this->manualImportResolver->assess($candidates, $service, $downloadId);

        return [
            'service' => $service,
            'download_id' => $downloadId,
            'total' => $assessment['total'],
            'importable' => $assessment['importable'],
            'fully_mapped' => $assessment['fully_mapped'],
            'files' => $this->manualImportResolver->describe($candidates, $service),
        ];
    }
}
