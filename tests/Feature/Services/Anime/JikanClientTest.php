<?php

declare(strict_types=1);

use App\Enums\AnimeAirStatus;
use App\Enums\AnimeFormat;
use App\Enums\AnimeSeason;
use App\Services\Anime\JikanClient;
use App\Services\Anime\SeasonalAnimeEntry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->client = new JikanClient;
});

test('slug identifies the source', function (): void {
    expect($this->client->slug())->toBe('jikan');
});

test('fetchSeason GETs the seasons endpoint and parses entries', function (): void {
    Http::fake([
        'api.jikan.moe/v4/seasons/2023/fall*' => Http::response([
            'pagination' => ['has_next_page' => false],
            'data' => [
                [
                    'mal_id' => 52991,
                    'title' => 'Sousou no Frieren',
                    'title_english' => "Frieren: Beyond Journey's End",
                    'type' => 'TV',
                    'status' => 'Finished Airing',
                    'episodes' => 28,
                    'members' => 500000,
                    'score' => 9.3,
                    'images' => ['jpg' => ['large_image_url' => 'https://img/frieren.jpg']],
                    'aired' => ['from' => '2023-09-29T00:00:00+00:00'],
                ],
            ],
        ]),
    ]);

    $entries = $this->client->fetchSeason(2023, AnimeSeason::Fall);

    expect($entries)->toHaveCount(1);

    /** @var SeasonalAnimeEntry $entry */
    $entry = $entries->first();
    expect($entry->anilistId)->toBeNull();
    expect($entry->malId)->toBe(52991);
    expect($entry->title)->toBe("Frieren: Beyond Journey's End");
    expect($entry->format)->toBe(AnimeFormat::Tv);
    expect($entry->airStatus)->toBe(AnimeAirStatus::Finished);
    expect($entry->episodes)->toBe(28);
    expect($entry->posterUrl)->toBe('https://img/frieren.jpg');
    // aired.from ISO truncated to YYYY-MM-DD.
    expect($entry->startDate)->toBe('2023-09-29');
    // members become popularity.
    expect($entry->popularity)->toBe(500000);
    expect($entry->score)->toBe(9.3);
});

test('fetchSeason walks pagination until has_next_page is false', function (): void {
    Http::fake([
        'api.jikan.moe/v4/seasons/2026/summer*' => Http::sequence()
            ->push([
                'pagination' => ['has_next_page' => true],
                'data' => [
                    ['mal_id' => 10, 'title' => 'One', 'type' => 'TV', 'status' => 'Currently Airing', 'aired' => ['from' => '2026-07-01T00:00:00+00:00']],
                ],
            ])
            ->push([
                'pagination' => ['has_next_page' => false],
                'data' => [
                    ['mal_id' => 20, 'title' => 'Two', 'type' => 'Movie', 'status' => 'Not yet aired', 'aired' => ['from' => null]],
                ],
            ]),
    ]);

    $entries = $this->client->fetchSeason(2026, AnimeSeason::Summer);

    expect($entries)->toHaveCount(2);
    expect($entries->pluck('malId')->all())->toBe([10, 20]);

    $pages = [];
    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) use (&$pages): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $pages[] = (int) ($query['page'] ?? 0);

        return true;
    });
    expect($pages)->toBe([1, 2]);
});

test('fetchSeason falls back to the base title when no english title is present', function (): void {
    Http::fake([
        'api.jikan.moe/v4/seasons/2020/winter*' => Http::response([
            'pagination' => ['has_next_page' => false],
            'data' => [
                [
                    'mal_id' => 77,
                    'title' => 'Base Title',
                    'type' => 'OVA',
                    'status' => 'Finished Airing',
                    'aired' => ['from' => null],
                ],
            ],
        ]),
    ]);

    /** @var SeasonalAnimeEntry $entry */
    $entry = $this->client->fetchSeason(2020, AnimeSeason::Winter)->first();

    expect($entry->title)->toBe('Base Title');
    expect($entry->format)->toBe(AnimeFormat::Ova);
    expect($entry->startDate)->toBeNull();
    expect($entry->score)->toBeNull();
    expect($entry->popularity)->toBe(0);
});
