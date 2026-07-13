<?php

declare(strict_types=1);

namespace App\Services\Anime;

use App\Enums\AnimeAirStatus;
use App\Enums\AnimeFormat;
use App\Enums\AnimeSeason;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Seasonal anime source backed by the public Jikan (unofficial MyAnimeList)
 * REST API. Needs no API key but is rate limited (~3 req/s), so pagination
 * is walked with a small delay between pages. Entries carry only a MAL id.
 *
 * @see https://docs.api.jikan.moe/
 */
class JikanClient implements SeasonalAnimeSource
{
    private const string BASE_URL = 'https://api.jikan.moe/v4';

    private const int MAX_PAGES = 15;

    public function slug(): string
    {
        return 'jikan';
    }

    public function fetchSeason(int $year, AnimeSeason $animeSeason): Collection
    {
        $entries = collect();
        $page = 1;

        do {
            $payload = $this->client()
                ->get(sprintf('%s/seasons/%d/%s', self::BASE_URL, $year, $animeSeason->value), ['page' => $page])
                ->throw()
                ->json() ?? [];

            foreach ($payload['data'] ?? [] as $anime) {
                $entries->push($this->mapEntry($anime));
            }

            $hasNext = (bool) ($payload['pagination']['has_next_page'] ?? false);
            $page++;
        } while ($hasNext && $page <= self::MAX_PAGES);

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $anime
     */
    private function mapEntry(array $anime): SeasonalAnimeEntry
    {
        return new SeasonalAnimeEntry(
            anilistId: null,
            malId: isset($anime['mal_id']) ? (int) $anime['mal_id'] : null,
            title: $anime['title_english'] ?? $anime['title'] ?? 'Unknown',
            format: AnimeFormat::fromRaw($anime['type'] ?? null),
            airStatus: AnimeAirStatus::fromRaw($anime['status'] ?? null),
            episodes: isset($anime['episodes']) ? (int) $anime['episodes'] : null,
            posterUrl: $anime['images']['jpg']['large_image_url'] ?? $anime['images']['jpg']['image_url'] ?? null,
            startDate: $this->formatDate($anime['aired']['from'] ?? null),
            popularity: isset($anime['members']) ? (int) $anime['members'] : 0,
            score: isset($anime['score']) ? (float) $anime['score'] : null,
        );
    }

    private function formatDate(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return substr($iso, 0, 10);
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->withUserAgent('MediaManager/'.config('app.version').' JikanClient')
            ->retry(3, fn (int $attempt): int => $attempt * 500, throw: false);
    }

    /**
     * Whether the Jikan API is reachable — used by health checks.
     */
    public function isReachable(): bool
    {
        try {
            return $this->client()->get(self::BASE_URL.'/seasons/now', ['limit' => 1])->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
