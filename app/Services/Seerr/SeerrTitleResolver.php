<?php

declare(strict_types=1);

namespace App\Services\Seerr;

use App\Models\ServiceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

class SeerrTitleResolver
{
    /**
     * Given a list of Seerr request rows (each with media.mediaType + media.tmdbId),
     * resolve a {mediaType:tmdbId => title} map. Cached 5 minutes per connection.
     *
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, string>
     */
    public function resolve(ServiceConnection $serviceConnection, SeerrClient $seerrClient, array $requests): array
    {
        $pairs = $this->extractPairs($requests);
        $titles = [];

        foreach ($pairs as $key => [$mediaType, $tmdbId]) {
            $titles[$key] = Cache::remember(
                $this->cacheKey($serviceConnection, $mediaType, $tmdbId),
                now()->addMinutes(5),
                fn (): string => $this->fetchTitle($seerrClient, $mediaType, $tmdbId),
            );
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

    private function fetchTitle(SeerrClient $seerrClient, string $mediaType, int $tmdbId): string
    {
        try {
            $detail = $mediaType === 'movie'
                ? $seerrClient->getMovieDetails($tmdbId)
                : $seerrClient->getTvDetails($tmdbId);
        } catch (RequestException|ConnectionException) {
            return sprintf('%s #%d', ucfirst($mediaType), $tmdbId);
        }

        return (string) (
            $detail['title']
            ?? $detail['name']
            ?? $detail['originalTitle']
            ?? $detail['originalName']
            ?? sprintf('%s #%d', ucfirst($mediaType), $tmdbId)
        );
    }
}
