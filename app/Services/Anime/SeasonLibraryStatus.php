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
use Illuminate\Support\Collection;

/**
 * Library status of the seasonal anime rows already owned in Sonarr/Radarr.
 *
 * A row is owned when the index holds it (`indexed_series.tvdb_id`,
 * `indexed_movies.tmdb_id`); when several connections hold it, the row on
 * that service's active connection wins. Monitoring state is read live from
 * Sonarr/Radarr over the index, because it changes and the overlay is meant
 * to show what is true now — so the uncached `fetchSeriesByIds()` /
 * `fetchMoviesByIds()` reads are used, never the client's entity cache.
 *
 * Each connection gets one concurrent round per call: its distinct
 * series/movie ids are read together, one attempt each, so several seasons of
 * one series share a single request and a hung connection costs one timeout
 * rather than one per card. Inactive connections are never called. An item
 * that could not be read — a connection failure, any non-2xx such as a 404
 * for a series deleted upstream but still indexed, or a 200 that is not a
 * series/movie — falls back on its own: the indexed `monitored` flag decides,
 * at series/movie level only, and no season number is offered.
 *
 * Season 0 (Specials) is judged at series level, like an unknown season: no
 * season-scope check and no season number. Sonarr leaves Specials unmonitored
 * by default and the Fribb mapping puts OVAs, specials and movies on season 0,
 * so a season-level check would flag every such entry of a monitored series, and
 * monitoring season 0 would search for every special.
 *
 * @phpstan-type OwnedStatus array{status: 'in_library'|'unmonitored', unmonitoredScope: 'series'|'season'|'movie'|null, library: array{service: 'sonarr'|'radarr', itemId: int, connectionId: int, seasonNumber: int|null, onActiveConnection: bool}}
 */
final readonly class SeasonLibraryStatus
{
    /**
     * Statuses keyed by row `key`; rows that are not owned are absent.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{status: 'in_library'|'unmonitored', unmonitoredScope: 'series'|'season'|'movie'|null, library: array{service: 'sonarr'|'radarr', itemId: int, connectionId: int, seasonNumber: int|null, onActiveConnection: bool}}>
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

        $liveSeries = $this->liveItems($seriesByTvdbId, 'sonarr_id', static fn (ServiceConnection $serviceConnection, array $ids): array => new SonarrClient($serviceConnection)->fetchSeriesByIds($ids));
        $liveMovies = $this->liveItems($moviesByTmdbId, 'radarr_id', static fn (ServiceConnection $serviceConnection, array $ids): array => new RadarrClient($serviceConnection)->fetchMoviesByIds($ids));
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
                    $liveSeries[(int) $series->service_connection_id][(int) $series->sonarr_id] ?? null,
                    $activeSonarrId,
                );
            } elseif ($movie instanceof IndexedMovie) {
                $resolved[$row['key']] = $this->movieStatus(
                    $movie,
                    $liveMovies[(int) $movie->service_connection_id][(int) $movie->radarr_id] ?? null,
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
     * Live payloads of the chosen indexed rows, read in one concurrent round
     * per active connection: connection id => item id => payload, or null for
     * an item that could not be read. Rows on an inactive or missing
     * connection are left out, so they fall back to the index.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $indexed
     * @param  Closure(ServiceConnection, list<int>): array<int, array<string, mixed>|null>  $fetchByIds
     * @return array<int, array<int, array<string, mixed>|null>>
     */
    private function liveItems(Collection $indexed, string $itemIdColumn, Closure $fetchByIds): array
    {
        /** @var array<int, array{connection: ServiceConnection, ids: list<int>}> $wanted */
        $wanted = [];

        foreach ($indexed as $model) {
            $serviceConnection = $model->getRelationValue('serviceConnection');

            if (! $serviceConnection instanceof ServiceConnection || ! $serviceConnection->is_active) {
                continue;
            }

            $wanted[$serviceConnection->id] ??= ['connection' => $serviceConnection, 'ids' => []];
            $wanted[$serviceConnection->id]['ids'][] = (int) $model->getAttribute($itemIdColumn);
        }

        return array_map(
            static fn (array $connectionIds): array => $fetchByIds($connectionIds['connection'], array_values(array_unique($connectionIds['ids']))),
            $wanted,
        );
    }

    /**
     * Season 0 (Specials) counts as an unknown season: it is never checked at
     * season level and offers no season number (see the class docblock).
     *
     * @param  array<string, mixed>|null  $live
     * @return OwnedStatus
     */
    private function seriesStatus(IndexedSeries $indexedSeries, mixed $tvdbSeason, ?array $live, ?int $activeConnectionId): array
    {
        $seasonNumber = $tvdbSeason !== null && (int) $tvdbSeason !== 0 ? (int) $tvdbSeason : null;
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
