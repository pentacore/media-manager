<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Seerr\SeerrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SearchController extends Controller
{
    private const int MAX_RESULTS = 20;

    public function index(Request $request): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:500'],
        ]);

        $term = trim((string) $request->query('q', ''));

        return Inertia::render('Search', [
            'query' => $term,
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
            return ['results' => [], 'error' => $throwable->getMessage()];
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
            return ['results' => [], 'error' => $throwable->getMessage()];
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
            return ['results' => [], 'error' => $throwable->getMessage()];
        }

        $results = is_array($response['results'] ?? null) ? $response['results'] : [];
        $titles = $this->resolveSeerrTitles($connection, $seerrClient, $results);

        $enriched = array_map(function (array $req) use ($titles): array {
            $mediaType = $req['type'] ?? ($req['media']['mediaType'] ?? null);
            $tmdbId = $req['media']['tmdbId'] ?? null;
            $titleKey = ($mediaType !== null && $tmdbId !== null)
                ? sprintf('%s:%d', (string) $mediaType, (int) $tmdbId)
                : null;

            return [
                'id' => $req['id'] ?? null,
                'media_type' => $mediaType,
                'title' => $titleKey !== null ? ($titles[$titleKey] ?? null) : null,
                'tmdb_id' => $tmdbId,
                'tvdb_id' => $req['media']['tvdbId'] ?? null,
                'status' => $req['status'] ?? null,
                'overview' => null,
                'poster_path' => null,
            ];
        }, $results);

        $matches = $this->filterByTitle($enriched, $term);

        return [
            'results' => array_values($matches),
            'error' => null,
        ];
    }

    /**
     * Fetch titles for every distinct (media_type, tmdb_id) pair.
     * Cached for 5 minutes per connection + pair.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, string>
     */
    private function resolveSeerrTitles(ServiceConnection $serviceConnection, SeerrClient $seerrClient, array $requests): array
    {
        $pairs = [];
        foreach ($requests as $request) {
            $mediaType = $request['type'] ?? ($request['media']['mediaType'] ?? null);
            $tmdbId = $request['media']['tmdbId'] ?? null;
            if ($mediaType === null) {
                continue;
            }
            if ($tmdbId === null) {
                continue;
            }

            $pairs[sprintf('%s:%d', (string) $mediaType, (int) $tmdbId)] = [
                'type' => (string) $mediaType,
                'id' => (int) $tmdbId,
            ];
        }

        $titles = [];
        foreach ($pairs as $key => $pair) {
            $cacheKey = sprintf('seerr:title:%d:%s:%d', $serviceConnection->id, $pair['type'], $pair['id']);
            $titles[$key] = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($seerrClient, $pair): string {
                try {
                    $detail = $pair['type'] === 'movie'
                        ? $seerrClient->getMovieDetails($pair['id'])
                        : $seerrClient->getTvDetails($pair['id']);
                } catch (RequestException|ConnectionException) {
                    return sprintf('%s #%d', ucfirst($pair['type']), $pair['id']);
                }

                return (string) (
                    $detail['title']
                    ?? $detail['name']
                    ?? $detail['originalTitle']
                    ?? $detail['originalName']
                    ?? sprintf('%s #%d', ucfirst($pair['type']), $pair['id'])
                );
            });
        }

        return $titles;
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
}
