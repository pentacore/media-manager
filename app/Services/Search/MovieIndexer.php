<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Jobs\EmbedLibraryItem;
use App\Models\IndexedMovie;
use App\Models\ServiceConnection;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

class MovieIndexer
{
    /**
     * @param  array<string, mixed>  $movie  Radarr's movie object — webhook payload `$payload['movie']`
     *                                       or one row from RadarrClient::getMovies().
     */
    public function upsert(array $movie, ServiceConnection $serviceConnection): IndexedMovie
    {
        $radarrId = (int) ($movie['id'] ?? 0);

        throw_if($radarrId === 0, InvalidArgumentException::class, 'Radarr movie payload missing id.');

        $indexedMovie = IndexedMovie::query()->updateOrCreate(
            [
                'service_connection_id' => $serviceConnection->id,
                'radarr_id' => $radarrId,
            ],
            [
                'tmdb_id' => $this->nullableInt($movie['tmdbId'] ?? null),
                'imdb_id' => isset($movie['imdbId']) ? (string) $movie['imdbId'] : null,
                'title' => (string) ($movie['title'] ?? '(unknown)'),
                'sort_title' => isset($movie['sortTitle']) ? (string) $movie['sortTitle'] : null,
                'original_title' => isset($movie['originalTitle']) ? (string) $movie['originalTitle'] : null,
                'year' => $this->nullableInt($movie['year'] ?? null),
                'title_slug' => isset($movie['titleSlug']) ? (string) $movie['titleSlug'] : null,
                'status' => isset($movie['status']) ? (string) $movie['status'] : null,
                'monitored' => (bool) ($movie['monitored'] ?? false),
                'has_file' => (bool) ($movie['hasFile'] ?? false),
                'genres' => $this->stringList($movie['genres'] ?? null),
                'overview' => isset($movie['overview']) ? (string) $movie['overview'] : null,
                'poster_url' => $this->posterFrom($movie['images'] ?? []),
                'arr_added_at' => $this->parseDate($movie['added'] ?? null),
            ],
        );

        if ($indexedMovie->wasRecentlyCreated || $indexedMovie->wasChanged(['title', 'overview', 'genres', 'year'])) {
            EmbedLibraryItem::dispatch($indexedMovie::class, $indexedMovie->id);
        }

        return $indexedMovie;
    }

    public function forget(int $radarrId, ServiceConnection $serviceConnection): void
    {
        IndexedMovie::query()
            ->where('service_connection_id', $serviceConnection->id)
            ->where('radarr_id', $radarrId)
            ->get()
            ->each(static fn (IndexedMovie $indexedMovie): bool => $indexedMovie->delete() !== false);
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
