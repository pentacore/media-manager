<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Library\WantedRequest;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Missing and cutoff-unmet items from the primary Sonarr and Radarr, paged
 * upstream. Remote-service listing, so the props envelope is built here.
 */
class WantedController extends Controller
{
    private const int PER_PAGE = 20;

    public function __invoke(WantedRequest $wantedRequest): Response
    {
        $validated = $wantedRequest->validated();
        $tab = (string) ($validated['tab'] ?? 'missing');
        $monitored = (bool) ($validated['monitored'] ?? true);
        $sonarrPage = (int) ($validated['sonarr_page'] ?? 1);
        $radarrPage = (int) ($validated['radarr_page'] ?? 1);

        return Inertia::render('Library/Wanted', [
            'filters' => ['tab' => $tab, 'monitored' => $monitored, 'sonarr_page' => $sonarrPage, 'radarr_page' => $radarrPage],
            'sonarr' => Inertia::defer(fn (): array => $this->section(ServiceType::Sonarr, $tab, $sonarrPage, $monitored), 'sonarr'),
            'radarr' => Inertia::defer(fn (): array => $this->section(ServiceType::Radarr, $tab, $radarrPage, $monitored), 'radarr'),
        ]);
    }

    /**
     * @return array{connected: bool, service_connection_id: int|null, records: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}, error: string|null}
     */
    private function section(ServiceType $serviceType, string $tab, int $page, bool $monitored): array
    {
        $meta = ['current_page' => $page, 'last_page' => 1, 'total' => 0, 'per_page' => self::PER_PAGE];

        try {
            $connection = ServiceConnection::resolveActive($serviceType);
        } catch (ModelNotFoundException) {
            return ['connected' => false, 'service_connection_id' => null, 'records' => [], 'meta' => $meta, 'error' => null];
        }

        $client = $serviceType === ServiceType::Sonarr ? new SonarrClient($connection) : new RadarrClient($connection);

        try {
            $payload = $client->getWanted($tab, $page, self::PER_PAGE, $monitored, withRetry: false);
        } catch (RequestException|ConnectionException) {
            return [
                'connected' => true,
                'service_connection_id' => $connection->id,
                'records' => [],
                'meta' => $meta,
                'error' => sprintf('%s is unreachable right now.', ucfirst($serviceType->value)),
            ];
        }

        $records = array_values(array_filter(is_array($payload['records'] ?? null) ? $payload['records'] : [], is_array(...)));
        $total = (int) ($payload['totalRecords'] ?? count($records));
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        return [
            'connected' => true,
            'service_connection_id' => $connection->id,
            'records' => array_map(
                fn (array $record): array => $serviceType === ServiceType::Sonarr ? $this->episodeRow($record) : $this->movieRow($record),
                $records,
            ),
            'meta' => [
                // Clamp instead of echoing a requested page past the end
                // (e.g. a stale bookmark after items clear) — "Page 12 of 3"
                // reads as broken, not just empty.
                'current_page' => min(max($page, 1), $lastPage),
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => self::PER_PAGE,
            ],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $episode
     * @return array<string, mixed>
     */
    private function episodeRow(array $episode): array
    {
        $seriesId = (int) ($episode['seriesId'] ?? 0);

        return [
            'id' => (int) ($episode['id'] ?? 0),
            'series_id' => $seriesId,
            'title' => (string) ($episode['series']['title'] ?? __('Unknown series')),
            'episode_title' => is_string($episode['title'] ?? null) ? $episode['title'] : null,
            'code' => sprintf('S%02dE%02d', (int) ($episode['seasonNumber'] ?? 0), (int) ($episode['episodeNumber'] ?? 0)),
            'air_date_utc' => is_string($episode['airDateUtc'] ?? null) ? $episode['airDateUtc'] : null,
            'library_url' => $seriesId > 0 ? route('media.series.show', ['id' => $seriesId], absolute: false) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>
     */
    private function movieRow(array $movie): array
    {
        $movieId = (int) ($movie['id'] ?? 0);
        $date = $movie['digitalRelease'] ?? $movie['physicalRelease'] ?? $movie['inCinemas'] ?? null;

        return [
            'id' => $movieId,
            'title' => (string) ($movie['title'] ?? __('Unknown movie')),
            'year' => isset($movie['year']) ? (int) $movie['year'] : null,
            'air_date_utc' => is_string($date) ? $date : null,
            'library_url' => $movieId > 0 ? route('media.movies.show', ['id' => $movieId], absolute: false) : null,
        ];
    }
}
