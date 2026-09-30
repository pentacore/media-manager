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
use App\Services\Seerr\SeerrTitlePresenter;
use App\Services\Seerr\SeerrUserResolver;
use App\Services\Sonarr\SonarrClient;
use App\Support\Abilities;
use App\Support\UrlQueryRedactor;
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

    public function index(Request $request, SeerrTitlePresenter $seerrTitlePresenter, SeerrUserResolver $seerrUserResolver): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
            'scope' => ['nullable', 'string', 'in:all,library,requests,indexers'],
        ]);

        $term = trim((string) $request->query('q', ''));
        $scope = (string) $request->query('scope', 'all');

        // Viewers browse the library read-only through its own pages; Search is
        // their way into Seerr only, so the library and indexer fan-outs stay
        // member+ (spec: "Viewers get only the Seerr scope").
        $seerrOnly = ! $request->user()->can(Abilities::MANAGE_LIBRARY);

        if ($seerrOnly) {
            $scope = 'requests';
        }

        $empty = ['results' => [], 'error' => null];

        // Indexers are heavy and noisy, so they are opt-in: only fire the
        // Prowlarr fan-out when the user explicitly switches to that scope.
        $includeIndexers = ! $seerrOnly && $term !== '' && $scope === 'indexers';

        $seerrConnection = $term === '' ? null : $this->activeConnection(ServiceType::Seerr);

        return Inertia::render('Search', [
            'query' => $term,
            'scope' => $scope,
            'connections' => $this->resolveConnectionUrls($seerrOnly),
            'seriesResults' => $term === '' || $seerrOnly
                ? $empty
                : Inertia::defer(fn (): array => $this->searchSonarr($term)),
            'movieResults' => $term === '' || $seerrOnly
                ? $empty
                : Inertia::defer(fn (): array => $this->searchRadarr($term)),
            'requestResults' => $term === ''
                ? $empty
                : Inertia::defer(fn (): array => $this->searchSeerr($term, $seerrTitlePresenter)),
            'requesting' => $seerrConnection instanceof ServiceConnection
                ? Inertia::defer(fn (): array => $seerrUserResolver->requestingContext($seerrConnection, $request->user()), 'requesting')
                : null,
            'indexerResults' => $includeIndexers
                ? Inertia::defer(fn (): array => $this->searchIndexers($term))
                : $empty,
        ]);
    }

    private function activeConnection(ServiceType $serviceType): ?ServiceConnection
    {
        try {
            return ServiceConnection::resolveActive($serviceType);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /**
     * @return array{sonarr: ?array{url: string}, radarr: ?array{url: string}, seerr: ?array{url: string}}
     */
    private function resolveConnectionUrls(bool $seerrOnly): array
    {
        if ($seerrOnly) {
            return [
                'sonarr' => null,
                'radarr' => null,
                'seerr' => $this->externalConnectionUrlFor(ServiceType::Seerr),
            ];
        }

        return [
            'sonarr' => $this->connectionUrlFor(ServiceType::Sonarr),
            'radarr' => $this->connectionUrlFor(ServiceType::Radarr),
            'seerr' => $this->connectionUrlFor(ServiceType::Seerr),
        ];
    }

    /**
     * A viewer only ever gets the connection's public external URL — never the
     * internal URL linkUrl() would fall back to.
     *
     * @return ?array{url: string}
     */
    private function externalConnectionUrlFor(ServiceType $serviceType): ?array
    {
        $externalUrl = $this->activeConnection($serviceType)?->external_url;

        return is_string($externalUrl) && $externalUrl !== '' ? ['url' => rtrim($externalUrl, '/')] : null;
    }

    /**
     * @return ?array{url: string}
     */
    private function connectionUrlFor(ServiceType $serviceType): ?array
    {
        $connection = $this->activeConnection($serviceType);

        return $connection instanceof ServiceConnection ? ['url' => $connection->linkUrl()] : null;
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
     * Seerr's TMDB-backed multi-search, one row per movie/TV title whatever its
     * request state — unrequested titles included, so the detail sheet can
     * request them. Status comes from the hit's own `mediaInfo`.
     *
     * @return array{results: list<array<string, mixed>>, error: ?string}
     */
    private function searchSeerr(string $term, SeerrTitlePresenter $seerrTitlePresenter): array
    {
        $connection = $this->activeConnection(ServiceType::Seerr);

        if (! $connection instanceof ServiceConnection) {
            return ['results' => [], 'error' => 'No active Seerr connection configured.'];
        }

        try {
            $response = new SeerrClient($connection)->search($term);
        } catch (Throwable $throwable) {
            return $this->serviceFailure('seerr', $throwable);
        }

        return [
            'results' => array_slice($seerrTitlePresenter->results($response), 0, self::MAX_RESULTS),
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

            // Never pass Prowlarr's downloadUrl (it embeds the Prowlarr API key)
            // or the raw guid (it can carry a tracker passkey) to the browser;
            // the row key is a hash of the guid instead.
            $guid = $hit['guid'] ?? null;

            return [
                'key' => is_string($guid) && $guid !== '' ? md5($guid) : null,
                'title' => $hit['title'] ?? null,
                'tracker' => $hit['indexer'] ?? null,
                'category' => $hit['categories'][0]['name'] ?? null,
                'size_bytes' => $hit['size'] ?? null,
                'seeders' => $hit['seeders'] ?? null,
                'leechers' => $hit['leechers'] ?? null,
                'age' => $age,
                'info_url' => self::sanitizeInfoUrl($hit['infoUrl'] ?? null),
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
     * Prowlarr's infoUrl can carry a tracker passkey either in the query
     * string (e.g. `?passkey=...`) or, more rarely, in the URL's userinfo
     * part (`https://user:pass@host/...`). The query string is redacted so
     * the details link stays usable; a userinfo credential can't be dropped
     * piecemeal, so the whole URL is discarded instead.
     */
    private static function sanitizeInfoUrl(mixed $infoUrl): ?string
    {
        if (! is_string($infoUrl) || $infoUrl === '') {
            return null;
        }

        if (parse_url($infoUrl, PHP_URL_USER) !== null) {
            return null;
        }

        return UrlQueryRedactor::redact($infoUrl);
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
