<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Enums\BazarrServiceRole;
use App\Http\Resources\Bazarr\SubtitleHistoryResource;
use App\Http\Resources\Bazarr\SubtitleItemResource;
use App\Models\ServiceConnection;
use App\Services\ServiceClientFactory;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Reads the subtitle inventory pages for one Bazarr connection: the overview
 * counts, the mapped library, the wanted (missing) feeds and the subtitle
 * history, each merged episode-then-movie, filtered and paginated, with an
 * unavailable source reported as a partial result rather than an empty list.
 */
final readonly class SubtitleLibraryReader
{
    public const int MAX_PER_PAGE = 100;

    private const int EPISODE_SERIES_BATCH_SIZE = 50;

    public function __construct(
        private ServiceClientFactory $serviceClientFactory,
        private SubtitleInventoryConnections $subtitleInventoryConnections,
        private SubtitleItemMapper $subtitleItemMapper,
    ) {}

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
     * @return list<array<string, mixed>>
     */
    public function episodeLibrary(BazarrClient $bazarrClient, ServiceConnection $serviceConnection): array
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

    public function validatePagination(int $page, int $perPage): void
    {
        throw_if($page <= 0, InvalidArgumentException::class, 'Page must be positive.');
        throw_if($perPage <= 0 || $perPage > self::MAX_PER_PAGE, InvalidArgumentException::class, 'Per page must be between 1 and 100.');
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
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
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
}
