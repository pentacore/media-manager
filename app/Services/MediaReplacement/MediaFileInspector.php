<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\MediaReplacementScope;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use InvalidArgumentException;

/**
 * Builds a compact, ID-and-metadata-only snapshot of an installed Sonarr episode
 * file set or Radarr movie file. Exposes no filesystem paths. Ambiguous targets
 * are returned as explicit ambiguity data instead of guessing.
 */
final readonly class MediaFileInspector
{
    private const array HISTORY_EVENT_TYPES = ['grabbed', 'downloadFolderImported'];

    public function __construct(
        private LanguageNormalizer $languageNormalizer,
        private SonarrMediaScopeResolver $sonarrMediaScopeResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inspect(
        string $service,
        int $itemId,
        ?int $seasonNumber = null,
        ?int $episodeNumber = null,
        ?int $absoluteEpisodeNumber = null,
        ?ServiceConnection $serviceConnection = null,
    ): array {
        return match (mb_strtolower(trim($service))) {
            'sonarr' => $this->inspectSonarr($itemId, $seasonNumber, $episodeNumber, $absoluteEpisodeNumber, $serviceConnection),
            'radarr' => $this->inspectRadarr($itemId, $serviceConnection),
            default => throw new InvalidArgumentException('service must be "sonarr" or "radarr".'),
        };
    }

    /**
     * Re-inspect the current installed file(s) from a previously stored target
     * snapshot, so an executor can confirm nothing changed after approval. The
     * connection is pinned to the one the request was approved against (stored
     * in the snapshot), because multiple same-type connections can be active and
     * their media IDs overlap across instances.
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function inspectFromSnapshot(array $target, ?ServiceConnection $serviceConnection = null): array
    {
        $service = mb_strtolower(trim((string) ($target['service'] ?? '')));
        $serviceConnection ??= $this->connectionFor($target);

        if ($service === 'radarr') {
            return $this->inspect('radarr', $this->integer($target['movie_id'] ?? null) ?? 0, serviceConnection: $serviceConnection);
        }

        $episodeNumbers = is_array($target['episode_numbers'] ?? null) ? array_values($target['episode_numbers']) : [];

        return $this->inspect(
            'sonarr',
            $this->integer($target['series_id'] ?? null) ?? 0,
            seasonNumber: $this->wholeNumber($target['season_number'] ?? null),
            episodeNumber: $episodeNumbers === [] ? null : $this->integer($episodeNumbers[0]),
            serviceConnection: $serviceConnection,
        );
    }

    /**
     * Resolve the pinned connection stored on a snapshot, falling back to the
     * active connection of the matching service when absent.
     *
     * @param  array<string, mixed>  $target
     */
    public function connectionFor(array $target): ServiceConnection
    {
        $id = $this->integer($target['service_connection_id'] ?? null);

        if ($id !== null) {
            $connection = ServiceConnection::find($id);

            if ($connection instanceof ServiceConnection) {
                return $connection;
            }
        }

        return ServiceConnection::resolveActive(
            mb_strtolower(trim((string) ($target['service'] ?? ''))) === 'radarr'
                ? ServiceType::Radarr
                : ServiceType::Sonarr,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectSonarr(
        int $seriesId,
        ?int $seasonNumber,
        ?int $episodeNumber,
        ?int $absoluteEpisodeNumber,
        ?ServiceConnection $connection = null,
    ): array {
        $serviceConnection = $connection ?? ServiceConnection::resolveActive(ServiceType::Sonarr);
        $sonarrClient = new SonarrClient($serviceConnection);
        $series = $sonarrClient->getSeriesById($seriesId);
        $scope = $this->sonarrMediaScopeResolver->resolve($serviceConnection, $series);

        if (! $scope instanceof MediaReplacementScope) {
            return [
                'ambiguous' => true,
                'reason' => 'unconfigured_root_scope',
                'service' => 'sonarr',
            ];
        }

        $episodes = array_values(array_filter(
            $sonarrClient->getEpisodesBySeries($seriesId),
            is_array(...),
        ));

        $matched = $this->matchEpisodes($episodes, $seasonNumber, $episodeNumber, $absoluteEpisodeNumber);

        if ($matched === []) {
            return ['ambiguous' => true, 'reason' => 'no_match', 'service' => 'sonarr'];
        }

        if (count($matched) > 1) {
            return [
                'ambiguous' => true,
                'reason' => 'multiple_episodes',
                'service' => 'sonarr',
                'choices' => array_map($this->episodeChoice(...), $matched),
            ];
        }

        $episode = $matched[0];
        $fileId = $this->integer($episode['episodeFileId'] ?? null);

        if ($fileId === null) {
            return ['ambiguous' => true, 'reason' => 'no_file', 'service' => 'sonarr'];
        }

        $sharing = array_values(array_filter(
            $episodes,
            fn (array $candidate): bool => $this->integer($candidate['episodeFileId'] ?? null) === $fileId,
        ));

        if (count($sharing) > 1) {
            return [
                'ambiguous' => true,
                'reason' => 'shared_multi_episode_file',
                'service' => 'sonarr',
                'episode_ids' => $this->sortedIds(array_map(static fn (array $episode): mixed => $episode['id'] ?? null, $sharing)),
                'affected_episodes' => array_map($this->episodeChoice(...), $sharing),
            ];
        }

        $episodeFile = $sonarrClient->getEpisodeFileById($fileId);
        $episodeId = $this->integer($episode['id'] ?? null);
        $sceneName = $this->sceneName($episodeFile);

        return [
            'ambiguous' => false,
            'service' => 'sonarr',
            'service_connection_id' => $serviceConnection->id,
            'scope' => $scope->value,
            'series_id' => $seriesId,
            'display_name' => $this->sonarrDisplayName($series, $episode),
            'season_number' => $this->wholeNumber($episode['seasonNumber'] ?? null),
            'episode_numbers' => array_values(array_filter(
                [$this->integer($episode['episodeNumber'] ?? null)],
                static fn (?int $number): bool => $number !== null,
            )),
            'episode_ids' => $episodeId === null ? [] : [$episodeId],
            'episode_file_ids' => [$fileId],
            'monitored' => ($episode['monitored'] ?? null) === true,
            'scene_name' => $sceneName,
            'installed_release' => $sceneName,
            'release_group' => $this->nonEmptyString($episodeFile['releaseGroup'] ?? null),
            'quality' => $this->quality($episodeFile['quality'] ?? null),
            'size' => $this->integer($episodeFile['size'] ?? null),
            'date_added' => $this->nonEmptyString($episodeFile['dateAdded'] ?? null),
            'subtitles' => $this->normalizeSubtitles($episodeFile['mediaInfo']['subtitles'] ?? null),
            'original_history_id' => $this->originalHistoryId(
                $sonarrClient->getHistory(['episodeId' => $episodeId, 'pageSize' => 100]),
                'episodeId',
                $episodeId,
                $fileId,
                'episodeFileId',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectRadarr(int $movieId, ?ServiceConnection $connection = null): array
    {
        $serviceConnection = $connection ?? ServiceConnection::resolveActive(ServiceType::Radarr);
        $radarrClient = new RadarrClient($serviceConnection);
        $movie = $radarrClient->getMovieById($movieId);
        $fileId = $this->integer($movie['movieFileId'] ?? null);

        if ($fileId === null) {
            return ['ambiguous' => true, 'reason' => 'no_file', 'service' => 'radarr'];
        }

        $movieFile = $radarrClient->getMovieFileById($fileId);
        $sceneName = $this->sceneName($movieFile);

        return [
            'ambiguous' => false,
            'service' => 'radarr',
            'service_connection_id' => $serviceConnection->id,
            'scope' => MediaReplacementScope::Movie->value,
            'movie_id' => $movieId,
            'display_name' => $this->nonEmptyString($movie['title'] ?? null) ?? sprintf('Movie %d', $movieId),
            'movie_file_ids' => [$fileId],
            'monitored' => ($movie['monitored'] ?? null) === true,
            'scene_name' => $sceneName,
            'installed_release' => $sceneName,
            'release_group' => $this->nonEmptyString($movieFile['releaseGroup'] ?? null),
            'quality' => $this->quality($movieFile['quality'] ?? null),
            'size' => $this->integer($movieFile['size'] ?? null),
            'date_added' => $this->nonEmptyString($movieFile['dateAdded'] ?? null),
            'subtitles' => $this->normalizeSubtitles($movieFile['mediaInfo']['subtitles'] ?? null),
            'original_history_id' => $this->originalHistoryId(
                $radarrClient->getHistory(['movieId' => $movieId, 'pageSize' => 100]),
                'movieId',
                $movieId,
                $fileId,
                'movieFileId',
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $episodes
     * @return array<int, array<string, mixed>>
     */
    private function matchEpisodes(
        array $episodes,
        ?int $seasonNumber,
        ?int $episodeNumber,
        ?int $absoluteEpisodeNumber,
    ): array {
        if ($absoluteEpisodeNumber !== null) {
            return array_values(array_filter(
                $episodes,
                fn (array $episode): bool => $this->integer($episode['absoluteEpisodeNumber'] ?? null) === $absoluteEpisodeNumber,
            ));
        }

        if ($seasonNumber === null) {
            return [];
        }

        return array_values(array_filter(
            $episodes,
            fn (array $episode): bool => $this->wholeNumber($episode['seasonNumber'] ?? null) === $seasonNumber
                && ($episodeNumber === null || $this->integer($episode['episodeNumber'] ?? null) === $episodeNumber),
        ));
    }

    /**
     * @param  array<string, mixed>  $episode
     * @return array<string, mixed>
     */
    private function episodeChoice(array $episode): array
    {
        return [
            'episode_id' => $this->integer($episode['id'] ?? null),
            'season_number' => $this->wholeNumber($episode['seasonNumber'] ?? null),
            'episode_number' => $this->integer($episode['episodeNumber'] ?? null),
            'absolute_episode_number' => $this->integer($episode['absoluteEpisodeNumber'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $episode
     */
    private function sonarrDisplayName(array $series, array $episode): string
    {
        $title = $this->nonEmptyString($series['title'] ?? null) ?? 'Series';
        $season = $this->wholeNumber($episode['seasonNumber'] ?? null);
        $number = $this->integer($episode['episodeNumber'] ?? null);

        if ($season === null || $number === null) {
            return $title;
        }

        return sprintf('%s S%02dE%02d', $title, $season, $number);
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function sceneName(array $file): ?string
    {
        return $this->nonEmptyString($file['sceneName'] ?? null)
            ?? $this->nonEmptyString($file['relativePath'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function normalizeSubtitles(mixed $subtitles): array
    {
        if (is_string($subtitles)) {
            $subtitles = preg_split('#[/,]#', $subtitles) ?: [];
        }

        if (! is_array($subtitles)) {
            return [];
        }

        $strings = array_filter($subtitles, is_string(...));

        return $this->languageNormalizer->normalizeMany(array_values($strings));
    }

    /**
     * Identify the `grabbed` history record for the release that produced the
     * currently installed file, so the executor blocklists the right release.
     *
     * `markHistoryFailed` is destructive (it triggers redownload behaviour), so
     * this must not guess: it returns a grab id only when it is (a) positively
     * correlated — the import of the installed file (matched by file id) ties to
     * a download id that has a matching grab — or (b) truly unique (exactly one
     * grabbed record for the target). Otherwise it returns null and blocklisting
     * is skipped, rather than risk failing an unrelated (e.g. later manual) grab.
     *
     * @param  array<string, mixed>  $history
     */
    private function originalHistoryId(array $history, string $idField, ?int $targetId, ?int $installedFileId, string $fileIdField): ?int
    {
        if ($targetId === null) {
            return null;
        }

        $records = $history['records'] ?? $history;

        if (! is_array($records)) {
            return null;
        }

        $matching = array_values(array_filter(
            $records,
            fn (mixed $record): bool => is_array($record)
                && in_array($record['eventType'] ?? null, self::HISTORY_EVENT_TYPES, true)
                && $this->integer($record[$idField] ?? null) === $targetId,
        ));

        // (a) Positive correlation: the installed file's import → download id → grab.
        $downloadId = $this->correlatedDownloadId($matching, $installedFileId, $fileIdField);

        if ($downloadId !== null) {
            foreach ($matching as $record) {
                if (($record['eventType'] ?? null) === 'grabbed' && $this->recordDownloadId($record) === $downloadId) {
                    $id = $this->integer($record['id'] ?? null);

                    if ($id !== null) {
                        return $id;
                    }
                }
            }
        }

        // (b) Unique fallback: exactly one grabbed record for the target.
        $grabIds = [];

        foreach ($matching as $record) {
            if (($record['eventType'] ?? null) === 'grabbed') {
                $id = $this->integer($record['id'] ?? null);

                if ($id !== null) {
                    $grabIds[$id] = $id;
                }
            }
        }

        return count($grabIds) === 1 ? array_first($grabIds) : null;
    }

    /**
     * Download id of the import that produced the installed file, matched
     * strictly by file id. Returns null when no import positively identifies the
     * installed file — never guesses from an uncorrelated import.
     *
     * @param  array<int, array<string, mixed>>  $records
     */
    private function correlatedDownloadId(array $records, ?int $installedFileId, string $fileIdField): ?string
    {
        if ($installedFileId === null) {
            return null;
        }

        foreach ($records as $record) {
            if (($record['eventType'] ?? null) !== 'downloadFolderImported') {
                continue;
            }

            $recordFileId = $this->integer($record[$fileIdField] ?? ($record['data'][$fileIdField] ?? null));
            $downloadId = $this->recordDownloadId($record);

            if ($recordFileId === $installedFileId && $downloadId !== null) {
                return $downloadId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordDownloadId(array $record): ?string
    {
        $downloadId = $record['downloadId'] ?? ($record['data']['downloadId'] ?? null);

        return is_string($downloadId) && trim($downloadId) !== '' ? $downloadId : null;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function sortedIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            $integer = $this->integer($id);

            if ($integer !== null) {
                $normalized[$integer] = $integer;
            }
        }

        sort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }

    private function quality(mixed $quality): ?string
    {
        if (is_array($quality)) {
            $quality = is_array($quality['quality'] ?? null)
                ? ($quality['quality']['name'] ?? null)
                : ($quality['name'] ?? null);
        }

        return $this->nonEmptyString($quality);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1) {
            $integer = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return is_int($integer) ? $integer : null;
        }

        return null;
    }

    /**
     * Coerce a season number, which is a valid whole number >= 0 (Sonarr uses
     * season 0 for Specials/OVAs). Distinct from integer(), which is for
     * positive identifiers.
     */
    private function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1) {
            $integer = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            return is_int($integer) ? $integer : null;
        }

        return null;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        return mb_check_encoding($value, 'UTF-8') && trim($value) !== '' ? $value : null;
    }
}
