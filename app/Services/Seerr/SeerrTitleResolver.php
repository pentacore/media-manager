<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Cache;

class SeerrTitleResolver
{
    /**
     * Titles are essentially immutable once a TMDB record exists, so the
     * resolver caches them aggressively — refetching only after a full day
     * even though Seerr's underlying detail entity cache is shorter.
     */
    private const int TITLE_CACHE_TTL_SECONDS = 86_400;

    /**
     * Given a list of Seerr request rows (each with media.mediaType + media.tmdbId),
     * resolve a {mediaType:tmdbId => title} map. Issues a single concurrent
     * batch via SeerrClient::getMediaDetailsBatch for any titles missing
     * from the cache, then folds the results in.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, string>
     */
    public function resolve(ServiceConnection $serviceConnection, SeerrClient $seerrClient, array $requests): array
    {
        $pairs = $this->extractPairs($requests);

        if ($pairs === []) {
            return [];
        }

        $titles = [];
        $missing = [];

        foreach ($pairs as $key => [$mediaType, $tmdbId]) {
            $cached = Cache::get($this->cacheKey($serviceConnection, $mediaType, $tmdbId));
            if (is_string($cached)) {
                $titles[$key] = $cached;

                continue;
            }

            $missing[$key] = [$mediaType, $tmdbId];
        }

        if ($missing === []) {
            return $titles;
        }

        $details = $seerrClient->getMediaDetailsBatch(array_values($missing));

        foreach ($missing as $key => [$mediaType, $tmdbId]) {
            $title = $this->extractTitle($details[$key] ?? [], $mediaType, $tmdbId);
            Cache::put(
                $this->cacheKey($serviceConnection, $mediaType, $tmdbId),
                $title,
                self::TITLE_CACHE_TTL_SECONDS,
            );
            $titles[$key] = $title;
        }

        return $titles;
    }

    /**
     * Compose the title for a single request row from a pre-resolved title map.
     * Returns null when the row didn't have id/type to lookup with.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, string>  $titles
     */
    public function titleFor(array $request, array $titles): ?string
    {
        $mediaType = $request['type'] ?? ($request['media']['mediaType'] ?? null);
        $tmdbId = $request['media']['tmdbId'] ?? null;

        if ($mediaType === null || $tmdbId === null) {
            return null;
        }

        $key = sprintf('%s:%d', (string) $mediaType, (int) $tmdbId);

        return $titles[$key] ?? null;
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
        return sprintf('seerr:title:%d:%s:%d', $serviceConnection->id, $mediaType, $tmdbId);
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
}
