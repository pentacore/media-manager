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
     * Structural assessment of a stuck import — mapping completeness only. It
     * deliberately does NOT interpret rejection text: deciding what to do about
     * rejections ("not an upgrade" → remove, "matched by series id" → import, …)
     * is the agent's job, reasoning over describe()'s output. This only answers
     * "can these files even be imported, and are they all mapped?" so the import
     * path has a deterministic safety rail (never auto-import a partial set).
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{total: int, importable: int, fully_mapped: bool, reasons: array<int, string>}
     */
    public function assess(array $candidates, string $service, string $downloadId): array
    {
        $total = count($candidates);
        $importable = count($this->toImportPayload($candidates, $service, $downloadId));

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

        return [
            'total' => $total,
            'importable' => $importable,
            'fully_mapped' => $importable > 0 && $importable === $total,
            'reasons' => $reasons,
        ];
    }

    /**
     * Human/agent-readable per-file breakdown of a stuck import: whether each
     * candidate maps to a series/movie, what it is, and the raw upstream
     * rejection reasons verbatim. The DecisionAgent reads this to decide
     * import-vs-remove-vs-leave — so rejections are passed through untouched
     * rather than bucketed by us.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    public function describe(array $candidates, string $service): array
    {
        return array_values(array_map(
            function (array $candidate) use ($service): array {
                $rejections = array_values(array_map(
                    static fn (array $rejection): string => (string) ($rejection['reason'] ?? ''),
                    is_array($candidate['rejections'] ?? null) ? $candidate['rejections'] : [],
                ));

                $base = [
                    'path' => $candidate['path'] ?? null,
                    'quality' => $candidate['quality']['quality']['name'] ?? null,
                    'rejections' => array_values(array_filter($rejections, static fn (string $r): bool => $r !== '')),
                ];

                if ($service === 'sonarr') {
                    $episodes = is_array($candidate['episodes'] ?? null) ? $candidate['episodes'] : [];

                    return [
                        ...$base,
                        'mapped' => ($candidate['series']['id'] ?? null) !== null && $episodes !== [],
                        'series' => $candidate['series']['title'] ?? null,
                        'episodes' => array_values(array_map(
                            static fn (array $episode): string => sprintf(
                                'S%02dE%02d',
                                (int) ($episode['seasonNumber'] ?? 0),
                                (int) ($episode['episodeNumber'] ?? 0),
                            ),
                            $episodes,
                        )),
                    ];
                }

                return [
                    ...$base,
                    'mapped' => ($candidate['movie']['id'] ?? null) !== null,
                    'movie' => $candidate['movie']['title'] ?? null,
                ];
            },
            $candidates,
        ));
    }
}
