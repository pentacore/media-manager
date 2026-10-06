<?php

declare(strict_types=1);

namespace App\Services\Anime;

use App\Enums\ServiceType;
use App\Models\IndexedMovie;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;

/**
 * Library status of the seasonal anime rows already owned in Sonarr/Radarr.
 *
 * A row is owned when the index holds it (`indexed_series.tvdb_id`,
 * `indexed_movies.tmdb_id`); when several connections hold it, the row on
 * that service's active connection wins. Monitoring state is read live from
 * Sonarr/Radarr over the index, because it changes and the overlay is meant
 * to show what is true now — so the uncached `fetchSeriesById()` /
 * `fetchMovieById()` reads are used, never the client's entity cache.
 *
 * Each distinct (connection, series/movie) is looked up once per call, so
 * several seasons of one series share a single request. The first failure on
 * a connection (a RequestException, a ConnectionException, or an answer that
 * is not a series/movie) stops every further call to that connection for the
 * rest of the call, so an outage costs one retried request instead of one per
 * card; inactive connections are never called. Without live data the indexed
 * `monitored` flag decides, at series/movie level only, and no season number
 * is offered.
 *
 * @phpstan-type OwnedStatus array{status: 'in_library'|'unmonitored', unmonitoredScope: 'series'|'season'|'movie'|null, library: array{service: 'sonarr'|'radarr', itemId: int, connectionId: int, seasonNumber: int|null, onActiveConnection: bool}}
 */
final readonly class SeasonLibraryStatus
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{status: 'in_library'|'unmonitored', unmonitoredScope: 'series'|'season'|'movie'|null, library: array{service: 'sonarr'|'radarr', itemId: int, connectionId: int, seasonNumber: int|null, onActiveConnection: bool}}>
     *                                                                                                                                                                                                                                                  keyed by row `key`; rows that are not owned are absent.
     */
    public function resolve(array $rows): array
    {
        $tvdbIds = $this->distinctIds($rows, $this->seriesTvdbId(...));
        $movieTmdbIds = $this->distinctIds($rows, $this->movieTmdbId(...));

        $indexedSeries = $tvdbIds === []
            ? new EloquentCollection
            : IndexedSeries::query()->with('serviceConnection')->whereIn('tvdb_id', $tvdbIds)->orderBy('id')->get();
        $indexedMovies = $movieTmdbIds === []
            ? new EloquentCollection
            : IndexedMovie::query()->with('serviceConnection')->whereIn('tmdb_id', $movieTmdbIds)->orderBy('id')->get();

        $activeSonarrId = $indexedSeries->isEmpty() ? null : ServiceConnection::findActive(ServiceType::Sonarr)?->id;
        $activeRadarrId = $indexedMovies->isEmpty() ? null : ServiceConnection::findActive(ServiceType::Radarr)?->id;

        $seriesByTvdbId = $this->preferConnection($indexedSeries, 'tvdb_id', $activeSonarrId);
        $moviesByTmdbId = $this->preferConnection($indexedMovies, 'tmdb_id', $activeRadarrId);

        $liveItem = $this->liveLookup();
        $resolved = [];

        foreach ($rows as $row) {
            $mapping = $row['mapping'];
            $tvdbId = $this->seriesTvdbId($mapping);
            $movieTmdbId = $this->movieTmdbId($mapping);
            $series = $tvdbId !== null ? $seriesByTvdbId->get($tvdbId) : null;
            $movie = $movieTmdbId !== null ? $moviesByTmdbId->get($movieTmdbId) : null;

            if ($series instanceof IndexedSeries) {
                $resolved[$row['key']] = $this->seriesStatus(
                    $series,
                    $mapping['tvdbSeason'] ?? null,
                    $liveItem($series->serviceConnection, (int) $series->sonarr_id),
                    $activeSonarrId,
                );
            } elseif ($movie instanceof IndexedMovie) {
                $resolved[$row['key']] = $this->movieStatus(
                    $movie,
                    $liveItem($movie->serviceConnection, (int) $movie->radarr_id),
                    $activeRadarrId,
                );
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function seriesTvdbId(array $mapping): ?int
    {
        return $mapping['mapped'] && $mapping['mediaType'] === 'tv' && $mapping['tvdbId'] !== null
            ? (int) $mapping['tvdbId']
            : null;
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function movieTmdbId(array $mapping): ?int
    {
        return $mapping['mapped'] && $mapping['mediaType'] === 'movie' && $mapping['tmdbId'] !== null
            ? (int) $mapping['tmdbId']
            : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Closure(array<string, mixed>): ?int  $externalId
     * @return array<int, int>
     */
    private function distinctIds(array $rows, Closure $externalId): array
    {
        $ids = array_map(static fn (array $row): ?int => $externalId($row['mapping']), $rows);

        return array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
    }

    /**
     * One indexed row per external id: the one on the active connection, else the first.
     *
     * @template TModel of Model
     *
     * @param  EloquentCollection<int, TModel>  $indexed
     * @return Collection<int, TModel>
     */
    private function preferConnection(EloquentCollection $indexed, string $externalIdColumn, ?int $activeConnectionId): Collection
    {
        return $indexed->toBase()
            ->groupBy($externalIdColumn)
            ->map(static fn (Collection $group): Model => $group->first(
                static fn (Model $model): bool => $activeConnectionId !== null && (int) $model->getAttribute('service_connection_id') === $activeConnectionId,
            ) ?? $group->first());
    }

    /**
     * A memoizing live reader for one resolve() call.
     *
     * @return Closure(?ServiceConnection, int): (array<string, mixed>|null)
     */
    private function liveLookup(): Closure
    {
        /** @var array<string, array<string, mixed>|null> $items */
        $items = [];
        /** @var array<int, true> $failedConnections */
        $failedConnections = [];

        return function (?ServiceConnection $serviceConnection, int $itemId) use (&$items, &$failedConnections): ?array {
            if (! $serviceConnection instanceof ServiceConnection) {
                return null;
            }

            $memoKey = sprintf('%d:%d', $serviceConnection->id, $itemId);

            if (array_key_exists($memoKey, $items)) {
                return $items[$memoKey];
            }

            if (! $serviceConnection->is_active || isset($failedConnections[$serviceConnection->id])) {
                return null;
            }

            try {
                $item = $this->fetchItem($serviceConnection, $itemId);
            } catch (RequestException|ConnectionException) {
                $item = [];
            }

            if (! isset($item['id'])) {
                $failedConnections[$serviceConnection->id] = true;

                return $items[$memoKey] = null;
            }

            return $items[$memoKey] = $item;
        };
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws RequestException|ConnectionException
     */
    private function fetchItem(ServiceConnection $serviceConnection, int $itemId): array
    {
        return $serviceConnection->type === ServiceType::Radarr
            ? new RadarrClient($serviceConnection)->fetchMovieById($itemId)
            : new SonarrClient($serviceConnection)->fetchSeriesById($itemId);
    }

    /**
     * @param  array<string, mixed>|null  $live
     * @return OwnedStatus
     */
    private function seriesStatus(IndexedSeries $indexedSeries, mixed $tvdbSeason, ?array $live, ?int $activeConnectionId): array
    {
        $seasonNumber = $tvdbSeason !== null ? (int) $tvdbSeason : null;
        $season = $live !== null && $seasonNumber !== null ? $this->liveSeason($live, $seasonNumber) : null;

        $scope = match (true) {
            $live === null => $indexedSeries->monitored ? null : 'series',
            ($live['monitored'] ?? null) === false => 'series',
            ($season['monitored'] ?? null) === false => 'season',
            default => null,
        };

        return $this->status($scope, 'sonarr', (int) $indexedSeries->sonarr_id, (int) $indexedSeries->service_connection_id, $season !== null ? $seasonNumber : null, $activeConnectionId);
    }

    /**
     * @param  array<string, mixed>|null  $live
     * @return OwnedStatus
     */
    private function movieStatus(IndexedMovie $indexedMovie, ?array $live, ?int $activeConnectionId): array
    {
        $monitored = $live !== null ? ($live['monitored'] ?? null) !== false : $indexedMovie->monitored;

        return $this->status($monitored ? null : 'movie', 'radarr', (int) $indexedMovie->radarr_id, (int) $indexedMovie->service_connection_id, null, $activeConnectionId);
    }

    /**
     * The live season entry for a season number, or null when Sonarr does not list it.
     *
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>|null
     */
    private function liveSeason(array $series, int $seasonNumber): ?array
    {
        $seasons = is_array($series['seasons'] ?? null) ? $series['seasons'] : [];

        foreach ($seasons as $season) {
            if (is_array($season) && isset($season['seasonNumber']) && (int) $season['seasonNumber'] === $seasonNumber) {
                return $season;
            }
        }

        return null;
    }

    /**
     * @param  'series'|'season'|'movie'|null  $unmonitoredScope
     * @param  'sonarr'|'radarr'  $service
     * @return OwnedStatus
     */
    private function status(?string $unmonitoredScope, string $service, int $itemId, int $connectionId, ?int $seasonNumber, ?int $activeConnectionId): array
    {
        return [
            'status' => $unmonitoredScope === null ? 'in_library' : 'unmonitored',
            'unmonitoredScope' => $unmonitoredScope,
            'library' => [
                'service' => $service,
                'itemId' => $itemId,
                'connectionId' => $connectionId,
                'seasonNumber' => $seasonNumber,
                'onActiveConnection' => $connectionId === $activeConnectionId,
            ],
        ];
    }
}
