<?php

declare(strict_types=1);

use App\Enums\AnimeAirStatus;
use App\Enums\AnimeFormat;
use App\Enums\AnimeSeason;
use App\Services\Anime\AniListClient;
use App\Services\Anime\SeasonalAnimeEntry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->client = new AniListClient;
});

test('slug identifies the source', function (): void {
    expect($this->client->slug())->toBe('anilist');
});

test('fetchSeason parses media into normalised entries', function (): void {
    Http::fake([
        'graphql.anilist.co' => Http::response([
            'data' => [
                'Page' => [
                    'pageInfo' => ['hasNextPage' => false],
                    'media' => [
                        [
                            'id' => 154587,
                            'idMal' => 52991,
                            'format' => 'TV',
                            'status' => 'FINISHED',
                            'episodes' => 28,
                            'popularity' => 99000,
                            'averageScore' => 93,
                            'title' => ['romaji' => 'Sousou no Frieren', 'english' => 'Frieren'],
                            'startDate' => ['year' => 2023, 'month' => 9, 'day' => 29],
                            'coverImage' => ['large' => 'https://img/frieren.jpg'],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $entries = $this->client->fetchSeason(2023, AnimeSeason::Fall);

    expect($entries)->toHaveCount(1);

    /** @var SeasonalAnimeEntry $entry */
    $entry = $entries->first();
    expect($entry->anilistId)->toBe(154587);
    expect($entry->malId)->toBe(52991);
    expect($entry->title)->toBe('Frieren');
    expect($entry->format)->toBe(AnimeFormat::Tv);
    expect($entry->airStatus)->toBe(AnimeAirStatus::Finished);
    expect($entry->episodes)->toBe(28);
    expect($entry->posterUrl)->toBe('https://img/frieren.jpg');
    expect($entry->startDate)->toBe('2023-09-29');
    expect($entry->popularity)->toBe(99000);
    // averageScore (0-100) is divided by 10.
    expect($entry->score)->toBe(9.3);
});

test('fetchSeason posts the season and year as GraphQL variables', function (): void {
    Http::fake([
        'graphql.anilist.co' => Http::response([
            'data' => ['Page' => ['pageInfo' => ['hasNextPage' => false], 'media' => []]],
        ]),
    ]);

    $this->client->fetchSeason(2026, AnimeSeason::Summer);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->method() === 'POST'
            && str_contains($request->url(), 'graphql.anilist.co')
            && ($body['variables']['season'] ?? null) === 'SUMMER'
            && ($body['variables']['seasonYear'] ?? null) === 2026
            && ($body['variables']['page'] ?? null) === 1;
    });
});

test('fetchSeason walks pagination until hasNextPage is false', function (): void {
    Http::fake([
        'graphql.anilist.co' => Http::sequence()
            ->push([
                'data' => [
                    'Page' => [
                        'pageInfo' => ['hasNextPage' => true],
                        'media' => [
                            ['id' => 1, 'idMal' => 10, 'format' => 'TV', 'status' => 'RELEASING', 'title' => ['romaji' => 'One'], 'startDate' => ['year' => 2026, 'month' => 7, 'day' => 1], 'popularity' => 5],
                        ],
                    ],
                ],
            ])
            ->push([
                'data' => [
                    'Page' => [
                        'pageInfo' => ['hasNextPage' => false],
                        'media' => [
                            ['id' => 2, 'idMal' => 20, 'format' => 'MOVIE', 'status' => 'NOT_YET_RELEASED', 'title' => ['romaji' => 'Two'], 'startDate' => ['year' => 2026, 'month' => 8, 'day' => 5], 'popularity' => 3],
                        ],
                    ],
                ],
            ]),
    ]);

    $entries = $this->client->fetchSeason(2026, AnimeSeason::Summer);

    expect($entries)->toHaveCount(2);
    expect($entries->pluck('anilistId')->all())->toBe([1, 2]);

    // Two pages walked → page=1 then page=2.
    $pages = [];
    Http::assertSentCount(2);
    Http::assertSent(function (Request $request) use (&$pages): bool {
        $pages[] = $request->data()['variables']['page'] ?? null;

        return true;
    });
    expect($pages)->toBe([1, 2]);
});

test('fetchSeason falls back to romaji title and leaves score null when averageScore is absent', function (): void {
    Http::fake([
        'graphql.anilist.co' => Http::response([
            'data' => [
                'Page' => [
                    'pageInfo' => ['hasNextPage' => false],
                    'media' => [
                        [
                            'id' => 99,
                            'idMal' => null,
                            'format' => 'OVA',
                            'status' => 'FINISHED',
                            'title' => ['romaji' => 'Romaji Only', 'english' => null],
                            'startDate' => ['year' => null],
                            'popularity' => 0,
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    /** @var SeasonalAnimeEntry $entry */
    $entry = $this->client->fetchSeason(2020, AnimeSeason::Winter)->first();

    expect($entry->title)->toBe('Romaji Only');
    expect($entry->malId)->toBeNull();
    expect($entry->format)->toBe(AnimeFormat::Ova);
    expect($entry->score)->toBeNull();
    // startDate with no year → null.
    expect($entry->startDate)->toBeNull();
});
