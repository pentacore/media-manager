<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrClient;
use App\Services\Sonarr\SonarrClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The household calendar: every active Sonarr and Radarr connection's
 * calendar merged into one list. A failing connection is reported, never
 * fatal. Library pages resolve the primary (first active) connection, so only
 * its items get a library link — numeric ids overlap across instances.
 */
final readonly class LibraryCalendar
{
    /**
     * @return array{items: list<array<string, mixed>>, failures: list<array{service: string, instance: string}>}
     */
    public function between(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $items = [];
        $failures = [];

        foreach ([ServiceType::Sonarr, ServiceType::Radarr] as $serviceType) {
            $connections = ServiceConnection::query()
                ->where('type', $serviceType)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();
            $primaryId = $connections->first()?->id;
            $labelled = $connections->count() > 1;

            foreach ($connections as $connection) {
                try {
                    $entries = $serviceType === ServiceType::Sonarr
                        ? new SonarrClient($connection)->getCalendar($start, $end)
                        : new RadarrClient($connection)->getCalendar($start, $end);
                } catch (RequestException|ConnectionException) {
                    $failures[] = ['service' => ucfirst($serviceType->value), 'instance' => $connection->name];

                    continue;
                }

                foreach ($entries as $entry) {
                    $item = $serviceType === ServiceType::Sonarr
                        ? $this->episodeItem($connection, $entry, $labelled, $connection->id === $primaryId)
                        : $this->movieItem($connection, $entry, $start, $end, $labelled, $connection->id === $primaryId);

                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
            }
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['air_date_utc'], $b['air_date_utc']));

        return ['items' => $items, 'failures' => $failures];
    }

    /**
     * @param  array<string, mixed>  $episode
     * @return array<string, mixed>|null
     */
    private function episodeItem(ServiceConnection $serviceConnection, array $episode, bool $labelled, bool $primary): ?array
    {
        $episodeId = (int) ($episode['id'] ?? 0);
        $seriesId = (int) ($episode['seriesId'] ?? 0);
        $airDate = $this->parse($episode['airDateUtc'] ?? null);

        if ($episodeId <= 0 || $seriesId <= 0 || ! $airDate instanceof CarbonImmutable) {
            return null;
        }

        $series = is_array($episode['series'] ?? null) ? $episode['series'] : [];
        $monitored = (bool) ($episode['monitored'] ?? false) && (bool) ($series['monitored'] ?? true);

        return [
            'key' => sprintf('sonarr:%d:%d', $serviceConnection->id, $episodeId),
            'service' => 'sonarr',
            'service_connection_id' => $serviceConnection->id,
            'instance' => $labelled ? $serviceConnection->name : null,
            'title' => is_string($series['title'] ?? null) ? $series['title'] : __('Unknown series'),
            'episode_title' => is_string($episode['title'] ?? null) ? $episode['title'] : null,
            'code' => sprintf('S%02dE%02d', (int) ($episode['seasonNumber'] ?? 0), (int) ($episode['episodeNumber'] ?? 0)),
            'air_date_utc' => $airDate->toIso8601ZuluString(),
            'state' => $this->state((bool) ($episode['hasFile'] ?? false), $monitored, $airDate),
            'monitored' => $monitored,
            'poster_url' => $this->poster($series['images'] ?? null),
            'library_url' => $primary ? route('media.series.show', ['id' => $seriesId], absolute: false) : null,
            'series_id' => $seriesId,
            'episode_id' => $episodeId,
            'movie_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>|null
     */
    private function movieItem(ServiceConnection $serviceConnection, array $movie, CarbonImmutable $start, CarbonImmutable $end, bool $labelled, bool $primary): ?array
    {
        $movieId = (int) ($movie['id'] ?? 0);
        $releaseDate = null;

        // Digital first: it is the date a movie becomes downloadable.
        foreach (['digitalRelease', 'physicalRelease', 'inCinemas'] as $field) {
            $date = $this->parse($movie[$field] ?? null);

            if ($date instanceof CarbonImmutable && $date->betweenIncluded($start, $end)) {
                $releaseDate = $date;

                break;
            }
        }

        if ($movieId <= 0 || ! $releaseDate instanceof CarbonImmutable) {
            return null;
        }

        $monitored = (bool) ($movie['monitored'] ?? false);

        return [
            'key' => sprintf('radarr:%d:%d', $serviceConnection->id, $movieId),
            'service' => 'radarr',
            'service_connection_id' => $serviceConnection->id,
            'instance' => $labelled ? $serviceConnection->name : null,
            'title' => is_string($movie['title'] ?? null) ? $movie['title'] : __('Unknown movie'),
            'episode_title' => null,
            'code' => 'Movie',
            'air_date_utc' => $releaseDate->toIso8601ZuluString(),
            'state' => $this->state((bool) ($movie['hasFile'] ?? false), $monitored, $releaseDate),
            'monitored' => $monitored,
            'poster_url' => $this->poster($movie['images'] ?? null),
            'library_url' => $primary ? route('media.movies.show', ['id' => $movieId], absolute: false) : null,
            'series_id' => null,
            'episode_id' => null,
            'movie_id' => $movieId,
        ];
    }

    private function state(bool $hasFile, bool $monitored, CarbonImmutable $airDate): string
    {
        return match (true) {
            $hasFile => 'downloaded',
            ! $monitored => 'unmonitored',
            $airDate->lessThanOrEqualTo(now()) => 'missing',
            default => 'upcoming',
        };
    }

    private function poster(mixed $images): ?string
    {
        foreach (is_array($images) ? $images : [] as $image) {
            if (is_array($image) && ($image['coverType'] ?? null) === 'poster') {
                $url = $image['remoteUrl'] ?? $image['url'] ?? null;

                return is_string($url) && $url !== '' ? $url : null;
            }
        }

        return null;
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
