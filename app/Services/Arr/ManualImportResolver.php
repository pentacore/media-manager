<?php

declare(strict_types=1);

namespace App\Services\Arr;

use Illuminate\Support\Str;

/**
 * Shared logic for turning Sonarr/Radarr manual-import candidates into the
 * payload the ManualImport command expects, plus an ambiguity assessment
 * used by the DecisionAgent to decide whether an auto-import is safe.
 *
 * Single source of truth for the candidate→file mapping, consumed by the
 * library UI (ActivityController), the action executor (ManualImportActions),
 * and the agent tool (ResolveManualImportTool).
 *
 * Samples are never imported, and callers may exclude further paths; both only
 * remove candidates from what was fetched — the upstream re-fetch stays
 * authoritative for which files exist.
 */
class ManualImportResolver
{
    /**
     * A file Sonarr/Radarr flagged as a sample, or one named or filed as a sample.
     * "Unable to determine if file is a sample" is not a sample.
     *
     * @param  array<string, mixed>  $candidate
     */
    public function isSample(array $candidate): bool
    {
        foreach (is_array($candidate['rejections'] ?? null) ? $candidate['rejections'] : [] as $rejection) {
            if (is_array($rejection) && Str::lower(Str::trim((string) ($rejection['reason'] ?? ''))) === 'sample') {
                return true;
            }
        }

        $path = str_replace('\\', '/', (string) ($candidate['path'] ?? ''));
        $basename = pathinfo($path, PATHINFO_FILENAME);
        $folder = Str::lower(basename(dirname($path)));

        return preg_match('/(^|[ ._-])sample([ ._-]|$)/i', $basename) === 1
            || in_array($folder, ['sample', 'samples'], true);
    }

    /**
     * Whether a candidate carries what the import command needs: a path, a
     * quality and the foreign key (Sonarr → seriesId+episodeIds, Radarr → movieId).
     *
     * @param  array<string, mixed>  $candidate
     */
    public function maps(array $candidate, string $service): bool
    {
        if (($candidate['path'] ?? null) === null || ($candidate['quality'] ?? null) === null) {
            return false;
        }

        if ($service === 'sonarr') {
            return ($candidate['series']['id'] ?? null) !== null
                && $this->episodeIds($candidate) !== [];
        }

        return ($candidate['movie']['id'] ?? null) !== null;
    }

    /**
     * Convert raw candidates into the file shape Sonarr/Radarr expect on the
     * ManualImport command. Skips samples and excluded paths (exclusions only
     * remove; the upstream re-fetch stays authoritative for what exists) and
     * drops candidates that lack the required foreign key.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  list<string>  $excludedPaths
     * @return array<int, array<string, mixed>>
     */
    public function toImportPayload(array $candidates, string $service, string $downloadId, array $excludedPaths = []): array
    {
        $files = [];

        foreach ($this->kept($candidates, $excludedPaths)[0] as $candidate) {
            if (! $this->maps($candidate, $service)) {
                continue;
            }

            $base = [
                'path' => $candidate['path'],
                'folderName' => $candidate['folderName'] ?? null,
                'quality' => $candidate['quality'],
                'languages' => $candidate['languages'] ?? [],
                'releaseGroup' => $candidate['releaseGroup'] ?? null,
                'indexerFlags' => $candidate['indexerFlags'] ?? 0,
                'downloadId' => $downloadId,
            ];

            if ($service === 'sonarr') {
                $files[] = [
                    ...$base,
                    'seriesId' => $candidate['series']['id'],
                    'episodeIds' => $this->episodeIds($candidate),
                    'releaseType' => $candidate['releaseType'] ?? null,
                ];

                continue;
            }

            $files[] = [
                ...$base,
                'movieId' => $candidate['movie']['id'],
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
     * Samples and excluded paths are skipped and left out of `total`.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  list<string>  $excludedPaths
     * @return array{total: int, importable: int, fully_mapped: bool, reasons: list<string>, skipped: list<array{path: string, why: string}>}
     */
    public function assess(array $candidates, string $service, string $downloadId, array $excludedPaths = []): array
    {
        [$kept, $skipped] = $this->kept($candidates, $excludedPaths);

        $total = count($kept);
        $importable = count($this->toImportPayload($kept, $service, $downloadId));

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
            'skipped' => $skipped,
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
                    'sample' => $this->isSample($candidate),
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

    /**
     * Split candidates into those to consider and those skipped (sample first,
     * then explicitly excluded paths).
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  list<string>  $excludedPaths
     * @return array{0: list<array<string, mixed>>, 1: list<array{path: string, why: string}>}
     */
    private function kept(array $candidates, array $excludedPaths): array
    {
        $kept = [];
        $skipped = [];

        foreach ($candidates as $candidate) {
            $path = (string) ($candidate['path'] ?? '');

            if ($this->isSample($candidate)) {
                $skipped[] = ['path' => $path, 'why' => 'sample'];
            } elseif (in_array($path, $excludedPaths, true)) {
                $skipped[] = ['path' => $path, 'why' => 'excluded'];
            } else {
                $kept[] = $candidate;
            }
        }

        return [$kept, $skipped];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<int>
     */
    private function episodeIds(array $candidate): array
    {
        return array_values(array_filter(array_map(
            static fn (array $episode): ?int => isset($episode['id']) ? (int) $episode['id'] : null,
            is_array($candidate['episodes'] ?? null) ? $candidate['episodes'] : [],
        )));
    }
}
