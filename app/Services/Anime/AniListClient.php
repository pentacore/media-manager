<?php

declare(strict_types=1);

namespace App\Services\Anime;

use App\Enums\AnimeAirStatus;
use App\Enums\AnimeFormat;
use App\Enums\AnimeSeason;
use App\Services\Anime\Concerns\BuildsPublicApiClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Seasonal anime source backed by the public AniList GraphQL API. Needs no
 * API key. A single query yields the MAL id, start date and cover image, so
 * it doubles as the richest input to the ID mapper.
 *
 * @see https://anilist.gitbook.io/anilist-apiv2-docs/
 */
class AniListClient implements SeasonalAnimeSource
{
    use BuildsPublicApiClient;

    private const string ENDPOINT = 'https://graphql.anilist.co';

    private const int PER_PAGE = 50;

    private const int MAX_PAGES = 10;

    private const string QUERY = <<<'GRAPHQL'
    query ($season: MediaSeason, $seasonYear: Int, $page: Int, $perPage: Int) {
        Page(page: $page, perPage: $perPage) {
            pageInfo { hasNextPage }
            media(season: $season, seasonYear: $seasonYear, type: ANIME, sort: POPULARITY_DESC) {
                id
                idMal
                format
                status
                episodes
                popularity
                averageScore
                title { romaji english }
                startDate { year month day }
                coverImage { large }
            }
        }
    }
    GRAPHQL;

    public function slug(): string
    {
        return 'anilist';
    }

    public function fetchSeason(int $year, AnimeSeason $animeSeason): Collection
    {
        $entries = collect();
        $page = 1;

        do {
            $response = $this->client()->post(self::ENDPOINT, [
                'query' => self::QUERY,
                'variables' => [
                    'season' => $animeSeason->anilist(),
                    'seasonYear' => $year,
                    'page' => $page,
                    'perPage' => self::PER_PAGE,
                ],
            ])->throw()->json('data.Page') ?? [];

            foreach ($response['media'] ?? [] as $media) {
                $entries->push($this->mapEntry($media));
            }

            $hasNext = (bool) ($response['pageInfo']['hasNextPage'] ?? false);
            $page++;
        } while ($hasNext && $page <= self::MAX_PAGES);

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function mapEntry(array $media): SeasonalAnimeEntry
    {
        $averageScore = $media['averageScore'] ?? null;

        return new SeasonalAnimeEntry(
            anilistId: isset($media['id']) ? (int) $media['id'] : null,
            malId: isset($media['idMal']) ? (int) $media['idMal'] : null,
            title: $media['title']['english'] ?? $media['title']['romaji'] ?? 'Unknown',
            format: AnimeFormat::fromRaw($media['format'] ?? null),
            airStatus: AnimeAirStatus::fromRaw($media['status'] ?? null),
            episodes: isset($media['episodes']) ? (int) $media['episodes'] : null,
            posterUrl: $media['coverImage']['large'] ?? null,
            startDate: $this->formatDate($media['startDate'] ?? null),
            popularity: (int) ($media['popularity'] ?? 0),
            score: $averageScore !== null ? (float) $averageScore / 10 : null,
        );
    }

    /**
     * @param  array{year?: int|null, month?: int|null, day?: int|null}|null  $date
     */
    private function formatDate(?array $date): ?string
    {
        if ($date === null || empty($date['year'])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $date['year'], $date['month'] ?? 1, $date['day'] ?? 1);
    }

    private function client(): PendingRequest
    {
        return $this->publicApiClient('AniListClient');
    }

    /**
     * Whether the AniList API is reachable — used by health checks.
     */
    public function isReachable(): bool
    {
        try {
            return $this->client()->post(self::ENDPOINT, [
                'query' => 'query { Page(perPage: 1) { pageInfo { total } } }',
            ])->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
