<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Arr\ArrConnections;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * One page of the primary (first active) Sonarr's or Radarr's missing or
 * cutoff-unmet list, shaped for the Wanted page and paged upstream. No
 * active connection is `connected: false`; an outage is an `error`, never an
 * empty list.
 */
final readonly class LibraryWanted
{
    public const int PER_PAGE = 20;

    public function __construct(private ArrConnections $arrConnections) {}

    /**
     * @return array{connected: bool, service_connection_id: int|null, records: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}, error: string|null}
     */
    public function section(ServiceType $serviceType, string $list, int $page, bool $monitored): array
    {
        $meta = ['current_page' => $page, 'last_page' => 1, 'total' => 0, 'per_page' => self::PER_PAGE];
        $connection = ServiceConnection::findActive($serviceType);

        if (! $connection instanceof ServiceConnection) {
            return ['connected' => false, 'service_connection_id' => null, 'records' => [], 'meta' => $meta, 'error' => null];
        }

        try {
            $payload = $this->arrConnections->client($connection)->getWanted($list, $page, self::PER_PAGE, $monitored, withRetry: false);
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
