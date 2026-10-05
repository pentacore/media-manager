<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Http\Resources\Bazarr\SubtitleHistoryResource;
use App\Http\Resources\Bazarr\SubtitleItemResource;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Radarr\RadarrClient;
use App\Services\ServiceClientFactory;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

final class SubtitleInventoryService
{
    private const int EPISODE_SERIES_BATCH_SIZE = 50;

    private const int MAX_PER_PAGE = 100;

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
        int $perPage = self::MAX_PER_PAGE,
    ): array {
        $this->validatePagination($page, $perPage);

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
                    $this->episodeLibrary($bazarrClient, $sonarr),
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
            $moviePage = $bazarrClient->getMovies(start: $start, length: self::MAX_PER_PAGE);
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
        $bazarrClient = $this->subtitleInventoryConnections->bazarrClient($serviceConnection);
        $episodeTotal = 0;
        $movieTotal = 0;
        $healthIssueCount = 0;
        $errors = [];

        try {
            $episodeTotal = $bazarrClient->getWantedEpisodes(length: 1)['total'];
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            $errors[] = 'Episode subtitle counts are temporarily unavailable.';
        }

        try {
            $movieTotal = $bazarrClient->getWantedMovies(length: 1)['total'];
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            $errors[] = 'Movie subtitle counts are temporarily unavailable.';
        }

        try {
            $healthIssueCount = count($bazarrClient->getHealth()['data']);
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            $errors[] = 'Bazarr health details are temporarily unavailable.';
        }

        return [
            'missing' => [
                'episodes' => $episodeTotal,
                'movies' => $movieTotal,
                'total' => $episodeTotal + $movieTotal,
            ],
            'health_issue_count' => $healthIssueCount,
            'partial' => $errors !== [],
            'errors' => $errors,
        ];
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
        $this->validatePagination($page, $perPage);

        $bazarrClient = $this->subtitleInventoryConnections->bazarrClient($serviceConnection);
        $items = [];
        $errors = [];

        $sonarr = $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Sonarr);

        if (! $sonarr instanceof ServiceConnection) {
            $errors[] = 'The mapped Sonarr connection is missing or inactive.';
        } else {
            try {
                $items = [
                    ...$items,
                    ...$this->episodeLibrary($bazarrClient, $sonarr),
                ];
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Sonarr episode inventory is temporarily unavailable.';
            }
        }

        $radarr = $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Radarr);

        if (! $radarr instanceof ServiceConnection) {
            $errors[] = 'The mapped Radarr connection is missing or inactive.';
        } else {
            try {
                // The episode half is enumerated in full, and the local filters
                // plus the reported total are computed over the merged list, so the
                // movie half has to be enumerated too. Reading a single capped page
                // hid every movie past the cap and reported a total that matched
                // only the rows that happened to be fetched.
                $movieTotal = 0;
                $items = [
                    ...$items,
                    ...array_values(array_filter(array_map(
                        $this->subtitleItemMapper->movieItem(...),
                        $this->allUpstreamPages(
                            fn (int $offset): array => $bazarrClient->getMovies(
                                start: $offset,
                                length: self::MAX_PER_PAGE,
                            ),
                            $movieTotal,
                        ),
                    ))),
                ];
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Radarr movie inventory is temporarily unavailable.';
            }
        }

        $items = $this->applyFilters($items, $filters);
        $total = count($items);

        return [
            'data' => array_map(
                static fn (array $item): array => new SubtitleItemResource($item)->resolve(),
                array_slice($items, ($page - 1) * $perPage, $perPage),
            ),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'partial' => $errors !== [],
            'errors' => $errors,
        ];
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
        $this->validatePagination($page, $perPage);

        $bazarrClient = $this->subtitleInventoryConnections->bazarrClient($serviceConnection);
        $offset = ($page - 1) * $perPage;
        $episodeItems = [];
        $movieItems = [];
        $episodeTotal = 0;
        $movieTotal = 0;
        $errors = [];
        $mediaTypeFilter = is_string($filters['media_type'] ?? null) ? $filters['media_type'] : null;
        throw_unless(
            $mediaTypeFilter === null || in_array($mediaTypeFilter, ['episode', 'movie'], true),
            InvalidArgumentException::class,
            'Media type filter must be episode or movie.',
        );

        // A filter the wanted feeds cannot express forces the whole set to be
        // enumerated before paging; otherwise only the requested window is read.
        $paginateLocally = $this->requiresLocalPagination($filters);

        $sonarr = $mediaTypeFilter === 'movie'
            ? null
            : $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Sonarr);

        if ($mediaTypeFilter !== 'movie' && ! $sonarr instanceof ServiceConnection) {
            $errors[] = 'The mapped Sonarr connection is missing or inactive.';
        } elseif ($sonarr instanceof ServiceConnection) {
            try {
                $sonarrClient = $this->serviceClientFactory->make($sonarr);
                throw_unless($sonarrClient instanceof SonarrClient, InvalidArgumentException::class, 'The mapped Sonarr connection is invalid.');

                $seriesById = collect($sonarrClient->getSeries())
                    ->filter(fn (mixed $series): bool => is_array($series) && $this->subtitleItemMapper->positiveInteger($series['id'] ?? null) !== null)
                    ->keyBy(fn (array $series): int => (int) $series['id']);

                foreach ($this->wantedEpisodePages($bazarrClient, $paginateLocally ? null : $offset, $perPage, $episodeTotal) as $episode) {
                    $seriesId = $this->subtitleItemMapper->positiveInteger($episode['sonarrSeriesId'] ?? null);
                    $series = $seriesId === null ? null : $seriesById->get($seriesId);

                    if (! is_array($series)) {
                        continue;
                    }

                    $item = $this->subtitleItemMapper->episodeItem($episode, $series, $sonarr);

                    if ($item !== null) {
                        $episodeItems[] = $item;
                    }
                }
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Sonarr wanted subtitles are temporarily unavailable.';
            }
        }

        $radarr = $mediaTypeFilter === 'episode'
            ? null
            : $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Radarr);

        if ($mediaTypeFilter !== 'episode' && ! $radarr instanceof ServiceConnection) {
            $errors[] = 'The mapped Radarr connection is missing or inactive.';
        } elseif ($radarr instanceof ServiceConnection) {
            try {
                // Episodes precede movies in the merged order, so the movie slice
                // starts where the episode totals stop covering this page.
                $window = $this->mergedWindow($offset, $perPage, $episodeTotal);

                foreach ($this->wantedMoviePages(
                    $bazarrClient,
                    $paginateLocally ? null : $window['movie']['start'],
                    $paginateLocally ? $perPage : max(1, $window['movie']['length']),
                    $movieTotal,
                ) as $movie) {
                    $item = $this->subtitleItemMapper->movieItem($movie);

                    if ($item !== null) {
                        $movieItems[] = $item;
                    }
                }

                if (! $paginateLocally) {
                    $movieItems = array_slice($movieItems, 0, max(0, $window['movie']['length']));
                }
            } catch (ConnectionException|RequestException|UnexpectedValueException) {
                $errors[] = 'Radarr wanted subtitles are temporarily unavailable.';
            }
        }

        $items = $this->applyFilters([...$episodeItems, ...$movieItems], $filters);
        $total = $paginateLocally ? count($items) : $episodeTotal + $movieTotal;

        return [
            'data' => array_map(
                static fn (array $item): array => new SubtitleItemResource($item)->resolve(),
                $paginateLocally ? array_slice($items, $offset, $perPage) : array_slice($items, 0, $perPage),
            ),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'partial' => $errors !== [],
            'errors' => $errors,
        ];
    }

    /**
     * Read the wanted episode feed: one page when $start is given, otherwise the
     * whole feed in bounded pages. $total is filled with the upstream total either
     * way, so the merged window and the reported total agree.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function wantedEpisodePages(BazarrClient $bazarrClient, ?int $start, int $perPage, int &$total): array
    {
        if ($start !== null) {
            $page = $bazarrClient->getWantedEpisodes($start, $perPage);
            $total = $page['total'];

            return $page['data'];
        }

        return $this->allUpstreamPages(
            fn (int $offset): array => $bazarrClient->getWantedEpisodes($offset, self::MAX_PER_PAGE),
            $total,
        );
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function wantedMoviePages(BazarrClient $bazarrClient, ?int $start, int $perPage, int &$total): array
    {
        if ($start !== null) {
            $page = $bazarrClient->getWantedMovies($start, $perPage);
            $total = $page['total'];

            return $page['data'];
        }

        return $this->allUpstreamPages(
            fn (int $offset): array => $bazarrClient->getWantedMovies($offset, self::MAX_PER_PAGE),
            $total,
        );
    }

    /**
     * Walk a Bazarr feed to its end in bounded pages.
     *
     * @param  callable(int): array{data: list<array<string, mixed>>, total: int}  $reader
     * @return list<array<string, mixed>>
     */
    private function allUpstreamPages(callable $reader, int &$total): array
    {
        $rows = [];
        $offset = 0;

        do {
            $page = $reader($offset);
            $batch = $page['data'];
            $total = $page['total'];
            $rows = [...$rows, ...$batch];
            $offset += count($batch);
        } while ($batch !== [] && $offset < $total);

        return $rows;
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
        $this->validatePagination($page, $perPage);

        $bazarrClient = $this->subtitleInventoryConnections->bazarrClient($serviceConnection);
        $offset = ($page - 1) * $perPage;
        $episodeItems = [];
        $movieItems = [];
        $episodeTotal = 0;
        $movieTotal = 0;
        $errors = [];
        $mediaTypeFilter = is_string($filters['media_type'] ?? null) ? $filters['media_type'] : null;
        throw_unless(
            $mediaTypeFilter === null || in_array($mediaTypeFilter, ['episode', 'movie'], true),
            InvalidArgumentException::class,
            'Media type filter must be episode or movie.',
        );

        // Provider cannot be pushed to the history feeds, so it forces the whole
        // set to be read before paging. media_type is honoured by skipping the
        // other feed entirely rather than fetching and discarding it.
        $paginateLocally = $this->requiresLocalPagination($filters);

        if ($mediaTypeFilter !== 'movie') {
            if (! $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Sonarr) instanceof ServiceConnection) {
                $errors[] = 'The mapped Sonarr connection is missing or inactive.';
            } else {
                try {
                    $episodeItems = array_values(array_filter(array_map(
                        fn (array $history): ?array => $this->subtitleItemMapper->historyItem($history, 'episode'),
                        $paginateLocally
                            ? $this->allUpstreamPages(
                                fn (int $readOffset): array => $bazarrClient->getEpisodeHistory($readOffset, self::MAX_PER_PAGE),
                                $episodeTotal,
                            )
                            : $this->readHistoryPage(
                                fn (int $readOffset, int $length): array => $bazarrClient->getEpisodeHistory($readOffset, $length),
                                $offset,
                                $perPage,
                                $episodeTotal,
                            ),
                    )));
                } catch (ConnectionException|RequestException|UnexpectedValueException) {
                    $errors[] = 'Sonarr subtitle history is temporarily unavailable.';
                }
            }
        }

        if ($mediaTypeFilter !== 'episode') {
            if (! $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Radarr) instanceof ServiceConnection) {
                $errors[] = 'The mapped Radarr connection is missing or inactive.';
            } else {
                try {
                    $window = $this->mergedWindow($offset, $perPage, $episodeTotal);
                    $movieItems = array_values(array_filter(array_map(
                        fn (array $history): ?array => $this->subtitleItemMapper->historyItem($history, 'movie'),
                        $paginateLocally
                            ? $this->allUpstreamPages(
                                fn (int $readOffset): array => $bazarrClient->getMovieHistory($readOffset, self::MAX_PER_PAGE),
                                $movieTotal,
                            )
                            : $this->readHistoryPage(
                                fn (int $readOffset, int $length): array => $bazarrClient->getMovieHistory($readOffset, $length),
                                $window['movie']['start'],
                                max(1, $window['movie']['length']),
                                $movieTotal,
                            ),
                    )));

                    if (! $paginateLocally) {
                        $movieItems = array_slice($movieItems, 0, max(0, $window['movie']['length']));
                    }
                } catch (ConnectionException|RequestException|UnexpectedValueException) {
                    $errors[] = 'Radarr subtitle history is temporarily unavailable.';
                }
            }
        }

        $items = $this->applyHistoryFilters([...$episodeItems, ...$movieItems], $filters);
        $total = $paginateLocally ? count($items) : $episodeTotal + $movieTotal;

        return [
            'data' => array_map(
                static fn (array $item): array => new SubtitleHistoryResource($item)->resolve(),
                $paginateLocally ? array_slice($items, $offset, $perPage) : array_slice($items, 0, $perPage),
            ),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'partial' => $errors !== [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  callable(int, int): array{data: list<array<string, mixed>>, total: int}  $reader
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|UnexpectedValueException
     */
    private function readHistoryPage(callable $reader, int $start, int $length, int &$total): array
    {
        $page = $reader($start, $length);
        $total = $page['total'];

        return $page['data'];
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
        throw_unless(in_array($mediaType, ['episode', 'movie'], true), InvalidArgumentException::class, 'Media type must be episode or movie.');
        throw_if($mediaId <= 0, InvalidArgumentException::class, 'Media ID must be positive.');

        $bazarrClient = $this->subtitleInventoryConnections->bazarrClient($serviceConnection);

        if ($mediaType === 'episode') {
            return $this->inspectEpisode($serviceConnection, $bazarrClient, $mediaId);
        }

        return $this->inspectMovie($serviceConnection, $bazarrClient, $mediaId);
    }

    /**
     * @return array{
     *     item: array<string, mixed>,
     *     history: list<array<string, mixed>>,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    private function inspectEpisode(
        ServiceConnection $serviceConnection,
        BazarrClient $bazarrClient,
        int $episodeId,
    ): array {
        $sonarr = $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Sonarr);

        throw_if(! $sonarr instanceof ServiceConnection, ModelNotFoundException::class, 'The mapped Sonarr connection is missing or inactive.');

        $episode = collect($bazarrClient->getEpisodes(episodeIds: [$episodeId])['data'])
            ->first(fn (array $candidate): bool => $this->subtitleItemMapper->positiveInteger($candidate['sonarrEpisodeId'] ?? null) === $episodeId);

        throw_unless(is_array($episode), ModelNotFoundException::class, 'The requested Bazarr episode was not found.');

        $seriesId = $this->subtitleItemMapper->positiveInteger($episode['sonarrSeriesId'] ?? null);

        throw_if($seriesId === null, UnexpectedValueException::class, 'The requested Bazarr episode is missing its Sonarr series ID.');

        $sonarrClient = $this->serviceClientFactory->make($sonarr);
        throw_unless($sonarrClient instanceof SonarrClient, InvalidArgumentException::class, 'The mapped Sonarr connection is invalid.');

        $item = $this->subtitleItemMapper->episodeItem($episode, $sonarrClient->getSeriesById($seriesId), $sonarr);

        throw_if($item === null, UnexpectedValueException::class, 'The requested Bazarr episode could not be projected.');

        $history = array_values(array_filter(array_map(
            fn (array $history): ?array => $this->subtitleItemMapper->historyItem($history, 'episode'),
            $bazarrClient->getEpisodeHistory(length: 10, episodeId: $episodeId)['data'],
        )));

        return [
            'item' => new SubtitleItemResource($item)->resolve(),
            'history' => array_map(
                static fn (array $historyItem): array => new SubtitleHistoryResource($historyItem)->resolve(),
                $history,
            ),
            'partial' => false,
            'errors' => [],
        ];
    }

    /**
     * @return array{
     *     item: array<string, mixed>,
     *     history: list<array<string, mixed>>,
     *     partial: bool,
     *     errors: list<string>
     * }
     */
    private function inspectMovie(
        ServiceConnection $serviceConnection,
        BazarrClient $bazarrClient,
        int $radarrId,
    ): array {
        throw_if(
            ! $this->subtitleInventoryConnections->activeMapped($serviceConnection, BazarrServiceRole::Radarr) instanceof ServiceConnection,
            ModelNotFoundException::class,
            'The mapped Radarr connection is missing or inactive.',
        );

        $movie = $bazarrClient->findMovieByRadarrId($radarrId);

        throw_unless(is_array($movie), ModelNotFoundException::class, 'The requested Bazarr movie was not found.');

        $item = $this->subtitleItemMapper->movieItem($movie);

        throw_if($item === null, UnexpectedValueException::class, 'The requested Bazarr movie could not be projected.');

        $history = array_values(array_filter(array_map(
            fn (array $history): ?array => $this->subtitleItemMapper->historyItem($history, 'movie'),
            $bazarrClient->getMovieHistory(length: 10, radarrId: $radarrId)['data'],
        )));

        return [
            'item' => new SubtitleItemResource($item)->resolve(),
            'history' => array_map(
                static fn (array $historyItem): array => new SubtitleHistoryResource($historyItem)->resolve(),
                $history,
            ),
            'partial' => false,
            'errors' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function episodeLibrary(BazarrClient $bazarrClient, ServiceConnection $serviceConnection): array
    {
        $sonarrClient = $this->serviceClientFactory->make($serviceConnection);

        throw_unless($sonarrClient instanceof SonarrClient, InvalidArgumentException::class, 'The mapped Sonarr connection is invalid.');

        $seriesById = collect($sonarrClient->getSeries())
            ->filter(fn (mixed $series): bool => is_array($series) && $this->subtitleItemMapper->positiveInteger($series['id'] ?? null) !== null)
            ->keyBy(fn (array $series): int => (int) $series['id']);
        $items = [];

        foreach (array_chunk($seriesById->keys()->all(), self::EPISODE_SERIES_BATCH_SIZE) as $seriesIds) {
            $episodes = $bazarrClient->getEpisodes(seriesIds: $seriesIds)['data'];

            foreach ($episodes as $episode) {
                $seriesId = $this->subtitleItemMapper->positiveInteger($episode['sonarrSeriesId'] ?? null);
                $series = $seriesId === null ? null : $seriesById->get($seriesId);

                if (! is_array($series)) {
                    continue;
                }

                $item = $this->subtitleItemMapper->episodeItem($episode, $series, $serviceConnection);

                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return $items;
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

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    /**
     * Split one page of the merged episode-then-movie stream into the slice each
     * upstream feed has to supply.
     *
     * Both feeds are paginated independently, so asking each of them for the same
     * offset and then truncating the concatenation silently drops the whole tail
     * source: a full episode page discarded every movie, and the next page moved
     * both offsets on, skipping those movies for good while the summed total kept
     * promising them. Because episodes always precede movies in the merged order,
     * the split is pure arithmetic over the upstream totals.
     *
     * @return array{episode: array{start: int, length: int}, movie: array{start: int, length: int}}
     */
    private function mergedWindow(int $offset, int $perPage, int $episodeTotal): array
    {
        $episodeLength = max(0, min($perPage, $episodeTotal - $offset));
        $movieLength = $perPage - $episodeLength;

        return [
            'episode' => [
                'start' => min($offset, max(0, $episodeTotal)),
                'length' => $episodeLength,
            ],
            'movie' => [
                'start' => max(0, $offset - $episodeTotal),
                'length' => $movieLength,
            ],
        ];
    }

    /**
     * Filters the upstream feeds cannot express have to be applied to the whole
     * logical result set before it is paginated, otherwise a page can come back
     * empty while matches sit further along and the advertised total counts rows
     * the caller will never receive.
     *
     * @param  array<string, mixed>  $filters
     */
    private function requiresLocalPagination(array $filters): bool
    {
        return is_string($filters['scope'] ?? null)
            || is_string($filters['provider'] ?? null)
            || ($filters['missing_only'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, array<string, mixed>>  $items
     */
    private function applyFilters(array $items, array $filters): array
    {
        $mediaType = is_string($filters['media_type'] ?? null) ? $filters['media_type'] : null;
        $scope = is_string($filters['scope'] ?? null) ? $filters['scope'] : null;
        $missingOnly = ($filters['missing_only'] ?? false) === true;

        return array_values(array_filter($items, static function (array $item) use ($mediaType, $scope, $missingOnly): bool {
            if ($mediaType !== null && ($item['media_type'] ?? null) !== $mediaType) {
                return false;
            }

            if ($scope !== null && ($item['scope'] ?? null) !== $scope) {
                return false;
            }

            return ! $missingOnly || ($item['missing_languages'] ?? []) !== [];
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyHistoryFilters(array $items, array $filters): array
    {
        $mediaType = is_string($filters['media_type'] ?? null) ? $filters['media_type'] : null;
        $provider = is_string($filters['provider'] ?? null)
            ? Str::of($filters['provider'])->trim()->lower()->toString()
            : null;

        return array_values(array_filter($items, static function (array $item) use ($mediaType, $provider): bool {
            if ($mediaType !== null && ($item['media_type'] ?? null) !== $mediaType) {
                return false;
            }

            return $provider === null || Str::lower((string) ($item['provider'] ?? '')) === $provider;
        }));
    }

    private function validatePagination(int $page, int $perPage): void
    {
        throw_if($page <= 0, InvalidArgumentException::class, 'Page must be positive.');
        throw_if($perPage <= 0 || $perPage > self::MAX_PER_PAGE, InvalidArgumentException::class, 'Per page must be between 1 and 100.');
    }
}
