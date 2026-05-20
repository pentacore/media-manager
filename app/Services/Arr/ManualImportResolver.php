<?php

declare(strict_types=1);

namespace App\Services\Arr;

/**
 * Shared logic for turning Sonarr/Radarr manual-import candidates into the
 * payload the ManualImport command expects, plus an ambiguity assessment
 * used by the DecisionAgent to decide whether an auto-import is safe.
 *
 * Single source of truth for the candidate→file mapping, consumed by the
 * library UI (ActivityController), the action executor (ManualImportActions),
 * and the agent tool (ResolveManualImportTool).
 */
class ManualImportResolver
{
    /**
     * Convert raw candidates into the file shape Sonarr/Radarr expect on the
     * ManualImport command. Drops candidates that lack the required foreign
     * key (Sonarr → seriesId+episodeIds, Radarr → movieId).
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    public function toImportPayload(array $candidates, string $service, string $downloadId): array
    {
        $files = [];

        foreach ($candidates as $candidate) {
            $base = [
                'path' => $candidate['path'] ?? null,
                'folderName' => $candidate['folderName'] ?? null,
                'quality' => $candidate['quality'] ?? null,
                'languages' => $candidate['languages'] ?? [],
                'releaseGroup' => $candidate['releaseGroup'] ?? null,
                'indexerFlags' => $candidate['indexerFlags'] ?? 0,
                'downloadId' => $downloadId,
            ];
            if ($base['path'] === null) {
                continue;
            }

            if ($base['quality'] === null) {
                continue;
            }

            if ($service === 'sonarr') {
                $seriesId = $candidate['series']['id'] ?? null;
                $episodeIds = array_values(array_filter(array_map(
                    static fn (array $episode): ?int => isset($episode['id']) ? (int) $episode['id'] : null,
                    is_array($candidate['episodes'] ?? null) ? $candidate['episodes'] : [],
                )));
                if ($seriesId === null) {
                    continue;
                }

                if ($episodeIds === []) {
                    continue;
                }

                $files[] = [
                    ...$base,
                    'seriesId' => $seriesId,
                    'episodeIds' => $episodeIds,
                    'releaseType' => $candidate['releaseType'] ?? null,
                ];

                continue;
            }

            $movieId = $candidate['movie']['id'] ?? null;
            if ($movieId === null) {
                continue;
            }

            $files[] = [
                ...$base,
                'movieId' => $movieId,
            ];
        }

        return $files;
    }

    /**
     * Decide whether a stuck import is safe to auto-resolve. It is considered
     * AMBIGUOUS (a human should confirm) when any candidate carries upstream
     * rejections, when some candidates can't be mapped to importable files, or
     * when nothing is importable at all. A clean, fully-mapped, rejection-free
     * set is treated as unambiguous.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{total: int, importable: int, rejected: int, ambiguous: bool, reasons: array<int, string>}
     */
    public function assess(array $candidates, string $service, string $downloadId): array
    {
        $total = count($candidates);
        $importable = count($this->toImportPayload($candidates, $service, $downloadId));

        $rejected = 0;
        foreach ($candidates as $candidate) {
            $rejections = is_array($candidate['rejections'] ?? null) ? $candidate['rejections'] : [];
            if ($rejections !== []) {
                $rejected++;
            }
        }

        $reasons = [];
        if ($total === 0) {
            $reasons[] = 'Sonarr/Radarr returned no candidate files for this download.';
        }
        if ($importable === 0 && $total > 0) {
            $reasons[] = 'No candidate could be mapped to a series/movie automatically.';
        }
        if ($importable > 0 && $importable < $total) {
            $reasons[] = sprintf('Only %d of %d files could be mapped automatically.', $importable, $total);
        }
        if ($rejected > 0) {
            $reasons[] = sprintf('%d file(s) carry upstream rejections.', $rejected);
        }

        $ambiguous = $importable === 0 || $importable !== $total || $rejected > 0;

        return [
            'total' => $total,
            'importable' => $importable,
            'rejected' => $rejected,
            'ambiguous' => $ambiguous,
            'reasons' => $reasons,
        ];
    }
}
