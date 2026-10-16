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

        return $this->shape($candidates, $service, $downloadId);
    }

    /**
     * Inspect several downloads with one pooled read. One entry per unique id,
     * in input order: the inspect() shape plus `ok => true`, or a
     * `lookup_failed` marker for an id the upstream could not answer.
     *
     * @param  list<string>  $downloadIds
     * @return list<array<string, mixed>>
     */
    public function inspectMany(ServiceConnection $serviceConnection, string $service, array $downloadIds): array
    {
        $candidatesById = $this->arrConnections->client($serviceConnection)->getManualImports($downloadIds);
        $entries = [];

        foreach (array_keys($candidatesById) as $downloadId) {
            $downloadId = (string) $downloadId;
            $candidates = $candidatesById[$downloadId];

            $entries[] = $candidates === null
                ? ['download_id' => $downloadId, 'ok' => false, 'reason' => 'lookup_failed']
                : ['ok' => true, ...$this->shape($candidates, $service, $downloadId)];
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{service: string, download_id: string, total: int, importable: int, fully_mapped: bool, files: array<int, array<string, mixed>>}
     */
    private function shape(array $candidates, string $service, string $downloadId): array
    {
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
