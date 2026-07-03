<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Jobs\EmbedLibraryItem;
use App\Models\IndexedSeries;
use App\Models\ServiceConnection;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

class SeriesIndexer
{
    /**
     * @param  array<string, mixed>  $series  Sonarr's series object — webhook payload `$payload['series']`
     *                                        or one row from SonarrClient::getSeries().
     */
    public function upsert(array $series, ServiceConnection $serviceConnection): IndexedSeries
    {
        $sonarrId = (int) ($series['id'] ?? 0);

        throw_if($sonarrId === 0, InvalidArgumentException::class, 'Sonarr series payload missing id.');

        $indexedSeries = IndexedSeries::query()->updateOrCreate(
            [
                'service_connection_id' => $serviceConnection->id,
                'sonarr_id' => $sonarrId,
            ],
            [
                'tvdb_id' => $this->nullableInt($series['tvdbId'] ?? null),
                'imdb_id' => $this->imdbToInt($series['imdbId'] ?? null),
                'title' => (string) ($series['title'] ?? '(unknown)'),
                'sort_title' => isset($series['sortTitle']) ? (string) $series['sortTitle'] : null,
                'year' => $this->nullableInt($series['year'] ?? null),
                'title_slug' => isset($series['titleSlug']) ? (string) $series['titleSlug'] : null,
                'status' => isset($series['status']) ? (string) $series['status'] : null,
                'monitored' => (bool) ($series['monitored'] ?? false),
                'network' => isset($series['network']) ? (string) $series['network'] : null,
                'genres' => $this->stringList($series['genres'] ?? null),
                'overview' => isset($series['overview']) ? (string) $series['overview'] : null,
                'poster_url' => $this->posterFrom($series['images'] ?? []),
                'arr_added_at' => $this->parseDate($series['added'] ?? null),
            ],
        );

        if ($indexedSeries->wasRecentlyCreated || $indexedSeries->wasChanged(['title', 'overview', 'genres', 'year'])) {
            EmbedLibraryItem::dispatch($indexedSeries::class, $indexedSeries->id);
        }

        return $indexedSeries;
    }

    public function forget(int $sonarrId, ServiceConnection $serviceConnection): void
    {
        IndexedSeries::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->where('sonarr_id', $sonarrId)
            ->get()
            ->each(static fn (IndexedSeries $indexedSeries): bool => $indexedSeries->delete() !== false);
    }

    /**
     * @param  array<int|string, mixed>  $images
     */
    private function posterFrom(array $images): ?string
    {
        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }

            if (($image['coverType'] ?? null) === 'poster') {
                $url = $image['remoteUrl'] ?? $image['url'] ?? null;

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    private function imdbToInt(mixed $imdbId): ?int
    {
        if (! is_string($imdbId)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $imdbId);

        return $digits === null || $digits === '' ? null : (int) $digits;
    }

    /**
     * @param  array<int|string, mixed>|mixed  $list
     * @return array<int, string>|null
     */
    private function stringList(mixed $list): ?array
    {
        if (! is_array($list)) {
            return null;
        }

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $list));
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
