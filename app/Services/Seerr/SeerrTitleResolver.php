<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;

class SeerrTitleResolver
{
    /**
     * Titles and poster paths are essentially immutable once a TMDB record
     * exists, so the resolver caches them aggressively — refetching only
     * after a full day even though Seerr's underlying detail entity cache
     * is shorter.
     */
    private const int TITLE_CACHE_TTL_SECONDS = 86_400;

    /**
     * Given a list of Seerr request rows (each with media.mediaType + media.tmdbId),
     * resolve a {mediaType:tmdbId => {title, poster_path}} map. Issues a single
     * concurrent batch via SeerrClient::getMediaDetailsBatch for any entries
     * missing from the cache, then folds the results in.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, array{title: string, poster_path: ?string}>
     */
    public function resolve(ServiceConnection $serviceConnection, SeerrClient $seerrClient, array $requests): array
    {
        $pairs = $this->extractPairs($requests);

        if ($pairs === []) {
            return [];
        }

        $media = [];
        $missing = [];

        foreach ($pairs as $key => [$mediaType, $tmdbId]) {
            $cached = Cache::get($this->cacheKey($serviceConnection, $mediaType, $tmdbId));
            if (is_array($cached) && isset($cached['title'])) {
                $media[$key] = $cached;

                continue;
            }

            $missing[$key] = [$mediaType, $tmdbId];
        }

        if ($missing === []) {
            return $media;
        }

        $details = $seerrClient->getMediaDetailsBatch(array_values($missing));

        foreach ($missing as $key => [$mediaType, $tmdbId]) {
            $detail = $details[$key] ?? [];
            $entry = [
                'title' => $this->extractTitle($detail, $mediaType, $tmdbId),
                'poster_path' => $this->extractPosterPath($detail),
            ];
            Cache::put(
                $this->cacheKey($serviceConnection, $mediaType, $tmdbId),
                $entry,
                self::TITLE_CACHE_TTL_SECONDS,
            );
            $media[$key] = $entry;
        }

        return $media;
    }

    /**
     * Compose the title for a single request row from a pre-resolved media map.
     * Returns null when the row didn't have id/type to lookup with.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, array{title: string, poster_path: ?string}>  $media
     */
    public function titleFor(array $request, array $media): ?string
    {
        return $this->entryFor($request, $media)['title'] ?? null;
    }

    /**
     * TMDB-relative poster path (e.g. "/abc.jpg") for a single request row,
     * or null when unknown.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, array{title: string, poster_path: ?string}>  $media
     */
    public function posterPathFor(array $request, array $media): ?string
    {
        return $this->entryFor($request, $media)['poster_path'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, array{title: string, poster_path: ?string}>  $media
     * @return array{title: string, poster_path: ?string}|null
     */
    private function entryFor(array $request, array $media): ?array
    {
        $mediaType = $request['type'] ?? ($request['media']['mediaType'] ?? null);
        $tmdbId = $request['media']['tmdbId'] ?? null;

        if ($mediaType === null || $tmdbId === null) {
            return null;
        }

        return $media[sprintf('%s:%d', (string) $mediaType, (int) $tmdbId)] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, array{0: string, 1: int}>
     */
    private function extractPairs(array $requests): array
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
                (string) $mediaType,
                (int) $tmdbId,
            ];
        }

        return $pairs;
    }

    private function cacheKey(ServiceConnection $serviceConnection, string $mediaType, int $tmdbId): string
    {
        // "media" (not "title") prefix: the cached value became an array when
        // poster paths were added, so stale string entries must miss.
        return sprintf('seerr:media:%d:%s:%d', $serviceConnection->id, $mediaType, $tmdbId);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function extractTitle(array $detail, string $mediaType, int $tmdbId): string
    {
        return (string) (
            $detail['title']
            ?? $detail['name']
            ?? $detail['originalTitle']
            ?? $detail['originalName']
            ?? sprintf('%s #%d', ucfirst($mediaType), $tmdbId)
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function extractPosterPath(array $detail): ?string
    {
        $posterPath = $detail['posterPath'] ?? null;

        return is_string($posterPath) && $posterPath !== '' ? $posterPath : null;
    }
}
