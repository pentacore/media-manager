<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Sonarr\SonarrClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SearchController extends Controller
{
    private const int MAX_RESULTS = 20;

    private function maxResults(): int
    {
        return (int) config('mediamanager.search.max_results', self::MAX_RESULTS);
    }

    private function driver(): string
    {
        $driver = config('mediamanager.search.driver', 'typesense');

        return is_string($driver) ? $driver : 'typesense';
    }

    public function index(Request $request): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'scope' => ['nullable', 'string', 'in:all,library,requests,indexers'],
        ]);

        $term = trim((string) $request->query('q', ''));
        $scope = (string) $request->query('scope', 'all');

        // Indexers are heavy and noisy, so they are opt-in: only fire the
        // Prowlarr fan-out when the user explicitly switches to that scope.
        $includeIndexers = $term !== '' && $scope === 'indexers';

        return Inertia::render('Search', [
            'query' => $term,
            'scope' => $scope,
            'connections' => $this->resolveConnectionUrls(),
            'seriesResults' => $term === ''
                ? ['results' => [], 'error' => null]
                : Inertia::defer(fn (): array => $this->searchSonarr($term)),
            'movieResults' => $term === ''
                ? ['results' => [], 'error' => null]
                : Inertia::defer(fn (): array => $this->searchRadarr($term)),
            'requestResults' => $term === ''
                ? ['results' => [], 'error' => null]
                : Inertia::defer(fn (): array => $this->searchSeerr($term)),
            'indexerResults' => $includeIndexers
                ? Inertia::defer(fn (): array => $this->searchIndexers($term))
                : ['results' => [], 'error' => null],
        ]);
    }

    /**
     * @return array{sonarr: ?array{url: string}, radarr: ?array{url: string}, seerr: ?array{url: string}}
     */
    private function resolveConnectionUrls(): array
    {
        return [
            'sonarr' => $this->connectionUrlFor(ServiceType::Sonarr),
            'radarr' => $this->connectionUrlFor(ServiceType::Radarr),
            'seerr' => $this->connectionUrlFor(ServiceType::Seerr),
        ];
    }

    /**
     * @return ?array{url: string}
     */
    private function connectionUrlFor(ServiceType $serviceType): ?array
    {
        try {
            $connection = ServiceConnection::resolveActive($serviceType);
        } catch (ModelNotFoundException) {
            return null;
        }

        return ['url' => $connection->linkUrl()];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchSonarr(string $term): array
    {
        return $this->driver() === 'fallback'
            ? $this->searchSonarrFallback($term)
            : $this->searchSonarrTypesense($term);
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchRadarr(string $term): array
    {
        return $this->driver() === 'fallback'
            ? $this->searchRadarrFallback($term)
            : $this->searchRadarrTypesense($term);
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchSonarrTypesense(string $term): array
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Sonarr);
        } catch (ModelNotFoundException) {
            return ['results' => [], 'error' => 'No active Sonarr connection configured.'];
        }

        $max = $this->maxResults();

        try {
            $hits = IndexedSeries::search($term)
                ->options([
                    'filter_by' => 'service_connection_id:='.$connection->id,
                    'per_page' => $max,
                ])
                ->take($max)
                ->get();
        } catch (Throwable $throwable) {
            return $this->serviceFailure('sonarr', $throwable);
        }

        return [
            'results' => $hits->map(static fn (IndexedSeries $indexedSeries): array => [
                'id' => $indexedSeries->sonarr_id,
                'tvdb_id' => $indexedSeries->tvdb_id,
                'title' => $indexedSeries->title,
                'year' => $indexedSeries->year,
                'overview' => $indexedSeries->overview,
                'title_slug' => $indexedSeries->title_slug,
                'status' => $indexedSeries->status,
                'monitored' => $indexedSeries->monitored,
                'remote_poster' => $indexedSeries->poster_url,
            ])->all(),
            'error' => null,
        ];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchRadarrTypesense(string $term): array
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Radarr);
        } catch (ModelNotFoundException) {
            return ['results' => [], 'error' => 'No active Radarr connection configured.'];
        }

        $max = $this->maxResults();

        try {
            $hits = IndexedMovie::search($term)
                ->options([
                    'filter_by' => 'service_connection_id:='.$connection->id,
                    'per_page' => $max,
                ])
                ->take($max)
                ->get();
        } catch (Throwable $throwable) {
            return $this->serviceFailure('radarr', $throwable);
        }

        return [
            'results' => $hits->map(static fn (IndexedMovie $indexedMovie): array => [
                'id' => $indexedMovie->radarr_id,
                'tmdb_id' => $indexedMovie->tmdb_id,
                'title' => $indexedMovie->title,
                'year' => $indexedMovie->year,
                'overview' => $indexedMovie->overview,
                'title_slug' => $indexedMovie->title_slug,
                'status' => $indexedMovie->status,
                'monitored' => $indexedMovie->monitored,
                'has_file' => $indexedMovie->has_file,
                'remote_poster' => $indexedMovie->poster_url,
            ])->all(),
            'error' => null,
        ];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchSonarrFallback(string $term): array
    {
        try {
            $sonarrClient = new SonarrClient(ServiceConnection::resolveActive(ServiceType::Sonarr));
            $items = $sonarrClient->getSeries();
        } catch (ModelNotFoundException) {
            return ['results' => [], 'error' => 'No active Sonarr connection configured.'];
        } catch (Throwable $throwable) {
            return $this->serviceFailure('sonarr', $throwable);
        }

        $matches = $this->filterByTitle($items, $term);

        return [
            'results' => array_map(fn (array $series): array => [
                'id' => $series['id'] ?? null,
                'tvdb_id' => $series['tvdbId'] ?? null,
                'title' => $series['title'] ?? null,
                'year' => $series['year'] ?? null,
                'overview' => $series['overview'] ?? null,
                'title_slug' => $series['titleSlug'] ?? null,
                'status' => $series['status'] ?? null,
                'monitored' => $series['monitored'] ?? false,
                'remote_poster' => null,
            ], $matches),
            'error' => null,
        ];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchRadarrFallback(string $term): array
    {
        try {
            $radarrClient = new RadarrClient(ServiceConnection::resolveActive(ServiceType::Radarr));
            $items = $radarrClient->getMovies();
        } catch (ModelNotFoundException) {
            return ['results' => [], 'error' => 'No active Radarr connection configured.'];
        } catch (Throwable $throwable) {
            return $this->serviceFailure('radarr', $throwable);
        }

        $matches = $this->filterByTitle($items, $term);

        return [
            'results' => array_map(fn (array $movie): array => [
                'id' => $movie['id'] ?? null,
                'tmdb_id' => $movie['tmdbId'] ?? null,
                'title' => $movie['title'] ?? null,
                'year' => $movie['year'] ?? null,
                'overview' => $movie['overview'] ?? null,
                'title_slug' => $movie['titleSlug'] ?? null,
                'status' => $movie['status'] ?? null,
                'monitored' => $movie['monitored'] ?? false,
                'has_file' => $movie['hasFile'] ?? false,
                'remote_poster' => null,
            ], $matches),
            'error' => null,
        ];
    }

    /**
     * Find existing Seerr requests matching the search term.
     *
     * Two-step: hit Seerr's TMDB-backed `/search` to get title matches, then
     * for any hit already tracked in Seerr (`mediaInfo` present) fetch the
     * movie/tv detail to read its `mediaInfo.requests` array. The /search
     * endpoint sets `mediaInfo` as a presence flag but does not include the
     * full requests collection. Returns one row per request, regardless of
     * status.
     *
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchSeerr(string $term): array
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Seerr);
        } catch (ModelNotFoundException) {
            return ['results' => [], 'error' => 'No active Seerr connection configured.'];
        }

        $seerrClient = new SeerrClient($connection);

        try {
            $response = $seerrClient->search($term);
        } catch (Throwable $throwable) {
            return $this->serviceFailure('seerr', $throwable);
        }

        $hits = is_array($response['results'] ?? null) ? $response['results'] : [];
        $rows = [];

        foreach ($hits as $hit) {
            $mediaType = (string) ($hit['mediaType'] ?? '');
            if (! in_array($mediaType, ['movie', 'tv'], true)) {
                continue;
            }

            // mediaInfo is only emitted on /search hits that have an entry in
            // Seerr's local DB — i.e. items that have ever been requested.
            if (! is_array($hit['mediaInfo'] ?? null)) {
                continue;
            }

            $tmdbId = (int) ($hit['id'] ?? $hit['mediaInfo']['tmdbId'] ?? 0);
            if ($tmdbId <= 0) {
                continue;
            }

            try {
                $detail = $mediaType === 'movie'
                    ? $seerrClient->getMovieDetails($tmdbId)
                    : $seerrClient->getTvDetails($tmdbId);
            } catch (Throwable $throwable) {
                Log::warning('Seerr detail lookup failed during search.', [
                    'media_type' => $mediaType,
                    'tmdb_id' => $tmdbId,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);

                continue;
            }

            $mediaInfo = is_array($detail['mediaInfo'] ?? null) ? $detail['mediaInfo'] : [];
            $requests = is_array($mediaInfo['requests'] ?? null) ? $mediaInfo['requests'] : [];
            if ($requests === []) {
                continue;
            }

            $title = $detail['title'] ?? $detail['name'] ?? $hit['title'] ?? $hit['name'] ?? null;
            $overview = $detail['overview'] ?? $hit['overview'] ?? null;
            $posterPath = $detail['posterPath'] ?? $hit['posterPath'] ?? null;
            $tvdbId = $mediaInfo['tvdbId'] ?? $hit['mediaInfo']['tvdbId'] ?? null;

            foreach ($requests as $request) {
                $rows[] = [
                    'id' => $request['id'] ?? null,
                    'media_type' => $mediaType,
                    'title' => is_string($title) ? $title : null,
                    'tmdb_id' => $tmdbId,
                    'tvdb_id' => $tvdbId,
                    'status' => $request['status'] ?? null,
                    'overview' => is_string($overview) ? $overview : null,
                    'poster_path' => is_string($posterPath) ? $posterPath : null,
                ];

                if (count($rows) >= self::MAX_RESULTS) {
                    break 2;
                }
            }
        }

        return ['results' => $rows, 'error' => null];
    }

    /**
     * Fan out a Prowlarr indexer search and return a release table the
     * unified-search page can render. Each row carries title / tracker /
     * size / seeders / leechers / age / category / quality score.
     *
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchIndexers(string $term): array
    {
        try {
            $connection = ServiceConnection::resolveActive(ServiceType::Prowlarr);
        } catch (ModelNotFoundException) {
            return ['results' => [], 'error' => 'No active Prowlarr connection configured.'];
        }

        try {
            $hits = new ProwlarrClient($connection)->searchIndexers($term);
        } catch (Throwable $throwable) {
            return $this->serviceFailure('prowlarr', $throwable);
        }

        $rows = array_map(static function (array $hit): array {
            $publishDate = $hit['publishDate'] ?? null;
            $age = null;

            if (is_string($publishDate) && $publishDate !== '') {
                try {
                    $age = CarbonImmutable::parse($publishDate)->diffForHumans();
                } catch (Throwable) {
                    $age = null;
                }
            }

            return [
                'guid' => $hit['guid'] ?? null,
                'title' => $hit['title'] ?? null,
                'tracker' => $hit['indexer'] ?? null,
                'category' => $hit['categories'][0]['name'] ?? null,
                'size_bytes' => $hit['size'] ?? null,
                'seeders' => $hit['seeders'] ?? null,
                'leechers' => $hit['leechers'] ?? null,
                'age' => $age,
                'download_url' => $hit['downloadUrl'] ?? null,
                'info_url' => $hit['infoUrl'] ?? null,
                // Prowlarr returns a quality-style score in 0-100 only for
                // some indexers; expose what's there but don't synthesise.
                'score' => $hit['qualityWeight'] ?? null,
            ];
        }, $hits);

        return [
            'results' => array_slice($rows, 0, self::MAX_RESULTS),
            'error' => null,
        ];
    }

    /**
     * Case-insensitive substring filter on the `title` key, capped to MAX_RESULTS.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function filterByTitle(array $items, string $term): array
    {
        $needle = mb_strtolower($term);

        $matches = array_filter($items, function (array $item) use ($needle): bool {
            $title = $item['title'] ?? null;
            if (! is_string($title) || $title === '') {
                return false;
            }

            return str_contains(mb_strtolower($title), $needle);
        });

        return array_slice(array_values($matches), 0, self::MAX_RESULTS);
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: string}
     */
    private function serviceFailure(string $service, Throwable $throwable): array
    {
        Log::warning('Media search failed.', [
            'service' => $service,
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
        ]);

        return [
            'results' => [],
            'error' => sprintf('%s search is temporarily unavailable.', ucfirst($service)),
        ];
    }
}
