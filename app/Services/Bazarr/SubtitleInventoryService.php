<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Radarr\RadarrClient;
use App\Services\ServiceClientFactory;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

final class SubtitleInventoryService
{
    /**
     * Mapped-library discovery feeds already scanned by this instance, keyed by
     * Bazarr connection id. One reconciliation cycle resolves the service once and
     * then walks its pages, so this bounds the scan to once per cycle. Deliberately
     * not a shared cache: a cycle must not inherit another cycle's snapshot.
     *
     * @var array<int, array{0: list<array<string, mixed>>, 1: list<string>}>
     */
    private array $discoveryFeeds = [];

    public function __construct(
        private readonly ServiceClientFactory $serviceClientFactory,
        private readonly SubtitleInventoryConnections $subtitleInventoryConnections,
        private readonly SubtitleItemMapper $subtitleItemMapper,
        private readonly SubtitleLibraryReader $subtitleLibraryReader,
        private readonly SubtitleInspector $subtitleInspector,
        private readonly SubtitleCaseFingerprint $subtitleCaseFingerprint,
    ) {}

    /**
     * Return backend-only material case identities from bounded mapped-library pages.
     *
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int, partial: bool, errors: list<string>}
     */
    public function caseCandidates(
        ServiceConnection $serviceConnection,
        int $page = 1,
        int $perPage = SubtitleLibraryReader::MAX_PER_PAGE,
    ): array {
        $this->subtitleLibraryReader->validatePagination($page, $perPage);

        $bazarrClient = $this->subtitleInventoryConnections->bazarrClient($serviceConnection);
        $sonarr = $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Sonarr);
        $radarr = $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Radarr);

        // Discovery walks the whole mapped library, and a reconciliation cycle asks
        // for successive pages through this same instance. Rebuilding the catalog
        // per page meant max_cases_per_cycle bounded only the dispatched jobs, not
        // the job's own API work, so a large library could time out every cycle
        // without the cursor ever advancing. One scan per instance, sliced locally.
        [$combined, $errors] = $this->discoveryFeed($serviceConnection, $bazarrClient, $sonarr, $radarr);
        $window = array_slice($combined, ($page - 1) * $perPage, $perPage);
        $sonarrClient = $sonarr instanceof ServiceConnection ? $this->serviceClientFactory->make($sonarr) : null;
        $radarrClient = $radarr instanceof ServiceConnection ? $this->serviceClientFactory->make($radarr) : null;
        $candidates = [];

        foreach ($window as $item) {
            $identity = match ($item['media_type'] ?? null) {
                'episode' => $sonarrClient instanceof SonarrClient && $sonarr instanceof ServiceConnection
                    ? $this->episodeCaseIdentity($item, $sonarr, $sonarrClient)
                    : null,
                'movie' => $radarrClient instanceof RadarrClient && $radarr instanceof ServiceConnection
                    ? $this->movieCaseIdentity($item, $radarr, $radarrClient)
                    : null,
                default => null,
            };

            if ($identity === null) {
                continue;
            }

            $identity['bazarr_connection_id'] = $serviceConnection->id;
            $candidates[$identity['file_fingerprint']] ??= $identity;
        }

        return [
            'data' => array_values($candidates),
            'page' => $page,
            'per_page' => $perPage,
            'total' => count($combined),
            'partial' => $errors !== [],
            'errors' => $errors,
        ];
    }

    /**
     * The mapped-library discovery feed for one connection, scanned at most once
     * per service instance — that is, once per reconciliation cycle.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function discoveryFeed(
        ServiceConnection $serviceConnection,
        BazarrClient $bazarrClient,
        ?ServiceConnection $sonarr,
        ?ServiceConnection $radarr,
    ): array {
        if (isset($this->discoveryFeeds[$serviceConnection->id])) {
            return $this->discoveryFeeds[$serviceConnection->id];
        }

        $errors = [];
        $episodeItems = [];
        $movieItems = [];

        if ($sonarr instanceof ServiceConnection) {
            try {
                $episodeItems = array_values(array_filter(
                    $this->subtitleLibraryReader->episodeLibrary($bazarrClient, $sonarr),
                    static fn (array $item): bool => $item['missing_languages'] !== [],
                ));
                usort($episodeItems, fn (array $left, array $right): int => ($left['media_id'] <=> $right['media_id']));
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Sonarr episode inventory is temporarily unavailable.';
            }
        }

        if ($radarr instanceof ServiceConnection) {
            try {
                $movieItems = $this->missingMovieItems($bazarrClient);
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Radarr movie inventory is temporarily unavailable.';
            }
        }

        // A partial scan is not memoized: the next page should retry the source
        // that failed rather than inherit a truncated feed for the whole cycle.
        if ($errors === []) {
            $this->discoveryFeeds[$serviceConnection->id] = [[...$episodeItems, ...$movieItems], $errors];
        }

        return [[...$episodeItems, ...$movieItems], $errors];
    }

    /**
     * Project a single subtitle case's current live target through the same
     * case-identity plumbing as the bulk sweep, so targeted reconciliation can
     * determine whether the required tracks have since appeared. Returns a
     * reconciler-ready candidate, or null when the target can no longer be read.
     *
     * @return array<string, mixed>|null
     */
    public function caseCandidateFor(SubtitleCase $subtitleCase): ?array
    {
        $bazarr = ServiceConnection::query()->find($subtitleCase->bazarr_connection_id);

        if (! $bazarr instanceof ServiceConnection) {
            return null;
        }

        $mediaId = $this->subtitleItemMapper->positiveInteger($subtitleCase->media_type === 'episode'
            ? ($subtitleCase->target_ids['episode_id'] ?? null)
            : ($subtitleCase->target_ids['radarr_id'] ?? null));

        if ($mediaId === null) {
            return null;
        }

        return $this->caseCandidateForMedia($bazarr, $subtitleCase->media_type, $mediaId);
    }

    /**
     * The same projection keyed by the Bazarr media ID rather than an existing case,
     * so a case created outside reconciliation (a manual upload) carries the
     * identity the executor's live revalidation recomputes.
     *
     * @return array<string, mixed>|null
     */
    public function caseCandidateForMedia(
        ServiceConnection $serviceConnection,
        string $mediaType,
        int $mediaId,
    ): ?array {
        if ($serviceConnection->type !== ServiceType::Bazarr
            || ! $serviceConnection->is_active
            || ! in_array($mediaType, ['episode', 'movie'], true)
            || $mediaId < 1) {
            return null;
        }

        $bazarrClient = $this->subtitleInventoryConnections->bazarrClient($serviceConnection);

        $identity = $mediaType === 'episode'
            ? $this->episodeCandidateFor($mediaId, $serviceConnection, $bazarrClient)
            : $this->movieCandidateFor($mediaId, $serviceConnection, $bazarrClient);

        if ($identity === null) {
            return null;
        }

        $identity['bazarr_connection_id'] = $serviceConnection->id;

        return $identity;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function episodeCandidateFor(
        int $episodeId,
        ServiceConnection $bazarr,
        BazarrClient $bazarrClient,
    ): ?array {
        $sonarr = $this->subtitleInventoryConnections->activeMapped($bazarr, BazarrServiceRole::Sonarr);

        if (! $sonarr instanceof ServiceConnection) {
            return null;
        }

        $episode = collect($bazarrClient->getEpisodes(episodeIds: [$episodeId])['data'])
            ->first(fn (array $candidate): bool => $this->subtitleItemMapper->positiveInteger($candidate['sonarrEpisodeId'] ?? null) === $episodeId);

        if (! is_array($episode)) {
            return null;
        }

        $seriesId = $this->subtitleItemMapper->positiveInteger($episode['sonarrSeriesId'] ?? null);

        if ($seriesId === null) {
            return null;
        }

        $sonarrClient = $this->serviceClientFactory->make($sonarr);

        if (! $sonarrClient instanceof SonarrClient) {
            return null;
        }

        $item = $this->subtitleItemMapper->episodeItem($episode, $sonarrClient->getSeriesById($seriesId), $sonarr);

        if ($item === null) {
            return null;
        }

        return $this->episodeCaseIdentity($item, $sonarr, $sonarrClient);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function movieCandidateFor(
        int $radarrId,
        ServiceConnection $bazarr,
        BazarrClient $bazarrClient,
    ): ?array {
        $radarr = $this->subtitleInventoryConnections->activeMapped($bazarr, BazarrServiceRole::Radarr);

        if (! $radarr instanceof ServiceConnection) {
            return null;
        }

        $movie = $bazarrClient->findMovieByRadarrId($radarrId);

        if (! is_array($movie)) {
            return null;
        }

        $item = $this->subtitleItemMapper->movieItem($movie);

        if ($item === null) {
            return null;
        }

        $radarrClient = $this->serviceClientFactory->make($radarr);

        if (! $radarrClient instanceof RadarrClient) {
            return null;
        }

        return $this->movieCaseIdentity($item, $radarr, $radarrClient);
    }

    /**
     * Enumerate the complete mapped movie library in bounded upstream pages and
     * keep only titles missing a MediaManager-required language. Windowing over
     * the combined episode/movie stream happens locally so upstream offset
     * drift between cycles can never permanently skip a title.
     *
     * @return list<array<string, mixed>>
     */
    private function missingMovieItems(BazarrClient $bazarrClient): array
    {
        $movieItems = [];
        $start = 0;

        do {
            $moviePage = $bazarrClient->getMovies(start: $start, length: SubtitleLibraryReader::MAX_PER_PAGE);
            $batch = $moviePage['data'];
            $movieItems = [...$movieItems, ...array_values(array_filter(array_map(
                $this->subtitleItemMapper->movieItem(...),
                $batch,
            ), static fn (?array $item): bool => is_array($item) && $item['missing_languages'] !== []))];
            $start += count($batch);
        } while ($batch !== [] && $start < $moviePage['total']);

        usort($movieItems, fn (array $left, array $right): int => ($left['media_id'] <=> $right['media_id']));

        return $movieItems;
    }

    /**
     * @return array{
     *     missing: array{episodes: int, movies: int, total: int},
     *     health_issue_count: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function overview(ServiceConnection $serviceConnection): array
    {
        return $this->subtitleLibraryReader->overview($serviceConnection);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function library(
        ServiceConnection $serviceConnection,
        int $page,
        int $perPage,
        array $filters = [],
    ): array {
        return $this->subtitleLibraryReader->library($serviceConnection, $page, $perPage, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function missing(
        ServiceConnection $serviceConnection,
        int $page,
        int $perPage,
        array $filters = [],
    ): array {
        return $this->subtitleLibraryReader->missing($serviceConnection, $page, $perPage, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     data: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function history(
        ServiceConnection $serviceConnection,
        int $page,
        int $perPage,
        array $filters = [],
    ): array {
        return $this->subtitleLibraryReader->history($serviceConnection, $page, $perPage, $filters);
    }

    /**
     * @return array{
     *     item: array<string, mixed>,
     *     history: list<array<string, mixed>>,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    public function inspect(
        ServiceConnection $serviceConnection,
        string $mediaType,
        int $mediaId,
    ): array {
        return $this->subtitleInspector->inspect($serviceConnection, $mediaType, $mediaId);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function episodeCaseIdentity(
        array $item,
        ServiceConnection $serviceConnection,
        SonarrClient $sonarrClient,
    ): ?array {
        $seriesId = $this->subtitleItemMapper->positiveInteger($item['series_id'] ?? null);
        $episodeId = $this->subtitleItemMapper->positiveInteger($item['media_id'] ?? null);

        if ($seriesId === null || $episodeId === null) {
            return null;
        }

        $episodes = array_values(array_filter($sonarrClient->getEpisodesBySeries($seriesId), is_array(...)));
        $episode = collect($episodes)->first(fn (array $candidate): bool => $this->subtitleItemMapper->positiveInteger($candidate['id'] ?? null) === $episodeId);
        $fileId = is_array($episode) ? $this->subtitleItemMapper->positiveInteger($episode['episodeFileId'] ?? null) : null;

        if ($fileId === null) {
            return null;
        }

        $sharingEpisodeIds = array_values(array_filter(array_map(
            fn (array $candidate): ?int => $this->subtitleItemMapper->positiveInteger($candidate['episodeFileId'] ?? null) === $fileId
                ? $this->subtitleItemMapper->positiveInteger($candidate['id'] ?? null)
                : null,
            $episodes,
        )));
        $file = $sonarrClient->getEpisodeFileById($fileId);

        return $this->caseIdentity(
            item: $item,
            service: 'sonarr',
            serviceConnection: $serviceConnection,
            fileIds: [$fileId],
            mediaIds: $sharingEpisodeIds,
            targetIds: [
                'series_id' => $seriesId,
                'episode_id' => $episodeId,
                'episode_ids' => $sharingEpisodeIds,
                'episode_file_id' => $fileId,
            ],
            file: $file,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function movieCaseIdentity(
        array $item,
        ServiceConnection $serviceConnection,
        RadarrClient $radarrClient,
    ): ?array {
        $movieId = $this->subtitleItemMapper->positiveInteger($item['media_id'] ?? null);

        if ($movieId === null) {
            return null;
        }

        $movie = $radarrClient->getMovieById($movieId);
        $fileId = $this->subtitleItemMapper->positiveInteger($movie['movieFileId'] ?? null);

        if ($fileId === null) {
            return null;
        }

        return $this->caseIdentity(
            item: $item,
            service: 'radarr',
            serviceConnection: $serviceConnection,
            fileIds: [$fileId],
            mediaIds: [$movieId],
            targetIds: [
                'radarr_id' => $movieId,
                'movie_file_id' => $fileId,
            ],
            file: $radarrClient->getMovieFileById($fileId),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<int>  $fileIds
     * @param  list<int>  $mediaIds
     * @param  array<string, int|list<int>>  $targetIds
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function caseIdentity(
        array $item,
        string $service,
        ServiceConnection $serviceConnection,
        array $fileIds,
        array $mediaIds,
        array $targetIds,
        array $file,
    ): array {
        $scope = (string) ($item['scope'] ?? '');
        $requiredLanguages = is_array($item['required_languages'] ?? null) ? $item['required_languages'] : [];

        return [
            'bazarr_connection_id' => null,
            'service' => $service,
            'service_connection_id' => $serviceConnection->id,
            'scope' => $scope,
            'media_type' => $item['media_type'],
            'target_ids' => $targetIds,
            'display_name' => $item['title'],
            'required_languages' => $requiredLanguages,
            'missing_languages' => is_array($item['missing_languages'] ?? null) ? $item['missing_languages'] : [],
            'current_subtitles' => $this->subtitleItemMapper->currentSubtitleLanguages($item['subtitle_tracks'] ?? []),
            'monitored' => ($item['monitored'] ?? false) === true,
            'file_fingerprint' => $this->subtitleCaseFingerprint->file([
                'service' => $service,
                'service_connection_id' => $serviceConnection->id,
                'file_ids' => $fileIds,
                'media_ids' => $mediaIds,
                'size' => $file['size'] ?? null,
                'date_added' => $file['dateAdded'] ?? null,
                'scene_name' => $file['sceneName'] ?? null,
            ]),
            'requirements_fingerprint' => $this->subtitleCaseFingerprint->requirements($scope, $requiredLanguages),
        ];
    }
}
