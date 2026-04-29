<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Prowlarr\ProwlarrClient;
use App\Services\Radarr\RadarrClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Seerr\SeerrTitleResolver;
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

    public function __construct(
        private readonly SeerrTitleResolver $seerrTitleResolver,
    ) {}

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

        return ['url' => rtrim($connection->url, '/')];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    private function searchSonarr(string $term): array
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
    private function searchRadarr(string $term): array
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
            $response = $seerrClient->getRequests(['take' => 100, 'sort' => 'added']);
        } catch (Throwable $throwable) {
            return $this->serviceFailure('seerr', $throwable);
        }

        $results = is_array($response['results'] ?? null) ? $response['results'] : [];
        $titles = $this->seerrTitleResolver->resolve($connection, $seerrClient, $results);

        $enriched = array_map(fn (array $req): array => [
            'id' => $req['id'] ?? null,
            'media_type' => $req['type'] ?? ($req['media']['mediaType'] ?? null),
            'title' => $this->seerrTitleResolver->titleFor($req, $titles),
            'tmdb_id' => $req['media']['tmdbId'] ?? null,
            'tvdb_id' => $req['media']['tvdbId'] ?? null,
            'status' => $req['status'] ?? null,
            'overview' => null,
            'poster_path' => null,
        ], $results);

        $matches = $this->filterByTitle($enriched, $term);

        return [
            'results' => array_values($matches),
            'error' => null,
        ];
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
