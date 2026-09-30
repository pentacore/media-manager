<?php

declare(strict_types=1);

namespace App\Services\Seerr;

/**
 * Shapes Seerr discover/search hits and movie/tv detail payloads into the
 * title rows the Discover page, the Search page and the title detail sheet
 * render. Status comes from Seerr's `mediaInfo.status` (MediaStatus: 1
 * unknown, 2 pending, 3 processing, 4 partially available, 5 available) and,
 * per TV season, from any request that is not declined (MediaRequestStatus:
 * 1 pending, 2 approved, 3 declined, 4 failed, 5 completed).
 */
final readonly class SeerrTitlePresenter
{
    public const string AVAILABLE = 'available';

    public const string PARTIALLY_AVAILABLE = 'partially_available';

    public const string REQUESTED = 'requested';

    public const string PENDING = 'pending';

    public const string NONE = 'none';

    private const int DECLINED_REQUEST = 3;

    private const int PENDING_REQUEST = 1;

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{tmdb_id: int, media_type: string, title: string, year: int|null, poster_path: string|null, backdrop_path: string|null, overview: string|null, release_date: string|null, status: string}>
     */
    public function results(array $payload, ?string $mediaType = null): array
    {
        $rows = [];

        foreach (is_array($payload['results'] ?? null) ? $payload['results'] : [] as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $row = $this->title($hit, $mediaType);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array{tmdb_id: int, media_type: string, title: string, year: int|null, poster_path: string|null, backdrop_path: string|null, overview: string|null, release_date: string|null, status: string}|null
     */
    public function title(array $hit, ?string $mediaType = null): ?array
    {
        $type = (string) ($hit['mediaType'] ?? $mediaType ?? '');
        $tmdbId = (int) ($hit['id'] ?? 0);
        $title = $hit['title'] ?? $hit['name'] ?? null;

        if (! in_array($type, ['movie', 'tv'], true) || $tmdbId <= 0 || ! is_string($title) || $title === '') {
            return null;
        }

        $releaseDate = $this->stringOrNull($hit['releaseDate'] ?? $hit['firstAirDate'] ?? null);

        return [
            'tmdb_id' => $tmdbId,
            'media_type' => $type,
            'title' => $title,
            'year' => $releaseDate === null ? null : (int) substr($releaseDate, 0, 4),
            'poster_path' => $this->stringOrNull($hit['posterPath'] ?? null),
            'backdrop_path' => $this->stringOrNull($hit['backdropPath'] ?? null),
            'overview' => $this->stringOrNull($hit['overview'] ?? null),
            'release_date' => $releaseDate,
            'status' => $this->status(is_array($hit['mediaInfo'] ?? null) ? $hit['mediaInfo'] : null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $mediaInfo
     */
    public function status(?array $mediaInfo): string
    {
        return match ((int) ($mediaInfo['status'] ?? 1)) {
            5 => self::AVAILABLE,
            4 => self::PARTIALLY_AVAILABLE,
            3 => self::REQUESTED,
            2 => self::PENDING,
            default => self::NONE,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function detail(string $mediaType, array $detail): ?array
    {
        $title = $this->title($detail, $mediaType);

        if ($title === null) {
            return null;
        }

        $runtime = $mediaType === 'movie'
            ? ($detail['runtime'] ?? null)
            : ($detail['episodeRunTime'][0] ?? null);

        return [
            ...$title,
            'rating' => is_numeric($detail['voteAverage'] ?? null) ? round((float) $detail['voteAverage'], 1) : null,
            'runtime' => is_numeric($runtime) ? (int) $runtime : null,
            'season_count' => $mediaType === 'tv' && is_numeric($detail['numberOfSeasons'] ?? null) ? (int) $detail['numberOfSeasons'] : null,
            'seasons' => $mediaType === 'tv' ? $this->seasons($detail) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return list<array{season_number: int, name: string, episode_count: int, status: string, requestable: bool}>
     */
    private function seasons(array $detail): array
    {
        $mediaInfo = is_array($detail['mediaInfo'] ?? null) ? $detail['mediaInfo'] : [];
        $libraryStatus = [];
        $requestStatus = [];

        foreach (is_array($mediaInfo['seasons'] ?? null) ? $mediaInfo['seasons'] : [] as $season) {
            if (is_array($season)) {
                $libraryStatus[(int) ($season['seasonNumber'] ?? -1)] = $this->status($season);
            }
        }

        foreach (is_array($mediaInfo['requests'] ?? null) ? $mediaInfo['requests'] : [] as $request) {
            if (! is_array($request) || (int) ($request['status'] ?? 0) === self::DECLINED_REQUEST) {
                continue;
            }

            $state = (int) ($request['status'] ?? 0) === self::PENDING_REQUEST ? self::PENDING : self::REQUESTED;

            foreach (is_array($request['seasons'] ?? null) ? $request['seasons'] : [] as $season) {
                if (is_array($season)) {
                    $requestStatus[(int) ($season['seasonNumber'] ?? -1)] ??= $state;
                }
            }
        }

        $rows = [];

        foreach (is_array($detail['seasons'] ?? null) ? $detail['seasons'] : [] as $season) {
            $number = is_array($season) ? (int) ($season['seasonNumber'] ?? 0) : 0;

            // Specials (season 0) are not requestable with Seerr's defaults.
            if ($number <= 0) {
                continue;
            }

            $library = $libraryStatus[$number] ?? self::NONE;
            $status = $library !== self::NONE ? $library : ($requestStatus[$number] ?? self::NONE);

            $rows[] = [
                'season_number' => $number,
                'name' => is_string($season['name'] ?? null) ? $season['name'] : sprintf('Season %d', $number),
                'episode_count' => (int) ($season['episodeCount'] ?? 0),
                'status' => $status,
                'requestable' => $status === self::NONE,
            ];
        }

        return $rows;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
